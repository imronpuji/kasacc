<?php

use App\Domain\Loyalty\LoyaltyService;
use App\Domain\Pos\PosService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyPoint;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function loyaltyUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

function accountBalance(string $code): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)
        ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->tx = app(Transactions::class);
    $this->loyalty = app(LoyaltyService::class);
    $this->cashier = loyaltyUser('cashier', $this->branch);
    $this->owner = loyaltyUser('owner', $this->branch);
    $this->today = now()->toDateString();
    Product::create(['sku' => 'CS1', 'name' => 'Casing', 'kind' => 'barang', 'sale_price' => 50_000]);
    $this->tx->depositCapital($this->branch->id, $this->today, 5_000_000, 'kas');
    $this->tx->purchase($this->branch->id, $this->today, 'CS1', 100, 10_000, 'kas');
    $this->productId = Product::where('sku', 'CS1')->value('id');
    $this->customer = Customer::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $this->sell = fn (int $qty, ?string $contact = 'budi@example.com', int $points = 0, string $method = 'kas') => app(PosService::class)->checkout(
        $this->cashier, $this->branch->id, $this->today, [['product_id' => $this->productId, 'qty' => $qty]], $method, $contact, $points,
    );
});

it('gives 1 point per Rp10.000 paid, rounded down, only to identified customers', function () {
    $sale = ($this->sell)(5); // Rp250.000 -> 25 points
    ($this->sell)(1, null);  // anonymous sale: no points anywhere

    expect($this->loyalty->balance($this->customer))->toBe(25);
    expect(LoyaltyPoint::count())->toBe(1);
    expect(LoyaltyPoint::first()->source_id)->toBe($sale->id);
    expect($this->loyalty->pointsFor(19_999))->toBe(1)->and($this->loyalty->pointsFor(9_999))->toBe(0);
});

it('redeems points as a discount: cash is reduced, revenue stays full, discount is a promotion expense', function () {
    $this->loyalty->adjust($this->customer, 100, 'bonus awal', $this->owner);
    $cashBefore = accountBalance('1-1100');
    $revenueBefore = accountBalance('4-1100');

    $sale = ($this->sell)(4, 'budi@example.com', 40); // Rp200.000, 40 points = Rp4.000

    expect($sale->discount)->toBe(4_000)->and($sale->points_redeemed)->toBe(40)->and((int) $sale->total)->toBe(200_000);
    expect(accountBalance('1-1100') - $cashBefore)->toBe(196_000);
    expect(accountBalance('6-7000'))->toBe(4_000);
    expect($revenueBefore - accountBalance('4-1100'))->toBe(200_000); // credit side grew by the full price
    // 100 - 40 redeemed + floor(196.000 / 10.000) = 19 earned
    expect($this->loyalty->balance($this->customer))->toBe(79);
});

it('rejects redeeming more than the balance, more than the cap, or without a customer', function () {
    $this->loyalty->adjust($this->customer, 30, 'bonus', $this->owner);

    expect(fn () => ($this->sell)(4, 'budi@example.com', 31))->toThrow(DomainException::class, 'tidak cukup');
    expect(fn () => ($this->sell)(4, null, 5))->toThrow(DomainException::class, 'nomor HP pelanggan');
    expect(fn () => ($this->sell)(4, 'budi@example.com', -5))->toThrow(DomainException::class, 'minus');

    config(['kasir.loyalty.max_redeem_percent' => 1]); // cap Rp2.000 on Rp200.000
    expect(fn () => ($this->sell)(4, 'budi@example.com', 30))->toThrow(DomainException::class, 'paling banyak Rp2.000');
    expect(PosSale::count())->toBe(0);
    expect($this->loyalty->balance($this->customer))->toBe(30);
});

it('voiding a receipt takes the earned points back and returns the redeemed points, and refunds only what was paid', function () {
    $this->loyalty->adjust($this->customer, 100, 'bonus', $this->owner);
    $cashBefore = accountBalance('1-1100');
    $sale = ($this->sell)(4, 'budi@example.com', 40);

    app(PosService::class)->void($this->owner, $sale->receipt_no, $this->today, 'pelanggan batal');

    expect($this->loyalty->balance($this->customer))->toBe(100);
    expect(accountBalance('1-1100'))->toBe($cashBefore);
    expect(accountBalance('6-7000'))->toBe(0);
});

