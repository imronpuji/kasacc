<?php

namespace App\Filament\Resources\Journals\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JournalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Jurnal')->columns(3)->schema([
                TextEntry::make('id')->label('No.'),
                TextEntry::make('entry_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('branch.name')->label('Cabang'),
                TextEntry::make('source')->label('Sumber')->badge(),
                TextEntry::make('reference')->label('Referensi')->placeholder('-'),
                TextEntry::make('description')->label('Keterangan')->placeholder('-'),
                TextEntry::make('reversal_of')->label('Membalik jurnal no.')->placeholder('-'),
            ]),
            Section::make('Baris jurnal')->schema([
                RepeatableEntry::make('lines')->label('')->columns(3)->schema([
                    TextEntry::make('account.code')->label('Kode'),
                    TextEntry::make('account.name')->label('Akun'),
                    TextEntry::make('debit')->label('Debit')->numeric(0, ',', '.')->placeholder('0'),
                    TextEntry::make('credit')->label('Kredit')->numeric(0, ',', '.')->placeholder('0'),
                ]),
            ]),
        ]);
    }
}
