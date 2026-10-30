<?php

namespace App\Domain\Payroll;

use App\Models\Holiday;
use Carbon\CarbonImmutable;

/** Hari kerja sebenarnya tiap bulan: hari dalam bulan dikurangi libur mingguan dan tanggal merah yang diliburkan toko. */
class WorkCalendar
{
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    /** Nomor hari (ISO 1=Senin..7=Minggu) yang libur tiap minggu. Disimpan sebagai bitmask. */
    public static function weeklyOff(): array
    {
        $mask = PayrollSettings::get('weekly_off');

        return array_values(array_filter(array_keys(self::DAYS), fn ($d) => ($mask >> ($d - 1)) & 1));
    }

    public static function maskFrom(array $days): int
    {
        return array_reduce($days, fn ($c, $d) => $c | (1 << ((int) $d - 1)), 0);
    }

    public static function isWorkDay(CarbonImmutable $date, ?array $off = null, ?array $holidays = null): bool
    {
        $off ??= self::weeklyOff();
        $holidays ??= Holiday::where('store_off', true)->pluck('date')->map(fn ($d) => $d->toDateString())->all();

        return ! in_array($date->dayOfWeekIso, $off, true) && ! in_array($date->toDateString(), $holidays, true);
    }

    public static function workDays(int $year, int $month): int
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $off = self::weeklyOff();
        $holidays = Holiday::where('store_off', true)->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()])
            ->pluck('date')->map(fn ($d) => $d->toDateString())->all();
        $n = 0;
        for ($d = $first; $d->month === $month; $d = $d->addDay()) {
            if (self::isWorkDay($d, $off, $holidays)) {
                $n++;
            }
        }

        return $n;
    }
}
