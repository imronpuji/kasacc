<?php

namespace App\Domain\Inventory;

use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/** Persediaan dengan biaya rata-rata bergerak, dalam rupiah bulat. */
class InventoryService
{
    public function receive(int $branchId, int $productId, int $qty, int $totalCost, string $date, string $reason, ?string $reference = null): void
    {
        if ($qty <= 0 || $totalCost < 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih dari 0 dan harga tidak boleh minus.');
        }

        DB::transaction(function () use ($branchId, $productId, $qty, $totalCost, $date, $reason, $reference) {
            $balance = $this->lockedBalance($branchId, $productId);
            $balance->qty += $qty;
            $balance->value += $totalCost;
            $balance->save();
            $this->record($branchId, $productId, $date, 'in', $qty, $totalCost, $reason, $reference);
        });
    }

    /** Mengeluarkan barang dan mengembalikan biaya yang dikeluarkan (untuk HPP/retur/garansi). */
    public function issue(int $branchId, int $productId, int $qty, string $date, string $reason, ?string $reference = null): int
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih dari 0.');
        }

        return DB::transaction(function () use ($branchId, $productId, $qty, $date, $reason, $reference) {
            $balance = $this->lockedBalance($branchId, $productId);
            if ($qty > $balance->qty) {
                $name = \App\Models\Product::whereKey($productId)->value('name') ?? 'barang ini';
                throw new InsufficientStockException("Stok {$name} tidak cukup: sisa {$balance->qty}, diminta {$qty}. Kurangi jumlah atau tambah stok lewat menu Stok dan pembelian.");
            }

            // Unit terakhir mengambil sisa nilai agar tidak ada selisih pembulatan.
            $cost = $qty === $balance->qty
                ? $balance->value
                : intdiv(2 * $balance->value * $qty + $balance->qty, 2 * $balance->qty);

            $balance->qty -= $qty;
            $balance->value -= $cost;
            $balance->save();
            $this->record($branchId, $productId, $date, 'out', $qty, $cost, $reason, $reference);

            return $cost;
        });
    }

    public function balance(int $branchId, int $productId): StockBalance
    {
        return StockBalance::firstOrNew(['branch_id' => $branchId, 'product_id' => $productId], ['qty' => 0, 'value' => 0]);
    }

    private function lockedBalance(int $branchId, int $productId): StockBalance
    {
        StockBalance::firstOrCreate(['branch_id' => $branchId, 'product_id' => $productId], ['qty' => 0, 'value' => 0]);

        return StockBalance::where('branch_id', $branchId)->where('product_id', $productId)->lockForUpdate()->first();
    }

    private function record(int $branchId, int $productId, string $date, string $direction, int $qty, int $cost, string $reason, ?string $reference): void
    {
        StockMovement::create([
            'branch_id' => $branchId, 'product_id' => $productId, 'movement_date' => $date,
            'direction' => $direction, 'qty' => $qty, 'total_cost' => $cost,
            'reason' => $reason, 'reference' => $reference,
        ]);
    }
}
