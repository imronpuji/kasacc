<?php

namespace App\Domain\Pos;

use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PosService
{
    public function __construct(private Transactions $transactions)
    {
    }

    /**
     * Checkout kasir. $items: [['product_id' => 1, 'qty' => 2, 'price' => 120000 (opsional, default harga jual)]].
     * Pendapatan, HPP, stok, dan struk disimpan dalam satu transaksi database: gagal satu, batal semua.
     */
    public function checkout(User $cashier, int $branchId, string $date, array $items, string $method): PosSale
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

        return DB::transaction(function () use ($cashier, $branchId, $date, $lines, $method, $mdrBps) {
            $receiptNo = $this->nextReceiptNo($branchId, $date);
            $journal = $this->transactions->posSale($branchId, $date, array_map(
                fn ($l) => ['sku' => $l['sku'], 'qty' => $l['qty'], 'price' => $l['price']], $lines,
            ), $method, $mdrBps, $receiptNo);

            $total = array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines));
            $mdr = $method === 'qris' ? intdiv($total * $mdrBps * 2 + 10000, 20000) : 0;

            $sale = PosSale::create([
                'branch_id' => $branchId,
                'receipt_no' => $receiptNo,
                'cashier_id' => $cashier->id,
                'sold_on' => $date,
                'payment_method' => $method,
                'total' => $total,
                'mdr' => $mdr,
                'journal_id' => $journal->id,
            ]);

            foreach ($lines as $l) {
                $sale->items()->create([
                    'product_id' => $l['product_id'], 'qty' => $l['qty'],
                    'unit_price' => $l['price'], 'line_total' => $l['qty'] * $l['price'],
                ]);
            }

            return $sale->load('items.product');
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
