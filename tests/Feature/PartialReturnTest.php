<?php

use App\Domain\Accounting\OpsReportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Loyalty\LoyaltyService;
use App\Domain\Pos\PosService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SupplierClaim;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function retUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

function retBalance(string $code): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)
        ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->tx = app(Transactions::class);
    $this->inv = app(InventoryService::class);
    $this->pos = app(PosService::class);
    $this->loyalty = app(LoyaltyService::class);
    $this->today = now()->toDateString();
    $this->owner = retUser('owner', $this->branch);
    $this->cashier = retUser('cashier', $this->branch);
    Product::create(['sku' => 'A', 'name' => 'Casing', 'kind' => 'barang', 'sale_price' => 50_000]);
    Product::create(['sku' => 'B', 'name' => 'Kabel', 'kind' => 'barang', 'sale_price' => 30_000]);
    $this->a = Product::where('sku', 'A')->value('id');
    $this->b = Product::where('sku', 'B')->value('id');
    $this->tx->depositCapital($this->branch->id, $this->today, 10_000_000, 'kas');
    $this->tx->purchase($this->branch->id, $this->today, 'A', 10, 10_000, 'kas');
    $this->tx->purchase($this->branch->id, $this->today, 'B', 10, 5_000, 'kas');
    $this->sell = fn (array $items, ?string $contact = null, int $points = 0, string $method = 'kas') => $this->pos->checkout(
        $this->cashier, $this->branch->id, $this->today, array_map(fn ($i) => ['product_id' => $i[0], 'qty' => $i[1]], $items), $method, $contact, $points);
    $this->ret = fn ($sale, array $items, string $reason = 'tidak cocok') => $this->pos->partialReturn($this->owner, $sale->receipt_no, $this->today, $reason, $items);
});

it('returns part of a receipt: refunds only those items, restocks them at cost and reduces revenue and COGS', function () {
    $sale = ($this->sell)([[$this->a, 3], [$this->b, 2]]); // 150.000 + 60.000 = 210.000
    $cashBefore = retBalance('1-1100');

    $r = ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1]]);

    expect($r->refund)->toBe(50_000)->and($r->gross)->toBe(50_000)->and($r->cost)->toBe(10_000);
    expect($cashBefore - retBalance('1-1100'))->toBe(50_000);
    expect($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(8)->and($this->inv->balance($this->branch->id, $this->a)->value)->toBe(80_000);
    expect(retBalance('4-1100'))->toBe(-(210_000 - 50_000))->and(retBalance('5-1000'))->toBe(3 * 10_000 + 2 * 5_000 - 10_000);
    expect(retBalance('1-1500'))->toBe(80_000 + 40_000); // A: 8 x 10.000, B: 8 x 5.000
});

it('cannot return more than was bought, across several returns, or items not on the receipt', function () {
    $sale = ($this->sell)([[$this->a, 3]]);
    ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 2]]);

    expect(fn () => ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 2]]))->toThrow(DomainException::class, 'melebihi');
    expect(fn () => ($this->ret)($sale, [['product_id' => $this->b, 'qty' => 1]]))->toThrow(DomainException::class, 'tidak ada di struk');
    expect(fn () => ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 0]]))->toThrow(DomainException::class, 'melebihi');
    expect(fn () => ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1], ['product_id' => $this->a, 'qty' => 1]]))->toThrow(DomainException::class, 'dua kali');
    expect(fn () => ($this->ret)($sale, []))->toThrow(DomainException::class, 'Pilih barang');
    ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1]]); // the last one is still allowed
    expect($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(10);
});

it('splits the points discount in proportion and takes back / returns the points', function () {
    $customer = Customer::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $this->loyalty->adjust($customer, 100, 'bonus', $this->owner);
    $sale = ($this->sell)([[$this->a, 4]], 'budi@example.com', 50); // 200.000 - 5.000 points discount = 195.000; earns 19
    expect($this->loyalty->balance($customer))->toBe(100 - 50 + 19);

    $r = ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 2]]); // half: 100.000 gross, 2.500 discount share

    expect($r->discount_share)->toBe(2_500)->and($r->refund)->toBe(97_500);
    expect(retBalance('6-7000'))->toBe(5_000 - 2_500);
    // earned 19 -> 9 taken back (19*97.500/195.000 = 9); redeemed 50 -> 25 given back
    expect($this->loyalty->balance($customer))->toBe(69 - 9 + 25);
});

