<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['1-1100', 'Kas', 'asset'],
        ['1-1110', 'Bank', 'asset'],
        ['1-1300', 'Piutang karyawan (kasbon)', 'asset'],
        ['1-2000', 'Tanah dan bangunan', 'asset'],
        ['1-2100', 'Kendaraan', 'asset'],
        ['1-2200', 'Peralatan dan mesin', 'asset'],
        ['1-2900', 'Akumulasi penyusutan (pengurang nilai harta)', 'asset'],
        ['1-1500', 'Persediaan sparepart dan aksesoris', 'asset'],
        ['1-1600', 'Rekening antar cabang', 'asset'],
        ['1-1550', 'Barang klaim di pemasok', 'asset'],
        ['2-1100', 'Utang usaha', 'liability'],
        ['2-1200', 'Utang gaji', 'liability'],
        ['2-1300', 'Utang BPJS', 'liability'],
        ['2-1400', 'Uang muka pelanggan (DP servis)', 'liability'],
        ['2-1500', 'Utang PPh 21', 'liability'],
        ['2-1600', 'Utang pajak penghasilan usaha', 'liability'],
        ['2-1700', 'Utang pinjaman', 'liability'],
        ['3-1000', 'Modal disetor', 'equity'],
        ['3-2000', 'Uang pemilik yang diambil (prive)', 'equity'],
        ['4-1000', 'Pendapatan jasa servis', 'revenue'],
        ['4-1100', 'Pendapatan penjualan sparepart dan aksesoris', 'revenue'],
        ['4-2000', 'Pendapatan lain-lain (bunga, dll.)', 'revenue'],
        ['5-1000', 'Harga pokok penjualan (HPP)', 'cogs'],
        ['6-1000', 'Beban gaji', 'expense'],
        ['6-1100', 'Beban BPJS pemberi kerja', 'expense'],
        ['6-2000', 'Beban sewa', 'expense'],
        ['6-2100', 'Beban listrik dan internet', 'expense'],
        ['6-3000', 'Beban penyusutan', 'expense'],
        ['6-6000', 'Beban garansi', 'expense'],
        ['6-7000', 'Beban poin member (potongan harga)', 'expense'],
        ['6-7100', 'Kerugian klaim pemasok', 'expense'],
        ['8-1000', 'Beban biaya pembayaran (MDR QRIS)', 'other_expense'],
        ['8-1100', 'Beban administrasi bank', 'other_expense'],
        ['8-1300', 'Beban bunga pinjaman', 'other_expense'],
        ['8-1200', 'Beban pajak penghasilan usaha', 'other_expense'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type]) {
            Account::updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
        }
    }
}
