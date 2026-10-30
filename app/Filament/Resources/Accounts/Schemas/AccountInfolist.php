<?php

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Nama akun'),
            TextEntry::make('type')->label('Termasuk')->badge()
                ->formatStateUsing(fn (string $state) => AccountForm::label($state)),
            IconEntry::make('is_active')->label('Masih dipakai')->boolean(),
            TextEntry::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->placeholder('-'),
            TextEntry::make('updated_at')->label('Terakhir diubah')->dateTime('d M Y H:i')->placeholder('-'),
        ]);
    }
}
