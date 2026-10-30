<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public const KINDS = ['sparepart' => 'Sparepart', 'aksesoris' => 'Aksesoris'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Data barang')
                ->description('Barang yang diisi di sini akan muncul di menu Kasir dan saat servis memakai sparepart. Stok awal diisi lewat menu Stok dan pembelian, bukan di sini.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label('Nama barang')->placeholder('Contoh: LCD iPhone 13')->required()->maxLength(120)->columnSpanFull()
                        ->helperText('Nama yang tampil di kasir dan struk.'),
                    TextInput::make('sku')->label('Kode barang (SKU)')->placeholder('Contoh: LCD13')->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(40)
                        ->validationMessages(['unique' => 'Kode ini sudah dipakai barang lain.', 'alpha_dash' => 'Kode hanya boleh huruf, angka, strip (-) atau garis bawah (_), tanpa spasi.'])
                        ->helperText('Kode singkat buatan Anda sendiri, tanpa spasi. Tidak boleh sama dengan barang lain.'),
                    Select::make('kind')->label('Jenis barang')->options(self::KINDS)->default('sparepart')->required()->native(false)
                        ->helperText('Sparepart untuk suku cadang servis, aksesoris untuk barang yang dijual langsung.'),
                    TextInput::make('sale_price')->label('Harga jual')->prefix('Rp')->numeric()->integer()->minValue(0)->default(0)->required()
                        ->helperText('Harga yang dibayar pelanggan per satu barang. Barang dengan harga 0 tidak muncul di kasir.'),
                    TextInput::make('barcode')->label('Barcode (boleh dikosongkan)')->placeholder('Scan atau ketik kode barcode')->maxLength(40)->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Barcode ini sudah dipakai barang lain.'])
                        ->helperText('Isi kalau barang punya barcode, supaya bisa di-scan di kasir.'),
                ]),
        ]);
    }
}
