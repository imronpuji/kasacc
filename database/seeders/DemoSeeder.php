<?php

namespace Database\Seeders;

use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Data contoh: menjalankan skenario November 2026 (T1 sampai T19) agar laporan bisa langsung dilihat. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AccountSeeder::class, RoleSeeder::class]);

        $pusat = Branch::firstOrCreate(['code' => 'PUSAT'], ['name' => 'Cabang Pusat']);
        $bandung = Branch::firstOrCreate(['code' => 'BDG'], ['name' => 'Cabang Bandung']);

        foreach ([['LCD13', 'LCD iPhone 13', 'sparepart', 1_800_000], ['BAT12', 'Baterai iPhone 12', 'sparepart', 700_000], ['CASE', 'Casing', 'aksesoris', 120_000]] as [$sku, $name, $kind, $price]) {
            Product::firstOrCreate(['sku' => $sku], ['name' => $name, 'kind' => $kind, 'sale_price' => $price]);
        }

        foreach ([['owner', 'Pemilik', 'owner@kasir.test'], ['approver', 'Penyetuju', 'approver@kasir.test'], ['finance_admin', 'Admin Finance', 'finance@kasir.test'], ['cashier', 'Kasir', 'kasir@kasir.test']] as [$role, $name, $email]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => bcrypt('password')]);
            $user->syncRoles([$role]);
            $user->branches()->syncWithoutDetaching([$pusat->id, $bandung->id]);
        }

        if (\App\Models\Journal::exists()) {
            return;
        }

        $b = $pusat->id;
        $tx = app(Transactions::class);
        $tx->depositCapital($b, '2026-11-01', 50_000_000);
        $tx->transferCash($b, '2026-11-02', 5_000_000, 'bank', 'kas');
        $tx->purchase($b, '2026-11-03', 'LCD13', 10, 1_000_000, 'bank');
        $tx->purchase($b, '2026-11-05', 'LCD13', 10, 1_100_000, 'kredit');
        $tx->purchase($b, '2026-11-06', 'BAT12', 5, 400_000, 'kas');
        $tx->returnToSupplier($b, '2026-11-07', 'BAT12', 1, 'kas');
        $tx->payPayable($b, '2026-11-08', 6_000_000, 'bank');
        $tx->receiveDeposit($b, '2026-11-09', 200_000, 'kas', 'A');
        $tx->completeService($b, '2026-11-10', [['sku' => 'LCD13', 'qty' => 1, 'price' => 2_000_000]], 300_000, 200_000, 'kas', 0, 'A');
        $tx->receiveDeposit($b, '2026-11-11', 100_000, 'kas', 'B');
        $tx->cancelService($b, '2026-11-12', 100_000, 50_000, 'kas', 'B');
        $tx->completeService($b, '2026-11-13', [['sku' => 'LCD13', 'qty' => 1, 'price' => 2_000_000]], 300_000, 0, 'qris', 70, 'C');
        $tx->warrantyClaim($b, '2026-11-15', 'LCD13', 1, 'A');
        $tx->purchase($b, '2026-11-16', 'CASE', 20, 50_000, 'kas');
        $tx->posSale($b, '2026-11-16', [['sku' => 'CASE', 'qty' => 3, 'price' => 120_000]], 'kas');
        $tx->payExpense($b, '2026-11-20', '6-2000', 3_000_000, 'bank', 'Sewa November');
        $tx->payExpense($b, '2026-11-25', '6-1000', 4_000_000, 'bank', 'Gaji teknisi');
        $tx->payExpense($b, '2026-11-26', '6-2100', 500_000, 'kas', 'Listrik dan internet');
        $tx->payExpense($b, '2026-11-30', '8-1100', 15_000, 'bank', 'Biaya administrasi bank');
    }
}
