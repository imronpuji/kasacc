<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['1-1100', 'Kas', 'asset'],
        ['1-1110', 'Bank', 'asset'],
        ['1-1500', 'Persediaan sparepart dan aksesoris', 'asset'],
        ['2-1100', 'Utang usaha', 'liability'],
        ['2-1400', 'Uang muka pelanggan (DP servis)', 'liability'],
        ['3-1000', 'Modal disetor', 'equity'],
        ['4-1000', 'Pendapatan jasa servis', 'revenue'],
        ['4-1100', 'Pendapatan penjualan sparepart dan aksesoris', 'revenue'],
        ['5-1000', 'Harga pokok penjualan (HPP)', 'cogs'],
        ['6-1000', 'Beban gaji', 'expense'],
        ['6-2000', 'Beban sewa', 'expense'],
        ['6-2100', 'Beban listrik dan internet', 'expense'],
        ['6-6000', 'Beban garansi', 'expense'],
        ['8-1000', 'Beban biaya pembayaran (MDR QRIS)', 'other_expense'],
        ['8-1100', 'Beban administrasi bank', 'other_expense'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type]) {
            Account::updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
        }
    }
}
