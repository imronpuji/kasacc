<?php

namespace App\Domain\Customer;

/** Mengirim pesan singkat ke nomor HP pelanggan (WhatsApp/SMS). Pengirim sungguhan didaftarkan lewat config kasir.otp_driver. */
interface OtpSender
{
    public function send(string $phone, string $message): void;
}
