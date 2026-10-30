<?php

namespace App\Domain\Customer;

use App\Models\Complaint;
use App\Models\PosSale;
use App\Models\ServiceTicket;
use App\Models\User;
use DomainException;

class ComplaintService
{
    public const TYPES = ['garansi' => 'Garansi / servis ulang', 'produk' => 'Barang bermasalah', 'layanan' => 'Pelayanan', 'lainnya' => 'Lainnya'];

    public const STATUSES = ['baru' => 'Baru', 'diproses' => 'Sedang diproses', 'selesai' => 'Selesai'];

    private function authorize(User $user, int $branchId): void
    {
        if (! $user->can('manage-services')) {
            throw new DomainException('Anda tidak punya izin mencatat komplain. Hubungi pemilik toko.');
        }
        if (! $user->canAccessBranch($branchId)) {
            throw new DomainException('Anda tidak punya akses ke cabang ini. Pilih cabang lain atau hubungi pemilik toko.');
        }
    }

    /** $data: type, description, optional ticket_code, receipt_no, customer_id. Pelanggan diambil dari servis/struk bila tidak diisi. */
    public function create(User $user, int $branchId, string $date, array $data): Complaint
    {
        $this->authorize($user, $branchId);
        if (! isset(self::TYPES[$data['type'] ?? ''])) {
            throw new DomainException('Jenis komplain tidak dikenal. Pilih salah satu jenis yang tersedia.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new DomainException('Isi cerita komplainnya, supaya bisa ditangani.');
        }

        $ticket = null;
        if (trim((string) ($data['ticket_code'] ?? '')) !== '') {
            $ticket = ServiceTicket::where('code', trim($data['ticket_code']))->where('branch_id', $branchId)->first()
                ?? throw new DomainException('Nomor servis tidak ditemukan di cabang ini. Periksa nomor di nota servis.');
        }
        $sale = null;
        if (trim((string) ($data['receipt_no'] ?? '')) !== '') {
            $sale = PosSale::where('receipt_no', trim($data['receipt_no']))->where('branch_id', $branchId)->first()
                ?? throw new DomainException('Nomor struk tidak ditemukan di cabang ini. Periksa nomor di bagian atas struk.');
        }

        return Complaint::create([
            'branch_id' => $branchId,
            'customer_id' => ($data['customer_id'] ?? null) ?: ($ticket?->customer_id ?? $sale?->customer_id),
            'service_ticket_id' => $ticket?->id,
            'pos_sale_id' => $sale?->id,
            'type' => $data['type'],
            'description' => $description,
            'status' => 'baru',
            'received_on' => $date,
            'created_by' => $user->id,
        ]);
    }

    /** Tangani komplain: ubah status dan tulis penyelesaiannya. Komplain yang selesai tidak dibuka lagi. */
    public function handle(Complaint $complaint, User $user, string $date, string $status, ?string $resolution): Complaint
    {
        $this->authorize($user, $complaint->branch_id);
        if (! in_array($status, ['diproses', 'selesai'], true)) {
            throw new DomainException('Status tidak dikenal. Pilih sedang diproses atau selesai.');
        }
        if ($status === 'selesai' && trim((string) $resolution) === '') {
            throw new DomainException('Tulis penyelesaiannya dulu, misalnya "diganti baterai baru" atau "uang dikembalikan".');
        }
        if ($complaint->status === 'selesai') {
            throw new DomainException('Komplain ini sudah selesai dan tidak bisa diubah lagi.');
        }

        $complaint->update([
            'status' => $status,
            'resolution' => $resolution !== null ? mb_substr(trim($resolution), 0, 1000) : $complaint->resolution,
            'resolved_on' => $status === 'selesai' ? $date : null,
        ]);

        return $complaint;
    }
}
