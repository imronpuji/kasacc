<?php

namespace App\Domain\Inventory;

use App\Domain\Transactions;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Stok opname: satu orang menghitung fisik, orang lain (atau pemilik) menyetujui, baru stok dan pembukuan disesuaikan.
 * Setiap hasil hitung dibandingkan dengan stok sistem pada saat hasil itu diisi (system_qty diperbarui saat diisi), jadi
 * penjualan sebelum atau sesudah barang itu dihitung tidak salah dianggap hilang.
 */
class StockCountService
{
    public function __construct(private InventoryService $inventory, private Transactions $transactions)
    {
    }

    private function authorizeCount(User $user, int $branchId): void
    {
        if (! $user->can('post-purchases')) {
            throw new DomainException('Anda tidak punya izin menghitung stok. Hubungi pemilik toko.');
        }
        if (! $user->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
    }

    /** Mulai sesi hitung untuk semua barang yang tercatat di cabang (atau hanya $productIds). Satu sesi terbuka per cabang. */
    public function start(User $user, int $branchId, string $date, ?array $productIds = null, ?string $note = null): StockCount
    {
        $this->authorizeCount($user, $branchId);

        return DB::transaction(function () use ($user, $branchId, $date, $productIds, $note) {
            \App\Models\Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            if (StockCount::where('branch_id', $branchId)->where('status', 'draft')->exists()) {
                throw new DomainException('Masih ada hitungan stok yang belum selesai di cabang ini. Selesaikan atau batalkan dulu.');
            }
            $balances = StockBalance::where('branch_id', $branchId)->when($productIds, fn ($q) => $q->whereIn('product_id', $productIds))->get();
            if ($balances->isEmpty()) {
                throw new DomainException('Belum ada stok tercatat di cabang ini, jadi tidak ada yang bisa dihitung.');
            }
            $count = StockCount::create(['branch_id' => $branchId, 'counted_on' => $date, 'status' => 'draft', 'note' => $note ? mb_substr(trim($note), 0, 200) : null, 'created_by' => $user->id]);
            foreach ($balances as $b) {
                $count->lines()->create(['product_id' => $b->product_id, 'system_qty' => (int) $b->qty]);
            }

            return $count;
        });
    }

    public function setCounted(StockCountLine $line, ?int $qty): void
    {
        if ($qty !== null && $qty < 0) {
            throw new DomainException('Hasil hitungan tidak boleh minus.');
        }
        if ($line->stockCount->status !== 'draft') {
            throw new DomainException('Hitungan ini sudah selesai, tidak bisa diubah lagi.');
        }
        $line->update([
            'counted_qty' => $qty,
            // Pembanding adalah stok sistem saat hasil ini diisi, bukan saat sesi dimulai.
            'system_qty' => $qty === null ? $line->system_qty : (int) $this->inventory->balance($line->stockCount->branch_id, $line->product_id)->qty,
        ]);
    }

    public function cancel(StockCount $count, User $user): void
    {
        $this->authorizeCount($user, $count->branch_id);
        if ($count->status !== 'draft') {
            throw new DomainException('Hitungan ini sudah selesai, tidak bisa dibatalkan.');
        }
        $count->update(['status' => 'cancelled']);
    }

    /** Pemilik boleh menyetujui hitungannya sendiri (toko kecil); yang lain harus orang berbeda dari penghitung. */
    public static function canApprove(User $user, int $createdBy): bool
    {
        return $user->can('approve-adjustments') && ($user->hasRole('owner') || $user->id !== $createdBy);
    }

    public function post(StockCount $count, User $user, string $date): StockCount
    {
        if (! $user->can('approve-adjustments') || ! $user->canAccessBranch($count->branch_id)) {
            throw new DomainException('Anda tidak berwenang menyetujui hasil opname di cabang ini. Minta pemilik atau penyetuju yang ditunjuk.');
        }
        if (! self::canApprove($user, (int) $count->created_by)) {
            throw new DomainException('Hasil opname harus disetujui orang lain, bukan yang menghitung.');
        }

        return DB::transaction(function () use ($count, $user, $date) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'draft') {
                throw new DomainException('Hitungan ini sudah diproses sebelumnya.');
            }
            $lines = $count->lines()->whereNotNull('counted_qty')->get();
            if ($lines->isEmpty()) {
                throw new DomainException('Belum ada barang yang dihitung. Isi hasil hitungan dulu.');
            }

            $reference = 'OPNAME-'.$count->id;
            $net = 0;
            foreach ($lines as $line) {
                $diff = (int) $line->counted_qty - (int) $line->system_qty;
                if ($diff === 0) {
                    continue;
                }
                try {
                    $value = $diff < 0
                        ? -$this->inventory->issue($count->branch_id, $line->product_id, -$diff, $date, 'opname', $reference)
                        : $this->receiveSurplus($count->branch_id, $line->product_id, $diff, $date, $reference);
                } catch (InsufficientStockException) {
                    $name = $line->product->name ?? 'barang';
                    throw new DomainException("Stok {$name} sekarang lebih sedikit dari selisih yang mau dikurangkan, karena ada penjualan setelah hitungan dimulai. Hitung ulang barang ini.");
                }
                $line->update(['diff_value' => $value]);
                $net += $value;
            }

            $journalId = $net !== 0 ? $this->transactions->stockCountAdjustment($count->branch_id, $date, $net, $reference)->id : null;
            $count->update(['status' => 'posted', 'diff_value' => $net, 'journal_id' => $journalId, 'posted_by' => $user->id, 'posted_at' => now()]);

            return $count;
        });
    }

    /** Barang lebih masuk pada biaya rata-rata saat ini; kalau stoknya kosong, pakai harga beli terakhir. */
    private function receiveSurplus(int $branchId, int $productId, int $qty, string $date, string $reference): int
    {
        $balance = $this->inventory->balance($branchId, $productId);
        if ($balance->qty > 0) {
            $unit = intdiv(2 * (int) $balance->value + (int) $balance->qty, 2 * (int) $balance->qty);
        } else {
            $last = StockMovement::where('product_id', $productId)->where('direction', 'in')->where('qty', '>', 0)->orderByDesc('id')->first();
            if (! $last) {
                throw new DomainException('Barang ini belum pernah dibeli, jadi nilainya tidak diketahui. Catat pembeliannya dulu di menu Stok dan pembelian.');
            }
            $unit = intdiv(2 * (int) $last->total_cost + (int) $last->qty, 2 * (int) $last->qty);
        }
        $total = $unit * $qty;
        $this->inventory->receive($branchId, $productId, $qty, $total, $date, 'opname', $reference);

        return $total;
    }
}
