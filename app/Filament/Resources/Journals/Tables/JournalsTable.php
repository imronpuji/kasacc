<?php

namespace App\Filament\Resources\Journals\Tables;

use App\Domain\Accounting\LedgerService;
use App\Models\Journal;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
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
            ->columns([
                TextColumn::make('id')->label('No.')->sortable(),
                TextColumn::make('entry_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('branch.name')->label('Cabang')->sortable(),
                TextColumn::make('source')->label('Sumber')->badge(),
                TextColumn::make('description')->label('Keterangan')->searchable()->wrap(),
                TextColumn::make('reference')->label('Referensi')->searchable()->placeholder('-'),
                TextColumn::make('lines_sum_debit')->label('Total (Rp)')->numeric(0, ',', '.')->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('branch_id')->label('Cabang')->relationship('branch', 'name'),
                SelectFilter::make('source')->label('Sumber')->options([
                    'manual' => 'Manual', 'purchase' => 'Pembelian', 'service' => 'Servis',
                    'pos' => 'Kasir', 'transfer' => 'Transfer', 'reversal' => 'Pembalik',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('balik')
                    ->label('Balik jurnal')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Journal $r) => $r->source !== 'reversal' && ! Journal::where('reversal_of', $r->id)->exists())
                    ->schema([DatePicker::make('date')->label('Tanggal pembalik')->default(now())->required()])
                    ->action(function (Journal $record, array $data) {
                        try {
                            app(LedgerService::class)->reverse($record, $data['date'], auth()->id());
                            Notification::make()->title('Jurnal dibalik')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
