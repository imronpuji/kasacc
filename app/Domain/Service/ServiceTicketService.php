<?php

namespace App\Domain\Service;

use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalLine;
use App\Models\ServiceTicket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ServiceTicketService
{
    public function __construct(private Transactions $transactions)
    {
    }

    private function authorize(User $user, int $branchId): void
    {
        if (! $user->can('manage-services')) {
            throw new DomainException('Anda tidak punya izin mengelola servis. Hubungi pemilik toko.');
        }
        if (! $user->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
    }

    /** Terima unit dari pelanggan; DP (bila ada) dicatat sebagai uang muka, bukan pendapatan. */
    public function receive(User $user, int $branchId, string $date, array $data, int $deposit = 0, string $depositMethod = 'kas'): ServiceTicket
    {
        $this->authorize($user, $branchId);
        if ($deposit < 0) {
            throw new DomainException('Uang muka (DP) tidak boleh minus. Isi 0 kalau tidak ada DP.');
        }

        return DB::transaction(function () use ($branchId, $date, $data, $deposit, $depositMethod) {
            $ticket = ServiceTicket::create([
                'branch_id' => $branchId,
                'code' => $this->nextCode($branchId, $date),
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'device' => $data['device'],
                'complaint' => $data['complaint'] ?? null,
                'technician_id' => $data['technician_id'] ?? null,
                'status' => 'diterima',
                'deposit' => $deposit,
                'received_on' => $date,
            ]);

            if ($deposit > 0) {
                $this->transactions->receiveDeposit($branchId, $date, $deposit, $depositMethod, $ticket->code);
            }

            return $ticket;
        });
    }

    /** $parts: [['sku' => ..., 'qty' => ..., 'price' => ...]]. DP dipotong dari tagihan. */
    public function complete(ServiceTicket $ticket, User $user, string $date, array $parts, int $labor, string $method): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);

        return DB::transaction(function () use ($ticket, $date, $parts, $labor, $method) {
            $ticket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'diterima') {
                throw new DomainException('Servis ini sudah ditutup (selesai atau dibatalkan), jadi tidak bisa diubah lagi.');
            }

            $partsTotal = array_sum(array_map(fn ($p) => $p['qty'] * $p['price'], $parts));
            $total = $partsTotal + $labor;
            if ($labor < 0 || $total <= 0) {
                throw new DomainException('Total biaya servis harus lebih dari Rp0. Isi ongkos servis atau tambah sparepart.');
            }
            if ($ticket->deposit > $total) {
                throw new DomainException('DP melebihi total biaya servis. Tambah ongkos servis atau sparepart, atau pakai Batalkan servis.');
            }

            $mdrBps = $method === 'qris' ? \App\Domain\Accounting\TaxSettings::qrisBps() : 0;
            $journal = $this->transactions->completeService(
                $ticket->branch_id, $date, $parts, $labor, (int) $ticket->deposit, $method, $mdrBps, $ticket->code,
            );

            $ticket->update([
                'status' => 'selesai', 'labor_fee' => $labor, 'parts_total' => $partsTotal, 'total' => $total,
                'payment_method' => $method, 'closed_on' => $date, 'journal_id' => $journal->id,
            ]);

            return $ticket;
        });
    }

    /** Batal: DP dikurangi biaya cek menjadi pendapatan jasa, sisanya dikembalikan. */
    public function cancel(ServiceTicket $ticket, User $user, string $date, int $fee, string $refundMethod = 'kas'): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);

        return DB::transaction(function () use ($ticket, $date, $fee, $refundMethod) {
            $ticket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'diterima') {
                throw new DomainException('Servis ini sudah ditutup (selesai atau dibatalkan), jadi tidak bisa diubah lagi.');
            }
            if ($fee < 0 || $fee > $ticket->deposit) {
                throw new DomainException('Biaya pengecekan tidak boleh lebih besar dari DP yang sudah diterima.');
            }

            $journalId = null;
            if ($ticket->deposit > 0) {
                $journalId = $this->transactions->cancelService($ticket->branch_id, $date, (int) $ticket->deposit, $fee, $refundMethod, $ticket->code)->id;
            }

            $ticket->update([
                'status' => 'batal', 'labor_fee' => $fee, 'total' => $fee, 'closed_on' => $date, 'journal_id' => $journalId,
            ]);

            return $ticket;
        });
    }

    /** Klaim garansi: sparepart pengganti keluar dari stok sebagai beban garansi, bukan pendapatan. */
    public function claimWarranty(ServiceTicket $ticket, User $user, string $date, string $sku, int $qty = 1): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);

        return DB::transaction(function () use ($ticket, $date, $sku, $qty) {
            $ticket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'selesai') {
                throw new DomainException('Garansi hanya bisa dicatat untuk servis yang sudah selesai.');
            }

            $journal = $this->transactions->warrantyClaim($ticket->branch_id, $date, $sku, $qty, $ticket->code);
            $accountId = Account::where('code', Transactions::BEBAN_GARANSI)->value('id');
            $cost = (int) JournalLine::where('journal_id', $journal->id)->where('account_id', $accountId)->sum('debit');
            $ticket->update(['warranty_cost' => $ticket->warranty_cost + $cost]);

            return $ticket;
        });
    }

    private function nextCode(int $branchId, string $date): string
    {
        // Kunci baris cabang supaya dua penerimaan bersamaan tidak mengambil nomor servis yang sama.
        $code = Branch::lockForUpdate()->findOrFail($branchId)->code;
        $seq = ServiceTicket::where('branch_id', $branchId)->whereDate('received_on', $date)->count() + 1;

        return sprintf('SRV-%s-%s-%03d', $code, str_replace('-', '', $date), $seq);
    }
}
