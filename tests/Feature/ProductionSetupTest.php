<?php

use App\Models\Account;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProductionSeeder;

it('ProductionSeeder membuat bagan akun, peran, dan satu pemilik tanpa data demo, dan aman diulang', function () {
    putenv('KASIR_OWNER_EMAIL=pemilik@klien.test');
    putenv('KASIR_OWNER_PASSWORD=sandi-panjang-123');
    $_ENV['KASIR_OWNER_EMAIL'] = 'pemilik@klien.test';
    $_ENV['KASIR_OWNER_PASSWORD'] = 'sandi-panjang-123';

    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    expect(Account::count())->toBeGreaterThan(10)
        ->and(User::count())->toBe(1)
        ->and(User::first()->hasRole('owner'))->toBeTrue()
        ->and(Branch::count())->toBe(0);
});

it('ProductionSeeder menolak berjalan tanpa email pemilik atau dengan sandi pendek', function () {
    putenv('KASIR_OWNER_EMAIL=a@b.test');
    putenv('KASIR_OWNER_PASSWORD=pendek');
    $_ENV['KASIR_OWNER_EMAIL'] = 'a@b.test';
    $_ENV['KASIR_OWNER_PASSWORD'] = 'pendek';

    expect(fn () => $this->seed(ProductionSeeder::class))->toThrow(RuntimeException::class, 'minimal 12');
    expect(User::count())->toBe(0);
});

it('DemoSeeder ditolak di produksi', function () {
    $original = app()->environment();
    app()->instance('env', 'production');

    try {
        expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'produksi');
        expect(Branch::count())->toBe(0);
    } finally {
        app()->instance('env', $original);
    }
});
