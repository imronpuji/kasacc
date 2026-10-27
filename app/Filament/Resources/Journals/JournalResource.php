<?php

namespace App\Filament\Resources\Journals;

use App\Filament\Resources\Journals\Pages\ListJournals;
use App\Filament\Resources\Journals\Pages\ViewJournal;
use App\Filament\Resources\Journals\Schemas\JournalInfolist;
use App\Filament\Resources\Journals\Tables\JournalsTable;
use App\Models\Journal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Jurnal hanya bisa dilihat dan dibalik; tidak ada form buat atau ubah (jurnal tidak boleh diubah). */
class JournalResource extends Resource
{
    protected static ?string $model = Journal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Jurnal';

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    /** Alamat ngawur seperti /journals/create tidak boleh error 500: perlakukan sebagai tidak ditemukan. */
    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?\Illuminate\Database\Eloquent\Model
    {
        return ctype_digit((string) $key) ? parent::resolveRecordRouteBinding($key, $modifyQuery) : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return JournalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournals::route('/'),
            'view' => ViewJournal::route('/{record}'),
        ];
    }
}
