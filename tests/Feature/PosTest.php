<?php

use App\Domain\Inventory\InsufficientStockException;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\PosService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->other = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
    $this->case = Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cashier');
    $this->cashier->branches()->attach($this->branch->id);

    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'kas');
    $tx->purchase($this->branch->id, '2026-11-02', 'CASE', 20, 50_000, 'kas');
    $this->pos = app(PosService::class);
});

it('checkout tunai: jurnal pendapatan + HPP, stok turun, struk tercatat (T14b)', function () {
    $sale = $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 3]], 'kas');

    expect($sale->receipt_no)->toBe('POS-PUSAT-20261116-0001');
    expect($sale->total)->toBe(360_000)->and($sale->mdr)->toBe(0);
    expect($sale->items)->toHaveCount(1)->and($sale->items[0]->unit_price)->toBe(120_000)->and($sale->items[0]->line_total)->toBe(360_000);

    $lines = Journal::find($sale->journal_id)->lines()->with('account')->get()
        ->mapWithKeys(fn ($l) => [($l->debit ? 'Dr ' : 'Cr ').$l->account->code => (int) ($l->debit ?: $l->credit)])->all();
    expect($lines)->toBe(['Dr 1-1100' => 360_000, 'Cr 4-1100' => 360_000, 'Dr 5-1000' => 150_000, 'Cr 1-1500' => 150_000]);

    $stock = app(InventoryService::class)->balance($this->branch->id, $this->case->id);
    expect([$stock->qty, $stock->value])->toBe([17, 850_000]);
});

it('nomor struk berurutan per cabang per hari', function () {
    $a = $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas');
    $b = $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas');
    $c = $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-17', [['product_id' => $this->case->id, 'qty' => 1]], 'kas');

    expect([$a->receipt_no, $b->receipt_no, $c->receipt_no])->toBe(['POS-PUSAT-20261116-0001', 'POS-PUSAT-20261116-0002', 'POS-PUSAT-20261117-0001']);
});

it('QRIS: bank menerima nilai bersih dan biaya MDR 0,7% tercatat', function () {
    $sale = $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 5, 'price' => 200_000]], 'qris');

    expect($sale->total)->toBe(1_000_000)->and($sale->mdr)->toBe(7_000);
    $lines = Journal::find($sale->journal_id)->lines()->with('account')->get()
        ->mapWithKeys(fn ($l) => [($l->debit ? 'Dr ' : 'Cr ').$l->account->code => (int) ($l->debit ?: $l->credit)])->all();
    expect($lines['Dr 1-1110'])->toBe(993_000)->and($lines['Dr 8-1000'])->toBe(7_000);
});

it('stok kurang: seluruh checkout dibatalkan tanpa struk, jurnal, atau perubahan stok', function () {
    $journals = Journal::count();

    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 21]], 'kas'))
        ->toThrow(InsufficientStockException::class);

    expect(PosSale::count())->toBe(0)->and(Journal::count())->toBe($journals);
    expect(app(InventoryService::class)->balance($this->branch->id, $this->case->id)->qty)->toBe(20);
});

it('menolak keranjang kosong, jumlah tidak valid, dan metode bayar asing', function () {
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [], 'kas'))->toThrow(DomainException::class, 'kosong');
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 0]], 'kas'))->toThrow(DomainException::class, 'tidak valid');
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kredit'))->toThrow(DomainException::class, 'Metode');
    expect(PosSale::count())->toBe(0);
});

it('hanya kasir berwenang dan hanya di cabang yang ia punya aksesnya', function () {
    $tech = User::factory()->create();
    $tech->assignRole('technician');
    $tech->branches()->attach($this->branch->id);

    expect(fn () => $this->pos->checkout($tech, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas'))
        ->toThrow(DomainException::class, 'tidak berwenang');
    expect(fn () => $this->pos->checkout($this->cashier, $this->other->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas'))
        ->toThrow(DomainException::class, 'akses');
    expect(PosSale::count())->toBe(0);
});

it('periode yang dikunci menolak penjualan dan stok tidak berubah', function () {
    app(App\Domain\Accounting\PeriodService::class)->lock(2026, 11);

    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas'))
        ->toThrow(App\Domain\Accounting\Exceptions\PeriodLockedException::class);
    expect(PosSale::count())->toBe(0);
    expect(app(InventoryService::class)->balance($this->branch->id, $this->case->id)->qty)->toBe(20);
});
