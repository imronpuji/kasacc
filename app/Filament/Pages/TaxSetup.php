<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\TaxSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use App\Domain\Payroll\PayrollSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TaxSetup extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.tax-setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Pengaturan pajak dan biaya';

    protected static ?string $title = 'Pengaturan Pajak dan Biaya';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    public ?array $data = [];

    public function getSubheading(): ?string
    {
        return 'Atur sekali saja. Tidak yakin? Tanyakan konsultan pajak.';
    }

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
            'bpjs_on' => (bool) PayrollSettings::get('bpjs_on'),
            'pph21_on' => (bool) PayrollSettings::get('pph21_on'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isFinal = fn ($get) => $get('regime') === 'final';

        return $schema->statePath('data')->components([
            Section::make('Bentuk usaha dan cara hitung pajak')->description('Pilih bentuk usaha, isian di bawah terisi otomatis sesuai aturan umum. Boleh diubah kalau konsultan pajak menyarankan lain.')->schema([
                Select::make('entity')->label('Bentuk usaha')->options(TaxSettings::ENTITIES)->required()->live()->placeholder('Pilih bentuk usaha')
                    ->helperText('Contoh: CV, PT, atau perorangan (usaha pribadi).')
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
                Select::make('regime')->label('Cara hitung pajak')->options(TaxSettings::REGIMES)->required()->live()->placeholder('Pilih cara hitung pajak')
                    ->helperText('Kebanyakan usaha kecil memakai pajak final.'),
                TextInput::make('final_pct')->label('Tarif pajak final')->numeric()->step(0.01)->minValue(0)->maxValue(10)->suffix('%')->placeholder('0,5')
                    ->visible($isFinal)->required($isFinal)->helperText('Persen dari total penjualan. Umumnya 0,5. Contoh: penjualan Rp 100 juta, pajak Rp 500 ribu.'),
                TextInput::make('badan_pct')->label('Tarif pajak badan')->numeric()->step(0.01)->minValue(0)->maxValue(50)->suffix('%')->placeholder('22')
                    ->visible(fn ($get) => $get('regime') === 'badan')->required(fn ($get) => $get('regime') === 'badan')
                    ->helperText('Persen dari untung bersih. Umumnya 22. Ini perkiraan bulanan, angka pasti ditentukan konsultan pajak saat laporan tahunan.'),
                TextInput::make('exempt')->label('Penjualan setahun yang bebas pajak')->numeric()->minValue(0)->prefix('Rp')->placeholder('0')
                    ->visible($isFinal)->required($isFinal)->helperText('Isi 0 kalau tidak ada. Usaha perorangan umumnya 500.000.000.'),
                TextInput::make('start_year')->label('Tahun mulai')->numeric()->minValue(2018)->maxValue(2100)->placeholder('2024')
                    ->visible($isFinal)->helperText('Tahun usaha mulai memakai pajak final. Boleh dikosongkan. Dipakai untuk mengingatkan kalau masa tarif final hampir habis.'),
                TextInput::make('max_years')->label('Berapa tahun')->numeric()->minValue(1)->maxValue(20)->suffix('tahun')->placeholder('4')
                    ->visible($isFinal)->required($isFinal)->helperText('Lama pajak final boleh dipakai. Aturan umum: PT 3 tahun, CV atau Firma 4 tahun, perorangan 7 tahun.'),
            ])->columns(2),
            Section::make('Gaji karyawan')->description('Usaha yang belum terdaftar bisa mematikan dua pilihan ini. Slip gaji lalu tidak memotong dan tidak mencatat kewajiban yang memang belum Anda bayar. Nyalakan lagi kalau sudah terdaftar. Berlaku untuk gaji yang dihitung berikutnya.')->schema([
                Toggle::make('bpjs_on')->label('Hitung BPJS (Kesehatan dan Ketenagakerjaan)')->helperText('Mati: gaji tidak dipotong iuran BPJS dan usaha tidak mencatat iuran BPJS yang harus dibayar.'),
                Toggle::make('pph21_on')->label('Hitung pajak penghasilan karyawan (PPh 21)')->helperText('Mati: gaji tidak dipotong pajak karyawan.'),
            ]),
            Section::make('Biaya pembayaran')->schema([
                TextInput::make('qris_pct')->label('Potongan QRIS')->numeric()->step(0.01)->minValue(0)->maxValue(5)->required()->suffix('%')->placeholder('0,7')
                    ->helperText('Potongan dari penyedia QRIS tiap pembayaran, misalnya 0,7. Berlaku untuk penjualan baru, penjualan lama tidak berubah.'),
            ]),
        ]);
    }

    public function save(): void
    {
        try {
            $d = $this->form->getState(); // validasi; kolom yang disembunyikan tidak ikut
            TaxSettings::set('entity', $d['entity']);
            TaxSettings::set('regime', $d['regime']);
            if ($d['regime'] === 'final') {
                TaxSettings::set('final_bps', (int) round(((float) $d['final_pct']) * 100));
                TaxSettings::set('exempt', (int) $d['exempt']);
                TaxSettings::set('start_year', (int) ($d['start_year'] ?? 0));
                TaxSettings::set('max_years', (int) $d['max_years']);
            } elseif ($d['regime'] === 'badan') {
                TaxSettings::set('badan_bps', (int) round(((float) $d['badan_pct']) * 100));
            }
            PayrollSettings::set('bpjs_on', ! empty($d['bpjs_on']) ? 1 : 0);
            PayrollSettings::set('pph21_on', ! empty($d['pph21_on']) ? 1 : 0);
            TaxSettings::setQrisBps((int) round(((float) $d['qris_pct']) * 100));
            Notification::make()->title('Tersimpan')->body('Pengaturan pajak sudah disimpan.')->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Pengaturan belum tersimpan. Coba lagi. Kalau masih gagal, hubungi admin atau teknisi aplikasi.')->danger()->send();
        }
    }
}
