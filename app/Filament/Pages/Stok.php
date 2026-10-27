<?php

namespace App\Filament\Pages;

use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\StockBalance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class Stok extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Stok dan pembelian';

    protected static ?string $title = 'Stok & Pembelian';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $user = auth()->user();
            $fn($user);
            Notification::make()->title($ok)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    private static function guard($user, int ...$branchIds): void
    {
        foreach ($branchIds as $id) {
            if (! $user->canAccessBranch($id)) {
                throw new \DomainException('Pengguna tidak punya akses ke cabang ini.');
            }
        }
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $branchOptions = fn () => $user->hasRole('owner') ? Branch::pluck('name', 'id') : $user->branches()->pluck('branches.name', 'branches.id');

        return $table
            ->query(fn () => StockBalance::query()->with(['product', 'branch'])
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id'))))
            ->columns([
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('product.sku')->label('SKU')->searchable(),
                TextColumn::make('product.name')->label('Barang')->searchable(),
                TextColumn::make('qty')->label('Qty'),
                TextColumn::make('value')->label('Nilai persediaan')->money('IDR', locale: 'id'),
            ])
            ->headerActions([
                Action::make('pembelian')->label('Pembelian')->schema([
                    Select::make('branch_id')->label('Cabang')->options($branchOptions)->required(),
                    TextInput::make('sku')->label('SKU')->required(),
                    TextInput::make('qty')->numeric()->minValue(1)->required(),
                    TextInput::make('unit_cost')->label('Harga beli/unit')->numeric()->minValue(1)->required(),
                    Select::make('method')->options(['kas' => 'Tunai', 'bank' => 'Transfer', 'kredit' => 'Kredit (utang)'])->default('kas')->required(),
                ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                    self::guard($u, (int) $data['branch_id']);
                    app(Transactions::class)->purchase((int) $data['branch_id'], now()->toDateString(), $data['sku'], (int) $data['qty'], (int) $data['unit_cost'], $data['method']);
                }, 'Pembelian dicatat')),
                Action::make('retur')->label('Retur ke supplier')->schema([
                    Select::make('branch_id')->label('Cabang')->options($branchOptions)->required(),
                    TextInput::make('sku')->label('SKU')->required(),
                    TextInput::make('qty')->numeric()->minValue(1)->required(),
                    Select::make('method')->label('Pengembalian dana')->options(['kas' => 'Tunai', 'bank' => 'Transfer', 'utang' => 'Potong utang'])->default('kas')->required(),
                ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                    self::guard($u, (int) $data['branch_id']);
                    app(Transactions::class)->returnToSupplier((int) $data['branch_id'], now()->toDateString(), $data['sku'], (int) $data['qty'], $data['method']);
                }, 'Retur dicatat')),
                Action::make('transfer')->label('Transfer antar cabang')->schema([
                    Select::make('from')->label('Dari cabang')->options($branchOptions)->required(),
                    Select::make('to')->label('Ke cabang')->options(fn () => Branch::pluck('name', 'id'))->required(),
                    TextInput::make('sku')->label('SKU')->required(),
                    TextInput::make('qty')->numeric()->minValue(1)->required(),
                ])->action(fn (array $data) => self::run(function ($u) use ($data) {
                    self::guard($u, (int) $data['from']);
                    app(Transactions::class)->transferStock((int) $data['from'], (int) $data['to'], now()->toDateString(), $data['sku'], (int) $data['qty']);
                }, 'Transfer stok dicatat')),
            ]);
    }
}
