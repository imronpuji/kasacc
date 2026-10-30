<?php

namespace App\Domain\Pos;

use App\Domain\Accounting\LedgerService;
use App\Domain\Customer\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Loyalty\LoyaltyService;
use App\Domain\Purchasing\SupplierClaimService;
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
        private SupplierClaimService $claims,
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
     * $defective: [['product_id' => 1, 'qty' => 1]] untuk barang yang dikembalikan dalam keadaan rusak. Barang itu tidak
     * masuk stok bagus, tapi menjadi klaim ke pemasok (lihat SupplierClaimService).
     */
    public function void(User $user, string $receiptNo, string $date, string $reason, array $defective = []): PosSale
    {
        if (! $user->can('void-sales')) {
            throw new DomainException('Anda tidak berwenang membatalkan struk. Minta pemilik atau penyetuju yang ditunjuk.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Alasan pembatalan harus diisi, misalnya "pelanggan batal" atau "salah input barang".');
        }

        return DB::transaction(function () use ($user, $receiptNo, $date, $reason, $defective) {
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
            if ($sale->returns()->exists()) {
                throw new DomainException('Struk ini sudah ada retur sebagian, jadi tidak bisa dibatalkan penuh. Retur sisa barangnya lewat "Retur sebagian".');
            }

            // Uang yang dikembalikan sama dengan yang diterima toko: total dikurangi potongan poin dan biaya QRIS.
            $this->transactions->assertFunds($sale->branch_id, $date, $sale->payment_method, (int) $sale->total - (int) $sale->discount - (int) $sale->mdr);

            $reversal = $this->ledger->reverse(\App\Models\Journal::findOrFail($sale->journal_id), $date, $user->id);
            $movements = StockMovement::where('branch_id', $sale->branch_id)->where('reference', $sale->receipt_no)->where('direction', 'out')->where('reason', 'penjualan')->get();
            $left = [];
            foreach ($defective as $d) {
                $pid = (int) ($d['product_id'] ?? 0);
                $qty = (int) ($d['qty'] ?? 0);
                if ($qty <= 0 || $qty > (int) $movements->where('product_id', $pid)->sum('qty') - ($left[$pid] ?? 0)) {
                    throw new DomainException('Jumlah barang rusak lebih banyak dari yang terjual di struk ini. Periksa lagi barang dan jumlahnya.');
                }
                $left[$pid] = ($left[$pid] ?? 0) + $qty;
            }
            foreach ($movements as $m) {
                $bad = min((int) $m->qty, $left[$m->product_id] ?? 0);
                $left[$m->product_id] = ($left[$m->product_id] ?? 0) - $bad;
                $good = (int) $m->qty - $bad;
                $goodCost = $bad === 0 ? (int) $m->total_cost : intdiv((int) $m->total_cost * $good, (int) $m->qty);
                if ($good > 0) {
                    $this->inventory->receive($sale->branch_id, $m->product_id, $good, $goodCost, $date, 'retur penjualan', $sale->receipt_no);
                }
                if ($bad > 0) {
                    $this->claims->fromReturnedSale($user, $sale, (int) $m->product_id, $bad, (int) $m->total_cost - $goodCost, $date, 'Barang rusak dikembalikan pelanggan, struk '.$sale->receipt_no);
                }
            }

            $sale->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => mb_substr($reason, 0, 200), 'void_journal_id' => $reversal->id]);
            $this->loyalty->reverse('pos_sale', $sale->id, $user->id);

            return $sale;
        });
    }

    /** Pembulatan setengah ke atas untuk pembagian bilangan bulat. */
    private static function ratio(int $amount, int $part, int $whole): int
    {
        return $whole > 0 ? intdiv(2 * $amount * $part + $whole, 2 * $whole) : 0;
    }

    /**
     * Retur sebagian: pelanggan mengembalikan sebagian barang di struk. $items: [['product_id' => 1, 'qty' => 1, 'defective' => false]].
     * Uang kembali = harga barang dikurangi bagian potongan poin; barang bagus masuk stok, barang rusak jadi klaim ke pemasok.
     * Biaya QRIS (MDR) yang sudah dibayar toko tidak dikembalikan. Butuh izin yang sama dengan membatalkan struk.
     */
    public function partialReturn(User $user, string $receiptNo, string $date, string $reason, array $items): \App\Models\PosSaleReturn
    {
        if (! $user->can('void-sales')) {
            throw new DomainException('Anda tidak berwenang meretur barang. Minta pemilik atau penyetuju yang ditunjuk.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Alasan retur harus diisi, misalnya "ukuran tidak cocok" atau "barang rusak".');
        }
        if ($items === []) {
            throw new DomainException('Pilih barang yang dikembalikan dan jumlahnya.');
        }

        return DB::transaction(function () use ($user, $receiptNo, $date, $reason, $items) {
            $sale = PosSale::where('receipt_no', $receiptNo)->lockForUpdate()->first();
            if (! $sale) {
                throw new DomainException('Nomor struk tidak ditemukan. Periksa nomor yang tercetak di struk.');
            }
            if (! $user->canAccessBranch($sale->branch_id)) {
                throw new DomainException('Anda tidak punya akses ke cabang struk ini. Pilih cabang lain atau hubungi pemilik toko.');
            }
            if ($sale->voided_at) {
                throw new DomainException('Struk ini sudah dibatalkan, tidak ada yang bisa diretur lagi.');
            }

            $total = (int) $sale->total;
            $discount = (int) $sale->discount;
            $before = $sale->returns()->get(['gross', 'discount_share', 'refund']);
            $grossBefore = (int) $before->sum('gross');
            $shareBefore = (int) $before->sum('discount_share');
            $refundBefore = (int) $before->sum('refund');

            $rows = [];
            $gross = 0;
            $cost = 0;
            $seen = [];
            foreach ($items as $it) {
                $pid = (int) ($it['product_id'] ?? 0);
                $qty = (int) ($it['qty'] ?? 0);
                if (isset($seen[$pid])) {
                    throw new DomainException('Barang yang sama ditulis dua kali. Gabungkan jumlahnya jadi satu baris.');
                }
                $seen[$pid] = true;
                $line = $sale->items()->where('product_id', $pid)->first();
                if (! $line) {
                    throw new DomainException('Ada barang yang tidak ada di struk ini. Periksa lagi barang yang dipilih.');
                }
                $returned = (int) DB::table('pos_sale_return_items as ri')->join('pos_sale_returns as r', 'r.id', '=', 'ri.pos_sale_return_id')
                    ->where('r.pos_sale_id', $sale->id)->where('ri.product_id', $pid)->sum('ri.qty');
                if ($qty <= 0 || $qty > (int) $line->qty - $returned) {
                    throw new DomainException('Jumlah retur melebihi yang bisa diretur ('.((int) $line->qty - $returned).' dari '.(int) $line->qty.' yang dibeli). Periksa jumlahnya.');
                }
                // Modal diambil dari pergerakan stok struk ini, dibagi rata per buah dan dihitung kumulatif supaya total tepat.
                $moves = StockMovement::where('branch_id', $sale->branch_id)->where('reference', $sale->receipt_no)->where('product_id', $pid)
                    ->where('direction', 'out')->where('reason', 'penjualan')->get();
                $soldQty = (int) $moves->sum('qty');
                $soldCost = (int) $moves->sum('total_cost');
                $lineCost = self::ratio($soldCost, $returned + $qty, $soldQty) - self::ratio($soldCost, $returned, $soldQty);
                $lineGross = $qty * (int) $line->unit_price;
                $gross += $lineGross;
                $cost += $lineCost;
                $rows[] = ['product_id' => $pid, 'qty' => $qty, 'line_gross' => $lineGross, 'cost' => $lineCost, 'defective' => (bool) ($it['defective'] ?? false)];
            }

            $share = self::ratio($discount, $grossBefore + $gross, $total) - self::ratio($discount, $grossBefore, $total);
            $refund = $gross - $share;
            // Uang yang dikembalikan harus ada saldonya.
            $this->transactions->assertFunds($sale->branch_id, $date, $sale->payment_method, $refund);

            $return = \App\Models\PosSaleReturn::create([
                'pos_sale_id' => $sale->id, 'branch_id' => $sale->branch_id, 'returned_on' => $date, 'gross' => $gross,
                'discount_share' => $share, 'refund' => $refund, 'cost' => $cost, 'reason' => mb_substr($reason, 0, 200), 'created_by' => $user->id,
            ]);
            $journal = $this->transactions->posSaleReturn($sale->branch_id, $date, $sale->payment_method, $gross, $refund, $share, $cost, 'RETUR-'.$return->id);
            $return->update(['journal_id' => $journal->id]);

            foreach ($rows as $r) {
                $return->items()->create($r);
                if ($r['defective']) {
                    $this->claims->fromReturnedSale($user, $sale, $r['product_id'], $r['qty'], $r['cost'], $date, 'Barang rusak diretur pelanggan, struk '.$sale->receipt_no);
                } else {
                    $this->inventory->receive($sale->branch_id, $r['product_id'], $r['qty'], $r['cost'], $date, 'retur penjualan', $sale->receipt_no);
                }
            }

            $customer = $sale->customer_id ? \App\Models\Customer::find($sale->customer_id) : null;
            $this->loyalty->reversePartial($customer, $sale->id, $return->id, $total - $discount, $refundBefore, $refund, $discount, $shareBefore, $share, (int) $sale->points_redeemed, $user->id);

            return $return->load('items');
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
