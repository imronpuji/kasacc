<?php

namespace App\Filament\Resources\PaymentRequests\Tables;

use App\Domain\Accounting\PaymentApprovalService;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentRequestsTable
{
    /** Boleh diproses: masih menunggu, pengguna berwenang di cabang itu, dan bukan pengajunya sendiri. */
    private static function canProcess(PaymentRequest $r): bool
    {
        $u = auth()->user();

        return $r->status === 'pending'
            && $u && $u->can('approve-payments')
            && $u->canAccessBranch($r->branch_id)
            && (int) $r->requested_by !== (int) $u->id;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Belum ada pengajuan pembayaran utang')
            ->emptyStateDescription('Klik "Ajukan pembayaran" untuk meminta persetujuan membayar utang ke pemasok.')
            ->columns([
                TextColumn::make('id')->label('No.')->sortable(),
                TextColumn::make('created_at')->label('Diajukan pada')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('amount')->label('Jumlah (Rp)')->numeric(0, ',', '.')->alignEnd(),
                TextColumn::make('method')->label('Dibayar dari')->badge()
                    ->formatStateUsing(fn (string $state) => PaymentRequestResource::methodLabel($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => PaymentRequestResource::statusLabel($state))
                    ->color(fn (string $state) => PaymentRequestResource::statusColor($state)),
                TextColumn::make('requester.name')->label('Pengaju'),
                TextColumn::make('approver.name')->label('Diproses oleh')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(PaymentRequestResource::STATUSES)->placeholder('Semua status'),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                Action::make('setujui')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Setujui pembayaran utang ini?')
                    ->modalDescription('Uang akan dicatat keluar dari kas atau bank sesuai pengajuan. Langkah ini tidak bisa diulang.')
                    ->modalSubmitActionLabel('Ya, setujui dan bayar')
                    ->visible(fn (PaymentRequest $r) => self::canProcess($r))
                    ->schema([DatePicker::make('date')->label('Tanggal bayar')->default(now())->required()
                        ->helperText('Tanggal pembayaran dicatat. Tidak bisa di bulan yang sudah ditutup.')])
                    ->action(function (PaymentRequest $record, array $data) {
                        try {
                            app(PaymentApprovalService::class)->approve($record, auth()->user(), $data['date']);
                            Notification::make()->title('Pembayaran disetujui dan dicatat')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title('Pembayaran belum disetujui')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('tolak')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Tolak pengajuan ini?')
                    ->modalDescription('Tidak ada uang yang keluar. Pengaju bisa membuat pengajuan baru.')
                    ->modalSubmitActionLabel('Ya, tolak')
                    ->visible(fn (PaymentRequest $r) => self::canProcess($r))
                    ->schema([Textarea::make('reason')->label('Alasan penolakan (boleh dikosongkan)')->placeholder('Contoh: Nominal salah, cek ulang nota pemasok')->maxLength(150)->rows(2)
                        ->helperText('Alasan ditambahkan ke catatan pengajuan.')])
                    ->action(function (PaymentRequest $record, array $data) {
                        try {
                            app(PaymentApprovalService::class)->reject($record, auth()->user(), $data['reason'] ?? null);
                            Notification::make()->title('Pengajuan ditolak')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title('Pengajuan belum ditolak')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
