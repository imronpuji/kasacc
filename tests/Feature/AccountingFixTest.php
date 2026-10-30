<?php

use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\PaymentApprovalService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

function accUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->tx = app(Transactions::class);
});

it('persetujuan kedua dari data basi tidak membayar pemasok dua kali', function () {
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 20_000_000, 'bank');
    Product::create(['sku' => 'LCD13', 'name' => 'LCD iPhone 13', 'kind' => 'sparepart']);
    $this->tx->purchase($this->branch->id, '2026-11-05', 'LCD13', 10, 1_000_000, 'kredit'); // utang 10 juta
    $svc = app(PaymentApprovalService::class);
    $req = $svc->request(accUser('finance_admin', $this->branch), $this->branch->id, 2_000_000, 'bank');
    $stale = PaymentRequest::find($req->id);
    $svc->approve($req, accUser('approver', $this->branch), '2026-11-08');

    expect(fn () => $svc->approve($stale, accUser('approver', $this->branch), '2026-11-08'))->toThrow(DomainException::class, 'sudah diproses');
    expect(Journal::where('description', 'Pembayaran utang usaha')->count())->toBe(1);
});

it('menolak pengajuan yang sudah disetujui dari data basi tidak mengubah statusnya', function () {
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 20_000_000, 'bank');
    Product::create(['sku' => 'LCD13', 'name' => 'LCD iPhone 13', 'kind' => 'sparepart']);
    $this->tx->purchase($this->branch->id, '2026-11-05', 'LCD13', 10, 1_000_000, 'kredit');
    $svc = app(PaymentApprovalService::class);
    $req = $svc->request(accUser('finance_admin', $this->branch), $this->branch->id, 2_000_000, 'bank');
    $stale = PaymentRequest::find($req->id);
    $svc->approve($req, accUser('approver', $this->branch), '2026-11-08');

    expect(fn () => $svc->reject($stale, accUser('approver', $this->branch), 'salah'))->toThrow(DomainException::class, 'sudah diproses');
    expect($req->fresh()->status)->toBe('approved');
});

