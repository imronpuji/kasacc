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
    '/admin/branches', '/admin/payment-requests', '/admin/payment-requests/create',
]);

it('halaman panel menolak pengunjung yang belum login', function () {
    $this->get('/admin/laporan')->assertRedirect();
});
