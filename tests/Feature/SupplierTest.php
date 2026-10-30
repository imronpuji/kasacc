<?php

use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\OpsReportService;
use App\Domain\Accounting\PaymentApprovalService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

function supplierUser(string $role, Branch $branch): User
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
    $this->ops = app(OpsReportService::class);
    $this->today = now()->toDateString();
    Product::create(['sku' => 'LCD13', 'name' => 'LCD iPhone 13', 'kind' => 'sparepart']);
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 50_000_000, 'bank');
    $this->a = Supplier::create(['name' => 'Toko A']);
    $this->b = Supplier::create(['name' => 'Toko B']);
});

it('tracks payables per supplier from purchases, payments and returns', function () {
    $id = $this->branch->id;
    $this->tx->purchase($id, '2026-11-02', 'LCD13', 10, 1_000_000, 'kredit', $this->a->id); // A owes 10m
    $this->tx->purchase($id, '2026-11-03', 'LCD13', 5, 1_000_000, 'kredit', $this->b->id);  // B owes 5m
    $this->tx->purchase($id, '2026-11-04', 'LCD13', 2, 1_000_000, 'kredit');                // legacy, no supplier
    $this->tx->payPayable($id, '2026-11-05', 4_000_000, 'bank', $this->a->id);
    $this->tx->returnToSupplier($id, '2026-11-06', 'LCD13', 1, 'utang', $this->b->id);      // -1m at avg cost

    $by = collect($this->ops->payablesBySupplier($id, '2026-11-30'))->pluck('owed', 'name');

    expect($by['Toko A'])->toBe(6_000_000)->and($by['Toko B'])->toBe(4_000_000)->and($by['Tanpa pemasok (data lama)'])->toBe(2_000_000);
    expect($this->ops->payables($id, '2026-11-30'))->toBe(12_000_000)
        ->and($this->ops->payables($id, '2026-11-30', $this->a->id))->toBe(6_000_000);
});

it('hides suppliers that owe nothing and keeps the total equal to the ledger balance', function () {
    $id = $this->branch->id;
    $this->tx->purchase($id, '2026-11-02', 'LCD13', 3, 1_000_000, 'kredit', $this->a->id);
    $this->tx->payPayable($id, '2026-11-03', 3_000_000, 'bank', $this->a->id);

    expect($this->ops->payablesBySupplier($id, '2026-11-30'))->toBe([]);
    expect($this->ops->payables($id, '2026-11-30'))->toBe(0);
});

it('rejects returning goods against supplier debt larger than what is owed to that supplier', function () {
    $id = $this->branch->id;
    $this->tx->purchase($id, '2026-11-02', 'LCD13', 5, 1_000_000, 'bank');                   // paid in cash, no debt
    $this->tx->purchase($id, '2026-11-02', 'LCD13', 1, 1_000_000, 'kredit', $this->a->id);   // A owes 1m

    expect(fn () => $this->tx->returnToSupplier($id, '2026-11-03', 'LCD13', 3, 'utang', $this->a->id))
        ->toThrow(DomainException::class, 'lebih besar dari utang');
    expect($this->ops->payables($id, '2026-11-30', $this->a->id))->toBe(1_000_000);
});

it('limits a payment request to what is owed to the chosen supplier and pays that supplier', function () {
    $id = $this->branch->id;
    $this->tx->purchase($id, '2026-11-02', 'LCD13', 10, 1_000_000, 'kredit', $this->a->id);
    $this->tx->purchase($id, '2026-11-02', 'LCD13', 1, 1_000_000, 'kredit', $this->b->id);
    $svc = app(PaymentApprovalService::class);
    $maker = supplierUser('finance_admin', $this->branch);

    expect(fn () => $svc->request($maker, $id, 2_000_000, 'bank', 'x', $this->b->id))->toThrow(DomainException::class, 'pemasok ini');
    expect(fn () => $svc->request($maker, $id, 1_000, 'bank', 'x', 999_999))->toThrow(DomainException::class, 'Pemasok tidak ditemukan');

    $req = $svc->request($maker, $id, 3_000_000, 'bank', 'x', $this->a->id);
    $svc->approve($req, supplierUser('approver', $this->branch), '2026-11-08');

    expect(Journal::where('description', 'Pembayaran utang usaha')->value('supplier_id'))->toBe($this->a->id);
    expect($this->ops->payables($id, '2026-11-30', $this->a->id))->toBe(7_000_000)
        ->and($this->ops->payables($id, '2026-11-30', $this->b->id))->toBe(1_000_000);
});

it('keeps the supplier on reversal journals so the supplier balance returns to its old value', function () {
    $id = $this->branch->id;
    $j = $this->tx->purchase($id, '2026-11-02', 'LCD13', 2, 1_000_000, 'kredit', $this->a->id);
    app(LedgerService::class)->reverse($j, '2026-11-03');

    expect($this->ops->payables($id, '2026-11-30', $this->a->id))->toBe(0);
});

it('does not allow deleting a supplier that has journals', function () {
    $this->tx->purchase($this->branch->id, '2026-11-02', 'LCD13', 1, 1_000_000, 'kredit', $this->a->id);

    expect(fn () => $this->a->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('shows suppliers page and the per-supplier table in the payables report', function () {
    $owner = supplierUser('owner', $this->branch);
    $this->tx->purchase($this->branch->id, $this->today, 'LCD13', 2, 1_000_000, 'kredit', $this->a->id);

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Suppliers::class)
        ->assertSuccessful()->assertSee('Toko A')->assertSee('Rp2.000.000');
    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Reports::class)
        ->call('setReport', 'utang')->assertSuccessful()->assertSee('Utang per pemasok')->assertSee('Toko A');
});

it('lets a cashier without purchase permission stay out of the suppliers page', function () {
    $cashier = supplierUser('cashier', $this->branch);

    expect(App\Filament\Pages\Suppliers::canAccess())->toBeFalse(); // guest
    $this->actingAs($cashier);
    expect(App\Filament\Pages\Suppliers::canAccess())->toBe($cashier->can('post-purchases'));
});
