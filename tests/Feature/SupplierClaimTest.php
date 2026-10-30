<?php

use App\Domain\Accounting\OpsReportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\PosService;
use App\Domain\Purchasing\SupplierClaimService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function claimUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

function claimBalance(string $code): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)
        ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->tx = app(Transactions::class);
    $this->claims = app(SupplierClaimService::class);
    $this->inv = app(InventoryService::class);
    $this->owner = claimUser('owner', $this->branch);
    $this->cashier = claimUser('cashier', $this->branch);
    $this->today = now()->toDateString();
    $this->supplier = Supplier::create(['name' => 'Toko A']);
    Product::create(['sku' => 'LCD', 'name' => 'LCD', 'kind' => 'barang', 'sale_price' => 500_000]);
    $this->pid = Product::where('sku', 'LCD')->value('id');
    $this->tx->depositCapital($this->branch->id, $this->today, 20_000_000, 'bank');
    $this->tx->purchase($this->branch->id, $this->today, 'LCD', 10, 300_000, 'kredit', $this->supplier->id); // owes 3m
    $this->stock = fn () => $this->inv->balance($this->branch->id, $this->pid)->qty;
    $this->sellAndVoid = function (array $defective) {
        $sale = app(PosService::class)->checkout($this->cashier, $this->branch->id, $this->today, [['product_id' => $this->pid, 'qty' => 3]], 'kas');
        // cash for refund
        return [$sale, fn () => app(PosService::class)->void($this->owner, $sale->receipt_no, $this->today, 'barang rusak', $defective)];
    };
});

it('keeps defective returned goods out of sellable stock and moves their value to the claim account', function () {
    [$sale, $void] = ($this->sellAndVoid)([['product_id' => $this->pid, 'qty' => 1]]);
    $inventoryBefore = claimBalance('1-1500');
    expect(($this->stock)())->toBe(7);

    $void();

    expect(($this->stock)())->toBe(9); // 7 + 2 good ones, 1 defective stays out
    $claim = SupplierClaim::firstOrFail();
    expect($claim->qty)->toBe(1)->and($claim->cost)->toBe(300_000)->and($claim->status)->toBe('open')->and($claim->pos_sale_id)->toBe($sale->id);
    expect(claimBalance('1-1550'))->toBe(300_000);
    // ledger inventory equals stock value: 9 x 300.000
    expect(claimBalance('1-1500'))->toBe($this->inv->balance($this->branch->id, $this->pid)->value);
});

it('rejects marking more defective items than were sold on the receipt', function () {
    [, $void] = ($this->sellAndVoid)([['product_id' => $this->pid, 'qty' => 4]]);

    expect($void)->toThrow(DomainException::class, 'lebih banyak');
    expect(SupplierClaim::count())->toBe(0);
});

it('voids normally when nothing is defective', function () {
    [, $void] = ($this->sellAndVoid)([]);
    $void();

    expect(($this->stock)())->toBe(10)->and(SupplierClaim::count())->toBe(0)->and(claimBalance('1-1550'))->toBe(0);
});

it('settles a claim with a replacement: stock comes back at the same value', function () {
    [, $void] = ($this->sellAndVoid)([['product_id' => $this->pid, 'qty' => 1]]);
    $void();
    $claim = SupplierClaim::firstOrFail();
    $this->claims->assignSupplier($claim, $this->owner, $this->supplier->id);

    $this->claims->resolve($claim, $this->owner, $this->today, 'replaced');

    expect(($this->stock)())->toBe(10)->and(claimBalance('1-1550'))->toBe(0)->and(claimBalance('1-1500'))->toBe(3_000_000);
    expect($claim->fresh()->status)->toBe('replaced');
    expect(fn () => $this->claims->resolve($claim, $this->owner, $this->today, 'replaced'))->toThrow(DomainException::class, 'sudah diselesaikan');
});

