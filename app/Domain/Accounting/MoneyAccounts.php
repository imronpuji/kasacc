<?php

namespace App\Domain\Accounting;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Akun uang usaha: kas dan semua rekening bank. Akun harta berkode 1-1100 sampai 1-1299.
 * Kata "kas" dan "bank" tetap dikenal sebagai nama lain untuk akun bawaan (1-1100 dan 1-1110),
 * jadi data lama dan kode lama tetap jalan.
 */
class MoneyAccounts
{
    public const KAS = '1-1100';

    public const BANK = '1-1110';

    /** [kode => nama] akun uang yang masih dipakai, kas dulu. */
    public static function all(bool $onlyActive = true): array
    {
        return DB::table('accounts')->where('type', 'asset')->whereBetween('code', ['1-1100', '1-1299'])
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))->orderBy('code')->pluck('name', 'code')->all();
    }

    /** Semua kode akun uang (aktif atau tidak), untuk laporan. */
    public static function codes(): array
    {
        return array_keys(self::all(false));
    }

    /** Pilihan untuk dropdown: kunci 'kas' dan 'bank' untuk akun bawaan, kode untuk akun lain. */
    public static function options(array $extra = []): array
    {
        $out = [];
        foreach (self::all() as $code => $name) {
            $out[match ($code) { self::KAS => 'kas', self::BANK => 'bank', default => $code }] = $name;
        }

        return $out + $extra;
    }

    /** Pilihan dengan kunci kode akun (untuk fitur yang menyimpan kode, misalnya bayar pajak). */
    public static function optionsByCode(): array
    {
        return self::all();
    }

    public static function valid(string $method): bool
    {
        return $method === 'kas' || $method === 'bank' || isset(self::all()[$method]);
    }

    /** Ubah kata 'kas', 'bank', 'qris' atau kode akun menjadi kode akun uang. */
    public static function resolve(string $method): string
    {
        if ($method === 'kas') {
            return self::KAS;
        }
        if ($method === 'bank' || $method === 'qris') {
            return self::BANK;
        }
        if (isset(self::all()[$method])) {
            return $method;
        }
        throw new DomainException('Kas atau rekening yang dipilih tidak dikenal atau sudah dimatikan. Pilih yang lain.');
    }

    public static function label(?string $method): string
    {
        if ($method === null || $method === '') {
            return '';
        }
        if ($method === 'qris') {
            return 'QRIS';
        }
        $code = $method === 'kas' ? self::KAS : ($method === 'bank' ? self::BANK : $method);

        return DB::table('accounts')->where('code', $code)->value('name') ?? $method;
    }
}
