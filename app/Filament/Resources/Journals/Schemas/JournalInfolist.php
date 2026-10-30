<?php

namespace App\Filament\Resources\Journals\Schemas;

use App\Filament\Resources\Journals\JournalResource;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JournalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan')->columns(3)->schema([
                TextEntry::make('id')->label('No. catatan'),
                TextEntry::make('entry_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('branch.name')->label('Cabang')->placeholder('Semua cabang'),
                TextEntry::make('source')->label('Sumber')->badge()->formatStateUsing(fn (string $state) => JournalResource::sourceLabel($state)),
                TextEntry::make('reference')->label('Referensi')->placeholder('-'),
                TextEntry::make('description')->label('Keterangan')->placeholder('-'),
                TextEntry::make('reversal_of')->label('Membatalkan catatan no.')->placeholder('-'),
            ]),
            Section::make('Rincian uang masuk dan keluar')->schema([
                RepeatableEntry::make('lines')->label('')->columns(3)->schema([
                    TextEntry::make('account.code')->label('Kode'),
                    TextEntry::make('account.name')->label('Akun'),
                    TextEntry::make('debit')->label('Uang masuk ke akun (Rp)')->numeric(0, ',', '.')->placeholder('0'),
                    TextEntry::make('credit')->label('Uang keluar dari akun (Rp)')->numeric(0, ',', '.')->placeholder('0'),
                ]),
            ]),
        ]);
    }
}
