<?php

namespace App\Filament\Pages;

use App\Domain\Payroll\AttendanceLogService;
use App\Domain\Payroll\HrService;
use App\Domain\Payroll\PayrollSettings;
use App\Models\Branch;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Karyawan extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Karyawan dan absensi';

    protected static ?string $title = 'Data karyawan';

    public function getSubheading(): ?string
    {
        return 'Tiap bulan: 1) isi absen bulan ini untuk tiap karyawan, 2) catat kasbon bila ada, 3) buka menu Gaji karyawan untuk menghitung gaji.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-hr') ?? false;
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn(auth()->user());
            Notification::make()->title($ok)->success()->send();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\DomainException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Terjadi masalah di sistem, data Anda aman. Coba lagi sebentar lagi. Kalau masih gagal, hubungi pemilik toko.')->danger()->send();
        }
    }

    private static function months(): array
    {
        return [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    }

    private static function years(): array
    {
        $y = (int) now()->year;

        return [$y - 1 => (string) ($y - 1), $y => (string) $y, $y + 1 => (string) ($y + 1)];
    }

    private static function employeeFields(bool $creating = false): array
    {
        return [
            Select::make('branch_id')->label('Cabang')->placeholder('Pilih cabang tempat bekerja')->options(fn () => Branch::pluck('name', 'id'))->required()
                ->helperText('Cabang tempat karyawan bekerja.'),
            TextInput::make('name')->label('Nama lengkap')->placeholder('Contoh: Andi Saputra')->required()
                ->helperText('Nama yang akan tercetak di slip gaji.'),
            TextInput::make('position')->label('Jabatan (posisi kerja)')->placeholder('Contoh: Teknisi')
                ->helperText('Misalnya Teknisi, Kasir, atau Kepala cabang. Hanya keterangan di slip gaji, tidak mempengaruhi hitungan.'),
            DatePicker::make('hired_on')->label('Tanggal masuk')->placeholder('Pilih tanggal')->required()
                ->helperText('Cuti baru bisa diambil setelah karyawan bekerja 12 bulan sejak tanggal ini.'),
            TextInput::make('base_salary')->label('Gaji pokok per bulan')->prefix('Rp')->inputMode('numeric')->placeholder('4500000')->numeric()->minValue(0)->required()
                ->helperText('Tulis angka saja tanpa titik, misal 4500000. Dipakai juga untuk menghitung potongan kalau karyawan tidak masuk tanpa kabar.'),
            TextInput::make('fixed_allowance')->label('Tunjangan tetap per bulan')->prefix('Rp')->inputMode('numeric')->placeholder('0')->numeric()->minValue(0)->default(0)->required()
                ->helperText('Tunjangan yang sama tiap bulan, misal uang makan. Isi 0 kalau tidak ada.'),
            TextInput::make('nik')->label('NIK (nomor KTP)')->placeholder('16 angka')->inputMode('numeric')->length(16)->regex('/^\d{16}$/')
                ->validationMessages(['regex' => 'NIK harus 16 angka, tanpa spasi atau tanda baca.', 'length' => 'NIK harus 16 angka.'])
                ->helperText('Boleh dikosongkan dulu. Dipakai untuk bukti potong PPh 21 tahunan.'),
            TextInput::make('npwp')->label('NPWP')->placeholder('15 atau 16 angka')->inputMode('numeric')->regex('/^(\d{15}|\d{16})$/')
                ->validationMessages(['regex' => 'NPWP harus 15 atau 16 angka, tanpa titik atau strip.'])
                ->helperText('Boleh dikosongkan kalau karyawan belum punya. Tulis angka saja.'),
            Select::make('ptkp')->label('Status keluarga untuk pajak')->placeholder('Pilih status')->required()
                ->options([
                    'TK/0' => 'Belum menikah, tanpa tanggungan (TK/0)',
                    'TK/1' => 'Belum menikah, 1 tanggungan (TK/1)',
                    'TK/2' => 'Belum menikah, 2 tanggungan (TK/2)',
                    'TK/3' => 'Belum menikah, 3 tanggungan (TK/3)',
                    'K/0' => 'Menikah, tanpa anak (K/0)',
                    'K/1' => 'Menikah, 1 anak (K/1)',
                    'K/2' => 'Menikah, 2 anak (K/2)',
                    'K/3' => 'Menikah, 3 anak (K/3)',
                ])
                ->helperText('Dipakai untuk menghitung pajak penghasilan (PPh 21) karyawan. Lihat KTP atau KK; kalau ragu, tanyakan ke karyawan.'),
            Select::make('pay_method')->label('Cara gaji dibayarkan')->options(['bank' => 'Transfer bank', 'tunai' => 'Tunai'])->default('bank')->required()
                ->helperText('Menentukan gaji keluar dari rekening bank atau dari kas di pembukuan.'),
            ...($creating ? [
                Toggle::make('create_login')->label('Buatkan akun login untuk karyawan ini')->live()->default(true)
                    ->helperText('Dengan akun, karyawan bisa absen sendiri lewat HP.'),
                Select::make('login_role')->label('Akun ini dipakai sebagai')->placeholder('Pilih peran')->options([
                    'technician' => 'Teknisi (absen + mengerjakan servis, dapat komisi)',
                    'cashier' => 'Kasir (absen + kasir + servis)',
                    'staff' => 'Karyawan lain (hanya absen)',
                ])->default('staff')->visible(fn ($get) => (bool) $get('create_login'))->required(fn ($get) => (bool) $get('create_login'))
                    ->helperText('Menentukan menu yang bisa dibuka karyawan setelah masuk.'),
                TextInput::make('login_email')->label('Email untuk masuk')->email()->placeholder('nama@gmail.com')
                    ->visible(fn ($get) => (bool) $get('create_login'))->required(fn ($get) => (bool) $get('create_login'))
                    ->unique('users', 'email')->validationMessages(['unique' => 'Email ini sudah dipakai akun lain. Pakai email yang berbeda.'])
                    ->helperText('Dipakai karyawan untuk masuk ke aplikasi.'),
                TextInput::make('login_password')->label('Kata sandi awal')->password()->revealable()->minLength(8)->placeholder('Minimal 8 karakter')
                    ->visible(fn ($get) => (bool) $get('create_login'))->required(fn ($get) => (bool) $get('create_login'))
                    ->helperText('Minimal 8 karakter. Sampaikan ke karyawan, lalu sarankan menggantinya.'),
            ] : [
                Select::make('user_id')->label('Akun login')->searchable()->placeholder('Kosongkan kalau belum punya akun')
                    ->options(fn ($record) => \App\Models\User::role(['technician', 'cashier', 'staff'])
                        ->where(fn ($q) => $q->whereNotIn('id', Employee::whereNotNull('user_id')->pluck('user_id'))->orWhere('id', $record?->user_id))
                        ->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->email.')']))
                    ->helperText('Dengan akun, karyawan bisa absen sendiri lewat HP. Karyawan baru: buat akunnya lewat tombol Tambah karyawan.'),
                Toggle::make('is_active')->label('Karyawan masih bekerja')->default(true)
                    ->helperText('Matikan kalau karyawan sudah berhenti. Dia tidak bisa absen lagi. Kalau tanggal berhenti diisi, gajinya tetap dihitung sampai bulan itu.'),
                DatePicker::make('resigned_on')->label('Tanggal berhenti')->placeholder('Kosongkan kalau masih bekerja')
                    ->helperText('Isi tanggal terakhir bekerja. Gaji tetap dihitung sampai bulan ini, dan pajak PPh 21 setahun dihitung ulang di gaji bulan terakhir. Absen bulan terakhir tetap harus diisi.'),
            ]),
            TextInput::make('commission_pct')->label('Komisi servis (% dari biaya jasa)')->suffix('%')->placeholder('10')->numeric()->minValue(0)->maxValue(100)->step(0.01)->default(0)->required()
                ->helperText('Isi 10 artinya 10% dari biaya jasa servis yang selesai bulan itu. Isi 0 kalau tidak ada komisi.'),
        ];
    }

    private static function toRecord(array $data): array
    {
        $data['commission_bps'] = (int) round(((float) $data['commission_pct']) * 100);
        unset($data['commission_pct']);
        if (! empty($data['resigned_on'])) {
            $data['is_active'] = false;
        }
        if (! empty($data['create_login'])) {
            $user = \App\Models\User::create(['name' => $data['name'], 'email' => $data['login_email'], 'password' => bcrypt($data['login_password'])]);
            $user->assignRole($data['login_role'] ?? 'staff');
            $user->branches()->syncWithoutDetaching([(int) $data['branch_id']]);
            $data['user_id'] = $user->id;
        }
        unset($data['create_login'], $data['login_role'], $data['login_email'], $data['login_password']);

        return $data;
    }

    private static function suggest(Employee $record, $get, $set): void
    {
        $s = app(AttendanceLogService::class)->monthlySuggestion($record, (int) $get('year'), (int) $get('month'));
        foreach ($s as $k => $v) {
            $set($k, $v);
        }
    }

    private static function attendanceFields(Employee $record): array
    {
        $days = PayrollSettings::get('work_days');
        $fee = 'Rp'.number_format(PayrollSettings::get('late_fee'), 0, ',', '.');
        $refresh = fn ($get, $set) => self::suggest($record, $get, $set);

        return [
            Select::make('month')->label('Bulan')->options(self::months())->default((int) now()->month)->required()->live()->afterStateUpdated($refresh)
                ->helperText('Pilih bulan yang absennya mau diisi. Angka di bawah akan menyesuaikan.'),
            Select::make('year')->label('Tahun')->options(self::years())->default((int) now()->year)->required()->live()->afterStateUpdated($refresh),
            TextInput::make('present')->label('Hadir (hari)')->placeholder('0')->numeric()->minValue(0)->default(0)->required()
                ->helperText("Terisi otomatis dari absen di HP. Hadir + izin + sakit + tidak masuk harus pas {$days} hari kerja."),
            TextInput::make('leave')->label('Izin atau cuti resmi (hari)')->placeholder('0')->helperText('Tidak memotong gaji.')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('sick')->label('Sakit (hari)')->placeholder('0')->helperText('Sakit dengan keterangan, tidak memotong gaji.')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('absent')->label('Tidak masuk tanpa kabar (alpa) (hari)')->placeholder('0')->helperText("Gaji dipotong gaji pokok dibagi {$days} hari untuk tiap hari alpa.")->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('late')->label('Terlambat (kali)')->placeholder('0')->helperText("Dipotong {$fee} tiap kali terlambat.")->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('overtime_hours')->label('Jam lembur')->placeholder('0')->numeric()->minValue(0)->step(1)->default(0)->required()
                ->helperText('Isi jam penuh (dibulatkan). Jam pertama dihitung 1,5 kali, jam berikutnya 2 kali upah per jam.'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Employee::query()->with('branch'))
            ->emptyStateHeading('Belum ada karyawan')
            ->emptyStateDescription('Tekan Tambah karyawan untuk mengisi data karyawan pertama.')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('position')->label('Jabatan'),
                TextColumn::make('base_salary')->label('Gaji pokok')->formatStateUsing(fn ($state) => 'Rp '.number_format((int) $state, 0, ',', '.'))->alignEnd(),
                TextColumn::make('ptkp')->label('Status pajak'),
                TextColumn::make('status_aktif')->label('Status')->badge()->state(fn ($record) => $record->is_active ? 'Aktif' : 'Sudah berhenti')->color(fn ($state) => $state === 'Aktif' ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('aktif')->label('Hanya yang masih bekerja')->toggle()->default()
                    ->query(fn ($query) => $query->where('is_active', true)),
            ])
            ->headerActions([
                Action::make('tambah')->label('Tambah karyawan')->modalHeading('Tambah karyawan')->modalSubmitActionLabel('Simpan karyawan')->schema(self::employeeFields(true))
                    ->action(fn (array $data) => self::run(fn () => DB::transaction(fn () => Employee::create(self::toRecord($data))), 'Karyawan ditambahkan')),
            ])
            ->recordActions([
                Action::make('ubah')->label('Ubah')->modalSubmitActionLabel('Simpan perubahan')->fillForm(fn (Employee $record) => $record->only(['branch_id', 'name', 'position', 'hired_on', 'base_salary', 'fixed_allowance', 'ptkp', 'pay_method', 'user_id', 'is_active', 'resigned_on', 'npwp', 'nik']) + ['commission_pct' => $record->commission_bps / 100])
                    ->schema(self::employeeFields())
                    ->action(fn (array $data, Employee $record) => self::run(fn () => DB::transaction(fn () => $record->update(self::toRecord($data))), 'Data diperbarui')),
                Action::make('absensi')->label('Isi absen bulan ini')->modalHeading('Isi absen bulanan')->modalSubmitActionLabel('Simpan absen')
                    ->fillForm(fn (Employee $record) => ['year' => (int) now()->year, 'month' => (int) now()->month] + app(AttendanceLogService::class)->monthlySuggestion($record, (int) now()->year, (int) now()->month))
                    ->modalDescription('Hadir dan terlambat sudah terisi otomatis dari absen harian karyawan di HP. Periksa, ubah kalau perlu, tambahkan izin, sakit, dan lembur, lalu simpan. Untuk bulan lain, ganti bulan dan tahun.')
                    ->schema(fn (Employee $record) => self::attendanceFields($record))
                    ->action(fn (array $data, Employee $record) => self::run(fn ($u) => app(HrService::class)->recordAttendance($u, $record, (int) $data['year'], (int) $data['month'], [
                        'present' => (int) $data['present'], 'leave' => (int) $data['leave'], 'sick' => (int) $data['sick'], 'absent' => (int) $data['absent'],
                        'late' => (int) $data['late'], 'overtime_hours' => (int) round((float) $data['overtime_hours']),
                    ]), 'Absen disimpan')),
                Action::make('cuti')->label('Ajukan cuti')->modalSubmitActionLabel('Ajukan cuti')->schema(function () {
                    $quota = PayrollSettings::get('leave_quota');

                    return [
                        DatePicker::make('start')->label('Tanggal mulai cuti')->placeholder('Pilih tanggal')->required(),
                        TextInput::make('days')->label('Jumlah hari cuti')->placeholder('Contoh: 3')->numeric()->minValue(1)->required()
                            ->helperText("Jatah cuti {$quota} hari per tahun, berlaku setelah bekerja 12 bulan. Cuti harus disetujui orang lain."),
                    ];
                })->action(fn (array $data, Employee $record) => self::run(fn ($u) => app(HrService::class)->requestLeave($u, $record, $data['start'], (int) $data['days']), 'Cuti diajukan, menunggu persetujuan')),
                Action::make('kasbon')->label('Kasbon')->modalHeading('Catat kasbon')->modalSubmitActionLabel('Catat kasbon')
                    ->modalDescription('Kasbon adalah uang muka gaji yang dipinjam karyawan, dipotong dari gaji tiap bulan sampai lunas.')
                    ->schema([
                        DatePicker::make('date')->label('Tanggal kasbon diberikan')->default(now())->required(),
                        TextInput::make('amount')->label('Jumlah kasbon (Rp)')->prefix('Rp')->inputMode('numeric')->placeholder('1200000')->numeric()->minValue(1)->required()->helperText('Uang yang dipinjam karyawan. Dicatat sebagai piutang karyawan.'),
                        TextInput::make('installment')->label('Cicilan per bulan (Rp)')->prefix('Rp')->inputMode('numeric')->placeholder('200000')->numeric()->minValue(1)->required()->helperText('Dipotong otomatis dari gaji tiap bulan sampai lunas. Maksimal 50% dari gaji bersih.'),
                        Select::make('method')->label('Kasbon diberikan lewat')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options())->default('kas')->required()->helperText('Uangnya keluar dari kas (tunai) atau rekening bank (transfer).'),
                    ])->action(fn (array $data, Employee $record) => self::run(fn ($u) => app(HrService::class)->giveAdvance($u, $record, $data['date'], (int) $data['amount'], (int) $data['installment'], $data['method']), 'Kasbon dicatat')),
            ]);
    }
}
