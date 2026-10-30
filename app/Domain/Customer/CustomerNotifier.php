<?php

namespace App\Domain\Customer;

use App\Models\ServiceTicket;
use Illuminate\Support\Facades\Log;

/**
 * Pesan otomatis ke pelanggan lewat email (pengirim sama dengan kode masuk: config kasir.otp_driver).
 * Gagal kirim tidak boleh menggagalkan pekerjaan staf: dicatat di log dan dikembalikan false.
 */
class CustomerNotifier
{
    public function __construct(private OtpSender $sender)
    {
    }

    /** Beri tahu pelanggan bahwa HP siap diambil. Sekali per servis; false kalau tidak ada email, sudah pernah, atau gagal kirim. */
    public function serviceReady(ServiceTicket $ticket): bool
    {
        $to = $ticket->customer_id ? \App\Models\Customer::whereKey($ticket->customer_id)->value('email') : null;
        if (! $to) {
            return false;
        }

        // Klaim dulu secara atomik supaya dua klik bersamaan tidak mengirim dua email; dikembalikan kalau pengiriman gagal.
        if (ServiceTicket::whereKey($ticket->id)->whereNull('ready_notified_at')->update(['ready_notified_at' => now()]) === 0) {
            return false;
        }

        try {
            $branch = \App\Models\Branch::whereKey($ticket->branch_id)->value('name') ?? 'toko kami';
            $this->sender->send($to, 'Servis Anda siap diambil',
                "Halo {$ticket->customer_name}, {$ticket->device} Anda di {$branch} sudah siap diambil.\n\nLihat status servis: ".route('track', $ticket->public_token)."\n\nTerima kasih.");
        } catch (\Throwable $e) {
            ServiceTicket::whereKey($ticket->id)->update(['ready_notified_at' => null]);
            Log::warning('Email servis siap gagal terkirim: '.$e->getMessage(), ['ticket' => $ticket->id]);

            return false;
        }

        return true;
    }
}
