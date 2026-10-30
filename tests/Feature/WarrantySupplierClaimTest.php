<?php

use App\Domain\Purchasing\SupplierClaimService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function warrantyUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

function warrantyBalance(string $code): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)
        ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->owner = warrantyUser('owner', $this->branch);
    $this->cashier = warrantyUser('cashier', $this->branch);
    $this->svc = app(ServiceTicketService::class);
    $this->claims = app(SupplierClaimService::class);
    $this->supplier = Supplier::create(['name' => 'Toko A']);
    Product::create(['sku' => 'BAT12', 'name' => 'Baterai iPhone 12', 'kind' => 'sparepart']);
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'kas');
    $tx->purchase($this->branch->id, '2026-11-02', 'BAT12', 4, 400_000, 'kas');
    $t = $this->svc->receive($this->owner, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12']);
    $this->ticket = $this->svc->complete($t, $this->owner, '2026-11-18', [['sku' => 'BAT12', 'qty' => 1, 'price' => 700_000]], 100_000, 'kas');
});

it('moves a warranty cost to the supplier claim account and links it to the ticket and complaint', function () {
    $complaint = Complaint::create(['branch_id' => $this->branch->id, 'service_ticket_id' => $this->ticket->id, 'type' => 'garansi', 'description' => 'Baterai cepat habis', 'received_on' => '2026-11-25', 'status' => 'open']);
    $this->svc->claimWarranty($this->ticket, $this->owner, '2026-11-25', 'BAT12', 1, ['reason' => 'baterai cepat habis', 'supplier_id' => $this->supplier->id]);

    $claim = SupplierClaim::firstOrFail();
    expect($claim->cost)->toBe(400_000)->and($claim->service_ticket_id)->toBe($this->ticket->id)->and($claim->complaint_id)->toBe($complaint->id)->and($claim->status)->toBe('open');
    expect(warrantyBalance('1-1550'))->toBe(400_000)->and(warrantyBalance('6-6000'))->toBe(0); // warranty expense recovered into the claim asset
});

it('books a refund against the claim asset and a rejection as a loss', function () {
    $this->svc->claimWarranty($this->ticket, $this->owner, '2026-11-25', 'BAT12', 1, ['reason' => 'rusak', 'supplier_id' => $this->supplier->id]);
    $claim = SupplierClaim::firstOrFail();

    $this->claims->resolve($claim, $this->owner, '2026-11-30', 'refunded', 'kas', 300_000);

    expect(warrantyBalance('1-1550'))->toBe(0)->and(warrantyBalance('6-7100'))->toBe(100_000);
});

it('keeps a plain warranty claim unchanged when it is not billed to the supplier', function () {
    $t = $this->svc->claimWarranty($this->ticket, $this->owner, '2026-11-25', 'BAT12');

    expect($t->warranty_cost)->toBe(400_000)->and(SupplierClaim::count())->toBe(0)->and(warrantyBalance('6-6000'))->toBe(400_000);
});

it('bills one warranty claim to the supplier only once and only with permission', function () {
    $this->svc->claimWarranty($this->ticket, $this->owner, '2026-11-25', 'BAT12', 1, ['reason' => 'rusak']);
    $warrantyId = (int) DB::table('warranty_claims')->value('id');

    expect(fn () => $this->claims->fromWarranty($this->owner, $warrantyId, 'rusak'))->toThrow(DomainException::class, 'sudah ditagihkan');
    expect(fn () => $this->claims->fromWarranty($this->cashier, $warrantyId, 'rusak'))->toThrow(DomainException::class);
    expect(fn () => $this->claims->fromWarranty($this->owner, 999_999, 'rusak'))->toThrow(DomainException::class, 'tidak ditemukan');
    expect(SupplierClaim::count())->toBe(1);
});

it('rolls back the whole warranty claim when the supplier claim is invalid', function () {
    expect(fn () => $this->svc->claimWarranty($this->ticket, $this->owner, '2026-11-25', 'BAT12', 1, ['reason' => '  ']))->toThrow(DomainException::class, 'Alasan');

    expect(DB::table('warranty_claims')->count())->toBe(0)->and((int) $this->ticket->fresh()->warranty_cost)->toBe(0)->and(warrantyBalance('6-6000'))->toBe(0);
});

it('lets the owner bill the warranty to a supplier from the service page and see it on the claims page', function () {
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\ServiceTickets::class)
        ->callTableAction('garansi', $this->ticket, data: ['sku' => 'BAT12', 'qty' => 1, 'to_supplier' => true, 'claim_reason' => 'layar bergaris', 'claim_supplier' => $this->supplier->id])
        ->assertHasNoTableActionErrors();

    expect(SupplierClaim::firstOrFail()->supplier_id)->toBe($this->supplier->id);
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\SupplierClaims::class)->assertSee('Garansi '.$this->ticket->code);
});
