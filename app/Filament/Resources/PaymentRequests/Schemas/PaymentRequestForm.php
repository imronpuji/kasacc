<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Domain\Accounting\OpsReportService;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ajukan pembayaran utang')
                ->description('Pengajuan ini belum membayar apa pun. Uang baru keluar setelah orang lain (penyetuju) menekan Setujui. Anda tidak bisa menyetujui pengajuan buatan sendiri.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('branch_id')->label('Cabang')->relationship('branch', 'name')->required()->native(false)->placeholder('Pilih cabang')->live()
                        ->helperText('Cabang yang punya utang ini.'),
                    Select::make('supplier_id')->label('Pemasok')->options(fn () => \App\Models\Supplier::orderBy('name')->pluck('name', 'id'))->native(false)->searchable()->placeholder('Pilih pemasok')->live()
                        ->helperText('Kosongkan hanya untuk utang lama yang belum punya data pemasok.'),
                    TextInput::make('amount')->label('Jumlah yang dibayar')->placeholder('Contoh: 500000')->prefix('Rp')->numeric()->integer()->minValue(1)->required()
                        ->helperText(function (Get $get) {
                            $base = 'Tidak boleh lebih besar dari sisa utang usaha cabang ini.';
                            $branch = (int) $get('branch_id');
                            if (! $branch) {
                                return $base;
                            }
                            $sup = (int) $get('supplier_id') ?: null;
                            $owed = app(OpsReportService::class)->payables($branch, now()->addYears(10)->toDateString(), $sup);
                            $pending = (int) PaymentRequest::where('branch_id', $branch)->where('status', 'pending')->when($sup, fn ($q) => $q->where('supplier_id', $sup))->sum('amount');

                            return $base.' Sisa utang yang bisa diajukan: Rp'.number_format(max($owed - $pending, 0), 0, ',', '.').'.';
                        }),
                    Select::make('method')->label('Dibayar dari')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options())->required()->native(false)->placeholder('Pilih sumber uang')
                        ->helperText('Uang diambil dari mana saat pembayaran disetujui.'),
                    TextInput::make('description')->label('Catatan')->default('Pembayaran utang usaha')->maxLength(200)
                        ->helperText('Boleh diganti, misalnya menyebut nama pemasok.'),
                ]),
        ]);
    }
}