it('satu jurnal hanya bisa punya satu pembalik, juga di tingkat database', function () {
    $ledger = app(LedgerService::class);
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'bank');
    $j = Journal::first();
    $ledger->reverse($j, '2026-11-02');

    expect(fn () => $ledger->reverse($j, '2026-11-02'))->toThrow(\App\Domain\Accounting\Exceptions\InvalidJournalException::class, 'sudah pernah dibatalkan');
    expect(fn () => Journal::create(['branch_id' => $this->branch->id, 'entry_date' => '2026-11-02', 'source' => 'reversal', 'reversal_of' => $j->id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('bayar utang pemasok dan beli tunai ditolak kalau saldo tidak cukup', function () {
    Product::create(['sku' => 'LCD13', 'name' => 'LCD iPhone 13', 'kind' => 'sparepart']);
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 1_000_000, 'bank');

    expect(fn () => $this->tx->purchase($this->branch->id, '2026-11-02', 'LCD13', 5, 1_000_000, 'bank'))->toThrow(DomainException::class, 'Saldo');
    $this->tx->purchase($this->branch->id, '2026-11-02', 'LCD13', 5, 1_000_000, 'kredit');
    expect(fn () => $this->tx->payPayable($this->branch->id, '2026-11-03', 3_000_000, 'bank'))->toThrow(DomainException::class, 'Saldo');
    $this->tx->payPayable($this->branch->id, '2026-11-03', 1_000_000, 'bank');
});

it('DP servis batal tidak bisa dikembalikan kalau saldo kas tidak cukup', function () {
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 1_000_000, 'kas');
    $this->tx->receiveDeposit($this->branch->id, '2026-11-02', 500_000, 'kas', 'SRV-1');
    $this->tx->transferCash($this->branch->id, '2026-11-03', 1_400_000, 'kas', 'bank');

    expect(fn () => $this->tx->cancelService($this->branch->id, '2026-11-04', 500_000, 0, 'kas', 'SRV-1'))->toThrow(DomainException::class, 'Saldo');
    $this->tx->cancelService($this->branch->id, '2026-11-04', 500_000, 400_000, 'kas', 'SRV-1'); // refund 100 ribu cukup
});

it('nomor struk dan nomor servis urut per cabang per hari', function () {
    $cashier = accUser('cashier', $this->branch);
    Product::create(['sku' => 'CS1', 'name' => 'Casing', 'kind' => 'barang', 'sale_price' => 50_000]);
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'bank');
    $this->tx->purchase($this->branch->id, '2026-11-01', 'CS1', 10, 10_000, 'bank');
    $pos = app(\App\Domain\Pos\PosService::class);
    $id = Product::where('sku', 'CS1')->value('id');

    $a = $pos->checkout($cashier, $this->branch->id, '2026-11-05', [['product_id' => $id, 'qty' => 1]], 'kas');
    $b = $pos->checkout($cashier, $this->branch->id, '2026-11-05', [['product_id' => $id, 'qty' => 1]], 'kas');
    expect($a->receipt_no)->toEndWith('-0001')->and($b->receipt_no)->toEndWith('-0002');
});

function voidSetup(): array
{
    $t = test();
    $cashier = accUser('cashier', $t->branch);
    $owner = accUser('owner', $t->branch);
    Product::create(['sku' => 'CS1', 'name' => 'Casing', 'kind' => 'barang', 'sale_price' => 50_000]);
    $t->tx->depositCapital($t->branch->id, '2026-11-01', 5_000_000, 'bank');
    $t->tx->purchase($t->branch->id, '2026-11-01', 'CS1', 10, 10_000, 'bank');
    $id = Product::where('sku', 'CS1')->value('id');
    $sale = app(\App\Domain\Pos\PosService::class)->checkout($cashier, $t->branch->id, '2026-11-05', [['product_id' => $id, 'qty' => 3]], 'kas');

    return [$cashier, $owner, $sale, $id];
}

it('retur: batalkan struk mengembalikan stok dan uang, dan hanya sekali', function () {
    [$cashier, $owner, $sale, $id] = voidSetup();
    $stokSebelum = app(\App\Domain\Inventory\InventoryService::class)->balance($this->branch->id, $id);
    expect($stokSebelum->qty)->toBe(7);
    $kasSebelum = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '1-1100')->selectRaw('sum(l.debit)-sum(l.credit) as n')->value('n');

    $void = app(\App\Domain\Pos\PosService::class)->void($owner, $sale->receipt_no, '2026-11-05', 'pelanggan batal');
    $stok = app(\App\Domain\Inventory\InventoryService::class)->balance($this->branch->id, $id);
    $kas = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '1-1100')->selectRaw('sum(l.debit)-sum(l.credit) as n')->value('n');

    expect($void->voided_at)->not->toBeNull()->and($void->void_reason)->toBe('pelanggan batal');
    expect($stok->qty)->toBe(10)->and($stok->value)->toBe(100_000);
    expect((int) $kas)->toBe((int) $kasSebelum - 150_000);
    expect(app(\App\Domain\Accounting\OpsReportService::class)->dailySales($this->branch->id, '2026-11-01', '2026-11-30'))->toBe([]);
    expect(fn () => app(\App\Domain\Pos\PosService::class)->void($owner, $sale->receipt_no, '2026-11-05', 'lagi'))->toThrow(DomainException::class, 'sudah dibatalkan');
});

it('retur: kasir tidak boleh membatalkan struk, alasan wajib, nomor harus ada', function () {
    [$cashier, $owner, $sale] = voidSetup();
    $svc = app(\App\Domain\Pos\PosService::class);

    expect(fn () => $svc->void($cashier, $sale->receipt_no, '2026-11-05', 'x'))->toThrow(DomainException::class, 'tidak berwenang');
    expect(fn () => $svc->void($owner, $sale->receipt_no, '2026-11-05', '  '))->toThrow(DomainException::class, 'Alasan');
    expect(fn () => $svc->void($owner, 'POS-NOPE-1', '2026-11-05', 'x'))->toThrow(DomainException::class, 'tidak ditemukan');
    expect($sale->fresh()->voided_at)->toBeNull();
});

it('laporan refund dan komplain menghitung struk dibatalkan dan tampil di halaman laporan', function () {
    [$cashier, $owner, $sale] = voidSetup();
    app(\App\Domain\Pos\PosService::class)->void($owner, $sale->receipt_no, '2026-11-05', 'pelanggan batal');
    $today = now()->toDateString();

    $r = app(\App\Domain\Accounting\OpsReportService::class)->problemsReport($this->branch->id, $today, $today);
    expect($r['void_count'])->toBe(1)->and($r['void_total'])->toBe(150_000)->and($r['voids'][0]['reason'])->toBe('pelanggan batal')
        ->and($r['cancels'])->toBe([])->and($r['warranty'])->toBe([]);

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Laporan::class)
        ->call('setReport', 'masalah')->assertSuccessful()->assertSee('Refund dan komplain')->assertSee($sale->receipt_no);
});
