<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('Kode (SKU)')
                    ->searchable(),
                TextColumn::make('name')->label('Nama barang')
                    ->searchable(),
                TextColumn::make('kind')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => \App\Filament\Resources\Products\Schemas\ProductForm::KINDS[$state] ?? $state),
                TextColumn::make('sale_price')->label('Harga jual')->money('IDR', locale: 'id')->sortable(),
                TextColumn::make('barcode')->label('Barcode')->searchable()->placeholder('-'),
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
                        ->modalHeading('Hapus produk yang dipilih?')
                        ->modalDescription('Data yang sudah dipakai di transaksi tidak bisa dihapus.')
                        ->modalSubmitActionLabel('Ya, hapus')
                        ->successNotificationTitle('Produk yang dipilih dihapus')
                        ->failureNotificationTitle('Sebagian data belum terhapus')
                        ->missingBulkProcessingFailureNotificationMessage('Data yang sudah dipakai di transaksi tidak bisa dihapus.'),
                ]),
            ]);
    }
}
