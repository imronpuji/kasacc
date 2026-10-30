<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Domain\Accounting\PaymentApprovalService;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentRequest extends CreateRecord
{
    protected static string $resource = PaymentRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PaymentApprovalService::class)->request(
                auth()->user(), (int) $data['branch_id'], (int) $data['amount'], $data['method'], $data['description'] ?? 'Pembayaran utang usaha', isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            );
        } catch (DomainException $e) {
            Notification::make()->title('Pengajuan belum terkirim')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    public function getTitle(): string
    {
        return 'Ajukan pembayaran utang';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Pengajuan terkirim, menunggu persetujuan';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Kirim pengajuan');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Kirim dan buat lagi');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }
}
