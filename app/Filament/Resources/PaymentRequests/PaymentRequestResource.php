<?php

namespace App\Filament\Resources\PaymentRequests;

use App\Filament\Resources\PaymentRequests\Pages\CreatePaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Filament\Resources\PaymentRequests\Schemas\PaymentRequestForm;
use App\Filament\Resources\PaymentRequests\Schemas\PaymentRequestInfolist;
use App\Filament\Resources\PaymentRequests\Tables\PaymentRequestsTable;
use App\Models\PaymentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static ?string $navigationLabel = 'Pembayaran utang';

    protected static ?string $modelLabel = 'pembayaran utang';

    protected static ?string $pluralModelLabel = 'Pembayaran utang';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    public const STATUSES = ['pending' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    public static function methodLabel(?string $state): string
    {
        return \App\Domain\Accounting\MoneyAccounts::label($state);
    }

    public static function statusLabel(?string $state): string
    {
        return self::STATUSES[$state] ?? (string) $state;
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' };
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u && ($u->can('request-payments') || $u->can('approve-payments'));
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentRequests::route('/'),
            'create' => CreatePaymentRequest::route('/create'),
            'view' => ViewPaymentRequest::route('/{record}'),
        ];
    }
}
