<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\TaxSettings;
use App\Domain\Pos\PosService;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Kasir extends Page
{
    protected string $view = 'filament.pages.kasir';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Kasir';

    protected static ?string $title = 'Kasir';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 1;

    public ?int $branchId = null;

    public string $search = '';

    public string $method = 'kas';

    /** @var array<int, array{product_id:int,name:string,price:int,qty:int}> */
    public array $cart = [];

    public ?string $lastReceipt = null;

    public ?string $cashReceived = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-sales') ?? false;
    }

    public function mount(): void
    {
        $this->branchId = $this->branches()->first()?->id;
    }

    public function updatedBranchId(): void
    {
        // Ganti cabang = stok berbeda, jadi keranjang dikosongkan supaya tidak salah hitung.
        $this->cart = [];
    }

    /** Biaya QRIS dalam persen (dari pengaturan yang sama dengan yang dipakai saat pembayaran), mis. "0,7". */
    public function getQrisRateProperty(): string
    {
        return rtrim(rtrim(number_format(TaxSettings::qrisBps() / 100, 2, ',', ''), '0'), ',');
    }

    public function getChangeProperty(): ?int
    {
        $given = (int) preg_replace('/\D/', '', (string) $this->cashReceived);

        return $given > 0 ? $given - $this->total : null;
    }

    private function stockOf(int $productId): int
    {
        return (int) StockBalance::where('branch_id', $this->branchId)->where('product_id', $productId)->value('qty');
    }

    public function branches()
    {
        $user = auth()->user();

        return $user->hasRole('owner') ? Branch::orderBy('name')->get() : $user->branches()->orderBy('name')->get();
    }

    public function getResultsProperty()
    {
        $q = trim($this->search);
        $stock = fn ($p) => $this->stockOf($p->id);
        $query = Product::query()->where('sale_price', '>', 0)->orderBy('name');
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('barcode', $q)->orWhere('sku', 'ilike', "%$q%")->orWhere('name', 'ilike', "%$q%"));
        }

        return $query->limit(24)->get()->each(fn ($p) => $p->stock_qty = $stock($p));
    }

    public function add(int $productId): void
    {
        $p = Product::find($productId);
        if (! $p) {
            Notification::make()->title('Barang tidak ditemukan')->body('Muat ulang halaman lalu coba lagi.')->danger()->send();

            return;
        }
        if ((int) $p->sale_price <= 0) {
            Notification::make()->title('Harga jual belum diisi')->body("{$p->name} belum bisa dijual. Isi harga jualnya dulu lewat menu Produk.")->danger()->send();

            return;
        }
        $stock = $this->stockOf($p->id);
        $qty = ($this->cart[$p->id]['qty'] ?? 0) + 1;
        if ($qty > $stock) {
            Notification::make()->title($stock <= 0 ? 'Stok habis' : 'Stok tidak cukup')
                ->body($stock <= 0 ? "{$p->name} sedang habis." : "Sisa stok {$p->name} hanya {$stock}.")->warning()->send();

            return;
        }
        $this->cart[$p->id] = [
            'product_id' => $p->id, 'name' => $p->name, 'price' => (int) $p->sale_price, 'qty' => $qty,
        ];
        $this->search = '';
    }

    public function changeQty(int $productId, int $delta): void
    {
        if (! isset($this->cart[$productId])) {
            return;
        }
        $q = (int) $this->cart[$productId]['qty'] + $delta;
        if ($q <= 0) {
            unset($this->cart[$productId]);

            return;
        }
        $stock = $this->stockOf($productId);
        if ($q > $stock) {
            Notification::make()->title('Stok tidak cukup')->body("Sisa stok {$this->cart[$productId]['name']} hanya {$stock}.")->warning()->send();

            return;
        }
        $this->cart[$productId]['qty'] = $q;
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
                // Harga tidak dikirim dari layar: PosService memakai harga jual produk saat ini, jadi data layar yang dipalsukan tidak berpengaruh.
                array_values(array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'qty' => (int) $i['qty']], $this->cart)),
                $this->method,
            );
        } catch (DomainException|\InvalidArgumentException $e) {
            Notification::make()->title('Pembayaran belum berhasil')->body($e->getMessage())->danger()->send();

            return;
        } catch (ModelNotFoundException) {
            Notification::make()->title('Pembayaran belum berhasil')->body('Barang tidak ditemukan. Muat ulang halaman lalu coba lagi.')->danger()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Pembayaran belum berhasil')->body('Terjadi kesalahan. Coba lagi, kalau masih gagal hubungi pemilik toko.')->danger()->send();

            return;
        }

        $this->cart = [];
        $this->cashReceived = null;
        $this->lastReceipt = $sale->receipt_no;
        Notification::make()->title('Pembayaran berhasil')->body('Nomor struk: '.$sale->receipt_no)->success()
            ->actions([Action::make('cetak')->label('Cetak struk')->url(route('struk', $sale->receipt_no), shouldOpenInNewTab: true)->button()])
            ->send();
    }
}
