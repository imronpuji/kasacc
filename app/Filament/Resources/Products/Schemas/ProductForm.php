<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('SKU')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('kind')
                    ->label('Jenis')
                    ->required()
                    ->default('sparepart'),
                TextInput::make('sale_price')
                    ->label('Harga jual (Rp)')
                    ->numeric()->minValue(0)->default(0)->required(),
                TextInput::make('barcode')
                    ->label('Barcode')
                    ->unique(ignoreRecord: true),
            ]);
    }
}
