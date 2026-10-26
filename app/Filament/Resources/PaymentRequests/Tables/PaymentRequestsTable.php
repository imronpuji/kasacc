<?php

namespace App\Filament\Resources\PaymentRequests\Tables;

use App\Domain\Accounting\PaymentApprovalService;
use App\Models\PaymentRequest;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('No.'),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('amount')->label('Jumlah (Rp)')->numeric(0, ',', '.')->alignEnd(),
                TextColumn::make('method')->label('Dari')->badge(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }),
                TextColumn::make('requester.name')->label('Pengaju'),
                TextColumn::make('approver.name')->label('Penyetuju')->placeholder('-'),
            ])
            ->recordActions([
                Action::make('setujui')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PaymentRequest $r) => $r->status === 'pending')
                    ->schema([DatePicker::make('date')->label('Tanggal bayar')->default(now())->required()])
                    ->action(function (PaymentRequest $record, array $data) {
                        try {
                            app(PaymentApprovalService::class)->approve($record, auth()->user(), $data['date']);
                            Notification::make()->title('Pembayaran disetujui dan dijurnal')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
