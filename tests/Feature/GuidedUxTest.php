<?php

use App\Domain\Purchasing\PurchaseInvoiceService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BranchField;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

function guidedUser(string $role, Branch ...$branches): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    foreach ($branches as $b) {
        $u->branches()->attach($b->id);
    }

    return $u;
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
});

it('offers only the allowed branches and treats a single choice as no question', function () {
    $second = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
    $one = guidedUser('cashier', $this->branch);
    $owner = guidedUser('owner');

    $this->actingAs($one);
    expect(array_keys(BranchField::options()))->toBe([$this->branch->id]);
    $this->actingAs($owner);
    expect(BranchField::options())->toHaveCount(2)->and(BranchField::options(true))->toHaveCount(2);
    $this->actingAs(guidedUser('finance_admin'));
    expect(BranchField::options())->toBe([])->and(BranchField::options(true))->toHaveCount(2);
});

it('receives a phone without asking the branch for a one-branch cashier, and fills a returning customer', function () {
    $cashier = guidedUser('cashier', $this->branch);
    $customer = Customer::create(['name' => 'Budi', 'phone' => '081234567890', 'email' => 'budi@example.com']);

    Livewire\Livewire::actingAs($cashier)->test(App\Filament\Pages\ServiceTickets::class)
        ->callTableAction('terima', data: ['existing_customer' => $customer->id, 'customer_name' => 'Budi', 'customer_phone' => '081234567890', 'customer_email' => 'budi@example.com', 'device' => 'iPhone 12', 'complaint' => 'layar pecah', 'deposit' => 0, 'method' => 'kas'])
        ->assertHasNoTableActionErrors()->assertNotified('HP sudah diterima');

    $ticket = App\Models\ServiceTicket::firstOrFail();
    expect($ticket->branch_id)->toBe($this->branch->id)->and($ticket->customer_id)->toBe($customer->id);
});

it('shows the help page and a help link on the main pages', function () {
    $owner = guidedUser('owner');

    $this->actingAs($owner)->get('/admin/bantuan')->assertOk()->assertSee('Menjual barang di Kasir')->assertSee('Mencatat faktur dari pemasok');
    $this->actingAs($owner)->get('/admin/pos')->assertOk()->assertSee('Lihat cara memakai halaman ini', false)->assertSee('bantuan#kasir', false);
    $this->actingAs($owner)->get('/admin/purchase-invoices')->assertOk()->assertSee('bantuan#faktur', false);
    foreach (App\Filament\Pages\Help::TOPIC_BY_PAGE as $page => $topic) {
        expect(collect(App\Filament\Pages\Help::topics())->pluck('id'))->toContain($topic);
    }
});

it('shows a start-here checklist to a new owner and removes it when everything is set up', function () {
    $owner = guidedUser('owner');

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Home::class)->assertSee('Mulai dari sini')->assertSee('Isi daftar barang dan harga jual');

    Product::create(['sku' => 'A1', 'name' => 'LCD', 'kind' => 'sparepart']);
    Supplier::create(['name' => 'Toko A']);
    App\Models\Employee::create(['branch_id' => $this->branch->id, 'name' => 'Andi', 'hired_on' => '2025-01-01', 'base_salary' => 1_000_000, 'ptkp' => 'TK/0']);
    app(Transactions::class)->depositCapital($this->branch->id, now()->toDateString(), 1_000_000, 'kas');

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Home::class)->assertDontSee('Mulai dari sini');
});

it('reminds about overdue supplier invoices and open claims on the home page', function () {
    $owner = guidedUser('owner');
    $supplier = Supplier::create(['name' => 'Toko A']);
    $product = Product::create(['sku' => 'A1', 'name' => 'LCD', 'kind' => 'sparepart']);
    app(Transactions::class)->depositCapital($this->branch->id, '2026-09-01', 5_000_000, 'bank');
    app(PurchaseInvoiceService::class)->record($owner, $this->branch->id, $supplier->id, 'INV-1', '2026-09-02', '2026-09-20', 'kredit', [['product_id' => $product->id, 'qty' => 1, 'unit_cost' => 500_000]]);

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Home::class)->assertSee('lewat jatuh tempo');
});
