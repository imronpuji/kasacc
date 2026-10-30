<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data awal produksi: bagan akun, peran dan satu akun pemilik. Aman dijalankan berulang.
 * Wajib mengisi KASIR_OWNER_EMAIL dan KASIR_OWNER_PASSWORD di environment (tidak disimpan di kode).
 * Cabang, produk, dan staf dibuat sendiri oleh pemilik lewat panel.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('KASIR_OWNER_EMAIL');
        $password = env('KASIR_OWNER_PASSWORD');
        if (! $email || ! $password || strlen($password) < 12) {
            throw new \RuntimeException('Isi KASIR_OWNER_EMAIL dan KASIR_OWNER_PASSWORD (minimal 12 karakter) sebelum menjalankan ProductionSeeder.');
        }

        $this->call([AccountSeeder::class, RoleSeeder::class, HolidaySeeder::class]);

        $owner = User::firstOrCreate(
            ['email' => $email],
            ['name' => env('KASIR_OWNER_NAME', 'Pemilik'), 'password' => bcrypt($password)],
        );
        $owner->syncRoles(['owner']);
    }
}
