<?php

use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\PeriodService;
use App\Domain\Inventory\InsufficientStockException;
use App\Domain\Inventory\InventoryService;
use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Product;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->ledger = app(LedgerService::class);
});

it('K1: menolak jurnal tidak seimbang dan tidak menyimpan baris apa pun', function () {
    expect(fn () => $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-01',
        'lines' => [['account' => '1-1100', 'debit' => 1_000_000], ['account' => '3-1000', 'credit' => 900_000]],
    ]))->toThrow(UnbalancedJournalException::class);

    expect(Journal::count())->toBe(0)->and(JournalLine::count())->toBe(0);
});

it('K2: menolak nominal nol atau negatif', function (int $amount) {
    expect(fn () => $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-01',
        'lines' => [['account' => '1-1100', 'debit' => $amount], ['account' => '3-1000', 'credit' => $amount]],
    ]))->toThrow(InvalidJournalException::class);

    expect(Journal::count())->toBe(0);
})->with([0, -500]);

it('K3: jurnal yang sudah diposting tidak bisa diubah atau dihapus, koreksi lewat pembalik', function () {
    $journal = $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-01',
        'lines' => [['account' => '1-1100', 'debit' => 500_000], ['account' => '3-1000', 'credit' => 500_000]],
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->where('journal_id', $journal->id)->update(['debit' => 1])))->toThrow(Exception::class);
    expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $journal->id)->delete()))->toThrow(Exception::class);

    $reversal = $this->ledger->reverse($journal, '2026-11-02');
    expect($reversal->reversal_of)->toBe($journal->id);
    expect(app(App\Domain\Accounting\ReportService::class)->trialBalance()['rows']['1-1100']['debit'])->toBe(0);
    expect(fn () => $this->ledger->reverse($journal, '2026-11-03'))->toThrow(InvalidJournalException::class);
});

it('K4: periode yang dikunci menolak transaksi, dan hanya pemilik yang bisa membuka', function () {
    $periods = app(PeriodService::class);
    $periods->lock(2026, 11);

    expect(fn () => $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-28',
        'lines' => [['account' => '1-1100', 'debit' => 100], ['account' => '3-1000', 'credit' => 100]],
    ]))->toThrow(PeriodLockedException::class);

    expect(fn () => $periods->unlock(2026, 11, byOwner: false))->toThrow(DomainException::class);

    $periods->unlock(2026, 11, byOwner: true);
    $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-28',
        'lines' => [['account' => '1-1100', 'debit' => 100], ['account' => '3-1000', 'credit' => 100]],
    ]);
    expect(Journal::count())->toBe(1);
});

it('K5: akun yang sudah punya transaksi tidak bisa dihapus, hanya dinonaktifkan', function () {
    $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-01',
        'lines' => [['account' => '1-1100', 'debit' => 100], ['account' => '3-1000', 'credit' => 100]],
    ]);

    expect(fn () => Account::where('code', '1-1100')->first()->delete())->toThrow(DomainException::class);

    Account::where('code', '1-1100')->update(['is_active' => false]);
    expect(fn () => $this->ledger->post([
        'branch_id' => $this->branch->id, 'date' => '2026-11-02',
        'lines' => [['account' => '1-1100', 'debit' => 100], ['account' => '3-1000', 'credit' => 100]],
    ]))->toThrow(InvalidJournalException::class);
});

it('K7: penjualan kasir yang gagal di tengah jalan dibatalkan seluruhnya', function () {
    $product = Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris']);
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 1_000_000, 'kas');
    $tx->purchase($this->branch->id, '2026-11-02', 'CASE', 20, 50_000, 'kas');

    $journals = Journal::count();
    // Periode dikunci tepat sebelum penjualan sehingga posting jurnal gagal setelah stok keluar.
    app(PeriodService::class)->lock(2026, 11);

    expect(fn () => $tx->posSale($this->branch->id, '2026-11-16', [['sku' => 'CASE', 'qty' => 3, 'price' => 120_000]], 'kas'))
        ->toThrow(PeriodLockedException::class);

    $balance = app(InventoryService::class)->balance($this->branch->id, $product->id);
    expect($balance->qty)->toBe(20)->and($balance->value)->toBe(1_000_000);
    expect(Journal::count())->toBe($journals);
});

it('T15: stok tidak cukup ditolak tanpa jurnal baru dan stok tidak berubah', function () {
    $product = Product::create(['sku' => 'BAT12', 'name' => 'Baterai iPhone 12', 'kind' => 'sparepart']);
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'kas');
    $tx->purchase($this->branch->id, '2026-11-02', 'BAT12', 4, 400_000, 'kas');
    $journals = Journal::count();

    expect(fn () => $tx->completeService($this->branch->id, '2026-11-17', [['sku' => 'BAT12', 'qty' => 5, 'price' => 700_000]], 100_000, 0, 'kas'))
        ->toThrow(InsufficientStockException::class);

    expect(app(InventoryService::class)->balance($this->branch->id, $product->id)->qty)->toBe(4);
    expect(Journal::count())->toBe($journals);
});

it('K6: pencatat tidak boleh menyetujui pembayaran yang ia buat sendiri')->todo('butuh peran dan persetujuan (spatie/laravel-permission)');
it('K8: setiap jurnal yang diposting tercatat di audit log')->todo('butuh spatie/laravel-activitylog');
