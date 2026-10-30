<?php

namespace App\Filament\Pages;

use App\Domain\Payroll\PayrollSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PengaturanKerja extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-kerja';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Pengaturan jam kerja dan gaji';

    protected static ?string $title = 'Pengaturan Jam Kerja dan Gaji';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    public ?array $data = [];

    public function getSubheading(): ?string
    {
        return 'Atur sekali saja sesuai aturan tokomu. Perubahan berlaku untuk absen dan gaji berikutnya, catatan yang sudah ada tidak berubah.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('approve-payroll') ?? false;
    }

    private static function toTime(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    public function mount(): void
    {
        $this->form->fill([
            'start' => self::toTime(PayrollSettings::get('work_start_min')),
            'grace' => PayrollSettings::get('late_grace_min'),
            'late_fee' => PayrollSettings::get('late_fee'),
            'work_days' => PayrollSettings::get('work_days'),
            'leave_quota' => PayrollSettings::get('leave_quota'),
            'advance_pct' => PayrollSettings::get('advance_max_bps') / 100,
            'gps' => PayrollSettings::get('max_gps_accuracy_m'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Jam masuk dan keterlambatan')->description('Dipakai saat karyawan menekan Absen masuk di HP.')->schema([
                TextInput::make('start')->label('Jam masuk kerja')->type('time')->required()
                    ->helperText('Contoh: 09:00. Karyawan yang absen setelah jam ini (ditambah toleransi) dihitung terlambat.'),
                TextInput::make('grace')->label('Toleransi terlambat')->numeric()->integer()->minValue(0)->maxValue(120)->suffix('menit')->required()->placeholder('10')
                    ->helperText('Contoh: 10. Kalau jam masuk 09:00, absen sampai 09:10 masih dianggap tepat waktu.'),
                TextInput::make('late_fee')->label('Potongan gaji tiap kali terlambat')->numeric()->integer()->minValue(0)->prefix('Rp')->required()->placeholder('20000')
                    ->helperText('Angka saja tanpa titik, contoh 20000. Isi 0 kalau terlambat tidak memotong gaji.'),
            ])->columns(3),
            Section::make('Hari kerja dan cuti')->schema([
                TextInput::make('work_days')->label('Hari kerja dalam sebulan')->numeric()->integer()->minValue(1)->maxValue(31)->suffix('hari')->required()->placeholder('26')
                    ->helperText('Contoh: 26. Dipakai menghitung potongan gaji per hari kalau karyawan tidak masuk tanpa kabar (gaji pokok dibagi angka ini).'),
                TextInput::make('leave_quota')->label('Jatah cuti per tahun')->numeric()->integer()->minValue(0)->maxValue(60)->suffix('hari')->required()->placeholder('12')
                    ->helperText('Contoh: 12. Cuti baru bisa diambil setelah karyawan bekerja 12 bulan.'),
            ])->columns(2),
            Section::make('Kasbon dan lokasi')->schema([
                TextInput::make('advance_pct')->label('Kasbon paling banyak')->numeric()->minValue(0)->maxValue(100)->step(1)->suffix('% dari gaji')->required()->placeholder('50')
                    ->helperText('Contoh: 50. Karyawan tidak bisa kasbon lebih dari persen ini dari perkiraan gaji bersihnya.'),
                TextInput::make('gps')->label('Ketepatan GPS paling kasar yang diterima')->numeric()->integer()->minValue(20)->maxValue(1000)->suffix('meter')->required()->placeholder('150')
                    ->helperText('Contoh: 150. Kalau sinyal GPS HP lebih kasar dari ini, absen ditolak dan karyawan diminta pindah ke tempat terbuka. Jarak maksimal dari toko diatur di menu Cabang.'),
            ])->columns(2),
        ]);
    }

    public function save(): void
    {
        try {
            $d = $this->form->getState();
            [$h, $m] = array_map('intval', explode(':', $d['start']));
            PayrollSettings::set('work_start_min', $h * 60 + $m);
            PayrollSettings::set('late_grace_min', (int) $d['grace']);
            PayrollSettings::set('late_fee', (int) $d['late_fee']);
            PayrollSettings::set('work_days', (int) $d['work_days']);
            PayrollSettings::set('leave_quota', (int) $d['leave_quota']);
            PayrollSettings::set('advance_max_bps', (int) round(((float) $d['advance_pct']) * 100));
            PayrollSettings::set('max_gps_accuracy_m', (int) $d['gps']);
            Notification::make()->title('Tersimpan')->body('Pengaturan jam kerja dan gaji sudah disimpan.')->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Pengaturan belum tersimpan. Coba lagi. Kalau masih gagal, hubungi admin atau teknisi aplikasi.')->danger()->send();
        }
    }
}
