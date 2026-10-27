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

class Pajak extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

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
        $t = TaxSettings::regime() === 'none' ? 'Pajak tidak dihitung di aplikasi (lihat Pengaturan pajak dan biaya). ' : (TaxSettings::regime() === 'badan'
            ? 'PPh badan '.rtrim(rtrim(number_format(TaxSettings::bps() / 100, 2, ',', ''), '0'), ',').'% dari laba bersih sebulan (perkiraan). '
            : 'PPh final '.rtrim(rtrim(number_format(TaxSettings::bps() / 100, 2, ',', ''), '0'), ',').'% dari omzet sebulan. ');

        return $t.(TaxSettings::expiryNote() ? TaxSettings::expiryNote().' ' : '').'Dihitung otomatis dari pembukuan. Bulan: '.\Carbon\CarbonImmutable::parse($this->bulan.'-01')->translatedFormat('F Y').'. Setor paling lambat tanggal 15 bulan berikutnya.';
    }

    private function ym(): array
    {
        return [(int) substr($this->bulan, 0, 4), (int) substr($this->bulan, 5, 2)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulan')->label('Ganti bulan')->color('gray')
                ->fillForm(fn () => ['bulan' => $this->bulan.'-01'])
                ->schema([DatePicker::make('bulan')->label('Pilih tanggal di bulan yang dimaksud')->required()])
                ->action(fn (array $data) => $this->bulan = \Carbon\CarbonImmutable::parse($data['bulan'])->format('Y-m')),
        ];
    }

    public function table(Table $table): Table
    {
        $svc = app(FinalTaxService::class);

        return $table
            ->query(fn () => Branch::query())
            ->columns([
                TextColumn::make('name')->label('Cabang'),
                TextColumn::make('omzet')->label('Omzet bulan ini')->state(fn ($record) => 'Rp '.number_format($svc->turnover($record->id, ...$this->ym()), 0, ',', '.')),
                TextColumn::make('pajak')->label('Pajak bulan ini')->state(fn ($record) => 'Rp '.number_format($svc->due($record->id, ...$this->ym()), 0, ',', '.')),
                TextColumn::make('status')->label('Status')->badge()->state(function ($record) use ($svc) {
                    [$y, $m] = $this->ym();

                    return $svc->paid($record, $y, $m) ? 'Sudah disetor' : ($svc->accrued($record, $y, $m) ? 'Belum disetor' : 'Belum dicatat');
                }),
            ])
            ->recordActions([
                Action::make('catat')->label('Catat pajak')->requiresConfirmation()
                    ->visible(fn ($record) => $record && ! $svc->accrued($record, ...$this->ym()))
                    ->action(function ($record) use ($svc) {
                        try {
                            $t = $svc->accrue(auth()->user(), $record, ...$this->ym());
                            Notification::make()->title('Pajak dicatat: Rp '.number_format($t, 0, ',', '.'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('setor')->label('Catat setoran')
                    ->visible(fn ($record) => $record && $svc->accrued($record, ...$this->ym()) && ! $svc->paid($record, ...$this->ym()))
                    ->schema([
                        DatePicker::make('date')->label('Tanggal setor')->default(now())->required(),
                        Select::make('account')->label('Dibayar dari')->options(['1-1110' => 'Bank', '1-1100' => 'Kas'])->default('1-1110')->required(),
                    ])
                    ->action(function ($record, array $data) use ($svc) {
                        try {
                            $svc->pay(auth()->user(), $record, ...[...$this->ym(), $data['date'], $data['account']]);
                            Notification::make()->title('Setoran dicatat')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
