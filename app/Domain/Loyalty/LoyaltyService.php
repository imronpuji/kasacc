<?php

namespace App\Domain\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyPoint;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Poin member. Poin dapat dari uang yang benar-benar dibayar pelanggan (setelah potongan poin) dan dipakai
 * sebagai potongan harga. Semua gerak poin masuk buku poin; saldo tidak disimpan di tempat lain.
 * Panggil dari dalam transaksi database yang sama dengan penjualan atau servisnya.
 */
class LoyaltyService
{
    public function balance(Customer $customer): int
    {
        return (int) LoyaltyPoint::where('customer_id', $customer->id)->sum('points');
    }

    public function earnPer(): int
    {
        return max(1, (int) config('kasir.loyalty.earn_per'));
    }

    public function pointValue(): int
    {
        return max(1, (int) config('kasir.loyalty.point_value'));
    }

    /** Poin yang didapat dari uang yang dibayar. */
    public function pointsFor(int $paid): int
    {
        return max(intdiv($paid, $this->earnPer()), 0);
    }

    /** Potongan terbesar yang boleh diberikan untuk tagihan ini (persen dari total, dan tidak melebihi sisa bayar). */
    public function maxDiscount(int $total, int $payable): int
    {
        $byPercent = intdiv($total * max(0, min(100, (int) config('kasir.loyalty.max_redeem_percent'))), 100);

        return max(min($byPercent, $payable), 0);
    }

    /**
     * Periksa pemakaian poin dan kembalikan nilai potongannya (Rp). Mengunci baris pelanggan supaya dua kasir
     * tidak memakai poin yang sama bersamaan. $total = tagihan penuh, $payable = yang masih harus dibayar (setelah DP).
     */
    public function discountFor(?Customer $customer, int $points, int $total, int $payable): int
    {
        if ($points === 0) {
            return 0;
        }
        if ($points < 0) {
            throw new DomainException('Jumlah poin yang dipakai tidak boleh minus.');
        }
        if (! $customer) {
            throw new DomainException('Isi email atau nomor HP pelanggan dulu untuk memakai poin.');
        }
        Customer::whereKey($customer->id)->lockForUpdate()->first();
        $balance = $this->balance($customer);
        if ($points > $balance) {
            throw new DomainException('Poin pelanggan hanya '.number_format($balance, 0, ',', '.').', tidak cukup untuk memakai '.number_format($points, 0, ',', '.').' poin.');
        }
        $discount = $points * $this->pointValue();
        $max = $this->maxDiscount($total, $payable);
        if ($discount > $max) {
            throw new DomainException('Potongan poin paling banyak Rp'.number_format($max, 0, ',', '.').' ('.(int) config('kasir.loyalty.max_redeem_percent').'% dari tagihan). Kurangi poin yang dipakai.');
        }

        return $discount;
    }

    /** Catat pemakaian dan perolehan poin untuk satu transaksi. $paid = uang yang dibayar pelanggan setelah potongan. */
    public function record(?Customer $customer, string $sourceType, int $sourceId, int $pointsRedeemed, int $paid, ?int $userId = null): int
    {
        if (! $customer) {
            return 0;
        }
        if ($pointsRedeemed > 0) {
            $this->add($customer, -$pointsRedeemed, 'redeem', $sourceType, $sourceId, null, $userId);
        }
        $earned = $this->pointsFor($paid);
        if ($earned > 0) {
            $this->add($customer, $earned, 'earn', $sourceType, $sourceId, null, $userId);
        }

        return $earned;
    }

    /** Batalkan efek satu transaksi: poin yang didapat ditarik, poin yang dipakai dikembalikan. Aman dipanggil sekali per transaksi. */
    public function reverse(string $sourceType, int $sourceId, ?int $userId = null): void
    {
        $rows = LoyaltyPoint::where('source_type', $sourceType)->where('source_id', $sourceId)->whereIn('kind', ['earn', 'redeem'])->get();
        foreach ($rows as $r) {
            $this->add($r->customer, -$r->points, $r->kind === 'earn' ? 'void_earn' : 'void_redeem', $sourceType, $sourceId, null, $userId);
        }
    }

    /**
     * Retur sebagian: tarik poin yang didapat dan kembalikan poin yang dipakai, sebanding dengan bagian struk yang diretur.
     * $returnId = id retur (satu baris per jenis per retur). Dihitung kumulatif supaya beberapa retur kecil tidak menumpuk pembulatan.
     */
    public function reversePartial(?Customer $customer, int $saleId, int $returnId, int $paid, int $refundBefore, int $refund, int $discount, int $discountBefore, int $discountShare, int $pointsRedeemed, ?int $userId = null): void
    {
        if (! $customer) {
            return;
        }
        $earned = (int) LoyaltyPoint::where('source_type', 'pos_sale')->where('source_id', $saleId)->where('kind', 'earn')->value('points');
        $earnBack = $paid > 0 ? intdiv($earned * ($refundBefore + $refund), $paid) - intdiv($earned * $refundBefore, $paid) : 0;
        $redeemBack = $discount > 0 ? intdiv($pointsRedeemed * ($discountBefore + $discountShare), $discount) - intdiv($pointsRedeemed * $discountBefore, $discount) : 0;
        if ($earnBack > 0) {
            $this->add($customer, -$earnBack, 'void_earn', 'pos_return', $returnId, null, $userId);
        }
        if ($redeemBack > 0) {
            $this->add($customer, $redeemBack, 'void_redeem', 'pos_return', $returnId, null, $userId);
        }
    }

    /** Koreksi manual oleh pemilik (salah catat, hadiah, dll.). Alasan wajib. Saldo tidak boleh jadi minus. */
    public function adjust(Customer $customer, int $points, string $reason, User $by): void
    {
        if (! $by->hasRole('owner')) {
            throw new DomainException('Hanya pemilik toko yang boleh mengoreksi poin pelanggan.');
        }
        $reason = trim($reason);
        if ($points === 0 || $reason === '') {
            throw new DomainException('Isi jumlah poin (boleh minus) dan alasan koreksinya.');
        }
        DB::transaction(function () use ($customer, $points, $reason, $by) {
            Customer::whereKey($customer->id)->lockForUpdate()->first();
            if ($this->balance($customer) + $points < 0) {
                throw new DomainException('Koreksi ini membuat saldo poin minus. Kurangi jumlahnya.');
            }
            $this->add($customer, $points, 'adjust', null, null, mb_substr($reason, 0, 200), $by->id);
        });
    }

    private function add(Customer $customer, int $points, string $kind, ?string $sourceType, ?int $sourceId, ?string $note, ?int $userId): void
    {
        LoyaltyPoint::create([
            'customer_id' => $customer->id, 'points' => $points, 'kind' => $kind,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'note' => $note, 'created_by' => $userId,
        ]);
    }
}
