<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Payroll\HrService;
use App\Domain\Payroll\PayrollService;
use App\Models\Branch;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class Payroll extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Gaji karyawan';

    protected static ?string $title = 'Gaji karyawan';

    public function getSubheading(): ?string
    {
        return 'Langkah: 1) pastikan absen bulan itu sudah diisi untuk semua karyawan, 2) tekan Hitung gaji bulan ini, 3) orang lain menyetujui, lalu 4) catat pembayaran. Yang menghitung gaji tidak boleh menyetujuinya sendiri.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && ($u->can('manage-hr') || $u->can('approve-payroll'));
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

    public function table(Table $table): Table
    {
        $rp = fn ($s) => 'Rp'.number_format((int) $s, 0, ',', '.');

        return $table
            ->query(fn () => PayrollRun::query()->with(['branch', 'slips'])->orderByDesc('year')->orderByDesc('month'))
            ->emptyStateHeading('Belum ada gaji yang dihitung')
            ->emptyStateDescription('Tekan Hitung gaji bulan ini. Pastikan absen bulan itu sudah diisi untuk semua karyawan.')
            ->columns([
                TextColumn::make('period')->label('Periode')->state(fn ($record) => sprintf('%02d/%04d', $record->month, $record->year)),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => ['draft' => 'Konsep (belum disetujui)', 'disetujui' => 'Disetujui, siap dibayar', 'dibayar' => 'Sudah dibayar', 'batal' => 'Dibatalkan'][$state] ?? $state)
                    ->color(fn ($state) => ['draft' => 'gray', 'disetujui' => 'warning', 'dibayar' => 'success', 'batal' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('income')->label('Gaji kotor')->state(fn ($record) => $rp($record->slips->sum('income'))),
                TextColumn::make('pph')->label('Pajak penghasilan (PPh 21)')->state(fn ($record) => $rp($record->slips->sum('pph21'))),
                TextColumn::make('net')->label('Gaji bersih')->state(fn ($record) => $rp($record->slips->sum('net_pay'))),
            ])
            ->headerActions([
                Action::make('hitung')->label('Hitung gaji bulan ini')->modalSubmitActionLabel('Hitung gaji')->schema([
                    Select::make('branch_id')->label('Cabang')->placeholder('Pilih cabang')->options(fn () => Branch::pluck('name', 'id'))->required()->helperText('Gaji dihitung untuk semua karyawan yang masih bekerja di cabang ini.'),
                    Select::make('month')->label('Bulan gaji')->options(self::months())->default((int) now()->month)->required()->helperText('Absen bulan itu harus sudah diisi untuk semua karyawan.'),
                    TextInput::make('year')->label('Tahun gaji')->placeholder('Contoh: '.now()->year)->numeric()->default((int) now()->year)->required(),
                ])->action(fn (array $data) => self::run(fn ($u) => app(PayrollService::class)->generate($u, (int) $data['branch_id'], (int) $data['year'], (int) $data['month']), 'Gaji sudah dihitung. Minta orang lain menyetujui.')),
            ])
            ->recordActions([
                Action::make('slip')->label('Slip gaji')->url(fn ($record) => route('slip', $record->id))->openUrlInNewTab(),
                Action::make('setujui')->label('Setujui')->requiresConfirmation()->modalHeading('Setujui gaji ini?')->modalDescription('Setelah disetujui, gaji dicatat di pembukuan dan absen bulan itu terkunci.')->modalSubmitActionLabel('Ya, setujui')->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('approve-payroll'))
                    ->schema([DatePicker::make('date')->label('Tanggal pencatatan')->helperText('Tanggal gaji ini dicatat di pembukuan.')->default(now())->required()])
                    ->action(fn (array $data, $record) => self::run(fn ($u) => app(PayrollService::class)->approve($u, $record, $data['date']), 'Gaji disetujui dan sudah dicatat di pembukuan')),
                Action::make('bayar')->label('Bayar')->requiresConfirmation()->modalHeading('Catat pembayaran gaji?')->modalDescription('Pastikan uang gaji sudah benar-benar dibayarkan ke karyawan. Setelah dicatat, gaji tidak bisa dibatalkan.')->modalSubmitActionLabel('Ya, sudah dibayar')->visible(fn ($record) => $record->status === 'disetujui' && auth()->user()->can('pay-payroll'))
                    ->schema([
                        DatePicker::make('date')->label('Tanggal bayar')->helperText('Tanggal uang gaji dibayarkan.')->default(now())->required(),
                        Select::make('bank_source')->label('Transfer gaji dari rekening mana?')->options(fn () => MoneyAccounts::options())->default('bank')->required()->helperText('Rekening yang dipakai untuk transfer gaji, setoran BPJS, dan pajak.'),
                        Select::make('cash_source')->label('Gaji tunai diambil dari kas mana?')->options(fn () => MoneyAccounts::options())->default('kas')->required()->helperText('Dipakai kalau ada karyawan yang gajinya dibayar tunai.'),
                    ])
                    ->action(fn (array $data, $record) => self::run(fn ($u) => app(PayrollService::class)->pay($u, $record, $data['date'], $data['bank_source'] ?? 'bank', $data['cash_source'] ?? 'kas'), 'Pembayaran gaji dicatat')),
                Action::make('batal')->label('Batalkan')->color('danger')->requiresConfirmation()->modalHeading('Batalkan gaji ini?')->modalDescription('Gaji akan dibatalkan dan catatannya di pembukuan dibalik. Setelah itu absen bulan itu bisa diubah dan gaji bisa dihitung ulang.')->modalSubmitActionLabel('Ya, batalkan')->visible(fn ($record) => in_array($record->status, ['draft', 'disetujui']) && auth()->user()->can('approve-payroll'))
                    ->schema([DatePicker::make('date')->label('Tanggal pembatalan')->helperText('Tanggal pembatalan dicatat di pembukuan.')->default(now())->required()])
                    ->action(fn (array $data, $record) => self::run(fn ($u) => app(PayrollService::class)->cancel($u, $record, $data['date']), 'Gaji dibatalkan')),
            ]);
    }
}
