<?php

namespace App\Domain\Pos;

use App\Domain\Accounting\LedgerService;
use App\Domain\Customer\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Loyalty\LoyaltyService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PosService
{
    public function __construct(
        private Transactions $transactions,
        private LedgerService $ledger,
        private InventoryService $inventory,
        private CustomerService $customers,
        private LoyaltyService $loyalty,
    ) {
    }

    /**
     * Checkout kasir. $items: [['product_id' => 1, 'qty' => 2, 'price' => 120000 (opsional, default harga jual)]].
     * Pendapatan, HPP, stok, dan struk disimpan dalam satu transaksi database: gagal satu, batal semua.
     */
    public function checkout(User $cashier, int $branchId, string $date, array $items, string $method, ?string $customerContact = null, int $redeemPoints = 0): PosSale
    {
        if (! $cashier->can('post-sales')) {
            throw new DomainException('Anda tidak punya izin untuk berjualan di Kasir. Hubungi pemilik toko.');
        }
        if (! $cashier->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
        if ($method !== 'qris' && ! \App\Domain\Accounting\MoneyAccounts::valid($method)) {
            throw new DomainException('Cara bayar tidak dikenal. Pilih salah satu cara bayar yang tersedia di kasir.');
        }
        if ($items === []) {
            throw new DomainException('Keranjang masih kosong. Ketuk barang di daftar untuk menambahkannya.');
        }

        $lines = [];
        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            if (! $product) {
                throw new DomainException('Barang tidak ditemukan. Muat ulang halaman lalu coba lagi.');
            }
            $qty = $item['qty'] ?? 0;
            $price = $item['price'] ?? $product->sale_price;
            if (! is_int($price) || $price <= 0) {
                throw new DomainException("Harga jual {$product->name} belum diisi. Isi dulu lewat menu Produk.");
            }
            if (! is_int($qty) || $qty <= 0) {
                throw new DomainException("Jumlah atau harga {$product->name} tidak valid. Periksa lagi isi keranjang.");
            }
            $lines[] = ['sku' => $product->sku, 'product_id' => $product->id, 'qty' => $qty, 'price' => $price];
        }

        $mdrBps = $method === 'qris' ? \App\Domain\Accounting\TaxSettings::qrisBps() : 0;
        $customer = $this->customers->resolveContact($customerContact);

        return DB::transaction(function () use ($cashier, $branchId, $date, $lines, $method, $mdrBps, $customer, $redeemPoints) {
            $receiptNo = $this->nextReceiptNo($branchId, $date);
            $total = array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines));
            $discount = $this->loyalty->discountFor($customer, $redeemPoints, $total, $total);
            $journal = $this->transactions->posSale($branchId, $date, array_map(
                fn ($l) => ['sku' => $l['sku'], 'qty' => $l['qty'], 'price' => $l['price']], $lines,
            ), $method, $mdrBps, $receiptNo, $discount);

            $mdr = $method === 'qris' ? intdiv(($total - $discount) * $mdrBps * 2 + 10000, 20000) : 0;

            $sale = PosSale::create([
                'branch_id' => $branchId,
                'customer_id' => $customer?->id,
                'receipt_no' => $receiptNo,
                'cashier_id' => $cashier->id,
                'sold_on' => $date,
                'payment_method' => $method,
                'total' => $total,
                'mdr' => $mdr,
                'discount' => $discount,
                'points_redeemed' => $redeemPoints,
                'journal_id' => $journal->id,
            ]);

            foreach ($lines as $l) {
                $sale->items()->create([
                    'product_id' => $l['product_id'], 'qty' => $l['qty'],
                    'unit_price' => $l['price'], 'line_total' => $l['qty'] * $l['price'],
                ]);
            }

            $this->loyalty->record($customer, 'pos_sale', $sale->id, $redeemPoints, $total - $discount, $cashier->id);

            return $sale->load('items.product');
        });
    }

    /**
     * Batalkan satu struk penuh: jurnal dibalik, stok kembali dengan biaya semula, struk ditandai batal.
     * Hanya yang berwenang (pemilik atau penyetuju), harus ada alasan, dan uang pengembalian harus ada saldonya.
     */
    public function void(User $user, string $receiptNo, string $date, string $reason): PosSale
    {
        if (! $user->can('void-sales')) {
            throw new DomainException('Anda tidak berwenang membatalkan struk. Minta pemilik atau penyetuju yang ditunjuk.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Alasan pembatalan harus diisi, misalnya "pelanggan batal" atau "salah input barang".');
        }

        return DB::transaction(function () use ($user, $receiptNo, $date, $reason) {
            $sale = PosSale::where('receipt_no', $receiptNo)->lockForUpdate()->first();
            if (! $sale) {
                throw new DomainException('Nomor struk tidak ditemukan. Periksa nomor yang tercetak di struk.');
            }
            if (! $user->canAccessBranch($sale->branch_id)) {
                throw new DomainException('Anda tidak punya akses ke cabang struk ini. Pilih cabang lain atau hubungi pemilik toko.');
            }
            if ($sale->voided_at) {
                throw new DomainException('Struk ini sudah dibatalkan sebelumnya.');
            }

            // Uang yang dikembalikan sama dengan yang diterima toko: total dikurangi potongan poin dan biaya QRIS.
            $this->transactions->assertFunds($sale->branch_id, $date, $sale->payment_method, (int) $sale->total - (int) $sale->discount - (int) $sale->mdr);

            $reversal = $this->ledger->reverse(\App\Models\Journal::findOrFail($sale->journal_id), $date, $user->id);
            foreach (StockMovement::where('branch_id', $sale->branch_id)->where('reference', $sale->receipt_no)->where('direction', 'out')->where('reason', 'penjualan')->get() as $m) {
                $this->inventory->receive($sale->branch_id, $m->product_id, $m->qty, (int) $m->total_cost, $date, 'retur penjualan', $sale->receipt_no);
            }

            $sale->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => mb_substr($reason, 0, 200), 'void_journal_id' => $reversal->id]);
            $this->loyalty->reverse('pos_sale', $sale->id, $user->id);

            return $sale;
        });
    }

    private function nextReceiptNo(int $branchId, string $date): string
    {
        // Kunci baris cabang supaya dua kasir yang bayar bersamaan tidak mengambil nomor struk yang sama.
        $code = Branch::lockForUpdate()->findOrFail($branchId)->code;
        $seq = PosSale::where('branch_id', $branchId)->whereDate('sold_on', $date)->count() + 1;

        return sprintf('POS-%s-%s-%04d', $code, str_replace('-', '', $date), $seq);
    }
}
