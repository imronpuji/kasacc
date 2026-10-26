<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_id')->label('Cabang')->relationship('branch', 'name')->required(),
            TextInput::make('amount')->label('Jumlah (Rp)')->numeric()->minValue(1)->required(),
            Select::make('method')->label('Dibayar dari')->options(['bank' => 'Bank', 'kas' => 'Kas'])->required(),
            TextInput::make('description')->label('Keterangan')->default('Pembayaran utang usaha'),
        ]);
    }
}
