<?php

namespace App\Domain\Payroll;

use Illuminate\Support\Facades\DB;

/** Tarif dan aturan payroll disimpan di database (bps = per 10.000), bukan di kode. */
class PayrollSettings
{
    public const DEFAULTS = [
        'kes_er' => 400, 'kes_emp' => 100, 'kes_cap' => 12_000_000,
        'jht_er' => 370, 'jht_emp' => 200,
        'jp_er' => 200, 'jp_emp' => 100, 'jp_cap' => 11_086_300,
        'jkk' => 10, 'jkm' => 30,
        'work_days' => 26, 'late_fee' => 20_000, 'overtime_divisor' => 173,
        'leave_quota' => 12, 'advance_max_bps' => 5000,
        'job_cost_bps' => 500, 'job_cost_cap' => 6_000_000,
        'bpjs_on' => 1, 'pph21_on' => 1, // 1 = ikut hitung dan potong; 0 = usaha belum terdaftar, tidak dihitung
        'final_tax_bps' => 50, // PPh final UMKM 0,5% dari omzet bruto (PP 55/2022)
        'weekly_off' => 64, // bitmask hari libur mingguan: Minggu (hari ke-7)
        'work_start_min' => 540, 'late_grace_min' => 10, 'max_gps_accuracy_m' => 150, // jam masuk 09:00, toleransi 10 menit, GPS paling kasar yang diterima
    ];

    public static function get(string $key): int
    {
        $v = DB::table('payroll_settings')->where('key', $key)->value('value');

        return $v !== null ? (int) $v : self::DEFAULTS[$key];
    }

    public static function set(string $key, int $value): void
    {
        DB::table('payroll_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
    }

    /** Pembulatan setengah ke atas: jumlah x bps / 10.000. */
    public static function pct(int $amount, int $bps): int
    {
        return intdiv($amount * $bps + 5000, 10_000);
    }
}
