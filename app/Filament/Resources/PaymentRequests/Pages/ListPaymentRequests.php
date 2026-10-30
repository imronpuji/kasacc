<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentRequests extends ListRecords
{
    protected static string $resource = PaymentRequestResource::class;

    public function getTitle(): string
    {
        return 'Pembayaran utang';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Ajukan pembayaran'),
        ];
    }
}
