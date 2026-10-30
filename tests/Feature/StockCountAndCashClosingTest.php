<?php

use App\Domain\Accounting\CashClosingService;
use App\Domain\Accounting\OpsReportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Pos\PosService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\CashClosing;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function adjUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

function adjBalance(string $code): int
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
    $this->counts = app(StockCountService::class);
    $this->closings = app(CashClosingService::class);
    $this->today = now()->toDateString();
    $this->owner = adjUser('owner', $this->branch);
    $this->finance = adjUser('finance_admin', $this->branch);
    $this->approver = adjUser('approver', $this->branch);
    $this->cashier = adjUser('cashier', $this->branch);
    Product::create(['sku' => 'A', 'name' => 'Casing', 'kind' => 'barang', 'sale_price' => 50_000]);
    Product::create(['sku' => 'B', 'name' => 'Kabel', 'kind' => 'barang', 'sale_price' => 30_000]);
    $this->a = Product::where('sku', 'A')->value('id');
    $this->b = Product::where('sku', 'B')->value('id');
    $this->tx->depositCapital($this->branch->id, $this->today, 10_000_000, 'kas');
    $this->tx->purchase($this->branch->id, $this->today, 'A', 10, 10_000, 'kas');
    $this->tx->purchase($this->branch->id, $this->today, 'B', 5, 20_000, 'kas');
    $this->line = fn ($count, $pid) => $count->lines()->where('product_id', $pid)->first();
});

it('starts a count with a snapshot of system stock and allows only one open count per branch', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);

    expect($c->lines)->toHaveCount(2)->and(($this->line)($c, $this->a)->system_qty)->toBe(10);
    expect(fn () => $this->counts->start($this->finance, $this->branch->id, $this->today))->toThrow(DomainException::class, 'belum selesai');
    expect(fn () => $this->counts->start($this->cashier, $this->branch->id, $this->today))->toThrow(DomainException::class, 'izin');
});

it('posts shortages as a loss and surpluses as a gain, and ledger inventory still equals stock value', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $this->counts->setCounted(($this->line)($c, $this->a), 8);  // 2 missing at 10.000 = -20.000
    $this->counts->setCounted(($this->line)($c, $this->b), 6);  // 1 extra at avg 20.000 = +20.000... net 0

    $posted = $this->counts->post($c, $this->owner, $this->today);

    expect($posted->status)->toBe('posted')->and($posted->diff_value)->toBe(0)->and($posted->journal_id)->toBeNull();
    expect($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(8)->and($this->inv->balance($this->branch->id, $this->b)->qty)->toBe(6);
    $stockValue = $this->inv->balance($this->branch->id, $this->a)->value + $this->inv->balance($this->branch->id, $this->b)->value;
    expect(adjBalance('1-1500'))->toBe($stockValue);
});

it('books a net shortage on the stock difference account', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $this->counts->setCounted(($this->line)($c, $this->a), 7); // -3 x 10.000

    $posted = $this->counts->post($c, $this->approver, $this->today);

    expect($posted->diff_value)->toBe(-30_000)->and(adjBalance('6-7200'))->toBe(30_000);
    expect(adjBalance('1-1500'))->toBe($this->inv->balance($this->branch->id, $this->a)->value + $this->inv->balance($this->branch->id, $this->b)->value);
});

it('compares each count with system stock at the moment it was entered, so sales before or after do not look like losses', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $sell = fn (int $qty) => app(PosService::class)->checkout($this->cashier, $this->branch->id, $this->today, [['product_id' => $this->a, 'qty' => $qty]], 'kas');

    $sell(2); // before counting: system 8
    $this->counts->setCounted(($this->line)($c, $this->a), 8); // shelf really has 8
    $sell(1); // after counting: system 7
    $posted = $this->counts->post($c, $this->owner, $this->today);

    expect($posted->diff_value)->toBe(0)->and($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(7);
});

it('refuses a shortage larger than current stock with a clear message', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $this->counts->setCounted(($this->line)($c, $this->a), 5);  // -5 against system 10
    app(PosService::class)->checkout($this->cashier, $this->branch->id, $this->today, [['product_id' => $this->a, 'qty' => 9]], 'kas'); // 1 left

    expect(fn () => $this->counts->post($c, $this->owner, $this->today))->toThrow(DomainException::class, 'Hitung ulang');
    expect($c->fresh()->status)->toBe('draft')->and($this->inv->balance($this->branch->id, $this->a)->qty)->toBe(1);
});

it('needs approval from someone else (the owner may approve their own count) and cannot be posted twice', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $this->counts->setCounted(($this->line)($c, $this->a), 9);

    expect(fn () => $this->counts->post($c, $this->finance, $this->today))->toThrow(DomainException::class, 'berwenang');
    expect(fn () => $this->counts->post($c, $this->cashier, $this->today))->toThrow(DomainException::class, 'berwenang');
    $this->counts->post($c, $this->approver, $this->today);
    expect(fn () => $this->counts->post($c, $this->owner, $this->today))->toThrow(DomainException::class, 'sudah diproses');

    $ownCount = $this->counts->start($this->owner, $this->branch->id, $this->today);
    $this->counts->setCounted(($this->line)($ownCount, $this->a), 8);
    expect($this->counts->post($ownCount, $this->owner, $this->today)->status)->toBe('posted');
});

