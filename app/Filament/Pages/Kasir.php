<?php

namespace App\Filament\Pages;

use App\Domain\Pos\PosService;
use App\Models\Branch;
use App\Models\Product;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Kasir extends Page
{
    protected string $view = 'filament.pages.kasir';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Kasir';

    protected static ?string $title = 'Kasir (POS)';

    protected static ?int $navigationSort = 1;

    public ?int $branchId = null;

    public string $search = '';

    public string $method = 'kas';

    /** @var array<int, array{product_id:int,name:string,price:int,qty:int}> */
    public array $cart = [];

    public ?string $lastReceipt = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-sales') ?? false;
    }

    public function mount(): void
    {
        $this->branchId = $this->branches()->first()?->id;
    }

    public function branches()
    {
        $user = auth()->user();

        return $user->hasRole('owner') ? Branch::orderBy('name')->get() : $user->branches()->orderBy('name')->get();
    }

    public function getResultsProperty()
    {
        $q = trim($this->search);
        if ($q === '') {
            return collect();
        }

        return Product::where(fn ($w) => $w->where('barcode', $q)->orWhere('sku', 'ilike', "%$q%")->orWhere('name', 'ilike', "%$q%"))
            ->limit(8)->get();
    }

    public function add(int $productId): void
    {
        $p = Product::findOrFail($productId);
        $this->cart[$p->id] = [
            'product_id' => $p->id, 'name' => $p->name, 'price' => (int) $p->sale_price,
            'qty' => ($this->cart[$p->id]['qty'] ?? 0) + 1,
        ];
        $this->search = '';
    }

    public function remove(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function getTotalProperty(): int
    {
        return (int) collect($this->cart)->sum(fn ($i) => (int) $i['qty'] * (int) $i['price']);
    }

    public function checkout(): void
    {
        try {
            $sale = app(PosService::class)->checkout(
                auth()->user(), (int) $this->branchId, now()->toDateString(),
                array_values(array_map(fn ($i) => ['product_id' => $i['product_id'], 'qty' => (int) $i['qty'], 'price' => (int) $i['price']], $this->cart)),
                $this->method,
            );
        } catch (\Throwable $e) {
            Notification::make()->title('Transaksi gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->cart = [];
        $this->lastReceipt = $sale->receipt_no;
        Notification::make()->title('Berhasil: '.$sale->receipt_no)->success()->send();
    }
}
