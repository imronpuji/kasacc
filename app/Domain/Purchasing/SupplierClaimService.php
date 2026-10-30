<?php

namespace App\Domain\Purchasing;

use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Accounting\OpsReportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Transactions;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Barang rusak yang harus diklaim ke pemasok (dari pelanggan yang mengembalikan, atau dari stok sendiri).
 * Barang keluar dari stok bagus dan nilainya tercatat di "barang klaim di pemasok" sampai pemasok menyelesaikan:
 * ganti baru (stok masuk lagi), uang kembali (kas, bank, atau potong utang), atau ditolak (jadi kerugian).
 */
class SupplierClaimService
{
    public const OUTCOMES = ['replaced' => 'Diganti barang baru', 'refunded' => 'Uang kembali', 'rejected' => 'Ditolak pemasok (rugi)'];

    public function __construct(private Transactions $transactions, private InventoryService $inventory)
    {
    }

    private function authorize(User $user, int $branchId): void
    {
        if (! $user->can('post-purchases')) {
            throw new DomainException('Anda tidak punya izin mengurus klaim ke pemasok. Hubungi pemilik toko.');
        }
        if (! $user->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
    }

    /** Klaim dari stok sendiri (barang cacat dari pemasok, atau barang garansi). Barang keluar dari stok pada biaya rata-rata. */
    public function fromStock(User $user, int $branchId, string $date, string $sku, int $qty, string $reason, ?int $supplierId = null): SupplierClaim
    {
        $this->authorize($user, $branchId);
        $this->assertReason($reason);
        $this->assertSupplier($supplierId);
        $product = Product::where('sku', $sku)->first() ?? throw new DomainException('Kode barang tidak ditemukan.');
        if ($qty <= 0) {
            throw new DomainException('Jumlah barang yang diklaim harus lebih dari nol.');
        }

        return DB::transaction(function () use ($user, $branchId, $date, $product, $qty, $reason, $supplierId) {
            $cost = $this->inventory->issue($branchId, $product->id, $qty, $date, 'klaim pemasok');

            return $this->create($user, $branchId, $date, $product->id, $qty, $cost, $reason, $supplierId, null);
        });
    }

    /**
     * Dipanggil dari pembatalan struk untuk barang yang dikembalikan dalam keadaan rusak. Stok bagus tidak
     * ditambah; nilainya (yang sudah dikembalikan ke persediaan oleh jurnal pembalik) dipindah ke akun klaim.
     */
    public function fromReturnedSale(User $user, PosSale $sale, int $productId, int $qty, int $cost, string $date, string $reason): SupplierClaim
    {
        return $this->create($user, $sale->branch_id, $date, $productId, $qty, $cost, $reason, null, $sale->id, $sale->receipt_no);
    }

    /**
     * Tagihkan klaim garansi servis ke pemasok (sparepart yang gagal pakai). Nilainya sama dengan biaya garansi yang
     * sudah dicatat; tidak ada stok yang keluar lagi. Satu klaim garansi hanya bisa ditagihkan sekali.
     */
    public function fromWarranty(User $user, int $warrantyClaimId, string $reason, ?int $supplierId = null): SupplierClaim
    {
        $this->assertReason($reason);
        $this->assertSupplier($supplierId);

        return DB::transaction(function () use ($user, $warrantyClaimId, $reason, $supplierId) {
            $w = DB::table('warranty_claims')->where('id', $warrantyClaimId)->lockForUpdate()->first() ?? throw new DomainException('Klaim garansi tidak ditemukan.');
            $ticket = \App\Models\ServiceTicket::findOrFail($w->service_ticket_id);
            $this->authorize($user, $ticket->branch_id);
            if (! $w->product_id || ! $w->qty) {
                throw new DomainException('Klaim garansi lama ini tidak punya data barang, jadi tidak bisa ditagihkan ke pemasok.');
            }
            if (SupplierClaim::where('warranty_claim_id', $warrantyClaimId)->exists()) {
                throw new DomainException('Klaim garansi ini sudah ditagihkan ke pemasok.');
            }
            if ((int) $w->cost <= 0) {
                throw new DomainException('Biaya garansi ini nol, tidak ada yang bisa ditagihkan.');
            }

            $claim = SupplierClaim::create([
                'branch_id' => $ticket->branch_id, 'supplier_id' => $supplierId, 'product_id' => $w->product_id, 'qty' => $w->qty, 'cost' => (int) $w->cost,
                'status' => 'open', 'reason' => mb_substr(trim($reason), 0, 200), 'service_ticket_id' => $ticket->id, 'warranty_claim_id' => $warrantyClaimId,
                'complaint_id' => \App\Models\Complaint::where('service_ticket_id', $ticket->id)->latest('id')->value('id'),
                'opened_on' => now()->toDateString(), 'created_by' => $user->id,
            ]);
            $journal = $this->transactions->moveWarrantyToClaim($ticket->branch_id, now()->toDateString(), (int) $w->cost, 'Klaim pemasok KLAIM-'.$claim->id, $ticket->code, $supplierId);
            $claim->update(['claim_journal_id' => $journal->id]);

            return $claim;
        });
    }

    private function create(User $user, int $branchId, string $date, int $productId, int $qty, int $cost, string $reason, ?int $supplierId, ?int $saleId, ?string $reference = null): SupplierClaim
    {
        $claim = SupplierClaim::create([
            'branch_id' => $branchId, 'supplier_id' => $supplierId, 'product_id' => $productId, 'qty' => $qty, 'cost' => $cost,
            'status' => 'open', 'reason' => mb_substr(trim($reason), 0, 200), 'pos_sale_id' => $saleId,
            'opened_on' => $date, 'created_by' => $user->id,
        ]);
        $journal = $this->transactions->moveToClaim($branchId, $date, $cost, 'Klaim pemasok KLAIM-'.$claim->id, $reference, $supplierId);
        $claim->update(['claim_journal_id' => $journal->id]);

        return $claim;
    }

    public function assignSupplier(SupplierClaim $claim, User $user, int $supplierId): SupplierClaim
    {
        $this->authorize($user, $claim->branch_id);
        $this->assertSupplier($supplierId);
        if ($claim->status !== 'open') {
            throw new DomainException('Klaim ini sudah selesai, pemasoknya tidak bisa diubah lagi.');
        }
        $claim->update(['supplier_id' => $supplierId]);

        return $claim;
    }

    /**
     * Selesaikan klaim. $refund = uang yang diterima kembali (hanya untuk "refunded"), tidak boleh melebihi nilai barang;
     * kekurangannya jadi kerugian. $method: kas, bank, atau "utang" (memotong utang ke pemasok klaim ini).
     */
    public function resolve(SupplierClaim $claim, User $user, string $date, string $outcome, ?string $method = null, ?int $refund = null, ?string $note = null): SupplierClaim
    {
        $this->authorize($user, $claim->branch_id);
        if (! isset(self::OUTCOMES[$outcome])) {
            throw new DomainException('Hasil klaim tidak dikenal. Pilih diganti baru, uang kembali, atau ditolak.');
        }

        return DB::transaction(function () use ($claim, $user, $date, $outcome, $method, $refund, $note) {
            $claim = SupplierClaim::lockForUpdate()->findOrFail($claim->id);
            if ($claim->status !== 'open') {
                throw new DomainException('Klaim ini sudah diselesaikan sebelumnya.');
            }
            if (! $claim->supplier_id) {
                throw new DomainException('Pilih pemasok untuk klaim ini dulu, supaya tercatat ke pemasok yang benar.');
            }

            $cost = (int) $claim->cost;
            $refund = $outcome === 'refunded' ? ($refund ?? $cost) : 0;
            if ($outcome === 'refunded') {
                if ($refund < 0 || $refund > $cost) {
                    throw new DomainException('Uang kembali tidak boleh minus atau lebih dari nilai barang (Rp'.number_format($cost, 0, ',', '.').').');
                }
                if ($refund > 0 && $method !== 'utang' && ! MoneyAccounts::valid((string) $method)) {
                    throw new DomainException('Pilih uang kembali masuk ke mana: kas, bank, atau potong utang.');
                }
                if ($refund > 0 && $method === 'utang'
                    && $refund > app(OpsReportService::class)->payables($claim->branch_id, $date, $claim->supplier_id)) {
                    throw new DomainException('Uang kembali melebihi utang ke pemasok ini. Pilih kas atau bank, atau kecilkan jumlahnya.');
                }
            }

            $reference = 'KLAIM-'.$claim->id;
            if ($outcome === 'replaced') {
                $this->inventory->receive($claim->branch_id, $claim->product_id, (int) $claim->qty, $cost, $date, 'ganti klaim', $reference);
            }
            $journal = $this->transactions->settleClaim($claim->branch_id, $date, $outcome, $cost, $refund, $method, $reference, $claim->supplier_id);

            $claim->update([
                'status' => $outcome, 'refund_amount' => $refund, 'resolve_journal_id' => $journal->id,
                'resolved_on' => $date, 'resolved_by' => $user->id, 'resolve_note' => $note ? mb_substr(trim($note), 0, 200) : null,
            ]);

            return $claim;
        });
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('Alasan klaim harus diisi, misalnya "layar bergaris" atau "baterai cepat habis".');
        }
    }

    private function assertSupplier(?int $supplierId): void
    {
        if ($supplierId && ! Supplier::whereKey($supplierId)->exists()) {
            throw new DomainException('Pemasok tidak ditemukan. Pilih pemasok dari daftar.');
        }
    }
}
