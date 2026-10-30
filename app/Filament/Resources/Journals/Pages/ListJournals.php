<?php

namespace App\Filament\Resources\Journals\Pages;

use App\Filament\Resources\Journals\JournalResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListJournals extends ListRecords
{
    protected static string $resource = JournalResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Daftar ini hanya untuk dilihat; catatan dibuat otomatis oleh sistem.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
