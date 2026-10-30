<?php

namespace App\Domain\Customer;

use Illuminate\Support\Facades\Log;

/** Hanya menulis pesan ke log. Untuk pengembangan dan tes; tidak pernah sampai ke pelanggan. */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $message): void
    {
        Log::info("OTP untuk {$phone}: {$message}");
    }
}
