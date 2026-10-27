<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\TaxSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PengaturanPajak extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-pajak';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Pengaturan pajak dan biaya';

    protected static ?string $title = 'Pengaturan Pajak dan Biaya';

    protected static string|\UnitEnum|null $navigationGroup = 'Data master';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('approve-payroll') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'entity' => TaxSettings::entity(),
            'regime' => TaxSettings::regime(),
            'final_pct' => (int) TaxSettings::get('final_bps', 50) / 100,
            'badan_pct' => (int) TaxSettings::get('badan_bps', 2200) / 100,
            'exempt' => (int) TaxSettings::get('exempt', 0),
            'start_year' => TaxSettings::get('start_year') ?: null,
            'max_years' => (int) TaxSettings::get('max_years', 4),
            'qris_pct' => TaxSettings::qrisBps() / 100,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Bentuk usaha dan cara hitung pajak')->description('Pilih bentuk usaha, isian di bawah terisi otomatis sesuai aturan umum. Boleh diubah kalau konsultan pajak menyarankan lain.')->schema([
                Select::make('entity')->label('Bentuk usaha')->options(TaxSettings::ENTITIES)->required()->live()
                    ->afterStateUpdated(function ($state, $set) {
                        $p = TaxSettings::PRESETS[$state] ?? null;
                        if ($p) {
                            $set('regime', $p['regime']);
                            $set('final_pct', $p['final_bps'] / 100);
                            $set('badan_pct', $p['badan_bps'] / 100);
                            $set('exempt', $p['exempt']);
                            $set('max_years', $p['max_years']);
                        }
                    }),
                Select::make('regime')->label('Cara hitung pajak')->options(TaxSettings::REGIMES)->required()->live(),
                TextInput::make('final_pct')->label('Tarif PPh final (% dari omzet)')->numeric()->step(0.01)->minValue(0)->maxValue(10)->suffix('%')
                    ->visible(fn ($get) => $get('regime') === 'final')->helperText('Umumnya 0,5. Contoh: omzet Rp 100 juta, pajak Rp 500 ribu.'),
                TextInput::make('badan_pct')->label('Tarif PPh badan (% dari laba bersih)')->numeric()->step(0.01)->minValue(0)->maxValue(50)->suffix('%')
                    ->visible(fn ($get) => $get('regime') === 'badan')->helperText('Umumnya 22. Dihitung dari laba bersih bulan itu sebagai perkiraan, angka pasti ditentukan konsultan pajak saat laporan tahunan.'),
                TextInput::make('exempt')->label('Omzet setahun yang bebas pajak (Rp)')->numeric()->minValue(0)->prefix('Rp')
                    ->visible(fn ($get) => $get('regime') === 'final')->helperText('Isi 0 kalau tidak ada. Usaha perorangan umumnya 500.000.000.'),
                TextInput::make('start_year')->label('Tahun mulai memakai tarif final')->numeric()->minValue(2018)->maxValue(2100)
                    ->visible(fn ($get) => $get('regime') === 'final')->helperText('Opsional. Dipakai untuk mengingatkan kalau masa tarif final hampir habis.'),
                TextInput::make('max_years')->label('Lama masa tarif final (tahun)')->numeric()->minValue(1)->maxValue(20)
                    ->visible(fn ($get) => $get('regime') === 'final')->helperText('PT 3 tahun, CV/Firma 4 tahun, perorangan 7 tahun (aturan umum).'),
            ])->columns(2),
            Section::make('Biaya pembayaran')->schema([
                TextInput::make('qris_pct')->label('Biaya QRIS (MDR)')->numeric()->step(0.01)->minValue(0)->maxValue(5)->required()->suffix('%')
                    ->helperText('Potongan dari penyedia QRIS tiap pembayaran, misalnya 0,7. Berlaku untuk transaksi baru, transaksi lama tidak berubah.'),
            ]),
        ]);
    }

    public function save(): void
    {
        $this->form->getState(); // validasi
        $d = $this->data;
        TaxSettings::set('entity', $d['entity']);
        TaxSettings::set('regime', $d['regime']);
        TaxSettings::set('final_bps', (int) round(((float) ($d['final_pct'] ?? 0.5)) * 100));
        TaxSettings::set('badan_bps', (int) round(((float) ($d['badan_pct'] ?? 22)) * 100));
        TaxSettings::set('exempt', (int) ($d['exempt'] ?? 0));
        TaxSettings::set('start_year', (int) ($d['start_year'] ?? 0));
        TaxSettings::set('max_years', (int) ($d['max_years'] ?? 4));
        TaxSettings::setQrisBps((int) round(((float) $d['qris_pct']) * 100));
        Notification::make()->title('Pengaturan disimpan')->success()->send();
    }
}
