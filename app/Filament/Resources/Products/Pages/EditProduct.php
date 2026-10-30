<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Lihat'),
            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus produk ini?')
                ->modalDescription('Data yang sudah dipakai di transaksi tidak bisa dihapus.')
                ->modalSubmitActionLabel('Ya, hapus')
                ->successNotificationTitle('Produk dihapus')
                ->failureNotificationTitle('Produk belum dihapus')
                ->failureNotificationBody('Data ini sudah dipakai di transaksi, jadi tidak bisa dihapus.')
                ->using(function (Model $record): bool {
                    try {
                        return (bool) $record->delete();
                    } catch (QueryException) {
                        return false;
                    }
                }),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan produk disimpan';
    }
}
