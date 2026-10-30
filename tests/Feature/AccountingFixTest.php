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
