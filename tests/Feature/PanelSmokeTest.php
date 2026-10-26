<?php

use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

it('semua halaman panel Filament terbuka tanpa error untuk pemilik', function (string $path) {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)->get($path)->assertOk();
})->with([
    '/admin', '/admin/laporan', '/admin/journals', '/admin/accounts', '/admin/products',
    '/admin/branches', '/admin/payment-requests', '/admin/payment-requests/create', '/admin/kasir',
]);

it('halaman panel menolak pengunjung yang belum login', function () {
    $this->get('/admin/laporan')->assertRedirect();
});

it('kasir: halaman Kasir bisa checkout lewat Livewire dan struk dapat dibuka', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $branch = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $p = App\Models\Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    $tx = app(App\Domain\Transactions::class);
    $tx->depositCapital($branch->id, now()->toDateString(), 5_000_000, 'kas');
    $tx->purchase($branch->id, now()->toDateString(), 'CASE', 10, 50_000, 'kas');
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $cashier->branches()->attach($branch->id);

    Livewire\Livewire::actingAs($cashier)->test(App\Filament\Pages\Kasir::class)
        ->call('add', $p->id)->set('cart.'.$p->id.'.qty', 2)->set('method', 'qris')->call('checkout')
        ->assertSet('cart', [])->assertSet('lastReceipt', 'POS-PUSAT-'.now()->format('Ymd').'-0001');

    $this->actingAs($cashier)->get('/struk/POS-PUSAT-'.now()->format('Ymd').'-0001')->assertOk()->assertSee('Casing');
    $this->get('/admin/kasir')->assertOk();
});

it('kasir: peran tanpa izin post-sales tidak bisa membuka halaman Kasir', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $u = User::factory()->create();
    $u->assignRole('approver');
    $this->actingAs($u)->get('/admin/kasir')->assertForbidden();
});
