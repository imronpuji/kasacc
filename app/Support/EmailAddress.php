<?php

namespace App\Support;

class EmailAddress
{
    /** Email huruf kecil tanpa spasi, atau null kalau bukan alamat email yang masuk akal. */
    public static function normalize(?string $raw): ?string
    {
        $e = mb_strtolower(trim((string) $raw));

        return $e !== '' && mb_strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }
}
