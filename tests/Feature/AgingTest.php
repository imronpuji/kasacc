<?php

use App\Domain\Accounting\OpsReportService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

it('umur utang: pembayaran melunasi pembelian kredit tertua lebih dulu (FIFO)', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $b = Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    Product::create(['sku' => 'LCD', 'name' => 'LCD', 'kind' => 'sparepart']);
    $tx = app(Transactions::class);
    $tx->depositCapital($b->id, '2026-07-01', 50_000_000, 'kas');
    $tx->purchase($b->id, '2026-08-01', 'LCD', 3, 1_000_000, 'kredit');
    $tx->purchase($b->id, '2026-09-15', 'LCD', 2, 1_000_000, 'kredit');
    $tx->payPayable($b->id, '2026-10-01', 4_000_000, 'kas');
    $tx->purchase($b->id, '2026-11-01', 'LCD', 1, 1_000_000, 'kredit');

    $ops = app(OpsReportService::class);
    $a = $ops->payablesAging($b->id, '2026-11-30');

    expect($a['total'])->toBe(2_000_000)->and($a['total'])->toBe($ops->payables($b->id, '2026-11-30'));
    expect($a['buckets'])->toBe(['0-30' => 1_000_000, '31-60' => 0, '61-90' => 1_000_000, '>90' => 0]);
    expect($a['rows'])->toHaveCount(2);
    expect([$a['rows'][0]['date'], $a['rows'][0]['open'], $a['rows'][0]['age_days']])->toBe(['2026-09-15', 1_000_000, 76]);
    expect([$a['rows'][1]['date'], $a['rows'][1]['open'], $a['rows'][1]['age_days']])->toBe(['2026-11-01', 1_000_000, 29]);

    // sebelum pembayaran 1 Okt, ketiga pembelian masih terbuka penuh dan pembelian 1 Agu berumur 60 hari
    $early = $ops->payablesAging($b->id, '2026-09-30');
    expect($early['total'])->toBe(5_000_000)->and($early['buckets']['31-60'])->toBe(3_000_000)->and($early['buckets']['0-30'])->toBe(2_000_000);
});
