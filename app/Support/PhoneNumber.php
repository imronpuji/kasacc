<?php

namespace App\Support;

class PhoneNumber
{
    /** Nomor HP Indonesia dalam bentuk 08xxxxxxxxxx, atau null kalau bukan nomor yang masuk akal. */
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '62')) {
            $d = '0'.substr($d, 2);
        } elseif (str_starts_with($d, '8')) {
            $d = '0'.$d;
        }

        return preg_match('/^08\d{7,12}$/', $d) ? $d : null;
    }
}
