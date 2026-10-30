<?php

namespace App\Filament\Pages;

use App\Domain\Payroll\AttendanceLogService;
use App\Models\AttendanceLog;
use App\Models\Employee;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class MyAttendance extends Page
{
    protected string $view = 'filament.pages.my-attendance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Absen saya';

    protected static ?string $title = 'Absen saya';

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && Employee::where('user_id', $u->id)->where('is_active', true)->exists();
    }

    public function getSubheading(): ?string
    {
        return 'Tekan Absen masuk saat tiba di toko dan Absen pulang saat selesai kerja. HP akan meminta izin lokasi, pilih Izinkan.';
    }

    public function clockIn(float $lat, float $lng, float $accuracy = 0): void
    {
        $this->act(fn ($svc) => $svc->clockIn(auth()->user(), $lat, $lng, $accuracy), 'Absen masuk berhasil');
    }

    public function clockOut(float $lat, float $lng, float $accuracy = 0): void
    {
        $this->act(fn ($svc) => $svc->clockOut(auth()->user(), $lat, $lng, $accuracy), 'Absen pulang berhasil');
    }

    public function geoError(string $message): void
    {
        Notification::make()->title('Belum berhasil')->body($message)->danger()->send();
    }

    private function act(callable $fn, string $ok): void
    {
        try {
            $log = $fn(app(AttendanceLogService::class));
            Notification::make()->title($ok)->body('Anda berjarak '.($log->check_out_at ? $log->out_distance_m : $log->in_distance_m).' m dari toko.')->success()->send();
        } catch (\DomainException $e) {
            Notification::make()->title('Absen gagal')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Absen gagal')->body('Terjadi masalah di sistem. Tunggu sebentar lalu tekan tombol absen lagi.')->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        $svc = app(AttendanceLogService::class);
        $emp = Employee::with('branch')->where('user_id', auth()->id())->first();
        $tz = config('kasir.timezone');

        return [
            'employee' => $emp,
            'today' => $svc->today(auth()->user()),
            'logs' => $emp ? AttendanceLog::where('employee_id', $emp->id)->orderByDesc('work_date')->limit(10)->get() : collect(),
            'now' => $svc->now(),
            'tz' => $tz,
            'branchReady' => $emp && $emp->branch?->lat !== null && $emp->branch?->lng !== null,
        ];
    }
}
