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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Pos extends Page
{
    protected string $view = 'filament.pages.pos';

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

    public ?string $customerContact = null;

    public ?string $redeemPoints = null;

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

        return $given > 0 ? $given - $this->payable : null;
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

    /** Pelanggan yang cocok dengan isian email/HP, hanya dicari (tidak dibuat) supaya mengetik di kasir tidak menambah data. */
    public function getMemberProperty(): ?\App\Models\Customer
    {
        $c = trim((string) $this->customerContact);
        if ($c === '') {
            return null;
        }
        if (str_contains($c, '@')) {
            $email = \App\Support\EmailAddress::normalize($c);

            return $email ? \App\Models\Customer::where('email', $email)->first() : null;
        }
        $phone = \App\Support\PhoneNumber::normalize($c);

        return $phone ? \App\Models\Customer::where('phone', $phone)->first() : null;
    }

    public function getMemberPointsProperty(): int
    {
        return $this->member ? app(\App\Domain\Loyalty\LoyaltyService::class)->balance($this->member) : 0;
    }

    /** Poin terbanyak yang boleh dipakai untuk keranjang ini (saldo, batas persen, dan nilai tagihan). */
    public function getMaxPointsProperty(): int
    {
        $loyalty = app(\App\Domain\Loyalty\LoyaltyService::class);

        return max(min($this->memberPoints, intdiv($loyalty->maxDiscount($this->total, $this->total), $loyalty->pointValue())), 0);
    }

    public function getRedeemCountProperty(): int
    {
        return min(max((int) preg_replace('/\D/', '', (string) $this->redeemPoints), 0), $this->maxPoints);
    }

    public function getDiscountProperty(): int
    {
        return $this->redeemCount * app(\App\Domain\Loyalty\LoyaltyService::class)->pointValue();
    }

    public function getPayableProperty(): int
    {
        return $this->total - $this->discount;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('returSebagian')->label('Retur sebagian')->color('warning')->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn () => auth()->user()?->can('void-sales') ?? false)
                ->modalHeading('Retur sebagian struk')
                ->modalDescription('Pelanggan mengembalikan sebagian barang. Uang yang dikembalikan sebesar harga barang itu (dikurangi bagian potongan poin kalau ada). Barang bagus kembali ke stok; barang rusak jadi klaim ke pemasok.')
                ->modalSubmitActionLabel('Simpan retur')
                ->schema([
                    TextInput::make('receipt_no')->label('Nomor struk')->placeholder('Contoh: POS-PUSAT-20271001-0007')->required()
                        ->helperText('Nomor ada di bagian atas struk yang dicetak.'),
                    Textarea::make('reason')->label('Alasan retur')->placeholder('Contoh: ukuran tidak cocok')->required()->maxLength(200),
                    \Filament\Forms\Components\Repeater::make('items')->label('Barang yang dikembalikan')->addActionLabel('Tambah barang')->minItems(1)->defaultItems(1)->required()
                        ->schema([
                            \Filament\Forms\Components\Select::make('product_id')->label('Barang')->options(fn () => \App\Models\Product::orderBy('name')->pluck('name', 'id'))->searchable()->required()->placeholder('Pilih barang'),
                            TextInput::make('qty')->label('Jumlah')->numeric()->integer()->minValue(1)->default(1)->required(),
                            \Filament\Forms\Components\Toggle::make('defective')->label('Rusak (klaim ke pemasok)')->default(false),
                        ])->columns(3),
                ])
                ->action(function (array $data) {
                    try {
                        $r = app(PosService::class)->partialReturn(auth()->user(), trim($data['receipt_no']), now()->toDateString(), $data['reason'],
                            array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'qty' => (int) $i['qty'], 'defective' => (bool) ($i['defective'] ?? false)], $data['items'] ?? []));
                    } catch (DomainException|\InvalidArgumentException $e) {
                        Notification::make()->title('Retur belum tersimpan')->body($e->getMessage())->danger()->send();

                        return;
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Retur belum tersimpan')->body('Terjadi kesalahan. Coba lagi, kalau masih gagal hubungi pemilik toko.')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Retur dicatat')->body('Uang yang dikembalikan ke pelanggan: Rp'.number_format($r->refund, 0, ',', '.').'.')->success()->send();
                }),
            Action::make('batalStruk')->label('Batalkan struk')->color('danger')->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn () => auth()->user()?->can('void-sales') ?? false)
                ->modalHeading('Batalkan struk')->modalDescription('Seluruh isi struk dibatalkan: uang dikembalikan ke pelanggan dan stok barang kembali (kecuali barang rusak). Tidak bisa dibatalkan lagi.')
                ->modalSubmitActionLabel('Ya, batalkan struk')
                ->schema([
                    TextInput::make('receipt_no')->label('Nomor struk')->placeholder('Contoh: POS-PUSAT-20271001-0007')->required()
                        ->helperText('Nomor ada di bagian atas struk yang dicetak.'),
                    Textarea::make('reason')->label('Alasan pembatalan')->placeholder('Contoh: pelanggan batal beli, atau salah input barang')->required()->maxLength(200),
                    \Filament\Forms\Components\Repeater::make('defective')->label('Barang yang dikembalikan dalam keadaan rusak (boleh dikosongkan)')
                        ->helperText('Barang rusak tidak masuk stok bagus. Barangnya otomatis jadi klaim ke pemasok di menu Klaim pemasok.')
                        ->addActionLabel('Tambah barang rusak')->defaultItems(0)
                        ->schema([
                            \Filament\Forms\Components\Select::make('product_id')->label('Barang')->options(fn () => \App\Models\Product::orderBy('name')->pluck('name', 'id'))->searchable()->required()->placeholder('Pilih barang'),
                            TextInput::make('qty')->label('Jumlah rusak')->numeric()->integer()->minValue(1)->default(1)->required(),
                        ])->columns(2),
                ])
                ->action(function (array $data) {
                    try {
                        $sale = app(PosService::class)->void(auth()->user(), trim($data['receipt_no']), now()->toDateString(), $data['reason'],
                            array_map(fn ($d) => ['product_id' => (int) $d['product_id'], 'qty' => (int) $d['qty']], $data['defective'] ?? []));
                    } catch (DomainException|\InvalidArgumentException $e) {
                        Notification::make()->title('Struk belum dibatalkan')->body($e->getMessage())->danger()->send();

                        return;
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Struk belum dibatalkan')->body('Terjadi kesalahan. Coba lagi, kalau masih gagal hubungi pemilik toko.')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Struk dibatalkan')->body($sale->receipt_no.': uang dikembalikan dan stok barang kembali.')->success()->send();
                }),
        ];
    }

    public function checkout(): void
    {
        try {
            $sale = app(PosService::class)->checkout(
                auth()->user(), (int) $this->branchId, now()->toDateString(),
                // Harga tidak dikirim dari layar: PosService memakai harga jual produk saat ini, jadi data layar yang dipalsukan tidak berpengaruh.
                array_values(array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'qty' => (int) $i['qty']], $this->cart)),
                $this->method,
                $this->customerContact,
                $this->redeemCount,
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
        $this->customerContact = null;
        $this->redeemPoints = null;
        $this->lastReceipt = $sale->receipt_no;
        Notification::make()->title('Pembayaran berhasil')->body('Nomor struk: '.$sale->receipt_no)->success()
            ->actions([Action::make('cetak')->label('Cetak struk')->url(route('receipt', $sale->receipt_no), shouldOpenInNewTab: true)->button()])
            ->send();
    }
}
