<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('branch.name')->label('Cabang'),
            TextEntry::make('amount')->label('Jumlah')->money('IDR', locale: 'id'),
            TextEntry::make('method')->label('Dibayar dari')->badge()
                ->formatStateUsing(fn (string $state) => PaymentRequestResource::methodLabel($state)),
            TextEntry::make('description')->label('Catatan')->placeholder('-'),
            TextEntry::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => PaymentRequestResource::statusLabel($state))
                ->color(fn (string $state) => PaymentRequestResource::statusColor($state)),
            TextEntry::make('requester.name')->label('Diajukan oleh'),
            TextEntry::make('approver.name')->label('Diproses oleh')->placeholder('-'),
            TextEntry::make('journal_id')->label('No. catatan transaksi')->placeholder('Belum dicatat'),
            TextEntry::make('created_at')->label('Diajukan pada')->dateTime('d M Y H:i')->placeholder('-'),
        ]);
    }
}
