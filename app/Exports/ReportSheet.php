<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Satu lembar Excel: judul, periode, cabang, lalu tabel laporan. */
class ReportSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(private array $data)
    {
    }

    public function array(): array
    {
        return [[$this->data['title']], [$this->data['period'].' - '.$this->data['branch']], [], ...$this->data['rows']];
    }

    public function title(): string
    {
        return mb_substr($this->data['title'], 0, 31);
    }
}
