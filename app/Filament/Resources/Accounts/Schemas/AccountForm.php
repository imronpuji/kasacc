<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use App\Models\JournalLine;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountForm
{
    /** Jenis akun: [angka awal kode, nama di daftar, penjelasan singkat]. */
    public const TYPES = [
        'money' => ['1', 'Kas atau rekening bank', 'Tempat uang usaha disimpan. Contoh: Bank BCA, Bank BRI, kas toko cabang 2. Akan muncul sebagai pilihan bayar di kasir, servis, dan biaya.'],
        'fixed' => ['1', 'Harta tahan lama', 'Dipakai bertahun-tahun, bukan untuk dijual. Contoh: tanah, bangunan, kendaraan, mesin, peralatan toko.'],
        'asset' => ['1', 'Harta lain milik usaha', 'Contoh: uang yang akan diterima dari pihak lain.'],
        'liability' => ['2', 'Utang', 'Uang yang harus dibayar usaha ke pihak lain. Contoh: utang ke pemasok.'],
        'equity' => ['3', 'Modal', 'Uang pemilik yang ditanam di usaha.'],
        'revenue' => ['4', 'Pemasukan', 'Uang yang masuk dari jualan atau jasa servis.'],
        'cogs' => ['5', 'Modal barang terjual', 'Harga beli barang yang sudah terjual.'],
        'expense' => ['6', 'Pengeluaran usaha', 'Contoh: gaji, sewa toko, listrik, kebersihan.'],
        'other_expense' => ['8', 'Pengeluaran lain-lain', 'Contoh: biaya admin bank, pajak usaha.'],
    ];

    /** Jenis yang boleh dipilih saat membuat akun baru (modal barang terjual diatur otomatis oleh sistem). */
    public static function typeOptions(): array
    {
        return collect(self::TYPES)->except('cogs')->map(fn ($t) => $t[1])->all();
    }

    public static function label(?string $type): string
    {
        return self::TYPES[$type][1] ?? (string) $type;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (string $operation): string => $operation === 'edit' ? 'Ubah akun' : 'Tambah akun')
                ->description('Akun adalah nama untuk mencatat ke mana uang masuk atau keluar, misalnya "Biaya kebersihan". Akun bawaan biasanya sudah cukup, tambah hanya kalau memang perlu.')
                ->columns(1)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label('Nama akun')
                        ->placeholder('Contoh: Biaya kebersihan')
                        ->required()
                        ->maxLength(100)
                        ->helperText('Tulis nama yang mudah dipahami.'),
                    Select::make('type')
                        ->label('Ini termasuk yang mana?')
                        ->options(fn (?Account $record) => $record?->type === 'cogs' ? array_merge(self::typeOptions(), ['cogs' => self::label('cogs')]) : self::typeOptions())
                        ->required()
                        ->live()
                        ->native(false)
                        ->placeholder('Pilih salah satu')
                        ->disabled(fn (?Account $record) => $record && JournalLine::where('account_id', $record->id)->exists())
                        ->dehydrated()
                        ->helperText(fn ($state, ?Account $record) => $record && JournalLine::where('account_id', $record->id)->exists()
                            ? 'Tidak bisa diubah karena akun ini sudah dipakai di transaksi.'
                            : (self::TYPES[$state][2] ?? 'Pilih yang paling cocok dengan fungsi akun ini.')),
                    Toggle::make('is_active')
                        ->label('Masih dipakai')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Matikan kalau akun tidak dipakai lagi. Akun yang sudah punya transaksi tidak bisa dihapus, hanya dimatikan.'),
                ]),
        ]);
    }
}
