<?php

namespace App\Domain;

use App\Domain\Accounting\LedgerService;
use App\Domain\Inventory\InventoryService;
use App\Models\Journal;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    public const ANTAR_CABANG = '1-1600';
    public const UTANG = '2-1100';
    public const UANG_MUKA = '2-1400';
    public const MODAL = '3-1000';
    public const PEND_JASA = '4-1000';
    public const PEND_BARANG = '4-1100';
    public const HPP = '5-1000';
    public const BEBAN_GARANSI = '6-6000';
    public const BEBAN_POIN = '6-7000';
    public const BARANG_KLAIM = '1-1550';
    public const RUGI_KLAIM = '6-7100';
    public const SELISIH_STOK = '6-7200';
    public const SELISIH_KAS = '6-7300';
    public const BEBAN_MDR = '8-1000';

    public function __construct(
        private LedgerService $ledger,
        private InventoryService $inventory,
    ) {
    }

    /** Akun kas/bank menurut metode: kas, bank, atau qris (masuk ke bank). */
    private function moneyAccount(string $method): string
    {
        return \App\Domain\Accounting\MoneyAccounts::resolve($method);
    }

    private function productId(string $sku): int
    {
        $product = Product::where('sku', $sku)->first();
        if (! $product) {
            throw new ModelNotFoundException("Kode barang {$sku} tidak ditemukan.");
        }

        return $product->id;
    }

    private function post(int $branchId, string $date, array $lines, string $source, string $description, ?string $reference = null, ?int $supplierId = null): Journal
    {
        return $this->ledger->post([
            'branch_id' => $branchId, 'date' => $date, 'lines' => $lines,
            'source' => $source, 'description' => $description, 'reference' => $reference, 'supplier_id' => $supplierId,
        ]);
    }

    public function depositCapital(int $branchId, string $date, int $amount, string $method = 'bank'): Journal
    {
        $this->positive($amount);
        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($method), 'debit' => $amount],
            ['account' => self::MODAL, 'credit' => $amount],
        ], 'manual', 'Setoran modal');
    }

    public function transferCash(int $branchId, string $date, int $amount, string $from, string $to): Journal
    {
        $this->positive($amount);
        if ($this->moneyAccount($from) === $this->moneyAccount($to)) {
            throw new \DomainException('Asal dan tujuan uang tidak boleh sama. Pilih dua tempat yang berbeda.');
        }
        $this->assertFunds($branchId, $date, $from, $amount);
        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($to), 'debit' => $amount],
            ['account' => $this->moneyAccount($from), 'credit' => $amount],
        ], 'manual', 'Pemindahan kas dan bank');
    }

    /** $method: kas, bank, atau kredit (menjadi utang usaha). */
    public function purchase(int $branchId, string $date, string $sku, int $qty, int $unitCost, string $method, ?int $supplierId = null): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $sku, $qty, $unitCost, $method, $supplierId) {
            $total = $qty * $unitCost;
            $this->assertFunds($branchId, $date, $method, $total);
            $this->inventory->receive($branchId, $this->productId($sku), $qty, $total, $date, 'pembelian');

            return $this->post($branchId, $date, [
                ['account' => self::PERSEDIAAN, 'debit' => $total],
                ['account' => in_array($method, ['kredit', 'utang'], true) ? self::UTANG : $this->moneyAccount($method), 'credit' => $total],
            ], 'purchase', "Pembelian {$sku}", null, $supplierId);
        });
    }

    /** Retur ke supplier pada biaya rata-rata saat ini; pengembalian uang via $refundMethod atau mengurangi utang. */
    public function returnToSupplier(int $branchId, string $date, string $sku, int $qty, string $refundMethod, ?int $supplierId = null): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $sku, $qty, $refundMethod, $supplierId) {
            $cost = $this->inventory->issue($branchId, $this->productId($sku), $qty, $date, 'retur');
            if ($supplierId && in_array($refundMethod, ['utang', 'kredit'], true)
                && $cost > app(\App\Domain\Accounting\OpsReportService::class)->payables($branchId, $date, $supplierId)) {
                throw new \DomainException('Nilai barang yang dikembalikan lebih besar dari utang ke pemasok ini. Pilih "uang kembali" lewat kas atau bank, atau pilih pemasok yang benar.');
            }

            return $this->post($branchId, $date, [
                ['account' => in_array($refundMethod, ['utang', 'kredit'], true) ? self::UTANG : $this->moneyAccount($refundMethod), 'debit' => $cost],
                ['account' => self::PERSEDIAAN, 'credit' => $cost],
            ], 'purchase', "Retur {$sku}", null, $supplierId);
        });
    }

    public function payPayable(int $branchId, string $date, int $amount, string $method, ?int $supplierId = null): Journal
    {
        $this->assertFunds($branchId, $date, $method, $amount);

        return $this->post($branchId, $date, [
            ['account' => self::UTANG, 'debit' => $amount],
            ['account' => $this->moneyAccount($method), 'credit' => $amount],
        ], 'purchase', 'Pembayaran utang usaha', null, $supplierId);
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
    public function completeService(int $branchId, string $date, array $parts, int $labor, int $depositApplied, string $method, int $mdrBps = 0, ?string $ticket = null, string $source = 'service', string $description = 'Servis selesai', int $discount = 0): Journal
    {
        return DB::transaction(function () use ($branchId, $date, $parts, $labor, $depositApplied, $method, $mdrBps, $ticket, $source, $description, $discount) {
            $partsRevenue = 0;
            $hpp = 0;
            foreach ($parts as $p) {
                $partsRevenue += $p['qty'] * $p['price'];
                $hpp += $this->inventory->issue($branchId, $this->productId($p['sku']), $p['qty'], $date, 'penjualan', $ticket);
            }

            $total = $partsRevenue + $labor;
            $due = $total - $depositApplied - $discount;
            if ($due < 0) {
                throw new \DomainException('DP dan potongan poin melebihi total tagihan. Kurangi poin yang dipakai.');
            }
            $mdr = $method === 'qris' ? intdiv($due * $mdrBps * 2 + 10000, 20000) : 0;

            $lines = [];
            if ($depositApplied > 0) {
                $lines[] = ['account' => self::UANG_MUKA, 'debit' => $depositApplied];
            }
            if ($discount > 0) {
                // Potongan poin dicatat sebagai beban promosi, pendapatan tetap penuh.
                $lines[] = ['account' => $this->ensure(self::BEBAN_POIN), 'debit' => $discount];
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

            return $this->post($branchId, $date, $lines, $source, $description, $ticket);
        });
    }

    /** Servis batal: DP dikurangi biaya cek menjadi pendapatan jasa, sisanya dikembalikan. */
    public function cancelService(int $branchId, string $date, int $deposit, int $fee, string $refundMethod, ?string $ticket = null): Journal
    {
        $refund = $deposit - $fee;
        if ($refund > 0) {
            $this->assertFunds($branchId, $date, $refundMethod, $refund);
        }
        $lines = [['account' => self::UANG_MUKA, 'debit' => $deposit]];
        if ($fee > 0) {
            $lines[] = ['account' => self::PEND_JASA, 'credit' => $fee];
        }
        if ($refund > 0) {
            $lines[] = ['account' => $this->moneyAccount($refundMethod), 'credit' => $refund];
        }

        return $this->post($branchId, $date, $lines, 'service', 'Servis dibatalkan', $ticket);
    }

    /** Pindahkan nilai barang rusak dari persediaan ke "barang klaim di pemasok". Stoknya sudah dikeluarkan oleh pemanggil. */
    public function moveToClaim(int $branchId, string $date, int $cost, string $description, ?string $reference = null, ?int $supplierId = null): Journal
    {
        return $this->post($branchId, $date, [
            ['account' => $this->ensure(self::BARANG_KLAIM), 'debit' => $cost],
            ['account' => self::PERSEDIAAN, 'credit' => $cost],
        ], 'purchase', $description, $reference, $supplierId);
    }

    /**
     * Klaim selesai. $outcome: replaced (barang baru masuk stok), refunded (uang kembali $refund lewat $method,
     * selisih dengan nilai barang jadi kerugian), rejected (seluruh nilai jadi kerugian).
     */
    public function settleClaim(int $branchId, string $date, string $outcome, int $cost, int $refund, ?string $method, ?string $reference, ?int $supplierId): Journal
    {
        $claim = $this->ensure(self::BARANG_KLAIM);
        $lines = [];
        if ($outcome === 'replaced') {
            $lines[] = ['account' => self::PERSEDIAAN, 'debit' => $cost];
        } elseif ($outcome === 'refunded') {
            if ($refund > 0) {
                $lines[] = ['account' => in_array($method, ['utang', 'kredit'], true) ? self::UTANG : $this->moneyAccount((string) $method), 'debit' => $refund];
            }
            if ($cost - $refund > 0) {
                $lines[] = ['account' => $this->ensure(self::RUGI_KLAIM), 'debit' => $cost - $refund];
            }
        } else {
            $lines[] = ['account' => $this->ensure(self::RUGI_KLAIM), 'debit' => $cost];
        }
        $lines[] = ['account' => $claim, 'credit' => $cost];

        return $this->post($branchId, $date, $lines, 'purchase', 'Klaim pemasok selesai: '.$outcome, $reference, $supplierId);
    }

    /** Selisih hasil opname: $value negatif = barang kurang (rugi), positif = barang lebih. Stok sudah disesuaikan oleh pemanggil. */
    public function stockCountAdjustment(int $branchId, string $date, int $value, string $reference): Journal
    {
        $acc = $this->ensure(self::SELISIH_STOK);

        return $this->post($branchId, $date, $value < 0
            ? [['account' => $acc, 'debit' => -$value], ['account' => self::PERSEDIAAN, 'credit' => -$value]]
            : [['account' => self::PERSEDIAAN, 'debit' => $value], ['account' => $acc, 'credit' => $value]],
            'manual', 'Selisih stok opname '.$reference, $reference);
    }

    /** Selisih kas laci: $diff negatif = uang kurang (rugi), positif = uang lebih. */
    public function cashDifference(int $branchId, string $date, int $diff, string $reference): Journal
    {
        $acc = $this->ensure(self::SELISIH_KAS);

        return $this->post($branchId, $date, $diff < 0
            ? [['account' => $acc, 'debit' => -$diff], ['account' => self::KAS, 'credit' => -$diff]]
            : [['account' => self::KAS, 'debit' => $diff], ['account' => $acc, 'credit' => $diff]],
            'manual', 'Selisih kas '.$reference, $reference);
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
    public function posSale(int $branchId, string $date, array $items, string $method, int $mdrBps = 0, ?string $receipt = null, int $discount = 0): Journal
    {
        return $this->completeService($branchId, $date, $items, 0, 0, $method, $mdrBps, $receipt, 'pos', 'Penjualan kasir', $discount);
    }

    /** $method: kas, bank, kode rekening, atau kredit/utang (belum dibayar, masuk utang usaha). */
    public function payExpense(int $branchId, string $date, string $expenseAccount, int $amount, string $method, string $description): Journal
    {
        $this->positive($amount);
        $this->assertFunds($branchId, $date, $method, $amount);

        return $this->post($branchId, $date, [
            ['account' => $expenseAccount, 'debit' => $amount],
            ['account' => in_array($method, ['kredit', 'utang'], true) ? self::UTANG : $this->moneyAccount($method), 'credit' => $amount],
        ], 'manual', $description);
    }

    /** Tolak uang keluar kalau saldo kas/bank cabang itu sampai tanggal tersebut tidak cukup. */
    public function assertFunds(int $branchId, string $date, string $method, int $amount): void
    {
        if (in_array($method, ['kredit', 'utang'], true) || $amount <= 0) {
            return;
        }
        $code = $this->moneyAccount($method);
        $saldo = (int) DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->where('j.branch_id', $branchId)->where('j.entry_date', '<=', $date)
            ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
        if ($amount > $saldo) {
            $nama = \App\Models\Account::where('code', $code)->value('name') ?? $code;
            throw new \DomainException('Saldo '.$nama.' di cabang ini hanya Rp'.number_format(max($saldo, 0), 0, ',', '.').', tidak cukup untuk Rp'.number_format($amount, 0, ',', '.').'. Kecilkan jumlahnya, pilih sumber uang lain, atau tambah modal dulu.');
        }
    }

    private function positive(int $amount): void
    {
        if ($amount <= 0) {
            throw new \DomainException('Jumlah harus lebih dari nol. Isi jumlah rupiahnya.');
        }
    }

    /** Pastikan akun bawaan ada (untuk database lama yang belum menjalankan seeder akun). */
    private function ensure(string $code): string
    {
        foreach (\Database\Seeders\AccountSeeder::ACCOUNTS as [$c, $name, $type]) {
            if ($c === $code) {
                \App\Models\Account::firstOrCreate(['code' => $c], ['name' => $name, 'type' => $type, 'is_active' => true]);
            }
        }

        return $code;
    }

    /** Pemilik mengambil uang usaha untuk keperluan pribadi (prive). Bukan biaya, tidak mengurangi untung. */
    public function ownerWithdrawal(int $branchId, string $date, int $amount, string $method, string $note = ''): Journal
    {
        $this->positive($amount);
        $this->assertFunds($branchId, $date, $method, $amount);

        return $this->post($branchId, $date, [
            ['account' => $this->ensure('3-2000'), 'debit' => $amount],
            ['account' => $this->moneyAccount($method), 'credit' => $amount],
        ], 'manual', trim('Pemilik mengambil uang '.$note));
    }

    /** Uang masuk dari pinjaman (bank atau orang lain). Menjadi utang pinjaman, bukan pemasukan. */
    public function receiveLoan(int $branchId, string $date, int $amount, string $method, string $note = ''): Journal
    {
        $this->positive($amount);

        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($method), 'debit' => $amount],
            ['account' => $this->ensure('2-1700'), 'credit' => $amount],
        ], 'manual', trim('Terima pinjaman '.$note));
    }

    /** Bayar cicilan pinjaman: pokok mengurangi utang, bunga menjadi biaya. */
    public function repayLoan(int $branchId, string $date, int $principal, int $interest, string $method, string $note = ''): Journal
    {
        if ($principal < 0 || $interest < 0 || $principal + $interest <= 0) {
            throw new \DomainException('Isi jumlah cicilan pokok atau bunga. Angkanya tidak boleh minus.');
        }
        $this->ensure('2-1700');
        $owed = -(int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '2-1700')
            ->selectRaw('coalesce(sum(l.debit)-sum(l.credit),0) n')->value('n');
        if ($principal > $owed) {
            throw new \DomainException('Cicilan pokok melebihi sisa utang pinjaman (sisa Rp'.number_format(max($owed, 0), 0, ',', '.').'). Kecilkan jumlahnya.');
        }

        $this->assertFunds($branchId, $date, $method, $principal + $interest);

        return $this->post($branchId, $date, array_values(array_filter([
            ['account' => '2-1700', 'debit' => $principal],
            ['account' => $interest > 0 ? $this->ensure('8-1300') : '8-1300', 'debit' => $interest],
            ['account' => $this->moneyAccount($method), 'credit' => $principal + $interest],
        ], fn ($l) => ($l['debit'] ?? $l['credit']) > 0)), 'manual', trim('Bayar cicilan pinjaman '.$note));
    }

    /** Beli harta tahan lama (tanah, kendaraan, peralatan). Bukan biaya. $method boleh kredit (belum dibayar). */
    public function buyAsset(int $branchId, string $date, string $assetCode, int $amount, string $method, string $note = ''): Journal
    {
        $this->positive($amount);
        $acc = \App\Models\Account::where('code', $assetCode)->where('type', 'asset')->first();
        if (! $acc || $assetCode < '1-2000' || $assetCode >= '1-2900') {
            throw new \DomainException('Pilih jenis harta tahan lama yang sudah ada di Daftar akun (tanah, kendaraan, peralatan).');
        }
        $this->assertFunds($branchId, $date, $method, $amount);

        return $this->post($branchId, $date, [
            ['account' => $assetCode, 'debit' => $amount],
            ['account' => in_array($method, ['kredit', 'utang'], true) ? self::UTANG : $this->moneyAccount($method), 'credit' => $amount],
        ], 'manual', trim('Beli '.$acc->name.' '.$note));
    }

    /** Penyusutan: nilai kendaraan atau peralatan turun karena dipakai. Tidak ada uang keluar. */
    public function depreciate(int $branchId, string $date, int $amount, string $note = ''): Journal
    {
        $this->positive($amount);

        return $this->post($branchId, $date, [
            ['account' => $this->ensure('6-3000'), 'debit' => $amount],
            ['account' => $this->ensure('1-2900'), 'credit' => $amount],
        ], 'manual', trim('Penyusutan harta '.$note));
    }

    /** Uang masuk di luar jualan, misalnya bunga bank. */
    public function otherIncome(int $branchId, string $date, int $amount, string $method, string $note = ''): Journal
    {
        $this->positive($amount);

        return $this->post($branchId, $date, [
            ['account' => $this->moneyAccount($method), 'debit' => $amount],
            ['account' => $this->ensure('4-2000'), 'credit' => $amount],
        ], 'manual', trim('Pendapatan lain '.$note));
    }

    /**
     * Transfer stok antar cabang pada biaya rata-rata cabang asal. Tiap cabang mendapat jurnalnya sendiri
     * lewat akun antar cabang (1-1600) yang saling meniadakan saat laporan dikonsolidasi.
     *
     * @return array{0: Journal, 1: Journal}
     */
    public function transferStock(int $fromBranch, int $toBranch, string $date, string $sku, int $qty): array
    {
        if ($fromBranch === $toBranch) {
            throw new \DomainException('Pilih cabang tujuan yang berbeda dari cabang asal.');
        }

        return DB::transaction(function () use ($fromBranch, $toBranch, $date, $sku, $qty) {
            $productId = $this->productId($sku);
            $cost = $this->inventory->issue($fromBranch, $productId, $qty, $date, 'transfer keluar', $sku);
            $this->inventory->receive($toBranch, $productId, $qty, $cost, $date, 'transfer masuk', $sku);

            $out = $this->post($fromBranch, $date, [
                ['account' => self::ANTAR_CABANG, 'debit' => $cost],
                ['account' => self::PERSEDIAAN, 'credit' => $cost],
            ], 'transfer', "Transfer {$sku} ke cabang {$toBranch}");
            $in = $this->post($toBranch, $date, [
                ['account' => self::PERSEDIAAN, 'debit' => $cost],
                ['account' => self::ANTAR_CABANG, 'credit' => $cost],
            ], 'transfer', "Transfer {$sku} dari cabang {$fromBranch}");

            return [$out, $in];
        });
    }
}
