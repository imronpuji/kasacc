<?php

namespace App\Filament\Resources\Journals\Tables;

use App\Domain\Accounting\LedgerService;
use App\Filament\Resources\Journals\JournalResource;
use App\Models\Journal;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('lines', 'debit')->with('branch'))
            ->defaultSort('entry_date', 'desc')
            ->emptyStateHeading('Belum ada catatan transaksi')
            ->emptyStateDescription('Catatan muncul sendiri setelah ada penjualan, servis, pembelian, atau gaji.')
            ->columns([
                TextColumn::make('id')->label('No. catatan')->sortable(),
                TextColumn::make('entry_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('branch.name')->label('Cabang')->placeholder('Semua cabang')->sortable(),
                TextColumn::make('source')->label('Sumber')->badge()->formatStateUsing(fn (string $state) => JournalResource::sourceLabel($state)),
                TextColumn::make('description')->label('Keterangan')->searchable()->wrap(),
                TextColumn::make('reference')->label('Referensi')->searchable()->placeholder('-'),
                TextColumn::make('lines_sum_debit')->label('Nilai (Rp)')->numeric(0, ',', '.')->placeholder('0')->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('branch_id')->label('Cabang')->relationship('branch', 'name')->placeholder('Semua cabang'),
                SelectFilter::make('source')->label('Sumber')->options(JournalResource::SOURCES)->placeholder('Semua sumber'),
                Filter::make('entry_date')->label('Tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Dari tanggal')->placeholder('Contoh: 01/10/2026'),
                        DatePicker::make('until')->label('Sampai tanggal')->placeholder('Contoh: 31/10/2026'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                Action::make('balik')
                    ->label('Batalkan catatan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan catatan ini?')
                    ->modalDescription('Catatan lama tidak dihapus. Sistem membuat catatan baru yang membatalkan nilainya, jadi laporan kembali seperti sebelum catatan ini ada. Pembatalan tidak bisa diulang.')
                    ->modalSubmitActionLabel('Ya, batalkan catatan')
                    ->visible(fn (Journal $r) => (auth()->user()?->can('lock-periods') ?? false)
                        && $r->source !== 'reversal' && ! Journal::where('reversal_of', $r->id)->exists())
                    ->schema([DatePicker::make('date')->label('Tanggal pembatalan')->default(now())->required()
                        ->helperText('Tanggal catatan pembatalan. Tidak bisa di bulan yang sudah ditutup.')])
                    ->action(function (Journal $record, array $data) {
                        if (! (auth()->user()?->can('lock-periods') ?? false)) {
                            Notification::make()->title('Catatan belum dibatalkan')->body('Anda tidak berwenang membatalkan catatan. Minta pemilik usaha melakukannya.')->danger()->send();

                            return;
                        }
                        try {
                            app(LedgerService::class)->reverse($record, $data['date'], auth()->id());
                            Notification::make()->title('Catatan dibatalkan')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title('Catatan belum dibatalkan')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
