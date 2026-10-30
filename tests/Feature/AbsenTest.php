<?php

use App\Domain\Payroll\AttendanceLogService;
use App\Domain\Payroll\HrService;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

// Titik toko: Jakarta. 0,001 derajat lintang = sekitar 111 meter.
const TOKO_LAT = -6.200000;
const TOKO_LNG = 106.816666;

function jam(string $hhmm, string $date = '2027-02-10'): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse("$date $hhmm", config('kasir.timezone')));
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat', 'lat' => TOKO_LAT, 'lng' => TOKO_LNG, 'geofence_m' => 100]);
    $this->user = User::factory()->create();
    $this->user->assignRole('staff');
    $this->emp = Employee::create(['branch_id' => $this->branch->id, 'user_id' => $this->user->id, 'name' => 'Sari', 'position' => 'Kasir', 'hired_on' => '2025-06-01',
        'base_salary' => 4_000_000, 'fixed_allowance' => 0, 'ptkp' => 'TK/0', 'pay_method' => 'tunai']);
    $this->svc = app(AttendanceLogService::class);
    jam('08:55');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('absen masuk di toko berhasil, tepat waktu, jarak tercatat', function () {
    $log = $this->svc->clockIn($this->user, TOKO_LAT + 0.0003, TOKO_LNG, 10);
    expect($log->late_minutes)->toBe(0)->and($log->in_distance_m)->toBeBetween(30, 40);
    expect($log->work_date->toDateString())->toBe('2027-02-10');
});

it('terlambat dihitung setelah toleransi 10 menit', function () {
    jam('09:10');
    expect($this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5)->late_minutes)->toBe(0);
    jam('09:11', '2027-02-11');
    expect($this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5)->late_minutes)->toBe(11);
});

it('hari kerja mengikuti zona waktu WIB, bukan UTC', function () {
    jam('06:30', '2027-02-11'); // 23:30 UTC tanggal 10
    $log = $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5);
    expect($log->work_date->toDateString())->toBe('2027-02-11');
});

it('terlalu jauh dari toko ditolak dengan pesan jarak', function () {
    expect(fn () => $this->svc->clockIn($this->user, TOKO_LAT + 0.002, TOKO_LNG, 10))->toThrow(DomainException::class, 'radius 100 m');
    expect(AttendanceLog::count())->toBe(0);
});

it('sinyal GPS kasar, koordinat kosong atau nol, dan di luar jangkauan ditolak', function () {
    expect(fn () => $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 500))->toThrow(DomainException::class, 'kurang tepat');
    expect(fn () => $this->svc->clockIn($this->user, 0.0, 0.0, 5))->toThrow(DomainException::class, 'tidak terbaca');
    expect(fn () => $this->svc->clockIn($this->user, 95.0, 10.0, 5))->toThrow(DomainException::class, 'tidak terbaca');
    expect(fn () => $this->svc->clockIn($this->user, TOKO_LAT, 200.0, 5))->toThrow(DomainException::class, 'tidak terbaca');
});

it('absen dua kali, pulang tanpa masuk, dan pulang dua kali ditolak', function () {
    expect(fn () => $this->svc->clockOut($this->user, TOKO_LAT, TOKO_LNG, 5))->toThrow(DomainException::class, 'belum absen masuk');
    $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5);
    expect(fn () => $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5))->toThrow(DomainException::class, 'sudah absen masuk');
    jam('17:05');
    $this->svc->clockOut($this->user, TOKO_LAT, TOKO_LNG, 5);
    expect(fn () => $this->svc->clockOut($this->user, TOKO_LAT, TOKO_LNG, 5))->toThrow(DomainException::class, 'sudah absen pulang');
    expect(AttendanceLog::count())->toBe(1);
});

it('lokasi cabang belum diatur memberi pesan jelas', function () {
    $this->branch->update(['lat' => null, 'lng' => null]);
    expect(fn () => $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5))->toThrow(DomainException::class, 'belum diisi');
});

it('akun tanpa data karyawan dan karyawan nonaktif tidak bisa absen', function () {
    $orang = User::factory()->create();
    $orang->assignRole('staff');
    expect(fn () => $this->svc->clockIn($orang, TOKO_LAT, TOKO_LNG, 5))->toThrow(DomainException::class, 'belum terhubung');
    $this->emp->update(['is_active' => false]);
    expect(fn () => $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5))->toThrow(DomainException::class, 'nonaktif');
});

it('absen dari cabang lain tidak diterima karena titik yang dipakai adalah cabang karyawan', function () {
    Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung', 'lat' => -6.917464, 'lng' => 107.619125]);
    expect(fn () => $this->svc->clockIn($this->user, -6.917464, 107.619125, 5))->toThrow(DomainException::class, 'radius');
});