it('lets several partial returns add up to exactly the whole sale, with no rounding leftovers', function () {
    $sale = ($this->sell)([[$this->a, 3]]); // 150.000, cost 30.000
    $total = 0;
    $cost = 0;
    foreach ([1, 1, 1] as $q) {
        $r = ($this->ret)($sale, [['product_id' => $this->a, 'qty' => $q]]);
        $total += $r->refund;
        $cost += $r->cost;
    }

    expect($total)->toBe(150_000)->and($cost)->toBe(30_000)->and(retBalance('4-1100'))->toBe(0)->and(retBalance('5-1000'))->toBe(0);
});

it('sends defective returned items to a supplier claim instead of sellable stock', function () {
    $sale = ($this->sell)([[$this->a, 3]]);

    ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1, 'defective' => true]]);

    expect($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(7); // 10 - 3, the defective one is not restocked
    $claim = SupplierClaim::firstOrFail();
    expect($claim->qty)->toBe(1)->and($claim->cost)->toBe(10_000)->and(retBalance('1-1550'))->toBe(10_000);
    expect(retBalance('1-1500'))->toBe($this->inv->balance($this->branch->id, $this->a)->value + 10 * 5_000);
});

it('only allows people with void permission, blocks a full void after a return and a return after a void', function () {
    $sale = ($this->sell)([[$this->a, 2]]);
    expect(fn () => $this->pos->partialReturn($this->cashier, $sale->receipt_no, $this->today, 'x', [['product_id' => $this->a, 'qty' => 1]]))->toThrow(DomainException::class, 'berwenang');
    expect(fn () => ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1]], '  '))->toThrow(DomainException::class, 'Alasan');
    expect(fn () => $this->pos->partialReturn($this->owner, 'POS-NOPE', $this->today, 'x', [['product_id' => $this->a, 'qty' => 1]]))->toThrow(DomainException::class, 'tidak ditemukan');

    ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1]]);
    expect(fn () => $this->pos->void($this->owner, $sale->receipt_no, $this->today, 'batal'))->toThrow(DomainException::class, 'retur sebagian');

    $other = ($this->sell)([[$this->b, 1]]);
    $this->pos->void($this->owner, $other->receipt_no, $this->today, 'batal');
    expect(fn () => ($this->ret)($other, [['product_id' => $this->b, 'qty' => 1]]))->toThrow(DomainException::class, 'sudah dibatalkan');
});

it('refuses a return when the drawer or bank cannot cover the refund', function () {
    $sale = ($this->sell)([[$this->a, 2]]);
    $this->tx->transferCash($this->branch->id, $this->today, retBalance('1-1100'), 'kas', 'bank');

    expect(fn () => ($this->ret)($sale, [['product_id' => $this->a, 'qty' => 1]]))->toThrow(DomainException::class, 'Saldo');
    expect($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(8);
});

it('shows partial returns in the problems report and on the receipt, and works through the cashier page', function () {
    $sale = ($this->sell)([[$this->a, 2]]);

    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\Pos::class)
        ->callAction('returSebagian', data: ['receipt_no' => $sale->receipt_no, 'reason' => 'tidak cocok', 'items' => [['product_id' => $this->a, 'qty' => 1, 'defective' => false]]]);

    $r = app(OpsReportService::class)->problemsReport($this->branch->id, $this->today, $this->today);
    expect($r['partial_returns'])->toBe(1)->and($r['partial_return_total'])->toBe(50_000);
    $this->actingAs($this->owner)->get(route('receipt', $sale->receipt_no))->assertOk()->assertSee('Uang dikembalikan')->assertSee('Retur');
});
