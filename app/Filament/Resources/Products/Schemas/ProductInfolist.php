<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sku')
                    ->label('Kode (SKU)'),
                TextEntry::make('name')->label('Nama barang'),
                TextEntry::make('kind')->label('Jenis')->badge()->formatStateUsing(fn (string $state) => \App\Filament\Resources\Products\Schemas\ProductForm::KINDS[$state] ?? $state),
                TextEntry::make('sale_price')->label('Harga jual')->money('IDR', locale: 'id'),
                TextEntry::make('barcode')->label('Barcode')->placeholder('-'),
                TextEntry::make('created_at')->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextEntry::make('updated_at')->label('Terakhir diubah')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
            ]);
    }
}