it('usulan absensi bulanan: hadir dan terlambat dari absen harian, alpa dari sisa hari kerja', function () {
    foreach ([['2027-02-01', '08:50'], ['2027-02-02', '09:30'], ['2027-02-03', '08:58']] as [$tgl, $j]) {
        jam($j, $tgl);
        $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5);
    }
    Attendance::create(['employee_id' => $this->emp->id, 'year' => 2027, 'month' => 2, 'leave' => 2, 'sick' => 1, 'present' => 0]);
    $s = $this->svc->monthlySuggestion($this->emp, 2027, 2);
    expect($s)->toMatchArray(['present' => 3, 'late' => 1, 'leave' => 2, 'sick' => 1, 'absent' => 20]);
    expect($s['present'] + $s['leave'] + $s['sick'] + $s['absent'])->toBe(26);
});

it('halaman Absen saya: karyawan berakun bisa buka, akun tanpa karyawan 403, absen lewat layar', function () {
    $this->actingAs($this->user)->get('/admin/absen-saya')->assertOk()->assertSee('Absen masuk');
    $lain = User::factory()->create();
    $lain->assignRole('staff');
    $this->actingAs($lain)->get('/admin/absen-saya')->assertForbidden();
    Livewire::actingAs($this->user)->test(App\Filament\Pages\AbsenSaya::class)->call('clockIn', TOKO_LAT, TOKO_LNG, 5)->assertNotified('Absen masuk berhasil');
    expect(AttendanceLog::count())->toBe(1);
    Livewire::actingAs($this->user)->test(App\Filament\Pages\AbsenSaya::class)->call('clockIn', TOKO_LAT + 0.01, TOKO_LNG, 5)->assertNotified('Absen gagal');
    expect(AttendanceLog::count())->toBe(1);
});

it('karyawan umum (staff) hanya membuka Absen saya, bukan halaman lain', function () {
    foreach (['/admin/kasir', '/admin/servis', '/admin/stok', '/admin/laporan', '/admin/payroll', '/admin/karyawan', '/admin/rekap-absen'] as $url) {
        $this->actingAs($this->user)->get($url)->assertForbidden();
    }
    $this->actingAs($this->user)->get('/admin')->assertOk();
});

it('HR melihat rekap absen, karyawan biasa tidak', function () {
    $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5);
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $this->actingAs($hr)->get('/admin/rekap-absen')->assertOk()->assertSee('Sari');
    $this->actingAs($this->user)->get('/admin/rekap-absen')->assertForbidden();
});

it('form cabang: koordinat di luar batas, radius terlalu kecil atau besar ditolak', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');
    foreach ([['lat' => 91], ['lng' => 181], ['geofence_m' => 5], ['geofence_m' => 99999]] as $bad) {
        Livewire::actingAs($owner)->test(App\Filament\Resources\Branches\Pages\EditBranch::class, ['record' => $this->branch->getRouteKey()])
            ->fillForm($bad + ['code' => 'PUSAT', 'name' => 'Cabang Pusat', 'geofence_m' => 100])->call('save')->assertHasFormErrors();
    }
    expect((int) $this->branch->fresh()->geofence_m)->toBe(100);
});

it('Karyawan: tombol Absensi bulanan terisi dari absen harian dan menyimpan jumlah hari', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    jam('08:50', '2027-02-02');
    $this->svc->clockIn($this->user, TOKO_LAT, TOKO_LNG, 5);
    $s = $this->svc->monthlySuggestion($this->emp, 2027, 2);
    app(HrService::class)->recordAttendance($hr, $this->emp, 2027, 2, $s + ['overtime_hours' => 0]);
    expect(Attendance::first()->present)->toBe(1);
});

it('haversine akurat: 0,001 derajat lintang sekitar 111 meter', function () {
    expect(AttendanceLogService::distanceMeters(-6.2, 106.8, -6.201, 106.8))->toBeBetween(109, 113);
    expect(AttendanceLogService::distanceMeters(-6.2, 106.8, -6.2, 106.8))->toBe(0);
});

it('membaca koordinat dari tempelan Google Maps', function () {
    expect(\App\Support\MapsLink::coordinates('-6.200000, 106.816666'))->toBe([-6.2, 106.816666]);
    expect(\App\Support\MapsLink::coordinates('https://www.google.com/maps/place/Toko/@-6.9,107.6,17z/data=!3m1!4b1!4m6!3m5!8m2!3d-6.917464!4d107.619125'))->toBe([-6.917464, 107.619125]);
    expect(\App\Support\MapsLink::coordinates('https://www.google.com/maps?q=-6.917464,107.619125'))->toBe([-6.917464, 107.619125]);
    expect(\App\Support\MapsLink::coordinates('halo bukan koordinat'))->toBeNull();
    expect(\App\Support\MapsLink::coordinates('99.5, 300.5'))->toBeNull();
    expect(\App\Support\MapsLink::coordinates(''))->toBeNull();
});
