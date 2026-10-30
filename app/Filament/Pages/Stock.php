<?php

namespace App\Filament\Pages;

use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class Stock extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Stok dan pembelian';

    protected static ?string $title = 'Stok dan pembelian';

    public function getSubheading(): ?string
    {
        return 'Lihat stok tiap barang. Tombol di atas dipakai saat membeli barang, mengembalikan barang ke pemasok, atau memindahkan stok ke cabang lain.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Barang';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    private static function run(callable $fn, string $title, string $body): void
    {
        try {
            $user = auth()->user();
            $fn($user);
            Notification::make()->title($title)->body($body)->success()->send();
        } catch (\DomainException|\InvalidArgumentException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (ModelNotFoundException $e) {
            $msg = str_starts_with($e->getMessage(), 'Kode barang') ? $e->getMessage() : 'Data tidak ditemukan. Muat ulang halaman lalu coba lagi.';
            Notification::make()->title('Belum berhasil')->body($msg)->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Terjadi kesalahan. Coba lagi, kalau masih gagal hubungi pemilik toko.')->danger()->send();
        }
    }

    private static function guard($user, int ...$branchIds): void
    {
        foreach ($branchIds as $id) {
            if (! $user->canAccessBranch($id)) {
                throw new \DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
            }
        }
    }

    private static function productOptions(): array
    {
        return Product::orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->sku => "{$p->name} ({$p->sku})"])->all();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $branchOptions = fn () => $user->hasRole('owner') ? Branch::orderBy('name')->pluck('name', 'id') : $user->branches()->orderBy('name')->pluck('branches.name', 'branches.id');
        $branchSelect = fn (string $name, string $label) => Select::make($name)->label($label)->placeholder('Pilih cabang')->options($branchOptions)->required();
        $skuSelect = fn () => Select::make('sku')->label('Barang')->placeholder('Cari nama atau kode barang')->options(fn () => self::productOptions())->searchable()->required();
        $qtyInput = fn (string $label, string $help) => TextInput::make('qty')->label($label)->numeric()->inputMode('numeric')->minValue(1)->placeholder('Contoh: 5')->helperText($help)->required();

        return $table
            ->query(fn () => StockBalance::query()->with(['product', 'branch'])
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id'))))
            ->emptyStateHeading('Belum ada stok barang')
            ->emptyStateDescription('Ketuk "Catat pembelian barang" untuk memasukkan stok pertama.')
            ->columns([
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('product.sku')->label('Kode barang')->searchable(),
                TextColumn::make('product.name')->label('Barang')->searchable(),
                TextColumn::make('qty')->label('Jumlah stok')->sortable(),
                TextColumn::make('value')->label('Nilai stok (Rp)')->money('IDR', locale: 'id'),
            ])
            ->filters([
                Filter::make('menipis')->label('Stok menipis (5 atau kurang)')->query(fn (Builder $q) => $q->where('qty', '<=', 5)),
            ])
            ->headerActions([
                Action::make('pembelian')->label('Catat pembelian barang')->modalHeading('Catat pembelian barang')->modalSubmitActionLabel('Simpan pembelian')->schema([
                    $branchSelect('branch_id', 'Cabang yang menerima barang'),
                    $skuSelect(),
                    Select::make('supplier_id')->label('Pemasok')->options(fn () => \App\Models\Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Pilih pemasok')->live()
                        ->required(fn ($get) => in_array($get('method'), ['kredit', 'utang'], true))
                        ->helperText('Wajib kalau belum dibayar atau memotong utang, supaya utang tercatat atas nama pemasok. Tambah pemasok baru di menu Pemasok.'),
                    $qtyInput('Jumlah dibeli', 'Banyaknya barang yang dibeli.'),
                    TextInput::make('unit_cost')->label('Harga beli per buah (Rp)')->prefix('Rp')->inputMode('numeric')->numeric()->minValue(1)->placeholder('Contoh: 250000')->helperText('Harga satu buah dari pemasok, bukan total.')->required(),
                    Select::make('method')->label('Dibayar dengan')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options(['kredit' => 'Belum dibayar (utang ke pemasok)']))->default('kas')->required()
                        ->helperText('Tunai diambil dari laci kas, transfer dari rekening toko. Pilih "Belum dibayar" kalau akan dilunasi nanti.'),
                ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                    self::guard($u, (int) $data['branch_id']);
                    app(Transactions::class)->purchase((int) $data['branch_id'], now()->toDateString(), $data['sku'], (int) $data['qty'], (int) $data['unit_cost'], $data['method'], ($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null);
                }, 'Pembelian dicatat', 'Stok barang sudah bertambah.')),
                Action::make('retur')->label('Kembalikan barang ke pemasok')->modalHeading('Kembalikan barang ke pemasok')->modalSubmitActionLabel('Simpan pengembalian')->schema([
                    $branchSelect('branch_id', 'Cabang asal barang'),
                    $skuSelect(),
                    Select::make('supplier_id')->label('Pemasok')->options(fn () => \App\Models\Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Pilih pemasok')->live()
                        ->required(fn ($get) => in_array($get('method'), ['kredit', 'utang'], true))
                        ->helperText('Wajib kalau memotong utang, supaya utang pemasok itu yang berkurang. Tambah pemasok baru di menu Pemasok.'),
                    $qtyInput('Jumlah dikembalikan', 'Tidak boleh lebih dari stok yang ada.'),
                    Select::make('method')->label('Uang kembali lewat')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options(['utang' => 'Potong utang ke pemasok']))->default('kas')->required()
                        ->helperText('Pilih "Potong utang" kalau barang tadi belum dibayar.'),
                ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                    self::guard($u, (int) $data['branch_id']);
                    app(Transactions::class)->returnToSupplier((int) $data['branch_id'], now()->toDateString(), $data['sku'], (int) $data['qty'], $data['method'], ($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null);
                }, 'Pengembalian dicatat', 'Stok barang sudah berkurang.')),
                Action::make('klaim')->label('Klaim barang rusak ke pemasok')->modalHeading('Klaim barang rusak ke pemasok')->modalSubmitActionLabel('Simpan klaim')
                    ->modalDescription('Barang keluar dari stok bagus dan menunggu pemasok: diganti baru, uang kembali, atau ditolak. Selesaikan di menu Klaim pemasok.')
                    ->schema([
                        $branchSelect('branch_id', 'Cabang asal barang'),
                        $skuSelect(),
                        Select::make('supplier_id')->label('Pemasok (boleh diisi nanti)')->options(fn () => \App\Models\Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Pilih pemasok'),
                        $qtyInput('Jumlah rusak', 'Tidak boleh lebih dari stok yang ada.'),
                        TextInput::make('reason')->label('Kerusakan')->required()->maxLength(200)->placeholder('Contoh: layar bergaris'),
                    ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                        self::guard($u, (int) $data['branch_id']);
                        app(\App\Domain\Purchasing\SupplierClaimService::class)->fromStock($u, (int) $data['branch_id'], now()->toDateString(), $data['sku'], (int) $data['qty'], $data['reason'], ($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null);
                    }, 'Klaim dicatat', 'Barang keluar dari stok dan menunggu pemasok.')),
                Action::make('transfer')->label('Pindahkan stok ke cabang lain')->modalHeading('Pindahkan stok ke cabang lain')->modalSubmitActionLabel('Pindahkan stok')->schema([
                    $branchSelect('from', 'Dari cabang')->live(),
                    Select::make('to')->label('Ke cabang')->placeholder('Pilih cabang tujuan')->required()
                        ->options(fn ($get) => Branch::orderBy('name')->when($get('from'), fn ($q, $from) => $q->where('id', '!=', $from))->pluck('name', 'id')),
                    $skuSelect(),
                    $qtyInput('Jumlah dipindahkan', 'Tidak boleh lebih dari stok di cabang asal.'),
                ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                    self::guard($u, (int) $data['from']);
                    app(Transactions::class)->transferStock((int) $data['from'], (int) $data['to'], now()->toDateString(), $data['sku'], (int) $data['qty']);
                }, 'Stok dipindahkan', 'Stok di kedua cabang sudah diperbarui.')),
            ]);
    }
}
