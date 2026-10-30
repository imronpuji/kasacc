<?php

use App\Domain\Accounting\OpsReportService;
use App\Domain\Purchasing\PurchaseInvoiceService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function invoiceUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

function invoiceBalance(string $code): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)
        ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->owner = invoiceUser('owner', $this->branch);
    $this->svc = app(PurchaseInvoiceService::class);
    $this->ops = app(OpsReportService::class);
    $this->supplier = Supplier::create(['name' => 'Toko A']);
    $this->a = Product::create(['sku' => 'A1', 'name' => 'LCD', 'kind' => 'sparepart']);
    $this->b = Product::create(['sku' => 'B1', 'name' => 'Baterai', 'kind' => 'sparepart']);
    app(Transactions::class)->depositCapital($this->branch->id, '2026-11-01', 50_000_000, 'bank');
    $this->lines = [['product_id' => $this->a->id, 'qty' => 2, 'unit_cost' => 1_000_000], ['product_id' => $this->b->id, 'qty' => 4, 'unit_cost' => 250_000]];
});

it('records a multi-item credit invoice as one payable with stock received', function () {
    $inv = $this->svc->record($this->owner, $this->branch->id, $this->supplier->id, 'INV-001', '2026-11-02', '2026-12-02', 'kredit', $this->lines);

    expect($inv->total)->toBe(3_000_000)->and($inv->lines)->toHaveCount(2);
    expect(invoiceBalance('1-1500'))->toBe(3_000_000);
    expect($this->ops->payables($this->branch->id, '2026-11-30', $this->supplier->id))->toBe(3_000_000);
    expect(DB::table('journals')->where('id', $inv->journal_id)->value('supplier_id'))->toBe($this->supplier->id);
});

it('shows invoice number and due date in aging and flags overdue amounts', function () {
    $this->svc->record($this->owner, $this->branch->id, $this->supplier->id, 'INV-001', '2026-11-02', '2026-11-10', 'kredit', $this->lines);

    $aging = $this->ops->payablesAging($this->branch->id, '2026-11-20');
    expect($aging['rows'][0]['invoice_no'])->toBe('INV-001')->and($aging['rows'][0]['due_date'])->toBe('2026-11-10')->and($aging['rows'][0]['overdue_days'])->toBe(10);
    expect($aging['overdue'])->toBe(3_000_000)->and($aging['due_soon'])->toBe(0);

    $soon = $this->ops->payablesAging($this->branch->id, '2026-11-05');
    expect($soon['overdue'])->toBe(0)->and($soon['due_soon'])->toBe(3_000_000);
});

it('pays immediately without creating payable or due date', function () {
    $inv = $this->svc->record($this->owner, $this->branch->id, $this->supplier->id, 'INV-002', '2026-11-02', null, 'bank', $this->lines);

    expect($inv->due_date)->toBeNull();
    expect($this->ops->payables($this->branch->id, '2026-11-30'))->toBe(0);
    expect(invoiceBalance('1-1110'))->toBe(50_000_000 - 3_000_000);
});

it('rejects duplicate invoice numbers per supplier but allows the same number for another supplier', function () {
    $this->svc->record($this->owner, $this->branch->id, $this->supplier->id, 'INV-001', '2026-11-02', '2026-12-02', 'kredit', $this->lines);

    expect(fn () => $this->svc->record($this->owner, $this->branch->id, $this->supplier->id, 'INV-001', '2026-11-03', '2026-12-03', 'kredit', $this->lines))
        ->toThrow(DomainException::class, 'sudah tercatat');
    $other = Supplier::create(['name' => 'Toko B']);
    $this->svc->record($this->owner, $this->branch->id, $other->id, 'INV-001', '2026-11-03', '2026-12-03', 'kredit', $this->lines);
    expect(PurchaseInvoice::count())->toBe(2);
});

it('validates the input and leaves no trace when it fails', function () {
    $id = $this->branch->id;
    $s = $this->supplier->id;
    expect(fn () => $this->svc->record($this->owner, $id, $s, ' ', '2026-11-02', '2026-12-02', 'kredit', $this->lines))->toThrow(DomainException::class, 'Nomor faktur');
    expect(fn () => $this->svc->record($this->owner, $id, $s, 'X', '2026-11-02', null, 'kredit', $this->lines))->toThrow(DomainException::class, 'jatuh tempo');
    expect(fn () => $this->svc->record($this->owner, $id, $s, 'X', '2026-11-02', '2026-11-01', 'kredit', $this->lines))->toThrow(DomainException::class, 'sebelum tanggal faktur');
    expect(fn () => $this->svc->record($this->owner, $id, $s, 'X', '2026-11-02', '2026-12-02', 'kredit', []))->toThrow(DomainException::class, 'minimal satu');
    expect(fn () => $this->svc->record($this->owner, $id, $s, 'X', '2026-11-02', '2026-12-02', 'kredit', [['product_id' => $this->a->id, 'qty' => 0, 'unit_cost' => 5]]))->toThrow(DomainException::class, 'lebih dari nol');
    expect(fn () => $this->svc->record($this->owner, $id, 999_999, 'X', '2026-11-02', '2026-12-02', 'kredit', $this->lines))->toThrow(DomainException::class, 'Pemasok tidak ditemukan');
    expect(fn () => $this->svc->record($this->owner, $id, $s, 'X', '2026-11-02', null, 'bank', [['product_id' => $this->a->id, 'qty' => 1, 'unit_cost' => 999_000_000]]))->toThrow(DomainException::class);
    expect(PurchaseInvoice::count())->toBe(0)->and(invoiceBalance('1-1500'))->toBe(0);
});

it('blocks users without purchase permission and shows the page for the owner', function () {
    $cashier = invoiceUser('cashier', $this->branch);
    expect(fn () => $this->svc->record($cashier, $this->branch->id, $this->supplier->id, 'X', '2026-11-02', '2026-12-02', 'kredit', $this->lines))->toThrow(DomainException::class, 'izin');

    $this->svc->record($this->owner, $this->branch->id, $this->supplier->id, 'INV-009', now()->toDateString(), now()->addDays(30)->toDateString(), 'kredit', $this->lines);
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\PurchaseInvoices::class)->assertSuccessful()->assertSee('INV-009')->assertSee('Toko A');
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\Reports::class)->call('setReport', 'utang')->assertSuccessful()->assertSee('INV-009');
});
