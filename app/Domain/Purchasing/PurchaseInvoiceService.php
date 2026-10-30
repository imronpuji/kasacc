<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\InventoryService;
use App\Domain\Transactions;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Faktur pembelian: beberapa barang sekaligus dengan nomor faktur dan jatuh tempo. Stok dan pembukuan bergerak dalam satu transaksi. */
class PurchaseInvoiceService
{
    public function __construct(private Transactions $transactions, private InventoryService $inventory)
    {
    }

    /**
     * $lines: [['product_id' => 1, 'qty' => 5, 'unit_cost' => 250000]]. $method: kredit (jadi utang, wajib jatuh tempo),
     * atau kas/bank/kode rekening (langsung dibayar).
     */
    public function record(User $user, int $branchId, int $supplierId, string $invoiceNo, string $date, ?string $dueDate, string $method, array $lines, ?string $note = null): PurchaseInvoice
    {
        if (! $user->can('post-purchases')) {
            throw new DomainException('Anda tidak punya izin mencatat pembelian. Hubungi pemilik toko.');
        }
        if (! $user->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
        $invoiceNo = trim($invoiceNo);
        if ($invoiceNo === '') {
            throw new DomainException('Nomor faktur harus diisi. Salin dari faktur pemasok.');
        }
        if (! Supplier::whereKey($supplierId)->exists()) {
            throw new DomainException('Pemasok tidak ditemukan. Pilih pemasok dari daftar.');
        }
        if ($lines === []) {
            throw new DomainException('Isi minimal satu barang di faktur.');
        }
        $credit = in_array($method, ['kredit', 'utang'], true);
        if ($credit) {
            if (! $dueDate) {
                throw new DomainException('Isi tanggal jatuh tempo untuk faktur yang belum dibayar.');
            }
            if ($dueDate < $date) {
                throw new DomainException('Tanggal jatuh tempo tidak boleh sebelum tanggal faktur.');
            }
        } else {
            $dueDate = null;
        }

        $clean = [];
        $total = 0;
        $seen = [];
        foreach ($lines as $l) {
            $pid = (int) ($l['product_id'] ?? 0);
            $qty = (int) ($l['qty'] ?? 0);
            $unit = (int) ($l['unit_cost'] ?? 0);
            if (! Product::whereKey($pid)->exists()) {
                throw new DomainException('Ada barang yang tidak ditemukan. Muat ulang halaman lalu coba lagi.');
            }
            if (isset($seen[$pid])) {
                throw new DomainException('Barang yang sama ditulis dua kali di faktur. Gabungkan jadi satu baris.');
            }
            $seen[$pid] = true;
            if ($qty <= 0 || $unit <= 0) {
                throw new DomainException('Jumlah dan harga beli tiap barang harus lebih dari nol.');
            }
            $clean[] = ['product_id' => $pid, 'qty' => $qty, 'unit_cost' => $unit, 'line_total' => $qty * $unit];
            $total += $qty * $unit;
        }

        return DB::transaction(function () use ($user, $branchId, $supplierId, $invoiceNo, $date, $dueDate, $method, $clean, $total, $note) {
            if (PurchaseInvoice::where('supplier_id', $supplierId)->where('invoice_no', $invoiceNo)->exists()) {
                throw new DomainException("Faktur {$invoiceNo} dari pemasok ini sudah tercatat. Cek lagi supaya tidak dicatat dua kali.");
            }
            $journal = $this->transactions->purchaseInvoice($branchId, $date, $total, $method, $supplierId, 'FAKTUR-'.$invoiceNo);
            foreach ($clean as $l) {
                $this->inventory->receive($branchId, $l['product_id'], $l['qty'], $l['line_total'], $date, 'pembelian', 'FAKTUR-'.$invoiceNo);
            }
            $invoice = PurchaseInvoice::create([
                'branch_id' => $branchId, 'supplier_id' => $supplierId, 'invoice_no' => $invoiceNo, 'invoice_date' => $date, 'due_date' => $dueDate,
                'method' => $method, 'total' => $total, 'note' => $note ? mb_substr(trim($note), 0, 200) : null, 'journal_id' => $journal->id, 'created_by' => $user->id,
            ]);
            $invoice->lines()->createMany($clean);

            return $invoice->load('lines');
        });
    }
}