it('rejects empty counts, negative counts and editing a finished count', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    expect(fn () => $this->counts->post($c, $this->owner, $this->today))->toThrow(DomainException::class, 'Belum ada barang');
    expect(fn () => $this->counts->setCounted(($this->line)($c, $this->a), -1))->toThrow(DomainException::class, 'minus');
    $this->counts->cancel($c, $this->finance);
    expect(fn () => $this->counts->setCounted(($this->line)($c, $this->a)->load('stockCount'), 3))->toThrow(DomainException::class, 'sudah selesai');
});

it('closes the cash drawer when it matches and needs a reason and approval when it does not', function () {
    $expected = $this->closings->expected($this->branch->id, $this->today);

    $ok = $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected);
    expect($ok->status)->toBe('closed')->and($ok->diff)->toBe(0)->and($ok->journal_id)->toBeNull();
    expect(fn () => $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected))->toThrow(DomainException::class, 'sudah ditutup');
});

it('books a cash shortage only after approval, and the approver cannot be the cashier who closed', function () {
    $expected = $this->closings->expected($this->branch->id, $this->today);
    expect(fn () => $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected - 25_000))->toThrow(DomainException::class, 'alasan');

    $c = $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected - 25_000, 'kembalian salah');
    expect($c->status)->toBe('pending')->and($c->diff)->toBe(-25_000)->and(adjBalance('6-7300'))->toBe(0);
    expect(fn () => $this->closings->approve($c, $this->cashier, $this->today))->toThrow(DomainException::class, 'berwenang');

    $this->closings->approve($c, $this->approver, $this->today);
    expect($c->fresh()->status)->toBe('closed')->and(adjBalance('6-7300'))->toBe(25_000);
    expect($this->closings->expected($this->branch->id, $this->today))->toBe($expected - 25_000); // book now matches drawer
    expect(fn () => $this->closings->approve($c, $this->owner, $this->today))->toThrow(DomainException::class, 'sudah diproses');
});

it('books a cash surplus, and a rejected closing can be redone for the same day', function () {
    $expected = $this->closings->expected($this->branch->id, $this->today);
    $c = $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected + 10_000, 'ada uang tip');
    $this->closings->reject($c, $this->owner, 'hitung ulang');
    expect($c->fresh()->status)->toBe('rejected')->and(adjBalance('6-7300'))->toBe(0);

    $again = $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected + 10_000, 'ada uang tip');
    $this->closings->approve($again, $this->owner, $this->today);

    expect(adjBalance('6-7300'))->toBe(-10_000)->and(CashClosing::where('status', 'closed')->count())->toBe(1);
});

it('does not allow closing a future day or a negative amount', function () {
    expect(fn () => $this->closings->close($this->cashier, $this->branch->id, now()->addDay()->toDateString(), 1_000))->toThrow(DomainException::class, 'belum terjadi');
    expect(fn () => $this->closings->close($this->cashier, $this->branch->id, $this->today, -1))->toThrow(DomainException::class, 'minus');
});

it('shows both differences in the problems report and the pages load for the right roles', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $this->counts->setCounted(($this->line)($c, $this->a), 9);
    $this->counts->post($c, $this->owner, $this->today);
    $expected = $this->closings->expected($this->branch->id, $this->today);
    $cl = $this->closings->close($this->cashier, $this->branch->id, $this->today, $expected - 5_000, 'salah kembalian');
    $this->closings->approve($cl, $this->owner, $this->today);

    $r = app(OpsReportService::class)->problemsReport($this->branch->id, $this->today, $this->today);
    expect($r['stock_diff'])->toBe(-10_000)->and($r['cash_diff'])->toBe(-5_000);

    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\CashClosings::class)->assertSuccessful()->assertSee('salah kembalian');
    Livewire\Livewire::actingAs($this->finance)->test(App\Filament\Pages\StockCounts::class)->assertSuccessful();
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\Reports::class)->call('setReport', 'masalah')->assertSee('Selisih stok hasil opname');
    expect(StockCount::count())->toBe(1);
});

it('lets a finance admin enter counts through the page and the owner approve', function () {
    $c = $this->counts->start($this->finance, $this->branch->id, $this->today);
    $line = ($this->line)($c, $this->a);

    Livewire\Livewire::actingAs($this->finance)->test(App\Filament\Pages\StockCounts::class)
        ->assertSee('Casing')->assertTableActionHidden('setujui');
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\StockCounts::class)
        ->assertTableActionVisible('setujui');
});
