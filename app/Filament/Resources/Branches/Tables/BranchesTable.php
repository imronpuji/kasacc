<?php

namespace App\Filament\Resources\Branches\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode cabang')
                    ->searchable(),
                TextColumn::make('name')->label('Nama cabang')
                    ->searchable(),
                TextColumn::make('created_at')->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Terakhir diubah')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                EditAction::make()->label('Ubah'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Hapus terpilih')
                        ->modalHeading('Hapus cabang yang dipilih?')
                        ->modalDescription('Data yang sudah dipakai di transaksi tidak bisa dihapus.')
                        ->modalSubmitActionLabel('Ya, hapus')
                        ->successNotificationTitle('Cabang yang dipilih dihapus')
                        ->failureNotificationTitle('Sebagian data belum terhapus')
                        ->missingBulkProcessingFailureNotificationMessage('Data yang sudah dipakai di transaksi tidak bisa dihapus.'),
                ]),
            ]);
    }
}
