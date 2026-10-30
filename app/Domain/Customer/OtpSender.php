<?php

namespace App\Domain\Customer;

/** Mengirim kode masuk ke pelanggan (email). Pengirim dipilih lewat config kasir.otp_driver. */
interface OtpSender
{
    public function send(string $to, string $subject, string $message): void;
}
