<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class RecordFinance extends Page
{
    protected string $view = 'filament.pages.record-finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Modal, pinjaman, dan harta';

    protected static ?string $title = 'Modal, pinjaman, dan harta';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Pilih yang sesuai dengan kejadian uangnya. Contoh: pemilik menambah modal, pinjam ke bank, atau beli tanah.';
    }

    /** Kartu yang ditampilkan: [nama aksi, judul, penjelasan]. */
    public function cards(): array
    {
        return [
            ['tambahModal', 'Tambah modal', 'Pemilik memasukkan uang pribadi ke usaha.'],
            ['ambilUang', 'Pemilik ambil uang', 'Pemilik mengambil uang usaha untuk keperluan pribadi. Bukan biaya.'],
            ['pindahUang', 'Pindah uang antar kas atau bank', 'Contoh: tarik tunai dari BCA ke laci kas.'],
            ['terimaPinjaman', 'Terima pinjaman', 'Uang masuk dari pinjaman bank atau orang lain.'],
            ['bayarCicilan', 'Bayar cicilan pinjaman', 'Cicilan pokok dan bunga pinjaman.'],
            ['beliHarta', 'Beli harta tahan lama', 'Tanah, bangunan, kendaraan, mesin, atau peralatan.'],
            ['susutHarta', 'Catat penyusutan', 'Nilai kendaraan atau peralatan turun karena dipakai.'],
            ['uangMasukLain', 'Uang masuk lainnya', 'Misalnya bunga bank. Bukan dari jualan atau servis.'],
        ];
    }

    private function common(bool $withMethod = true, string $methodLabel = 'Lewat mana uangnya?', array $methodExtra = []): array
    {
        $f = [
            TextInput::make('amount')->label('Berapa rupiah?')->numeric()->minValue(1)->required()->prefix('Rp')->placeholder('1000000')->helperText('Angka saja, tanpa titik. Contoh: 1000000.'),
        ];
        if ($withMethod) {
            $f[] = Select::make('method')->label($methodLabel)->required()->native(false)->default('kas')
                ->options(fn () => MoneyAccounts::options($methodExtra))->helperText('Pilih kas atau rekening bank. Rekening baru ditambah di Daftar akun.');
        }

        return array_merge($f, [
            DatePicker::make('date')->label('Tanggal')->default(now(config('kasir.timezone')))->required(),
            Select::make('branch_id')->label('Untuk cabang mana?')->required()->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))->default(fn () => Branch::orderBy('id')->value('id')),
            Textarea::make('note')->label('Catatan (boleh dikosongkan)')->rows(2)->maxLength(200)->autocomplete(false),
        ]);
    }

    private function aksi(string $name, string $label, string $help, array $schema, \Closure $run, string $done): Action
    {
        return Action::make($name)->label($label)->modalHeading($label)->modalDescription($help)->modalSubmitActionLabel('Simpan')
            ->schema($schema)
            ->action(function (array $data) use ($run, $done) {
                try {
                    $run(app(Transactions::class), $data);
                    Notification::make()->title('Tercatat')->body($done)->success()->send();
                } catch (\DomainException $e) {
                    Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Belum berhasil')->body('Belum tersimpan. Coba lagi. Kalau masih gagal, hubungi admin atau teknisi aplikasi.')->danger()->send();
                }
            });
    }

    public function tambahModalAction(): Action
    {
        return $this->aksi('tambahModal', 'Tambah modal', 'Uang pemilik yang dimasukkan ke usaha. Ini bukan pemasukan dari jualan, jadi tidak masuk hitungan untung.',
            $this->common(true, 'Uangnya masuk ke mana?'),
            fn ($t, $d) => $t->depositCapital((int) $d['branch_id'], $d['date'], (int) $d['amount'], $d['method']), 'Modal sudah ditambahkan.');
    }

    public function ambilUangAction(): Action
    {
        return $this->aksi('ambilUang', 'Pemilik ambil uang', 'Uang usaha yang diambil pemilik untuk keperluan pribadi. Ini bukan biaya, jadi tidak mengurangi untung, tapi mengurangi modal.',
            $this->common(true, 'Uang diambil dari mana?'),
            fn ($t, $d) => $t->ownerWithdrawal((int) $d['branch_id'], $d['date'], (int) $d['amount'], $d['method'], (string) ($d['note'] ?? '')), 'Pengambilan uang pemilik sudah dicatat.');
    }

    public function pindahUangAction(): Action
    {
        $opts = fn () => MoneyAccounts::options();

        return $this->aksi('pindahUang', 'Pindah uang antar kas atau bank', 'Uang hanya berpindah tempat, jumlah uang usaha tidak berubah.', [
            Select::make('from')->label('Uang diambil dari')->required()->native(false)->options($opts)->default('bank'),
            Select::make('to')->label('Dipindah ke')->required()->native(false)->options($opts)->default('kas'),
            TextInput::make('amount')->label('Berapa rupiah?')->numeric()->minValue(1)->required()->prefix('Rp')->helperText('Angka saja, tanpa titik.'),
            DatePicker::make('date')->label('Tanggal')->default(now(config('kasir.timezone')))->required(),
            Select::make('branch_id')->label('Untuk cabang mana?')->required()->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))->default(fn () => Branch::orderBy('id')->value('id')),
        ], fn ($t, $d) => $t->transferCash((int) $d['branch_id'], $d['date'], (int) $d['amount'], $d['from'], $d['to']), 'Uang sudah dipindahkan.');
    }

    public function terimaPinjamanAction(): Action
    {
        return $this->aksi('terimaPinjaman', 'Terima pinjaman', 'Uang pinjaman bukan pemasukan dari jualan. Aplikasi mencatatnya sebagai utang pinjaman yang harus dikembalikan.',
            $this->common(true, 'Uang pinjaman masuk ke mana?'),
            fn ($t, $d) => $t->receiveLoan((int) $d['branch_id'], $d['date'], (int) $d['amount'], $d['method'], (string) ($d['note'] ?? '')), 'Pinjaman sudah dicatat sebagai utang.');
    }

    public function bayarCicilanAction(): Action
    {
        return $this->aksi('bayarCicilan', 'Bayar cicilan pinjaman', 'Pokok mengurangi utang pinjaman. Bunga dicatat sebagai biaya. Isi 0 kalau tidak ada bunga.', [
            TextInput::make('principal')->label('Cicilan pokok (Rp)')->numeric()->minValue(0)->required()->prefix('Rp')->helperText('Bagian yang mengurangi utang pinjaman.'),
            TextInput::make('interest')->label('Bunga (Rp)')->numeric()->minValue(0)->default(0)->required()->prefix('Rp')->helperText('Bagian bunga di tagihan cicilan. Isi 0 kalau tidak ada.'),
            Select::make('method')->label('Dibayar dari mana?')->required()->native(false)->default('bank')->options(fn () => MoneyAccounts::options()),
            DatePicker::make('date')->label('Tanggal bayar')->default(now(config('kasir.timezone')))->required(),
            Select::make('branch_id')->label('Untuk cabang mana?')->required()->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))->default(fn () => Branch::orderBy('id')->value('id')),
            Textarea::make('note')->label('Catatan (boleh dikosongkan)')->rows(2)->maxLength(200)->autocomplete(false),
        ], fn ($t, $d) => $t->repayLoan((int) $d['branch_id'], $d['date'], (int) $d['principal'], (int) $d['interest'], $d['method'], (string) ($d['note'] ?? '')), 'Cicilan pinjaman sudah dicatat.');
    }

    public function beliHartaAction(): Action
    {
        return $this->aksi('beliHarta', 'Beli harta tahan lama', 'Tanah, bangunan, kendaraan, mesin, atau peralatan. Ini bukan biaya: nilainya masuk sebagai harta usaha, jadi untung bulan ini tidak berkurang.', array_merge([
            Select::make('asset')->label('Harta apa?')->required()->native(false)->placeholder('Pilih jenis harta')
                ->options(fn () => Account::where('type', 'asset')->where('is_active', true)->where('code', '>=', '1-2000')->where('code', '<', '1-2900')->orderBy('code')->pluck('name', 'code'))
                ->helperText('Tidak ada yang cocok? Tambahkan di Daftar akun, pilih "Harta tahan lama".'),
        ], $this->common(true, 'Dibayar dari mana?', ['kredit' => 'Belum dibayar (jadi utang)'])),
            fn ($t, $d) => $t->buyAsset((int) $d['branch_id'], $d['date'], $d['asset'], (int) $d['amount'], $d['method'], (string) ($d['note'] ?? '')), 'Pembelian harta sudah dicatat.');
    }

    public function susutHartaAction(): Action
    {
        return $this->aksi('susutHarta', 'Catat penyusutan', 'Kendaraan dan peralatan makin lama makin turun nilainya. Tidak ada uang yang keluar. Jumlah ini menjadi biaya bulan ini dan mengurangi nilai harta. Tanah tidak disusutkan. Tanyakan konsultan pajak berapa jumlahnya.',
            $this->common(false),
            fn ($t, $d) => $t->depreciate((int) $d['branch_id'], $d['date'], (int) $d['amount'], (string) ($d['note'] ?? '')), 'Penyusutan sudah dicatat.');
    }

    public function uangMasukLainAction(): Action
    {
        return $this->aksi('uangMasukLain', 'Uang masuk lainnya', 'Uang masuk di luar jualan dan servis, misalnya bunga bank. Masuk ke laporan untung rugi sebagai pendapatan lain.',
            $this->common(true, 'Uangnya masuk ke mana?'),
            fn ($t, $d) => $t->otherIncome((int) $d['branch_id'], $d['date'], (int) $d['amount'], $d['method'], (string) ($d['note'] ?? '')), 'Uang masuk sudah dicatat.');
    }
}
