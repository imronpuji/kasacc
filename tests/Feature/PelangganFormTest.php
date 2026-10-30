<?php

use App\Filament\Pages\Customers;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('form tambah pelanggan memeriksa email dan HP ganda tanpa error', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $b = Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $u = User::factory()->create();
    $u->assignRole('owner');
    $u->branches()->attach($b->id);
    Customer::create(['name' => 'Lama', 'email' => 'lama@x.com', 'phone' => '081234567890']);

    Livewire::actingAs($u)->test(Customers::class)
        ->callAction(TestAction::make('tambah')->table(), ['name' => 'Baru', 'email' => 'lama@x.com', 'phone' => ''])->assertHasFormErrors(['email']);

    Livewire::actingAs($u)->test(Customers::class)
        ->callAction(TestAction::make('tambah')->table(), ['name' => 'Baru', 'email' => 'baru@x.com', 'phone' => '081234567890'])->assertHasFormErrors(['phone']);

    Livewire::actingAs($u)->test(Customers::class)
        ->callAction(TestAction::make('tambah')->table(), ['name' => 'Baru', 'email' => 'Baru@X.com', 'phone' => '0899999999'])->assertHasNoFormErrors();
    expect(Customer::where('email', 'baru@x.com')->exists())->toBeTrue();
});
