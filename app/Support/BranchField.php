<?php

namespace App\Support;

use App\Models\Branch;
use Filament\Forms\Components\Select;

/**
 * Pilihan cabang untuk form. Hanya cabang yang boleh dipakai pengguna (pemilik: semua). Kalau hanya ada satu pilihan,
 * pertanyaannya disembunyikan dan cabang itu dipakai otomatis, supaya toko satu cabang tidak ditanya hal yang sudah jelas.
 */
class BranchField
{
    public static function options(bool $all = false): array
    {
        $user = auth()->user();

        return ($user && ! $all && ! $user->hasRole('owner')
            ? $user->branches()->orderBy('name')->pluck('branches.name', 'branches.id')
            : Branch::orderBy('name')->pluck('name', 'id'))->all();
    }

    /** $all = true untuk bagian keuangan dan SDM, yang memang mengurus semua cabang. */
    public static function make(string $label = 'Cabang', bool $all = false, string $name = 'branch_id'): Select
    {
        return Select::make($name)->label($label)->required()->options(fn () => self::options($all))
            ->default(fn () => array_key_first(self::options($all)))
            ->hidden(fn () => count(self::options($all)) <= 1)->dehydratedWhenHidden();
    }
}
