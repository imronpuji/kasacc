<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\FinalTaxService;
use App\Domain\Accounting\TaxSettings;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class Tax extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.tax';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Pajak';

    protected static ?string $title = 'Pajak';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 4;

    public string $bulan = '';

    public function mount(): void
    {
        $this->bulan = now(config('kasir.timezone'))->subMonthNoOverflow()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && ($u->can('approve-payroll') || $u->can('pay-payroll'));
    }

    public function getSubheading(): ?string
    {
        $pct = rtrim(rtrim(number_format(TaxSettings::bps() / 100, 2, ',', ''), '0'), ',');
        $t = match (TaxSettings::regime()) {
            'none' => 'Pajak tidak dihitung di aplikasi. Kalau mau diubah, buka menu Pengaturan pajak dan biaya. ',
            'badan' => "Pajak penghasilan usaha {$pct}% dari untung bersih bulan terpilih (disebut PPh badan). Angkanya perkiraan. ",
            default => "Pajak penghasilan usaha {$pct}% dari total penjualan bulan terpilih (disebut PPh final). ",
        };

        return $t.(TaxSettings::expiryNote() ? TaxSettings::expiryNote().' ' : '').'Bulan terpilih: '.\Carbon\CarbonImmutable::parse($this->bulan.'-01')->translatedFormat('F Y').'.'.(TaxSettings::regime() === 'none' ? '' : ' Pajak dibayar paling lambat tanggal 15 bulan berikutnya.');
    }

    private function ym(): array
    {
        return [(int) substr($this->bulan, 0, 4), (int) substr($this->bulan, 5, 2)];
    }

    protected function getHeaderActions(): array
    {
        $names = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $thisYear = (int) now(config('kasir.timezone'))->format('Y');

        return [
            Action::make('bulan')->label('Ganti bulan pajak')->color('gray')->modalSubmitActionLabel('Tampilkan')
                ->fillForm(fn () => ['month' => (int) substr($this->bulan, 5, 2), 'year' => (int) substr($this->bulan, 0, 4)])
                ->schema([
                    Select::make('month')->label('Bulan pajak')->options(array_combine(range(1, 12), $names))->required()->helperText('Contoh: November.'),
                    Select::make('year')->label('Tahun')->options(array_combine(range($thisYear, $thisYear - 6), range($thisYear, $thisYear - 6)))->required()->helperText('Contoh: '.$thisYear.'.'),
                ])
                ->action(fn (array $data) => $this->bulan = sprintf('%04d-%02d', (int) $data['year'], (int) $data['month'])),
        ];
    }

    private function fail(\Throwable $e): void
    {
        if ($e instanceof \DomainException) {
            $body = $e->getMessage();
        } else {
            report($e);
            $body = 'Terjadi gangguan. Coba lagi sebentar lagi. Kalau masih gagal, hubungi admin atau teknisi aplikasi.';
        }
        Notification::make()->title('Belum berhasil')->body($body)->danger()->send();
    }

    public function table(Table $table): Table
    {
        $svc = app(FinalTaxService::class);

        return $table
            ->query(fn () => Branch::query())
            ->emptyStateHeading('Belum ada cabang')->emptyStateDescription('Tambahkan cabang dulu di menu Cabang.')
            ->columns([
                TextColumn::make('name')->label('Cabang'),
                TextColumn::make('omzet')->label('Total penjualan bulan terpilih')->state(fn ($record) => 'Rp '.number_format($svc->turnover($record->id, ...$this->ym()), 0, ',', '.')),
                TextColumn::make('pajak')->label('Pajak yang harus dibayar')->state(fn ($record) => 'Rp '.number_format($svc->due($record->id, ...$this->ym()), 0, ',', '.')),
                TextColumn::make('status')->label('Status')->badge()->state(function ($record) use ($svc) {
                    [$y, $m] = $this->ym();

                    return $svc->paid($record, $y, $m) ? 'Sudah dibayar' : ($svc->accrued($record, $y, $m) ? 'Belum dibayar' : (TaxSettings::regime() === 'none' ? 'Tidak dihitung' : 'Belum dihitung'));
                })->color(fn ($state) => match ($state) {
                    'Sudah dibayar' => 'success',
                    'Belum dibayar' => 'warning',
                    default => 'gray',
                }),
            ])
            ->recordActions([
                Action::make('catat')->label('Hitung dan catat pajak')->requiresConfirmation()
                    ->modalHeading('Hitung dan catat pajak?')
                    ->modalDescription('Aplikasi menghitung pajak bulan terpilih dan mencatatnya sebagai utang pajak. Ini cukup dilakukan sekali per cabang per bulan.')
                    ->visible(fn ($record) => $record && TaxSettings::regime() !== 'none' && ! $svc->accrued($record, ...$this->ym()))
                    ->action(function ($record) use ($svc) {
                        try {
                            $t = $svc->accrue(auth()->user(), $record, ...$this->ym());
                            Notification::make()->title('Pajak tercatat')->body('Pajak yang harus dibayar: Rp '.number_format($t, 0, ',', '.').'.')->success()->send();
                        } catch (\Throwable $e) {
                            $this->fail($e);
                        }
                    }),
                Action::make('setor')->label('Catat pembayaran pajak')->modalSubmitActionLabel('Catat pembayaran')
                    ->modalDescription('Pakai ini setelah pajak benar-benar Anda bayar ke negara.')
                    ->visible(fn ($record) => $record && $svc->accrued($record, ...$this->ym()) && ! $svc->paid($record, ...$this->ym()))
                    ->schema([
                        DatePicker::make('date')->label('Tanggal bayar')->default(now())->required()->helperText('Tanggal uang pajak keluar. Contoh: 15 Desember 2026.'),
                        Select::make('account')->label('Dibayar dari mana?')->options(fn () => \App\Domain\Accounting\MoneyAccounts::optionsByCode())->default('1-1110')->required()->helperText('Pilih sumber uang yang dipakai membayar pajak.'),
                    ])
                    ->action(function ($record, array $data) use ($svc) {
                        try {
                            $svc->pay(auth()->user(), $record, ...[...$this->ym(), $data['date'], $data['account']]);
                            Notification::make()->title('Pembayaran tercatat')->body('Pembayaran pajak sudah dicatat.')->success()->send();
                        } catch (\Throwable $e) {
                            $this->fail($e);
                        }
                    }),
            ]);
    }
}