it('settles a claim with a refund through supplier debt, and the shortfall is a loss', function () {
    $claim = $this->claims->fromStock($this->owner, $this->branch->id, $this->today, 'LCD', 2, 'layar bergaris', $this->supplier->id);
    expect(($this->stock)())->toBe(8)->and(claimBalance('1-1550'))->toBe(600_000);
    $debtBefore = app(OpsReportService::class)->payables($this->branch->id, $this->today, $this->supplier->id);

    $this->claims->resolve($claim, $this->owner, $this->today, 'refunded', 'utang', 500_000);

    expect(app(OpsReportService::class)->payables($this->branch->id, $this->today, $this->supplier->id))->toBe($debtBefore - 500_000);
    expect(claimBalance('6-7100'))->toBe(100_000)->and(claimBalance('1-1550'))->toBe(0);
    expect(($this->stock)())->toBe(8); // no replacement
});

it('settles a claim with a cash refund and a rejection becomes a full loss', function () {
    $a = $this->claims->fromStock($this->owner, $this->branch->id, $this->today, 'LCD', 1, 'rusak', $this->supplier->id);
    $b = $this->claims->fromStock($this->owner, $this->branch->id, $this->today, 'LCD', 1, 'rusak', $this->supplier->id);
    $cashBefore = claimBalance('1-1100');

    $this->claims->resolve($a, $this->owner, $this->today, 'refunded', 'kas', 300_000);
    $this->claims->resolve($b, $this->owner, $this->today, 'rejected');

    expect(claimBalance('1-1100') - $cashBefore)->toBe(300_000)->and(claimBalance('6-7100'))->toBe(300_000)->and(claimBalance('1-1550'))->toBe(0);
});

it('validates refund amount, debt limit and requires a supplier before resolving', function () {
    $noSupplier = $this->claims->fromStock($this->owner, $this->branch->id, $this->today, 'LCD', 1, 'rusak');
    expect(fn () => $this->claims->resolve($noSupplier, $this->owner, $this->today, 'rejected'))->toThrow(DomainException::class, 'Pilih pemasok');

    $c = $this->claims->fromStock($this->owner, $this->branch->id, $this->today, 'LCD', 1, 'rusak', $this->supplier->id);
    expect(fn () => $this->claims->resolve($c, $this->owner, $this->today, 'refunded', 'kas', 300_001))->toThrow(DomainException::class, 'lebih dari nilai barang');
    expect(fn () => $this->claims->resolve($c, $this->owner, $this->today, 'refunded', null, 100_000))->toThrow(DomainException::class, 'masuk ke mana');
    $this->tx->payPayable($this->branch->id, $this->today, 2_900_000, 'bank', $this->supplier->id); // only 100.000 owed now
    expect(fn () => $this->claims->resolve($c, $this->owner, $this->today, 'refunded', 'utang', 300_000))->toThrow(DomainException::class, 'melebihi utang');
    expect(fn () => $this->claims->resolve($c, $this->owner, $this->today, 'bogus'))->toThrow(DomainException::class, 'tidak dikenal');
    expect($c->fresh()->status)->toBe('open');
});

it('only people allowed to purchase can handle claims', function () {
    expect(fn () => $this->claims->fromStock($this->cashier, $this->branch->id, $this->today, 'LCD', 1, 'rusak'))->toThrow(DomainException::class, 'izin');
    expect(App\Filament\Pages\SupplierClaims::canAccess())->toBeFalse(); // guest
});

it('shows the claims page and resolves a claim through it', function () {
    $claim = $this->claims->fromStock($this->owner, $this->branch->id, $this->today, 'LCD', 1, 'layar bergaris', $this->supplier->id);

    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\SupplierClaims::class)
        ->assertSee('layar bergaris')->assertSee('Menunggu pemasok')
        ->callTableAction('selesai', $claim, data: ['outcome' => 'replaced'])->assertHasNoTableActionErrors();

    expect($claim->fresh()->status)->toBe('replaced');
});
