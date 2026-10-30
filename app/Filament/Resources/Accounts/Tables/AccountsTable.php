<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Resources\Accounts\Schemas\AccountForm;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->emptyStateHeading('Belum ada akun')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->label('Nama akun')->searchable()->wrap(),
                TextColumn::make('type')->label('Termasuk')->badge()
                    ->formatStateUsing(fn (string $state) => AccountForm::label($state)),
                IconColumn::make('is_active')->label('Masih dipakai')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Termasuk')->options(AccountForm::typeOptions()),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                EditAction::make()->label('Ubah'),
            ]);
    }
}
