<?php

namespace App\Filament\Pages;

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

class Payroll extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?string $title = 'Payroll (Penggajian)';

    public function getSubheading(): ?string
    {
        return 'Urutan: Hitung payroll (draft) → orang lain menyetujui → Bayar. Pembuat payroll tidak boleh menyetujui sendiri. Pastikan absensi bulan itu sudah diisi untuk semua karyawan.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'SDM';

    protected static ?int $navigationSort = 2;

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
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        $rp = fn ($s) => 'Rp'.number_format((int) $s, 0, ',', '.');

        return $table
            ->query(fn () => PayrollRun::query()->with(['branch', 'slips'])->orderByDesc('year')->orderByDesc('month'))
            ->emptyStateHeading('Belum ada payroll')
            ->emptyStateDescription('Klik Hitung payroll untuk membuat draft gaji bulan ini.')
            ->columns([
                TextColumn::make('period')->label('Periode')->state(fn ($record) => sprintf('%02d/%04d', $record->month, $record->year)),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('income')->label('Pendapatan')->state(fn ($record) => $rp($record->slips->sum('income'))),
                TextColumn::make('pph')->label('PPh 21')->state(fn ($record) => $rp($record->slips->sum('pph21'))),
                TextColumn::make('net')->label('Gaji bersih')->state(fn ($record) => $rp($record->slips->sum('net_pay'))),
            ])
            ->headerActions([
                Action::make('hitung')->label('Hitung payroll')->schema([
                    Select::make('branch_id')->label('Cabang')->options(fn () => Branch::pluck('name', 'id'))->required(),
                    TextInput::make('year')->label('Tahun gaji')->numeric()->default((int) now()->year)->required()->helperText('Pilih bulan yang gajinya mau dihitung. Absensi bulan itu harus sudah diisi untuk semua karyawan.'),
                    TextInput::make('month')->label('Bulan gaji (1-12)')->numeric()->minValue(1)->maxValue(12)->default((int) now()->month)->required(),
                ])->action(fn (array $data) => self::run(fn ($u) => app(PayrollService::class)->generate($u, (int) $data['branch_id'], (int) $data['year'], (int) $data['month']), 'Payroll dihitung (draft)')),
            ])
            ->recordActions([
                Action::make('slip')->label('Slip gaji')->url(fn ($record) => route('slip', $record->id))->openUrlInNewTab(),
                Action::make('setujui')->label('Setujui')->requiresConfirmation()->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('approve-payroll'))
                    ->schema([DatePicker::make('date')->label('Tanggal jurnal')->default(now())->required()])
                    ->action(fn (array $data, $record) => self::run(fn ($u) => app(PayrollService::class)->approve($u, $record, $data['date']), 'Payroll disetujui dan dijurnal')),
                Action::make('bayar')->label('Bayar')->requiresConfirmation()->visible(fn ($record) => $record->status === 'disetujui' && auth()->user()->can('pay-payroll'))
                    ->schema([DatePicker::make('date')->label('Tanggal bayar')->default(now())->required()])
                    ->action(fn (array $data, $record) => self::run(fn ($u) => app(PayrollService::class)->pay($u, $record, $data['date']), 'Pembayaran dicatat')),
                Action::make('batal')->label('Batalkan')->color('danger')->requiresConfirmation()->visible(fn ($record) => in_array($record->status, ['draft', 'disetujui']) && auth()->user()->can('approve-payroll'))
                    ->schema([DatePicker::make('date')->label('Tanggal pembalikan')->default(now())->required()])
                    ->action(fn (array $data, $record) => self::run(fn ($u) => app(PayrollService::class)->cancel($u, $record, $data['date']), 'Payroll dibatalkan')),
            ]);
    }
}