it('cannot record the same transaction twice in the points book', function () {
    $sale = ($this->sell)(5);

    expect(fn () => $this->loyalty->record($this->customer, 'pos_sale', $sale->id, 0, 250_000))->toThrow(\Illuminate\Database\QueryException::class);
});

it('lets only the owner correct points and never below zero', function () {
    expect(fn () => $this->loyalty->adjust($this->customer, 10, 'x', $this->cashier))->toThrow(DomainException::class, 'pemilik');
    expect(fn () => $this->loyalty->adjust($this->customer, 10, '  ', $this->owner))->toThrow(DomainException::class, 'alasan');
    expect(fn () => $this->loyalty->adjust($this->customer, -1, 'koreksi', $this->owner))->toThrow(DomainException::class, 'minus');

    $this->loyalty->adjust($this->customer, 10, 'hadiah', $this->owner);
    $this->loyalty->adjust($this->customer, -4, 'salah catat', $this->owner);
    expect($this->loyalty->balance($this->customer))->toBe(6);
});

it('uses points when a service is completed and gives points on what was actually paid, deposit included', function () {
    Product::create(['sku' => 'LCD', 'name' => 'LCD', 'kind' => 'sparepart']);
    $this->tx->purchase($this->branch->id, $this->today, 'LCD', 3, 100_000, 'kas');
    $this->loyalty->adjust($this->customer, 100, 'bonus', $this->owner);
    $svc = app(ServiceTicketService::class);
    $ticket = $svc->receive($this->owner, $this->branch->id, $this->today, [
        'customer_name' => 'Budi', 'customer_email' => 'budi@example.com', 'device' => 'iPhone 12', 'complaint' => 'layar',
    ], 100_000, 'kas');
    $cashBefore = accountBalance('1-1100');

    // total 600.000 (parts 500.000 + labor 100.000), deposit 100.000, 50 points = Rp5.000 off
    $done = $svc->complete($ticket, $this->owner, $this->today, [['sku' => 'LCD', 'qty' => 1, 'price' => 500_000]], 100_000, 'kas', 50);

    expect($done->discount)->toBe(5_000)->and($done->points_redeemed)->toBe(50);
    expect(accountBalance('1-1100') - $cashBefore)->toBe(600_000 - 100_000 - 5_000);
    // 100 - 50 + floor(595.000 / 10.000) = 59
    expect($this->loyalty->balance($this->customer))->toBe(109);
});

it('shows points in the cashier, customer list and customer portal', function () {
    $this->loyalty->adjust($this->customer, 25, 'bonus', $this->owner);

    Livewire\Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)
        ->set('customerContact', 'budi@example.com')->assertSee('25 poin');

    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\Pelanggan::class)
        ->assertSee('Budi')->callTableAction('koreksiPoin', $this->customer, data: ['points' => 5, 'reason' => 'hadiah'])->assertHasNoTableActionErrors();
    expect($this->loyalty->balance($this->customer))->toBe(30);

    $this->actingAs($this->customer, 'customer')->get(route('pelanggan.beranda'))->assertOk()->assertSee('30 poin');
});

it('lets the cashier page pay with points and clamps to the allowed maximum', function () {
    $this->loyalty->adjust($this->customer, 100_000, 'banyak', $this->owner);

    $page = Livewire\Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)
        ->set('branchId', $this->branch->id)->call('add', $this->productId)->call('changeQty', $this->productId, 3) // 4 x 50.000
        ->set('customerContact', 'budi@example.com')->set('redeemPoints', '999999');
    $page->call('checkout');

    $sale = PosSale::firstOrFail();
    expect($sale->discount)->toBe(100_000); // capped at 50% of Rp200.000
    expect($sale->points_redeemed)->toBe(1_000);
});
