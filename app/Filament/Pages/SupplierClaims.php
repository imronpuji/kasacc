<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Purchasing\SupplierClaimService;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierClaims extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Klaim pemasok';

    protected static ?string $title = 'Klaim pemasok';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 6;

    private const STATUS = ['open' => 'Menunggu pemasok', 'replaced' => 'Diganti baru', 'refunded' => 'Uang kembali', 'rejected' => 'Ditolak (rugi)'];

    public function getSubheading(): ?string
    {
        return 'Barang rusak yang dikembalikan pelanggan atau cacat dari pemasok. Catat hasilnya di sini: diganti baru, uang kembali, atau ditolak.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn();
        } catch (DomainException $e) {
            Notification::make()->title('Belum tersimpan')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($ok)->success()->send();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(fn (): Builder => SupplierClaim::query()->with(['branch', 'supplier', 'product', 'sale', 'ticket'])
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
                ->latest('id'))
            ->filters([SelectFilter::make('status')->label('Status')->options(self::STATUS)->default('open')])
            ->emptyStateHeading('Tidak ada klaim')
            ->emptyStateDescription('Klaim muncul saat struk dibatalkan dengan barang rusak, atau lewat "Klaim barang rusak ke pemasok" di menu Stok.')
            ->columns([
                TextColumn::make('opened_on')->label('Tanggal')->date('d/m/Y'),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('product.name')->label('Barang')->searchable(),
                TextColumn::make('qty')->label('Jumlah')->alignEnd(),
                TextColumn::make('cost')->label('Nilai')->alignEnd()->state(fn (SupplierClaim $r) => 'Rp'.number_format($r->cost, 0, ',', '.')),
                TextColumn::make('supplier.name')->label('Pemasok')->placeholder('Belum dipilih'),
                TextColumn::make('reason')->label('Kerusakan')->limit(40)->tooltip(fn (SupplierClaim $r) => $r->reason),
                TextColumn::make('source')->label('Asal')->state(fn (SupplierClaim $r) => $r->sale?->receipt_no ?? ($r->ticket ? 'Garansi '.$r->ticket->code : 'Stok')),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state) => self::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => ['open' => 'warning', 'replaced' => 'success', 'refunded' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
            ])
            ->recordActions([
                Action::make('pemasok')->label('Pilih pemasok')->color('gray')->visible(fn (SupplierClaim $r) => $r->status === 'open' && ! $r->supplier_id)
                    ->modalSubmitActionLabel('Simpan')
                    ->schema([Select::make('supplier_id')->label('Pemasok')->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->required()->placeholder('Pilih pemasok')])
                    ->action(fn (SupplierClaim $r, array $data) => self::run(fn () => app(SupplierClaimService::class)->assignSupplier($r, auth()->user(), (int) $data['supplier_id']), 'Pemasok disimpan')),
                Action::make('selesai')->label('Catat hasil')->visible(fn (SupplierClaim $r) => $r->status === 'open')
                    ->modalHeading(fn (SupplierClaim $r) => 'Hasil klaim KLAIM-'.$r->id)->modalSubmitActionLabel('Simpan hasil')
                    ->modalDescription(fn (SupplierClaim $r) => 'Nilai barang Rp'.number_format($r->cost, 0, ',', '.').'. Hasil tidak bisa diubah setelah disimpan.')
                    ->schema([
                        Select::make('supplier_id')->label('Pemasok')->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Pilih pemasok')
                            ->default(fn (SupplierClaim $r) => $r->supplier_id)->visible(fn (SupplierClaim $r) => ! $r->supplier_id)->required(fn (SupplierClaim $r) => ! $r->supplier_id),
                        Select::make('outcome')->label('Hasil dari pemasok')->options(SupplierClaimService::OUTCOMES)->required()->live()
                            ->helperText('Diganti baru: barang pengganti masuk stok. Uang kembali: uang masuk kas, bank, atau memotong utang. Ditolak: nilai barang menjadi kerugian toko.'),
                        Select::make('method')->label('Uang kembali masuk ke')->options(fn () => MoneyAccounts::options(['utang' => 'Potong utang ke pemasok']))->default('kas')
                            ->visible(fn ($get) => $get('outcome') === 'refunded')->required(fn ($get) => $get('outcome') === 'refunded'),
                        TextInput::make('refund')->label('Uang kembali (Rp)')->prefix('Rp')->numeric()->integer()->minValue(0)->inputMode('numeric')
                            ->default(fn (SupplierClaim $r) => (int) $r->cost)->visible(fn ($get) => $get('outcome') === 'refunded')
                            ->helperText('Kalau pemasok mengembalikan kurang dari nilai barang, selisihnya dicatat sebagai kerugian.'),
                        TextInput::make('note')->label('Catatan (boleh kosong)')->maxLength(200),
                    ])
                    ->action(fn (SupplierClaim $r, array $data) => self::run(function () use ($r, $data) {
                        $svc = app(SupplierClaimService::class);
                        if (! empty($data['supplier_id']) && ! $r->supplier_id) {
                            $r = $svc->assignSupplier($r, auth()->user(), (int) $data['supplier_id']);
                        }
                        $svc->resolve($r, auth()->user(), now()->toDateString(), $data['outcome'], $data['method'] ?? null,
                            isset($data['refund']) ? (int) $data['refund'] : null, $data['note'] ?? null);
                    }, 'Hasil klaim disimpan')),
            ]);
    }
}
