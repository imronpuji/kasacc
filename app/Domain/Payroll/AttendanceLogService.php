<?php

namespace App\Domain\Payroll;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

/** Absen harian oleh karyawan sendiri: tombol Masuk/Pulang dengan pengecekan lokasi GPS terhadap titik cabang. */
class AttendanceLogService
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('kasir.timezone'));
    }

    public function employeeFor(User $user): Employee
    {
        $e = Employee::with('branch')->where('user_id', $user->id)->first();
        if (! $e) {
            throw new DomainException('Akun ini belum terhubung ke data karyawan. Minta HR menghubungkannya di menu Karyawan dan absensi.');
        }
        if (! $e->is_active || ($e->resigned_on && $e->resigned_on->lt($this->now()->startOfDay()))) {
            throw new DomainException('Karyawan ini sudah nonaktif sehingga tidak bisa absen. Hubungi HR kalau ini keliru.');
        }

        return $e;
    }

    /** Jarak dua titik di bumi dalam meter (rumus haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6_371_000;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    private function checkLocation(Employee $e, float $lat, float $lng, float $accuracy): int
    {
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0) || $accuracy < 0) {
            throw new DomainException('Lokasi tidak terbaca. Nyalakan GPS di HP, tunggu sebentar, lalu tekan Absen lagi.');
        }
        $branch = $e->branch;
        if ($branch->lat === null || $branch->lng === null) {
            throw new DomainException("Lokasi toko {$branch->name} belum diisi. Hubungi pemilik atau HR untuk mengisinya di menu Cabang.");
        }
        $max = PayrollSettings::get('max_gps_accuracy_m');
        if ($accuracy > $max) {
            throw new DomainException('Sinyal GPS kurang tepat. Pindah ke tempat terbuka, tunggu sebentar, lalu tekan Absen lagi.');
        }
        $distance = self::distanceMeters($lat, $lng, (float) $branch->lat, (float) $branch->lng);
        if ($distance > (int) $branch->geofence_m) {
            throw new DomainException("Anda berjarak {$distance} m dari {$branch->name}. Absen hanya bisa dalam radius {$branch->geofence_m} m dari toko, jadi mendekatlah ke toko lalu coba lagi.");
        }

        return $distance;
    }

    public function clockIn(User $user, float $lat, float $lng, float $accuracy = 0): AttendanceLog
    {
        $e = $this->employeeFor($user);
        $now = $this->now();
        $existing = AttendanceLog::where('employee_id', $e->id)->whereDate('work_date', $now->toDateString())->first();
        if ($existing) {
            throw new DomainException('Anda sudah absen masuk hari ini pukul '.$existing->check_in_at->setTimezone(config('kasir.timezone'))->format('H:i').'.');
        }
        $distance = $this->checkLocation($e, $lat, $lng, $accuracy);

        $minutes = $now->hour * 60 + $now->minute;
        $start = PayrollSettings::get('work_start_min');
        $late = $minutes > $start + PayrollSettings::get('late_grace_min') ? $minutes - $start : 0;

        return AttendanceLog::create([
            'employee_id' => $e->id, 'work_date' => $now->toDateString(), 'check_in_at' => $now->utc(),
            'in_lat' => $lat, 'in_lng' => $lng, 'in_distance_m' => $distance, 'in_accuracy_m' => (int) round($accuracy), 'late_minutes' => $late,
        ]);
    }

    public function clockOut(User $user, float $lat, float $lng, float $accuracy = 0): AttendanceLog
    {
        $e = $this->employeeFor($user);
        $now = $this->now();
        $log = AttendanceLog::where('employee_id', $e->id)->whereDate('work_date', $now->toDateString())->first();
        if (! $log) {
            throw new DomainException('Anda belum absen masuk hari ini. Tekan Absen masuk dulu.');
        }
        if ($log->check_out_at) {
            throw new DomainException('Anda sudah absen pulang hari ini pukul '.$log->check_out_at->setTimezone(config('kasir.timezone'))->format('H:i').'.');
        }
        $distance = $this->checkLocation($e, $lat, $lng, $accuracy);
        $log->update(['check_out_at' => $now->utc(), 'out_lat' => $lat, 'out_lng' => $lng, 'out_distance_m' => $distance]);

        return $log->fresh();
    }

    public function today(User $user): ?AttendanceLog
    {
        $e = Employee::where('user_id', $user->id)->first();

        return $e ? AttendanceLog::where('employee_id', $e->id)->whereDate('work_date', $this->now()->toDateString())->first() : null;
    }

    /**
     * Usulan isian absensi bulanan dari absen harian. Izin/cuti dan sakit memakai isian yang sudah ada;
     * alpa = sisa hari kerja yang tidak ada absen maupun izin. HR tetap bisa mengubah sebelum menyimpan.
     */
    public function monthlySuggestion(Employee $e, int $year, int $month): array
    {
        $logs = AttendanceLog::where('employee_id', $e->id)->whereYear('work_date', $year)->whereMonth('work_date', $month)->get();
        $att = Attendance::where('employee_id', $e->id)->where('year', $year)->where('month', $month)->first();
        $days = PayrollSettings::get('work_days');
        $present = min($logs->count(), $days);
        $leave = (int) ($att->leave ?? 0);
        $sick = (int) ($att->sick ?? 0);

        return [
            'present' => $present, 'leave' => $leave, 'sick' => $sick,
            'absent' => max($days - $present - $leave - $sick, 0),
            'late' => $logs->where('late_minutes', '>', 0)->count(),
            'overtime_hours' => (int) ($att->overtime_hours ?? 0),
        ];
    }
}
