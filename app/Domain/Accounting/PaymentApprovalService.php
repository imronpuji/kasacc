<?php

namespace App\Domain\Accounting;

use App\Domain\Transactions;
use App\Models\PaymentRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Pembayaran utang: yang mengajukan tidak boleh menyetujui sendiri (pemisahan tugas). */
class PaymentApprovalService
{
    public function __construct(private Transactions $transactions)
    {
    }

    public function request(User $maker, int $branchId, int $amount, string $method, string $description = 'Pembayaran utang usaha'): PaymentRequest
    {
        if (! $maker->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik usaha.');
        }

        if (! \App\Domain\Accounting\MoneyAccounts::valid($method)) {
            throw new DomainException('Sumber uang tidak dikenal. Pilih kas atau salah satu rekening bank.');
        }
        if ($amount <= 0) {
            throw new DomainException('Jumlah pembayaran harus lebih dari nol. Isi jumlah yang mau dibayar.');
        }
        $pending = (int) PaymentRequest::where('branch_id', $branchId)->where('status', 'pending')->sum('amount');
        $owed = app(OpsReportService::class)->payables($branchId, now()->addYears(10)->toDateString());
        if ($amount > $owed - $pending) {
            throw new DomainException('Jumlah melebihi sisa utang ke pemasok (sisa Rp'.number_format(max($owed - $pending, 0), 0, ',', '.').', sudah termasuk pengajuan lain yang masih menunggu). Kecilkan jumlahnya.');
        }

        return PaymentRequest::create([
            'branch_id' => $branchId, 'amount' => $amount, 'method' => $method,
            'description' => $description, 'status' => 'pending', 'requested_by' => $maker->id,
        ]);
    }

    public function approve(PaymentRequest $request, User $approver, string $date): PaymentRequest
    {
        if ($request->status !== 'pending') {
            throw new DomainException('Pengajuan ini sudah diproses sebelumnya, tidak perlu diulang.');
        }
        if ($approver->id === $request->requested_by) {
            throw new DomainException('Pembayaran harus disetujui orang lain, bukan yang mengajukan.');
        }
        if (! $approver->can('approve-payments') || ! $approver->canAccessBranch($request->branch_id)) {
            throw new DomainException('Anda tidak berwenang menyetujui pembayaran di cabang ini. Minta pemilik atau penyetuju yang ditunjuk.');
        }

        if ((int) $request->amount > app(OpsReportService::class)->payables($request->branch_id, now()->addYears(10)->toDateString())) {
            throw new DomainException('Sisa utang sudah berkurang, jadi jumlah pengajuan terlalu besar. Tolak lalu ajukan lagi dengan jumlah baru.');
        }

        return DB::transaction(function () use ($request, $approver, $date) {
            $journal = $this->transactions->payPayable($request->branch_id, $date, (int) $request->amount, $request->method);
            $request->update(['status' => 'approved', 'approved_by' => $approver->id, 'journal_id' => $journal->id]);

            return $request;
        });
    }

    /** Menolak pengajuan: hanya mengubah status, tidak menyentuh pembukuan. Aturan izin sama dengan approve(). */
    public function reject(PaymentRequest $request, User $rejecter, ?string $reason = null): PaymentRequest
    {
        if ($request->status !== 'pending') {
            throw new DomainException('Pengajuan ini sudah diproses.');
        }
        if ($rejecter->id === $request->requested_by) {
            throw new DomainException('Pengaju tidak boleh menolak pengajuannya sendiri.');
        }
        if (! $rejecter->can('approve-payments') || ! $rejecter->canAccessBranch($request->branch_id)) {
            throw new DomainException('Pengguna ini tidak berwenang menolak pembayaran di cabang tersebut.');
        }

        $reason = trim((string) $reason);
        $request->update([
            'status' => 'rejected', 'approved_by' => $rejecter->id,
            'description' => $reason !== '' ? mb_substr(trim($request->description.' (Ditolak: '.$reason.')'), 0, 250) : $request->description,
        ]);

        return $request;
    }
}
