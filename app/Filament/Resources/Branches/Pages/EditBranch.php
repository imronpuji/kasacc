<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Lihat'),
            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus cabang ini?')
                ->modalDescription('Data yang sudah dipakai di transaksi tidak bisa dihapus.')
                ->modalSubmitActionLabel('Ya, hapus')
                ->successNotificationTitle('Cabang dihapus')
                ->failureNotificationTitle('Cabang belum dihapus')
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
        return parent::getSaveFormAction()->label('Simpan cabang');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan cabang disimpan';
    }
}
