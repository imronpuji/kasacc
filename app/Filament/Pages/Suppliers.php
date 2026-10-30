<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\OpsReportService;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class Suppliers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Pemasok';

    protected static ?string $title = 'Pemasok';

    protected static string|\UnitEnum|null $navigationGroup = 'Barang';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Daftar toko atau distributor tempat Anda membeli barang. Pilih pemasok saat mencatat pembelian, supaya utang ke masing-masing pemasok kelihatan.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    /** Sisa utang ke tiap pemasok (semua cabang yang boleh dilihat pengguna), dihitung sekali per halaman. */
    private function owedMap(): array
    {
        $user = auth()->user();
        $map = [];
        $branches = $user->hasRole('owner') ? [null] : $user->branches()->pluck('branches.id')->all();
        foreach ($branches as $b) {
            foreach (app(OpsReportService::class)->payablesBySupplier($b, now()->addYears(10)->toDateString()) as $r) {
                if ($r['supplier_id']) {
                    $map[$r['supplier_id']] = ($map[$r['supplier_id']] ?? 0) + $r['owed'];
                }
            }
        }

        return $map;
    }

    private static function fields(?int $ignoreId = null): array
    {
        return [
            TextInput::make('name')->label('Nama pemasok')->required()->maxLength(100)->placeholder('Contoh: Toko Sparepart Jaya')
                ->rules([Rule::unique('suppliers', 'name')->ignore($ignoreId)]),
            TextInput::make('phone')->label('Nomor HP (boleh kosong)')->tel()->maxLength(30),
            TextInput::make('address')->label('Alamat (boleh kosong)')->maxLength(250),
            Textarea::make('note')->label('Catatan (boleh kosong)')->rows(2)->maxLength(500),
        ];
    }

    public function table(Table $table): Table
    {
        $owed = $this->owedMap();
        $claims = \App\Models\SupplierClaim::where('status', 'open')->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, count(*) as n, sum(cost) as v')->groupBy('supplier_id')->get()
            ->mapWithKeys(fn ($r) => [$r->supplier_id => $r->n.' barang, Rp'.number_format($r->v, 0, ',', '.')])->all();

        return $table
            ->query(fn (): Builder => Supplier::query()->orderByDesc('is_active')->orderBy('name'))
            ->emptyStateHeading('Belum ada pemasok')
            ->emptyStateDescription('Ketuk "Tambah pemasok" untuk mencatat pemasok pertama.')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('phone')->label('Nomor HP')->placeholder('-'),
                TextColumn::make('address')->label('Alamat')->placeholder('-')->limit(40),
                TextColumn::make('owed')->label('Sisa utang')->alignEnd()
                    ->state(fn (Supplier $r) => 'Rp'.number_format($owed[$r->id] ?? 0, 0, ',', '.')),
                TextColumn::make('open_claims')->label('Klaim menunggu')->alignEnd()
                    ->state(fn (Supplier $r) => $claims[$r->id] ?? '-'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->headerActions([
                Action::make('tambah')->label('Tambah pemasok')->modalSubmitActionLabel('Simpan')
                    ->schema(self::fields())
                    ->action(function (array $data) {
                        Supplier::create(['name' => trim($data['name']), 'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null, 'note' => $data['note'] ?? null]);
                        Notification::make()->title('Pemasok disimpan')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('ubah')->label('Ubah')->modalSubmitActionLabel('Simpan perubahan')
                    ->fillForm(fn (Supplier $r) => $r->only(['name', 'phone', 'address', 'note', 'is_active']))
                    ->schema(fn (Supplier $r) => [
                        ...self::fields($r->id),
                        Toggle::make('is_active')->label('Masih dipakai')->helperText('Matikan kalau sudah tidak membeli dari pemasok ini. Riwayat utangnya tetap tersimpan.'),
                    ])
                    ->action(function (Supplier $r, array $data) {
                        $r->update(['name' => trim($data['name']), 'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null, 'note' => $data['note'] ?? null, 'is_active' => (bool) $data['is_active']]);
                        Notification::make()->title('Perubahan disimpan')->success()->send();
                    }),
            ]);
    }
}
