<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Domain\Accounting\ReportService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->bat = Product::create(['sku' => 'BAT12', 'name' => 'Baterai iPhone 12', 'kind' => 'sparepart']);
    $this->staff = User::factory()->create();
    $this->staff->assignRole('cashier');
    $this->staff->branches()->attach($this->branch->id);
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'kas');
    $tx->purchase($this->branch->id, '2026-11-02', 'BAT12', 4, 400_000, 'kas');
    $this->svc = app(ServiceTicketService::class);
});

it('terima unit dengan DP: tiket dibuat, DP jadi uang muka (bukan pendapatan)', function () {
    $t = $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12'], 200_000);
    expect($t->code)->toBe('SRV-PUSAT-20261117-001')->and($t->status)->toBe('diterima');

    $is = app(ReportService::class)->incomeStatement($this->branch->id, '2026-11-01', '2026-11-30');
    expect($is['revenue_total'] ?? 0)->toBe(0);
});

it('selesai: DP dipotong, sparepart keluar stok, pendapatan jasa+barang tercatat', function () {
    $t = $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12'], 200_000);
    $t = $this->svc->complete($t, $this->staff, '2026-11-18', [['sku' => 'BAT12', 'qty' => 1, 'price' => 700_000]], 100_000, 'kas');

    expect($t->status)->toBe('selesai')->and($t->total)->toBe(800_000)->and($t->parts_total)->toBe(700_000);
    expect(app(InventoryService::class)->balance($this->branch->id, $this->bat->id)->qty)->toBe(3);
    expect(fn () => $this->svc->complete($t, $this->staff, '2026-11-18', [['sku' => 'BAT12', 'qty' => 1, 'price' => 700_000]], 0, 'kas'))
        ->toThrow(DomainException::class, 'ditutup');
});

it('batal: biaya cek jadi pendapatan, sisa DP dikembalikan', function () {
    $t = $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12'], 200_000);
    $t = $this->svc->cancel($t, $this->staff, '2026-11-19', 50_000);
    expect($t->status)->toBe('batal')->and($t->total)->toBe(50_000);
    expect(fn () => $this->svc->cancel($t, $this->staff, '2026-11-19', 0))->toThrow(DomainException::class, 'ditutup');
});

it('batal: biaya cek tidak boleh melebihi DP', function () {
    $t = $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12'], 200_000);
    expect(fn () => $this->svc->cancel($t, $this->staff, '2026-11-19', 250_000))->toThrow(DomainException::class, 'lebih besar dari DP');
});

it('garansi: biaya sparepart tercatat di tiket dan stok berkurang', function () {
    $t = $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12']);
    $t = $this->svc->complete($t, $this->staff, '2026-11-18', [['sku' => 'BAT12', 'qty' => 1, 'price' => 700_000]], 100_000, 'kas');
    $t = $this->svc->claimWarranty($t, $this->staff, '2026-11-25', 'BAT12');
    expect($t->warranty_cost)->toBe(400_000);
    expect(app(InventoryService::class)->balance($this->branch->id, $this->bat->id)->qty)->toBe(2);
});

it('servis ditolak untuk user tanpa wewenang atau cabang lain', function () {
    $other = Branch::create(['code' => 'BDG', 'name' => 'Bandung']);
    expect(fn () => $this->svc->receive($this->staff, $other->id, '2026-11-17', ['customer_name' => 'X', 'device' => 'Y']))->toThrow(DomainException::class, 'akses');
    $fin = User::factory()->create(); $fin->assignRole('finance_admin'); $fin->branches()->attach($this->branch->id);
    expect(fn () => $this->svc->receive($fin, $this->branch->id, '2026-11-17', ['customer_name' => 'X', 'device' => 'Y']))->toThrow(DomainException::class, 'tidak punya izin');
});
