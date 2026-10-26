<?php

use App\Domain\Accounting\OpsReportService;
use App\Domain\Pos\PosService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

it('penjualan harian dan laporan teknisi memakai data dari modul kasir dan servis', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $b = Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $case = Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    Product::create(['sku' => 'BAT12', 'name' => 'Baterai', 'kind' => 'sparepart']);
    $tx = app(Transactions::class);
    $tx->depositCapital($b->id, '2026-11-01', 10_000_000, 'kas');
    $tx->purchase($b->id, '2026-11-02', 'CASE', 10, 50_000, 'kas');
    $tx->purchase($b->id, '2026-11-02', 'BAT12', 4, 400_000, 'kas');

    $cashier = User::factory()->create(); $cashier->assignRole('cashier'); $cashier->branches()->attach($b->id);
    $andi = User::factory()->create(['name' => 'Andi']); $andi->assignRole('technician'); $andi->branches()->attach($b->id);
    $budi = User::factory()->create(['name' => 'Budi']); $budi->assignRole('technician'); $budi->branches()->attach($b->id);

    $pos = app(PosService::class);
    $pos->checkout($cashier, $b->id, '2026-11-16', [['product_id' => $case->id, 'qty' => 3]], 'kas');
    $pos->checkout($cashier, $b->id, '2026-11-16', [['product_id' => $case->id, 'qty' => 2, 'price' => 200_000]], 'qris');

    $svc = app(ServiceTicketService::class);
    foreach ([[$andi, 100_000], [$andi, 150_000], [$budi, 80_000]] as $i => [$tech, $labor]) {
        $t = $svc->receive($cashier, $b->id, '2026-11-17', ['customer_name' => "P$i", 'device' => 'iPhone', 'technician_id' => $tech->id]);
        $svc->complete($t, $cashier, '2026-11-18', [], $labor, 'kas');
    }

    $ops = app(OpsReportService::class);
    expect($ops->dailySales($b->id, '2026-11-01', '2026-11-30'))->toBe([
        ['date' => '2026-11-16', 'method' => 'kas', 'receipts' => 1, 'total' => 360_000, 'mdr' => 0],
        ['date' => '2026-11-16', 'method' => 'qris', 'receipts' => 1, 'total' => 400_000, 'mdr' => 2_800],
    ]);
    expect($ops->technicianReport($b->id, '2026-11-01', '2026-11-30'))->toBe([
        ['technician' => 'Andi', 'tickets' => 2, 'labor' => 250_000, 'parts' => 0, 'total' => 250_000],
        ['technician' => 'Budi', 'tickets' => 1, 'labor' => 80_000, 'parts' => 0, 'total' => 80_000],
    ]);
});
