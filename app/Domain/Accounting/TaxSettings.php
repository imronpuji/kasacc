<?php

namespace App\Domain\Accounting;

use Illuminate\Support\Facades\DB;

/** Pengaturan pajak usaha dan biaya QRIS. Disimpan di database supaya pemilik bisa mengubahnya tanpa programmer. */
class TaxSettings
{
    public const ENTITIES = ['cv' => 'CV', 'pt' => 'PT', 'firma' => 'Firma', 'perorangan' => 'Perorangan / usaha pribadi'];

    public const REGIMES = [
        'final' => 'PPh final UMKM (persen dari omzet, tanpa melihat untung/rugi)',
        'badan' => 'PPh badan normal (persen dari laba bersih)',
        'none' => 'Tidak dihitung di aplikasi (diurus konsultan pajak)',
    ];

    /** Isian awal menurut bentuk usaha (PP 55/2022): batas waktu tarif final PT 3 tahun, CV/Firma 4 tahun, perorangan 7 tahun, omzet 500 juta pertama bebas pajak untuk perorangan. */
    public const PRESETS = [
        'cv' => ['regime' => 'final', 'final_bps' => 50, 'badan_bps' => 2200, 'exempt' => 0, 'max_years' => 4],
        'firma' => ['regime' => 'final', 'final_bps' => 50, 'badan_bps' => 2200, 'exempt' => 0, 'max_years' => 4],
        'pt' => ['regime' => 'final', 'final_bps' => 50, 'badan_bps' => 2200, 'exempt' => 0, 'max_years' => 3],
        'perorangan' => ['regime' => 'final', 'final_bps' => 50, 'badan_bps' => 2200, 'exempt' => 500_000_000, 'max_years' => 7],
    ];

    public static function get(string $key, string|int|null $default = null): string|int|null
    {
        $v = DB::table('payroll_settings')->where('key', 'tax_'.$key)->value('value');

        return $v ?? $default;
    }

    public static function set(string $key, string|int $value): void
    {
        DB::table('payroll_settings')->updateOrInsert(['key' => 'tax_'.$key], ['value' => (string) $value]);
    }

    public static function entity(): string
    {
        return (string) self::get('entity', 'cv');
    }

    public static function regime(): string
    {
        return (string) self::get('regime', 'final');
    }

    public static function bps(): int
    {
        return (int) self::get(self::regime() === 'badan' ? 'badan_bps' : 'final_bps', self::regime() === 'badan' ? 2200 : 50);
    }

    public static function exempt(): int
    {
        return (int) self::get('exempt', 0);
    }

    public static function qrisBps(): int
    {
        return (int) (DB::table('payroll_settings')->where('key', 'qris_mdr_bps')->value('value') ?? config('kasir.qris_mdr_bps'));
    }

    public static function setQrisBps(int $bps): void
    {
        DB::table('payroll_settings')->updateOrInsert(['key' => 'qris_mdr_bps'], ['value' => (string) $bps]);
    }

    /** Peringatan kalau masa tarif final sudah habis atau hampir habis (bukan penghalang, hanya pengingat). */
    public static function expiryNote(?int $year = null): ?string
    {
        if (self::regime() !== 'final') {
            return null;
        }
        $start = (int) self::get('start_year', 0);
        $max = (int) self::get('max_years', 0);
        if (! $start || ! $max) {
            return null;
        }
        $last = $start + $max - 1;
        $year ??= (int) now(config('kasir.timezone'))->format('Y');

        return $year > $last
            ? "Masa tarif final {$max} tahun sudah lewat (berakhir tahun {$last}). Tanyakan konsultan pajak, mungkin harus pindah ke PPh badan normal."
            : ($year === $last ? "Tahun {$last} adalah tahun terakhir tarif final. Mulai tahun depan kemungkinan pakai PPh badan normal." : null);
    }
}
