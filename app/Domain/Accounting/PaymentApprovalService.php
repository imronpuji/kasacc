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
            throw new DomainException('Pengguna tidak punya akses ke cabang ini.');
        }

        return PaymentRequest::create([
            'branch_id' => $branchId, 'amount' => $amount, 'method' => $method,
            'description' => $description, 'status' => 'pending', 'requested_by' => $maker->id,
        ]);
    }

    public function approve(PaymentRequest $request, User $approver, string $date): PaymentRequest
    {
        if ($request->status !== 'pending') {
            throw new DomainException('Pengajuan ini sudah diproses.');
        }
        if ($approver->id === $request->requested_by) {
            throw new DomainException('Pengaju tidak boleh menyetujui pembayarannya sendiri.');
        }
        if (! $approver->can('approve-payments') || ! $approver->canAccessBranch($request->branch_id)) {
            throw new DomainException('Pengguna ini tidak berwenang menyetujui pembayaran di cabang tersebut.');
        }

        return DB::transaction(function () use ($request, $approver, $date) {
            $journal = $this->transactions->payPayable($request->branch_id, $date, (int) $request->amount, $request->method);
            $request->update(['status' => 'approved', 'approved_by' => $approver->id, 'journal_id' => $journal->id]);

            return $request;
        });
    }
}
