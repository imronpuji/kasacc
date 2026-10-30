<?php

namespace App\Domain\Customer;

use Illuminate\Support\Facades\Mail;

/** Mengirim kode lewat email memakai mailer aplikasi (MAIL_MAILER=resend untuk Resend). */
class MailOtpSender implements OtpSender
{
    public function send(string $to, string $subject, string $message): void
    {
        Mail::raw($message, fn ($m) => $m->to($to)->subject($subject));
    }
}
