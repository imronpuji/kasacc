<?php

namespace App\Filament\Pages;

use App\Domain\Payroll\HrService;
use App\Models\LeaveRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class LeaveApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Persetujuan cuti';

    protected static ?string $title = 'Persetujuan cuti';

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Cuti yang diajukan HR menunggu persetujuan di sini. Yang mengajukan tidak bisa menyetujui atau menolak cuti sendiri.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('approve-payroll') ?? false;
    }

    private static function handle(callable $fn, string $ok): void
    {
        try {
            $fn(auth()->user());
            Notification::make()->title($ok)->success()->send();
        } catch (\DomainException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Terjadi masalah di sistem, data Anda aman. Coba lagi sebentar lagi. Kalau masih gagal, hubungi pemilik toko.')->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => LeaveRequest::query()->with('employee')->latest())
            ->emptyStateHeading('Belum ada pengajuan cuti')
            ->emptyStateDescription('Pengajuan cuti dibuat HR lewat tombol Ajukan cuti di menu Karyawan dan absensi.')
            ->columns([
                TextColumn::make('employee.name')->label('Karyawan'),
                TextColumn::make('start_date')->label('Mulai cuti')->date('d M Y'),
                TextColumn::make('days')->label('Jumlah hari')->suffix(' hari'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => ['menunggu' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'][$state] ?? $state)
                    ->color(fn ($state) => ['menunggu' => 'warning', 'disetujui' => 'success', 'ditolak' => 'danger'][$state] ?? 'gray'),
            ])
            ->recordActions([
                Action::make('setujui')->label('Setujui')->visible(fn ($record) => $record->status === 'menunggu')
                    ->requiresConfirmation()->modalHeading('Setujui cuti ini?')->modalDescription('Hari cuti akan masuk ke absen bulan itu sebagai izin atau cuti resmi (gaji tidak dipotong).')->modalSubmitActionLabel('Ya, setujui')
                    ->action(fn ($record) => self::handle(fn ($u) => app(HrService::class)->approveLeave($u, $record), 'Cuti disetujui')),
                Action::make('tolak')->label('Tolak')->color('danger')->visible(fn ($record) => $record->status === 'menunggu')
                    ->requiresConfirmation()->modalHeading('Tolak cuti ini?')->modalDescription('Pengajuan ditandai Ditolak dan jatah cuti karyawan tidak berkurang.')->modalSubmitActionLabel('Ya, tolak')
                    ->action(fn ($record) => self::handle(fn ($u) => app(HrService::class)->rejectLeave($u, $record), 'Cuti ditolak')),
            ]);
    }
}
