<?php

namespace App\Domain\Service;

use App\Domain\Customer\CustomerService;
use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\ServiceTicket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceTicketService
{
    /** Proses pengerjaan yang bisa dipilih staf selama HP belum ditutup. Status selesai/batal diatur otomatis oleh Selesai dan Batalkan. */
    public const PROGRESS = [
        'diterima' => 'HP sudah diterima',
        'dikerjakan' => 'Sedang dikerjakan',
        'menunggu_sparepart' => 'Menunggu sparepart',
        'siap_diambil' => 'Siap diambil',
    ];

    public function __construct(private Transactions $transactions, private CustomerService $customers, private \App\Domain\Loyalty\LoyaltyService $loyalty, private \App\Domain\Customer\CustomerNotifier $notifier, private \App\Domain\Purchasing\SupplierClaimService $supplierClaims)
    {
    }

    /** Tulisan status untuk pelanggan, dari status tiket dan proses terakhir. */
    public static function publicStatus(ServiceTicket $t): string
    {
        return match ($t->status) {
            'selesai' => 'Selesai. HP bisa diambil.',
            'batal' => 'Servis dibatalkan.',
            default => self::PROGRESS[$t->progress] ?? 'Sedang dikerjakan',
        };
    }

    private function logProgress(ServiceTicket $ticket, string $progress, ?string $note, ?int $userId): void
    {
        DB::table('service_progress_logs')->insert([
            'service_ticket_id' => $ticket->id, 'progress' => $progress, 'note' => $note ? mb_substr($note, 0, 200) : null,
            'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Ubah proses pengerjaan (mis. menunggu sparepart); pelanggan melihatnya di tautan lacak dan portal. */
    public function updateProgress(ServiceTicket $ticket, User $user, string $progress, ?string $note = null): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);
        if (! isset(self::PROGRESS[$progress])) {
            throw new DomainException('Proses tidak dikenal. Pilih salah satu proses yang tersedia.');
        }

        $ticket = DB::transaction(function () use ($ticket, $user, $progress, $note) {
            $ticket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'diterima') {
                throw new DomainException('Servis ini sudah ditutup (selesai atau dibatalkan), jadi prosesnya tidak bisa diubah lagi.');
            }
            $ticket->update(['progress' => $progress]);
            $this->logProgress($ticket, $progress, $note, $user->id);

            return $ticket;
        });

        // Di luar transaksi: email lambat atau gagal tidak boleh menahan atau membatalkan perubahan proses.
        if ($progress === 'siap_diambil') {
            $this->notifier->serviceReady($ticket);
        }

        return $ticket;
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

        $customer = $this->customers->resolve($data['customer_name'] ?? null, $data['customer_phone'] ?? null, $data['customer_email'] ?? null);

        return DB::transaction(function () use ($user, $branchId, $date, $data, $deposit, $depositMethod, $customer) {
            $ticket = ServiceTicket::create([
                'branch_id' => $branchId,
                'customer_id' => $customer?->id,
                'public_token' => Str::random(32),
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

            $this->logProgress($ticket, 'diterima', null, $user->id);

            if ($deposit > 0) {
                $this->transactions->receiveDeposit($branchId, $date, $deposit, $depositMethod, $ticket->code);
            }

            return $ticket;
        });
    }

    /** $parts: [['sku' => ..., 'qty' => ..., 'price' => ...]]. DP dipotong dari tagihan. */
    public function complete(ServiceTicket $ticket, User $user, string $date, array $parts, int $labor, string $method, int $redeemPoints = 0): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);

        $done = DB::transaction(function () use ($ticket, $user, $date, $parts, $labor, $method, $redeemPoints) {
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

            $customer = $ticket->customer_id ? \App\Models\Customer::find($ticket->customer_id) : null;
            $discount = $this->loyalty->discountFor($customer, $redeemPoints, $total, $total - (int) $ticket->deposit);

            $mdrBps = $method === 'qris' ? \App\Domain\Accounting\TaxSettings::qrisBps() : 0;
            $journal = $this->transactions->completeService(
                $ticket->branch_id, $date, $parts, $labor, (int) $ticket->deposit, $method, $mdrBps, $ticket->code, 'service', 'Servis selesai', $discount,
            );

            $ticket->update([
                'status' => 'selesai', 'labor_fee' => $labor, 'parts_total' => $partsTotal, 'total' => $total,
                'discount' => $discount, 'points_redeemed' => $redeemPoints,
                'payment_method' => $method, 'closed_on' => $date, 'journal_id' => $journal->id,
            ]);
            $this->logProgress($ticket, 'selesai', null, $user->id);
            $this->loyalty->record($customer, 'service_ticket', $ticket->id, $redeemPoints, $total - $discount, $user->id);

            return $ticket;
        });

        // Servis selesai berarti HP siap diambil. Email dikirim sekali saja (kalau sudah terkirim dari "Siap diambil", tidak dikirim lagi).
        $this->notifier->serviceReady($done);

        return $done;
    }

    /** Batal: DP dikurangi biaya cek menjadi pendapatan jasa, sisanya dikembalikan. */
    public function cancel(ServiceTicket $ticket, User $user, string $date, int $fee, string $refundMethod = 'kas'): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);

        return DB::transaction(function () use ($ticket, $user, $date, $fee, $refundMethod) {
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
            $this->logProgress($ticket, 'batal', null, $user->id);

            return $ticket;
        });
    }

    /** Klaim garansi: sparepart pengganti keluar dari stok sebagai beban garansi, bukan pendapatan. */
    /** $toSupplier = ['reason' => ..., 'supplier_id' => ...] kalau sparepart yang gagal juga ditagihkan ke pemasok. */
    public function claimWarranty(ServiceTicket $ticket, User $user, string $date, string $sku, int $qty = 1, ?array $toSupplier = null): ServiceTicket
    {
        $this->authorize($user, $ticket->branch_id);

        return DB::transaction(function () use ($ticket, $user, $date, $sku, $qty, $toSupplier) {
            $ticket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'selesai') {
                throw new DomainException('Garansi hanya bisa dicatat untuk servis yang sudah selesai.');
            }

            $journal = $this->transactions->warrantyClaim($ticket->branch_id, $date, $sku, $qty, $ticket->code);
            $accountId = Account::where('code', Transactions::BEBAN_GARANSI)->value('id');
            $cost = (int) JournalLine::where('journal_id', $journal->id)->where('account_id', $accountId)->sum('debit');
            $ticket->update(['warranty_cost' => $ticket->warranty_cost + $cost]);
            $warrantyId = DB::table('warranty_claims')->insertGetId([
                'service_ticket_id' => $ticket->id, 'claimed_on' => $date, 'cost' => $cost, 'product_id' => Product::where('sku', $sku)->value('id'), 'qty' => $qty,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($toSupplier) {
                $this->supplierClaims->fromWarranty($user, $warrantyId, (string) ($toSupplier['reason'] ?? ''), $toSupplier['supplier_id'] ?? null);
            }

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
