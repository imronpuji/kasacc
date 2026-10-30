<?php

namespace App\Filament\Resources\Branches\Schemas;

use App\Support\MapsLink;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->label('Kode cabang')->placeholder('Contoh: PUSAT')->required()
                    ->maxLength(10)->alphaDash()->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state)))
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->validationMessages([
                        'unique' => 'Kode ini sudah dipakai cabang lain. Pakai singkatan yang berbeda.',
                        'alpha_dash' => 'Kode hanya boleh huruf, angka, strip (-) atau garis bawah (_), tanpa spasi.',
                        'max' => 'Kode terlalu panjang. Pakai singkatan maksimal 10 karakter.',
                    ])
                    ->helperText('Singkatan pendek tanpa spasi, maksimal 10 karakter, otomatis huruf besar. Dipakai di nomor struk dan nomor servis.'),
                TextInput::make('name')->label('Nama cabang')->placeholder('Contoh: Cabang Pusat')->required()->maxLength(100)
                    ->helperText('Nama yang tampil di laporan dan struk.'),
                Section::make('Lokasi toko untuk absen karyawan')
                    ->description('Karyawan hanya bisa absen kalau HP mereka berada dekat titik ini. Isi sekali saja, lalu simpan.')
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.forms.branch-geo')->columnSpanFull(),
                        TextInput::make('maps_link')->label('Tempel link atau koordinat dari Google Maps')->columnSpanFull()->dehydrated(false)
                            ->placeholder('Contoh: -6.200000, 106.816666  atau  https://maps.app.goo.gl/...')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set) {
                                if (trim((string) $state) === '') {
                                    return;
                                }
                                $c = MapsLink::coordinates($state);
                                if ($c) {
                                    $set('lat', $c[0]);
                                    $set('lng', $c[1]);
                                    Notification::make()->title('Koordinat terisi dari Google Maps')->body('Periksa titik di peta pratinjau, lalu klik Simpan cabang.')->success()->send();
                                } else {
                                    Notification::make()->title('Koordinat tidak terbaca')->body('Salin dua angka koordinat dari Google Maps (contoh -6.200000, 106.816666) atau tempel link Bagikan.')->warning()->send();
                                }
                            })
                            ->helperText('Di Google Maps: cari atau tekan lama titik toko, lalu salin angka koordinat yang muncul, atau pilih Bagikan dan salin link. Tempel di sini, kolom lintang dan bujur terisi sendiri.'),
                        TextInput::make('lat')->label('Garis lintang (latitude)')->placeholder('Contoh: -6.200000')->numeric()->minValue(-90)->maxValue(90)
                            ->helperText('Isi bersama garis bujur. Tanpa lokasi toko, absen karyawan tidak bisa berjalan. Kota di Indonesia biasanya bernilai negatif, misalnya -6.200000.'),
                        TextInput::make('lng')->label('Garis bujur (longitude)')->placeholder('Contoh: 106.816666')->numeric()->minValue(-180)->maxValue(180)
                            ->helperText('Tanpa lokasi toko, absen karyawan tidak bisa berjalan. Biasanya antara 95 dan 141 untuk Indonesia.'),
                        TextInput::make('geofence_m')->label('Jarak maksimal dari toko (meter)')->numeric()->integer()->minValue(20)->maxValue(2000)->default(100)->required()
                            ->helperText('Isi 100 artinya karyawan harus berada dalam radius 100 meter dari titik toko. Terlalu kecil bisa menggagalkan absen karena GPS HP kurang tepat, 100 sampai 150 meter cukup aman.'),
                    ])->columns(2),
            ]);
    }
}
