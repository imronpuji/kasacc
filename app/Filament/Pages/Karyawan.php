<?php

namespace App\Filament\Pages;

use App\Domain\Payroll\HrService;
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
use Filament\Tables\Table;

class Karyawan extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Karyawan dan absensi';

    protected static ?string $title = 'Karyawan, Absensi, Cuti, Kasbon';

    public function getSubheading(): ?string
    {
        return 'Langkah tiap bulan: 1) cek Absensi bulanan tiap karyawan (hadir terisi otomatis dari absen di HP), 2) catat Kasbon bila ada, 3) lanjut ke menu Payroll untuk menghitung gaji.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'SDM';

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
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    private static function employeeFields(bool $creating = false): array
    {
        return [
            Select::make('branch_id')->label('Cabang')->options(fn () => Branch::pluck('name', 'id'))->required()
                ->helperText('Cabang tempat karyawan bekerja.'),
            TextInput::make('name')->label('Nama lengkap')->placeholder('Contoh: Andi Saputra')->required(),
            TextInput::make('position')->label('Jabatan (posisi kerja)')->placeholder('Contoh: Teknisi')->helperText('Ketik posisi karyawan, misalnya Teknisi, Kasir, atau Kepala cabang. Hanya untuk keterangan di slip gaji, tidak mempengaruhi hitungan.'),
            DatePicker::make('hired_on')->label('Tanggal masuk')->required()
                ->helperText('Dipakai untuk hak cuti: cuti baru bisa diambil setelah bekerja 12 bulan.'),
            TextInput::make('base_salary')->label('Gaji pokok (Rp per bulan)')->placeholder('Contoh: 4500000')->numeric()->minValue(0)->required()
                ->helperText('Tulis angka saja tanpa titik. Contoh: 4500000. Dasar potongan kalau alpa.'),
            TextInput::make('fixed_allowance')->label('Tunjangan tetap (Rp per bulan)')->numeric()->minValue(0)->default(0)->required()
                ->helperText('Tunjangan yang selalu sama tiap bulan (makan, transport). Isi 0 kalau tidak ada. Gaji pokok + tunjangan = dasar hitung BPJS dan lembur.'),
            Select::make('ptkp')->label('Status pajak (PTKP)')->required()
                ->options([
                    'TK/0' => 'TK/0 - belum menikah, tanpa tanggungan',
                    'TK/1' => 'TK/1 - belum menikah, 1 tanggungan',
                    'TK/2' => 'TK/2 - belum menikah, 2 tanggungan',
                    'TK/3' => 'TK/3 - belum menikah, 3 tanggungan',
                    'K/0' => 'K/0 - menikah, tanpa anak',
                    'K/1' => 'K/1 - menikah, 1 anak',
                    'K/2' => 'K/2 - menikah, 2 anak',
                    'K/3' => 'K/3 - menikah, 3 anak',
                ])
                ->helperText('Menentukan besar pajak penghasilan (PPh 21) karyawan. Lihat KTP/KK atau data karyawan. Kalau ragu, tanyakan ke karyawan.'),
            Select::make('pay_method')->label('Gaji dibayar lewat')->options(['bank' => 'Transfer bank', 'tunai' => 'Tunai'])->default('bank')->required()
                ->helperText('Menentukan gaji keluar dari Bank atau Kas di pembukuan.'),
            ...($creating ? [
                Toggle::make('create_login')->label('Buatkan akun login untuk karyawan ini')->live()->default(true)
                    ->helperText('Disarankan menyala untuk semua karyawan. Dengan akun ini karyawan bisa absen sendiri lewat HP (menu Absen saya). Teknisi juga bisa mengerjakan servis dan dapat komisi, kasir bisa memakai Kasir.'),
                Select::make('login_role')->label('Akun ini dipakai sebagai')->options([
                    'technician' => 'Teknisi (absen + mengerjakan servis, dapat komisi)',
                    'cashier' => 'Kasir (absen + kasir + servis)',
                    'staff' => 'Karyawan lain (hanya absen)',
                ])->default('staff')->visible(fn ($get) => (bool) $get('create_login'))->required(fn ($get) => (bool) $get('create_login'))
                    ->helperText('Menentukan menu yang bisa dibuka karyawan setelah login.'),
                TextInput::make('login_email')->label('Email login')->email()->placeholder('Contoh: supri@kasir.test')
                    ->visible(fn ($get) => (bool) $get('create_login'))->required(fn ($get) => (bool) $get('create_login'))
                    ->unique('users', 'email')->helperText('Dipakai karyawan untuk masuk ke aplikasi.'),
                TextInput::make('login_password')->label('Password awal')->password()->revealable()->minLength(8)
                    ->visible(fn ($get) => (bool) $get('create_login'))->required(fn ($get) => (bool) $get('create_login'))
                    ->helperText('Minimal 8 karakter. Sampaikan ke karyawan, lalu sarankan diganti.'),
            ] : [
                Select::make('user_id')->label('Akun login')->searchable()->placeholder('Kosongkan kalau belum punya akun')
                    ->options(fn ($record) => \App\Models\User::role(['technician', 'cashier', 'staff'])
                        ->where(fn ($q) => $q->whereNotIn('id', Employee::whereNotNull('user_id')->pluck('user_id'))->orWhere('id', $record?->user_id))
                        ->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->email.')']))
                    ->helperText('Akun yang belum dipakai karyawan lain. Dengan akun, karyawan bisa absen sendiri dan (untuk teknisi) servisnya terhitung komisi. Karyawan baru: buat akunnya lewat tombol Tambah karyawan.'),
            ]),
            TextInput::make('commission_pct')->label('Komisi servis (% dari biaya jasa)')->numeric()->minValue(0)->maxValue(100)->step(0.01)->default(0)->required()
                ->helperText('Isi 10 artinya 10% dari biaya jasa servis yang selesai bulan itu. Isi 0 kalau tidak ada komisi.'),
        ];
    }

    private static function toRecord(array $data): array
    {
        $data['commission_bps'] = (int) round(((float) $data['commission_pct']) * 100);
        unset($data['commission_pct']);
        if (! empty($data['create_login'])) {
            $user = \App\Models\User::create(['name' => $data['name'], 'email' => $data['login_email'], 'password' => bcrypt($data['login_password'])]);
            $user->assignRole($data['login_role'] ?? 'staff');
            $user->branches()->syncWithoutDetaching([(int) $data['branch_id']]);
            $data['user_id'] = $user->id;
        }
        unset($data['create_login'], $data['login_role'], $data['login_email'], $data['login_password']);

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Employee::query()->with('branch'))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('position')->label('Jabatan'),
                TextColumn::make('base_salary')->label('Gaji pokok')->formatStateUsing(fn ($state) => 'Rp '.number_format((int) $state, 0, ',', '.'))->alignEnd(),
                TextColumn::make('ptkp')->label('PTKP'),
                TextColumn::make('status_aktif')->label('Status')->badge()->state(fn ($record) => $record->is_active ? 'Aktif' : 'Nonaktif')->color(fn ($state) => $state === 'Aktif' ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('tambah')->label('Tambah karyawan')->schema(self::employeeFields(true))
                    ->action(fn (array $data) => self::run(fn () => Employee::create(self::toRecord($data)), 'Karyawan ditambahkan')),
            ])
            ->recordActions([
                Action::make('ubah')->label('Ubah')->fillForm(fn (Employee $record) => $record->only(['branch_id', 'name', 'position', 'hired_on', 'base_salary', 'fixed_allowance', 'ptkp', 'pay_method', 'user_id']) + ['commission_pct' => $record->commission_bps / 100])
                    ->schema(self::employeeFields())
                    ->action(fn (array $data, Employee $record) => self::run(fn () => $record->update(self::toRecord($data)), 'Data diperbarui')),
                Action::make('absensi')->label('Absensi bulanan')->fillForm(fn (Employee $record) => ['year' => (int) now()->year, 'month' => (int) now()->month] + app(\App\Domain\Payroll\AttendanceLogService::class)->monthlySuggestion($record, (int) now()->year, (int) now()->month))->modalDescription('Angka hadir dan terlambat sudah terisi otomatis dari absen harian karyawan di HP (bulan berjalan). Periksa, ubah kalau perlu, tambahkan izin, sakit, dan lembur, lalu simpan. Untuk bulan lain, ganti bulan lalu isi manual.')->schema([
                    TextInput::make('year')->label('Tahun absensi')->numeric()->default((int) now()->year)->required(),
                    TextInput::make('month')->label('Bulan absensi (1-12)')->numeric()->minValue(1)->maxValue(12)->default((int) now()->month)->required(),
                    TextInput::make('present')->label('Hadir (hari)')->numeric()->default(0)->required()->helperText('Jumlah hadir + izin/cuti + sakit + alpa harus pas 26 hari kerja.'),
                    TextInput::make('leave')->label('Izin/cuti (hari)')->helperText('Tidak memotong gaji.')->numeric()->default(0)->required(),
                    TextInput::make('sick')->label('Sakit (hari)')->helperText('Dengan keterangan, tidak memotong gaji.')->numeric()->default(0)->required(),
                    TextInput::make('absent')->label('Alpa (hari)')->helperText('Tanpa kabar. Gaji dipotong gaji pokok / 26 per hari.')->numeric()->default(0)->required(),
                    TextInput::make('late')->label('Terlambat (kali)')->helperText('Dipotong Rp20.000 per kali.')->numeric()->default(0)->required(),
                    TextInput::make('overtime_hours')->label('Lembur (jam)')->helperText('Jam pertama dihitung 1,5x, jam berikutnya 2x upah per jam.')->numeric()->default(0)->required(),
                ])->action(fn (array $data, Employee $record) => self::run(fn ($u) => app(HrService::class)->recordAttendance($u, $record, (int) $data['year'], (int) $data['month'], array_map('intval', $data)), 'Absensi disimpan')),
                Action::make('cuti')->label('Ajukan cuti')->schema([
                    DatePicker::make('start')->label('Mulai')->required(),
                    TextInput::make('days')->label('Jumlah hari cuti')->numeric()->minValue(1)->required()->helperText('Jatah cuti 12 hari per tahun, berlaku setelah bekerja 12 bulan. Cuti disetujui oleh orang lain.'),
                ])->action(fn (array $data, Employee $record) => self::run(fn ($u) => app(HrService::class)->requestLeave($u, $record, $data['start'], (int) $data['days']), 'Cuti diajukan, menunggu persetujuan')),
                Action::make('kasbon')->label('Kasbon')->schema([
                    DatePicker::make('date')->label('Tanggal')->default(now())->required(),
                    TextInput::make('amount')->label('Jumlah kasbon (Rp)')->placeholder('Contoh: 1200000')->numeric()->minValue(1)->required()->helperText('Uang muka gaji yang dipinjam karyawan. Dicatat sebagai piutang karyawan.'),
                    TextInput::make('installment')->label('Cicilan per bulan')->numeric()->minValue(1)->required()->helperText('Dipotong otomatis dari gaji tiap bulan sampai lunas. Maksimal 50% dari gaji bersih.'),
                    Select::make('method')->label('Dibayar')->options(['kas' => 'Tunai', 'bank' => 'Transfer'])->default('kas')->required(),
                ])->action(fn (array $data, Employee $record) => self::run(fn ($u) => app(HrService::class)->giveAdvance($u, $record, $data['date'], (int) $data['amount'], (int) $data['installment'], $data['method']), 'Kasbon dicatat')),
            ]);
    }
}
