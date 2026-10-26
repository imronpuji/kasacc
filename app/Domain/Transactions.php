<?php

namespace App\Domain;

use App\Domain\Accounting\LedgerService;
use App\Domain\Inventory\InventoryService;
use App\Models\Journal;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Resep transaksi harian. Setiap method atomik: bila jurnal atau stok gagal,
 * seluruh transaksi dibatalkan (skenario K7).
 */
class Transactions
{
    public const KAS = '1-1100';
    public const BANK = '1-1110';
    public const PERSEDIAAN = '1-1500';
    public const UTANG = '2-1100';
    public const UANG_MUKA = '2-1400';
    public const MODAL = '3-1000';
    public const PEND_JASA = '4-1000';
    public const PEND_BARANG = '4-1100';
    public const HPP = '5-1000';
    public const BEBAN_GARANSI = '6-6000';
    public const BEBAN_MDR = '8-1000';

    public function __construct(
        private LedgerService $ledger,
        private InventoryService $inventory,
    ) {
    }

    /** Akun kas/bank menurut metode: kas, bank, atau qris (masuk ke bank). */
    private function moneyAccount(string $method): string
    {
        return $method === 'kas' ? self::KAS : self::BANK;
    }

    private function productId(string $sku): int
    {
        return Product::where('sku', $sku)->firstOrFail()->id;
    }

    private function post(int $branchId, string $date, array $lines, string $source, string $description, ?string $reference = null): Journal
    {
        return $this->ledger->post([
            'branch_id' => $branchId, 'date' => $date, 'lines' => $lines,
            'source' => $source, 'description' => $description, 'reference' => $reference,
        ]);
    }

