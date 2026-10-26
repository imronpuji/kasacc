<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Domain\Accounting\PaymentApprovalService;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentRequest extends CreateRecord
{
    protected static string $resource = PaymentRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PaymentApprovalService::class)->request(
            auth()->user(), (int) $data['branch_id'], (int) $data['amount'], $data['method'], $data['description'] ?? 'Pembayaran utang usaha',
        );
    }
}
