<?php

namespace App\Domain\Accounting;

use App\Models\Period;

class PeriodService
{
    public function lock(int $year, int $month, ?int $userId = null): Period
    {
        $period = Period::firstOrCreate(['year' => $year, 'month' => $month]);
        $period->update(['locked_at' => now(), 'locked_by' => $userId]);

        return $period;
    }

    /** Hanya pemilik yang boleh membuka kunci; pemanggil menentukan hak itu lewat $byOwner. */
    public function unlock(int $year, int $month, bool $byOwner): Period
    {
        if (! $byOwner) {
            throw new \DomainException('Hanya pemilik usaha yang boleh membuka kunci bulan ini.');
        }
        $period = Period::where('year', $year)->where('month', $month)->firstOrFail();
        $period->update(['locked_at' => null, 'locked_by' => null]);

        return $period;
    }
}
