<?php

use App\Domain\Accounting\PaymentApprovalService;
use App\Domain\Accounting\ReportService;
use App\Domain\Inventory\InsufficientStockException;
use App\Domain\Inventory\InventoryService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->a = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat'])->id;
    $this->c = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung'])->id;
    Product::create(['sku' => 'LCD13', 'name' => 'LCD iPhone 13', 'kind' => 'sparepart']);
    $this->tx = app(Transactions::class);
    $this->reports = app(ReportService::class);
    $this->inv = app(InventoryService::class);
    $this->tx->depositCapital($this->a, '2026-11-01', 50_000_000);
    $this->tx->purchase($this->a, '2026-11-03', 'LCD13', 10, 1_000_000, 'bank');
});

it('transfer stok antar cabang memindahkan nilai dan saling meniadakan saat konsolidasi', function () {
    [$out, $in] = $this->tx->transferStock($this->a, $this->c, '2026-11-04', 'LCD13', 4);

    $pid = Product::where('sku', 'LCD13')->value('id');
    $stockA = $this->inv->balance($this->a, $pid);
    $stockB = $this->inv->balance($this->c, $pid);
    expect([$stockA->qty, $stockA->value])->toBe([6, 6_000_000]);
    expect([$stockB->qty, $stockB->value])->toBe([4, 4_000_000]);

    $bsA = $this->reports->balanceSheet($this->a, '2026-11-30');
    $bsB = $this->reports->balanceSheet($this->c, '2026-11-30');
    $all = $this->reports->balanceSheet(null, '2026-11-30');

    expect($bsA['asset_rows']['1-1500'])->toBe(6_000_000)->and($bsA['asset_rows']['1-1600'])->toBe(4_000_000);
    expect($bsB['asset_rows']['1-1500'])->toBe(4_000_000)->and($bsB['asset_rows']['1-1600'])->toBe(-4_000_000);
    expect($all['asset_rows']['1-1500'])->toBe(10_000_000)->and($all['asset_rows']['1-1600'])->toBe(0);
    expect($all['assets'])->toBe(50_000_000);
});

it('transfer yang melebihi stok ditolak dan tidak mengubah stok maupun jurnal kedua cabang', function () {
    $pid = Product::where('sku', 'LCD13')->value('id');

    expect(fn () => $this->tx->transferStock($this->a, $this->c, '2026-11-04', 'LCD13', 11))->toThrow(InsufficientStockException::class);

    expect($this->inv->balance($this->a, $pid)->qty)->toBe(10);
    expect($this->inv->balance($this->c, $pid)->qty)->toBe(0);
    expect($this->reports->balanceSheet($this->c, '2026-11-30')['assets'])->toBe(0);
});

it('laporan per cabang terpisah dan konsolidasi menjumlahkan semuanya', function () {
    $this->tx->transferStock($this->a, $this->c, '2026-11-04', 'LCD13', 5);
    $this->tx->completeService($this->a, '2026-11-10', [['sku' => 'LCD13', 'qty' => 1, 'price' => 2_000_000]], 300_000, 0, 'kas');
    $this->tx->completeService($this->c, '2026-11-12', [['sku' => 'LCD13', 'qty' => 2, 'price' => 2_000_000]], 400_000, 0, 'bank');

    $a = $this->reports->incomeStatement($this->a, '2026-11-01', '2026-11-30');
    $b = $this->reports->incomeStatement($this->c, '2026-11-01', '2026-11-30');
    $all = $this->reports->incomeStatement(null, '2026-11-01', '2026-11-30');

    expect($a['revenue'])->toBe(2_300_000)->and($a['cogs'])->toBe(1_000_000);
    expect($b['revenue'])->toBe(4_400_000)->and($b['cogs'])->toBe(2_000_000);
    expect($all['revenue'])->toBe(6_700_000)->and($all['cogs'])->toBe(3_000_000)->and($all['net_income'])->toBe(3_700_000);

    foreach ([$this->a, $this->c, null] as $branch) {
        $tb = $this->reports->trialBalance($branch, '2026-11-30');
        expect($tb['total_debit'])->toBe($tb['total_credit']);
    }
});

it('pengguna hanya bisa mengajukan pembayaran di cabang yang ia punya aksesnya', function () {
    $user = User::factory()->create();
    $user->assignRole('finance_admin');
    $user->branches()->attach($this->a);
    $approvals = app(PaymentApprovalService::class);

    expect($approvals->request($user, $this->a, 100_000, 'bank'))->not->toBeNull();
    expect(fn () => $approvals->request($user, $this->c, 100_000, 'bank'))->toThrow(DomainException::class);
});
