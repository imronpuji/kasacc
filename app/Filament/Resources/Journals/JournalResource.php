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

/** Catatan transaksi hanya bisa dilihat dan dibatalkan; tidak ada form buat atau ubah. */
class JournalResource extends Resource
{
    protected static ?string $model = Journal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Catatan transaksi';

    protected static ?string $modelLabel = 'catatan transaksi';

    protected static ?string $pluralModelLabel = 'Catatan transaksi';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 7;

    /** Satu-satunya daftar nama sumber catatan (dipakai tabel, filter, dan halaman detail). */
    public const SOURCES = [
        'manual' => 'Manual',
        'purchase' => 'Pembelian',
        'service' => 'Servis',
        'pos' => 'Kasir',
        'transfer' => 'Pindah stok',
        'reversal' => 'Pembatalan',
        'payroll' => 'Gaji',
        'payroll_payment' => 'Pembayaran gaji',
        'kasbon' => 'Kasbon',
        'tax' => 'Pajak',
        'bank_recon' => 'Rekening bank',
    ];

    public static function sourceLabel(?string $state): string
    {
        return self::SOURCES[$state] ?? (string) $state;
    }

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