    public function depositCapital(int $branchId, string $date, int $amount, string $method = 'bank'): Journal
    {
        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($method), 'debit' => $amount],
            ['account' => self::MODAL, 'credit' => $amount],
        ], 'manual', 'Setoran modal');
    }

    public function transferCash(int $branchId, string $date, int $amount, string $from, string $to): Journal
    {
        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($to), 'debit' => $amount],
            ['account' => $this->moneyAccount($from), 'credit' => $amount],
        ], 'manual', 'Pemindahan kas dan bank');
    }

    /** $method: kas, bank, atau kredit (menjadi utang usaha). */
    public function purchase(int $branchId, string $date, string $sku, int $qty, int $unitCost, string $method): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $sku, $qty, $unitCost, $method) {
            $total = $qty * $unitCost;
            $this->inventory->receive($branchId, $this->productId($sku), $qty, $total, $date, 'pembelian');

            return $this->post($branchId, $date, [
                ['account' => self::PERSEDIAAN, 'debit' => $total],
                ['account' => $method === 'kredit' ? self::UTANG : $this->moneyAccount($method), 'credit' => $total],
            ], 'purchase', "Pembelian {$sku}");
        });
    }

    /** Retur ke supplier pada biaya rata-rata saat ini; pengembalian uang via $refundMethod atau mengurangi utang. */
    public function returnToSupplier(int $branchId, string $date, string $sku, int $qty, string $refundMethod): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $sku, $qty, $refundMethod) {
            $cost = $this->inventory->issue($branchId, $this->productId($sku), $qty, $date, 'retur');

            return $this->post($branchId, $date, [
                ['account' => $refundMethod === 'utang' ? self::UTANG : $this->moneyAccount($refundMethod), 'debit' => $cost],
                ['account' => self::PERSEDIAAN, 'credit' => $cost],
            ], 'purchase', "Retur {$sku}");
        });
    }

    public function payPayable(int $branchId, string $date, int $amount, string $method): Journal
    {
        return $this->post($branchId, $date, [
            ['account' => self::UTANG, 'debit' => $amount],
            ['account' => $this->moneyAccount($method), 'credit' => $amount],
        ], 'purchase', 'Pembayaran utang usaha');
    }

    public function receiveDeposit(int $branchId, string $date, int $amount, string $method, ?string $ticket = null): Journal
    {
        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($method), 'debit' => $amount],
            ['account' => self::UANG_MUKA, 'credit' => $amount],
        ], 'service', 'DP servis', $ticket);
    }

    /**
     * Servis selesai. $parts: [['sku' => ..., 'qty' => ..., 'price' => ...]].
     * $mdrBps: biaya QRIS dalam basis poin (70 = 0,7%) atas jumlah yang dibayar sekarang.
     */
    public function completeService(int $branchId, string $date, array $parts, int $labor, int $depositApplied, string $method, int $mdrBps = 0, ?string $ticket = null): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $parts, $labor, $depositApplied, $method, $mdrBps, $ticket) {
            $partsRevenue = 0;
            $hpp = 0;
            foreach ($parts as $p) {
                $partsRevenue += $p['qty'] * $p['price'];
                $hpp += $this->inventory->issue($branchId, $this->productId($p['sku']), $p['qty'], $date, 'penjualan', $ticket);
            }

            $total = $partsRevenue + $labor;
            $due = $total - $depositApplied;
            $mdr = $method === 'qris' ? intdiv($due * $mdrBps * 2 + 10000, 20000) : 0;

            $lines = [];
            if ($depositApplied > 0) {
                $lines[] = ['account' => self::UANG_MUKA, 'debit' => $depositApplied];
            }
            if ($due > 0) {
                $lines[] = ['account' => $this->moneyAccount($method), 'debit' => $due - $mdr];
            }
            if ($mdr > 0) {
                $lines[] = ['account' => self::BEBAN_MDR, 'debit' => $mdr];
            }
            if ($partsRevenue > 0) {
                $lines[] = ['account' => self::PEND_BARANG, 'credit' => $partsRevenue];
            }
            if ($labor > 0) {
                $lines[] = ['account' => self::PEND_JASA, 'credit' => $labor];
            }
            if ($hpp > 0) {
                $lines[] = ['account' => self::HPP, 'debit' => $hpp];
                $lines[] = ['account' => self::PERSEDIAAN, 'credit' => $hpp];
            }

            return $this->post($branchId, $date, $lines, 'service', 'Servis selesai', $ticket);
        });
    }

    /** Servis batal: DP dikurangi biaya cek menjadi pendapatan jasa, sisanya dikembalikan. */
    public function cancelService(int $branchId, string $date, int $deposit, int $fee, string $refundMethod, ?string $ticket = null): Journal
    {
        $refund = $deposit - $fee;
        $lines = [['account' => self::UANG_MUKA, 'debit' => $deposit]];
        if ($fee > 0) {
            $lines[] = ['account' => self::PEND_JASA, 'credit' => $fee];
        }
        if ($refund > 0) {
            $lines[] = ['account' => $this->moneyAccount($refundMethod), 'credit' => $refund];
        }

        return $this->post($branchId, $date, $lines, 'service', 'Servis dibatalkan', $ticket);
    }

    public function warrantyClaim(int $branchId, string $date, string $sku, int $qty, ?string $ticket = null): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $sku, $qty, $ticket) {
            $cost = $this->inventory->issue($branchId, $this->productId($sku), $qty, $date, 'garansi', $ticket);

            return $this->post($branchId, $date, [
                ['account' => self::BEBAN_GARANSI, 'debit' => $cost],
                ['account' => self::PERSEDIAAN, 'credit' => $cost],
            ], 'service', 'Klaim garansi', $ticket);
        });
    }

    /** Penjualan kasir: pendapatan dan HPP dalam satu jurnal atomik. */
    public function posSale(int $branchId, string $date, array $items, string $method, int $mdrBps = 0): Journal
    {
        return $this->completeService($branchId, $date, $items, 0, 0, $method, $mdrBps);
    }

    public function payExpense(int $branchId, string $date, string $expenseAccount, int $amount, string $method, string $description): Journal
    {
        return $this->post($branchId, $date, [
            ['account' => $expenseAccount, 'debit' => $amount],
            ['account' => $this->moneyAccount($method), 'credit' => $amount],
        ], 'manual', $description);
    }
}
