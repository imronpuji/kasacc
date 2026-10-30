<?php

namespace App\Domain\Accounting;

use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\CashClosing;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tutup kas harian: uang di laci dihitung lalu dibandingkan dengan saldo kas di pembukuan.
 * Cocok = langsung tertutup. Selisih wajib diberi alasan dan menunggu persetujuan; baru setelah disetujui
 * selisihnya dibukukan (sebagai beban atau pendapatan "selisih kas").
 */
class CashClosingService
{
    public function __construct(private Transactions $transactions)
    {
    }

    /** Saldo kas (laci) menurut pembukuan sampai akhir tanggal itu. */
    public function expected(int $branchId, string $date): int
    {
        return (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', Transactions::KAS)->where('j.branch_id', $branchId)->where('j.entry_date', '<=', $date)
            ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');
    }

    public function close(User $user, int $branchId, string $date, int $counted, ?string $note = null): CashClosing
    {
        if (! $user->can('post-sales')) {
            throw new DomainException('Anda tidak punya izin menutup kas. Hubungi pemilik toko.');
        }
        if (! $user->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
        if ($counted < 0) {
            throw new DomainException('Uang yang dihitung tidak boleh minus.');
        }
        if ($date > now()->toDateString()) {
            throw new DomainException('Kas hari yang belum terjadi tidak bisa ditutup. Pilih hari ini atau sebelumnya.');
        }

        return DB::transaction(function () use ($user, $branchId, $date, $counted, $note) {
            Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            if (CashClosing::where('branch_id', $branchId)->whereDate('closing_date', $date)->where('status', '<>', 'rejected')->exists()) {
                throw new DomainException('Kas tanggal ini sudah ditutup atau sedang menunggu persetujuan.');
            }
            $expected = $this->expected($branchId, $date);
            $diff = $counted - $expected;
            $note = $note !== null ? mb_substr(trim($note), 0, 200) : null;
            if ($diff !== 0 && ($note === null || $note === '')) {
                throw new DomainException('Uang di laci berbeda dari catatan (selisih Rp'.number_format($diff, 0, ',', '.').'). Isi alasannya, misalnya "kembalian salah" atau "uang dipakai beli galon".');
            }

            return CashClosing::create([
                'branch_id' => $branchId, 'closing_date' => $date, 'expected' => $expected, 'counted' => $counted, 'diff' => $diff,
                'status' => $diff === 0 ? 'closed' : 'pending', 'note' => $note, 'created_by' => $user->id,
            ]);
        });
    }

    private function authorizeDecision(CashClosing $closing, User $user): void
    {
        if (! $user->can('approve-adjustments') || ! $user->canAccessBranch($closing->branch_id)) {
            throw new DomainException('Anda tidak berwenang memutuskan selisih kas di cabang ini. Minta pemilik atau penyetuju yang ditunjuk.');
        }
        if (! $user->hasRole('owner') && $user->id === (int) $closing->created_by) {
            throw new DomainException('Selisih kas harus diputuskan orang lain, bukan yang menutup kas.');
        }
    }

    /** Setujui selisih: dibukukan pada tanggal $date (hari ini). */
    public function approve(CashClosing $closing, User $user, string $date): CashClosing
    {
        $this->authorizeDecision($closing, $user);

        return DB::transaction(function () use ($closing, $user, $date) {
            $closing = CashClosing::lockForUpdate()->findOrFail($closing->id);
            if ($closing->status !== 'pending') {
                throw new DomainException('Penutupan kas ini sudah diproses sebelumnya.');
            }
            $journal = $this->transactions->cashDifference($closing->branch_id, $date, (int) $closing->diff, 'TUTUPKAS-'.$closing->id);
            $closing->update(['status' => 'closed', 'journal_id' => $journal->id, 'decided_by' => $user->id]);

            return $closing;
        });
    }

    /** Tolak: tidak ada yang dibukukan, kasir menghitung ulang dan menutup lagi. */
    public function reject(CashClosing $closing, User $user, ?string $reason = null): CashClosing
    {
        $this->authorizeDecision($closing, $user);

        return DB::transaction(function () use ($closing, $user, $reason) {
            $closing = CashClosing::lockForUpdate()->findOrFail($closing->id);
            if ($closing->status !== 'pending') {
                throw new DomainException('Penutupan kas ini sudah diproses sebelumnya.');
            }
            $reason = trim((string) $reason);
            $closing->update([
                'status' => 'rejected', 'decided_by' => $user->id,
                'note' => mb_substr(trim($closing->note.($reason !== '' ? ' (Ditolak: '.$reason.')' : ' (Ditolak)')), 0, 200),
            ]);

            return $closing;
        });
    }
}
