<?php

namespace App\Filament\Resources\Branches\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class BranchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code')->label('Kode cabang'),
                TextEntry::make('name')->label('Nama cabang'),
                TextEntry::make('lat')->label('Garis lintang (latitude)')->placeholder('Belum diisi, absen belum bisa dipakai'),
                TextEntry::make('lng')->label('Garis bujur (longitude)')->placeholder('Belum diisi, absen belum bisa dipakai'),
                TextEntry::make('geofence_m')->label('Jarak maksimal absen dari toko')->suffix(' meter')->placeholder('-'),
                TextEntry::make('created_at')->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextEntry::make('updated_at')->label('Terakhir diubah')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
            ]);
    }
}
