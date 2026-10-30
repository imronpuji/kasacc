<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Models\Account;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    /** Kode akun dibuat otomatis dari jenis akun: pengguna tidak perlu memikirkan nomor. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['type'] === 'money') {
            // Kas atau rekening bank: harta berkode 1-1120 dst. (1-1100 Kas dan 1-1110 Bank sudah bawaan).
            $max = (int) Account::where('type', 'asset')->whereBetween('code', ['1-1100', '1-1299'])->pluck('code')->map(fn ($c) => (int) substr($c, 2))->max();
            $next = max($max + 10, 1120);
            if ($next > 1299) {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.name' => 'Jumlah kas dan rekening sudah mencapai batas. Matikan yang tidak dipakai di Daftar akun.']);
            }
            $data['type'] = 'asset';
            $data['code'] = '1-'.$next;

            return $data;
        }
        if ($data['type'] === 'fixed') {
            $max = (int) Account::where('type', 'asset')->whereBetween('code', ['1-2000', '1-2899'])->pluck('code')->map(fn ($c) => (int) substr($c, 2))->max();
            $next = max($max + 100, 2300);
            if ($next > 2800) {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.name' => 'Jumlah jenis harta tahan lama sudah mencapai batas. Matikan yang tidak dipakai di Daftar akun.']);
            }
            $data['type'] = 'asset';
            $data['code'] = '1-'.$next;

            return $data;
        }
        $prefix = AccountForm::TYPES[$data['type']][0] ?? '9';
        $max = (int) Account::where('code', 'like', $prefix.'-%')
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, 2))
            ->max();
        $next = $max >= 9000 ? $max + 1 : (intdiv($max, 100) + 1) * 100;
        $data['code'] = $prefix.'-'.str_pad((string) max($next, 1000), 4, '0', STR_PAD_LEFT);

        return $data;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Simpan dan tambah lagi');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Akun berhasil ditambahkan';
    }
}
