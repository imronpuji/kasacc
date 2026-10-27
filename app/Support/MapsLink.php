<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/** Membaca koordinat dari tempelan Google Maps: "-6.2, 106.8", link panjang, atau link pendek hasil Bagikan. */
class MapsLink
{
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl'];

    /** @return array{0: float, 1: float}|null [lintang, bujur] atau null kalau tidak terbaca. */
    public static function coordinates(?string $input): ?array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $input)) {
            $input = self::expand($input);
        }
        $input = urldecode($input);

        // Titik pin tempat (!3d..!4d..) lebih tepat daripada pusat tampilan peta (@lat,lng).
        if (preg_match('/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/', $input, $m)
            || preg_match('/(-?\d{1,2}\.\d+)\s*[,;\s]\s*(-?\d{1,3}\.\d+)/', $input, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
            if (abs($lat) <= 90 && abs($lng) <= 180 && ! ($lat == 0.0 && $lng == 0.0)) {
                return [round($lat, 6), round($lng, 6)];
            }
        }

        return null;
    }

    /** Link pendek (maps.app.goo.gl) diikuti ke alamat panjangnya. Hanya host Google yang diizinkan. */
    private static function expand(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::SHORT_HOSTS, true)) {
            return $url;
        }
        try {
            $res = Http::timeout(6)->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true, 'protocols' => ['https']]])->get($url);
            $history = $res->header('X-Guzzle-Redirect-History');
            $last = $history ? (array_reverse(array_map('trim', explode(',', $history)))[0] ?? $url) : $url;
            $lastHost = strtolower((string) parse_url($last, PHP_URL_HOST));

            return str_ends_with($lastHost, 'google.com') ? $last : $url;
        } catch (\Throwable) {
            return $url;
        }
    }
}
