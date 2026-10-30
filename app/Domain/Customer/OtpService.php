<?php

namespace App\Domain\Customer;

use App\Models\Customer;
use App\Support\PhoneNumber;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Kode masuk sekali pakai (6 angka) untuk portal pelanggan. Hanya pelanggan yang sudah terdaftar yang bisa masuk. */
class OtpService
{
    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public const MAX_REQUESTS = 3; // per nomor, per 10 menit

    public function __construct(private OtpSender $sender)
    {
    }

    /**
     * Kirim kode ke nomor. Nomor yang belum terdaftar diperlakukan sama (tanpa pesan beda) supaya nomor orang lain tidak bisa ditebak.
     */
    public function request(string $rawPhone): void
    {
        $phone = PhoneNumber::normalize($rawPhone);
        if (! $phone) {
            throw new DomainException('Nomor HP tidak valid. Tulis seperti 081234567890.');
        }
        if (config('kasir.otp_driver') === 'log' && app()->isProduction()) {
            throw new DomainException('Masuk pelanggan belum aktif. Hubungi toko untuk menanyakan status servis.');
        }

        $customer = Customer::where('phone', $phone)->first();
        if (! $customer) {
            return;
        }

        $recent = DB::table('customer_otps')->where('customer_id', $customer->id)->where('created_at', '>=', now()->subMinutes(10))->count();
        if ($recent >= self::MAX_REQUESTS) {
            throw new DomainException('Terlalu sering meminta kode. Tunggu beberapa menit lalu coba lagi.');
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($customer, $code) {
            DB::table('customer_otps')->where('customer_id', $customer->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('customer_otps')->insert([
                'customer_id' => $customer->id, 'code_hash' => $this->hash($code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'created_at' => now(),
            ]);
        });

        $this->sender->send($phone, "Kode masuk Anda: {$code}. Berlaku ".self::TTL_MINUTES.' menit. Jangan bagikan kode ini kepada siapa pun.');
    }

    /** Cek kode; berhasil mengembalikan pelanggan dan kode langsung hangus. */
    public function verify(string $rawPhone, string $code): Customer
    {
        $fail = new DomainException('Kode salah atau sudah kedaluwarsa. Minta kode baru.');
        $phone = PhoneNumber::normalize($rawPhone);
        $customer = $phone ? Customer::where('phone', $phone)->first() : null;
        if (! $customer) {
            throw $fail;
        }

        // Hasil dikembalikan (bukan dilempar) dari dalam transaksi: kalau dilempar, kenaikan jumlah percobaan ikut dibatalkan dan kode bisa ditebak tanpa batas.
        $ok = DB::transaction(function () use ($customer, $code) {
            $otp = DB::table('customer_otps')->where('customer_id', $customer->id)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->orderByDesc('id')->lockForUpdate()->first();
            if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
                return false;
            }
            if (! hash_equals($otp->code_hash, $this->hash(trim($code)))) {
                DB::table('customer_otps')->where('id', $otp->id)->increment('attempts');

                return false;
            }
            DB::table('customer_otps')->where('id', $otp->id)->update(['consumed_at' => now()]);
            $customer->update(['last_login_at' => now()]);

            return true;
        });
        if (! $ok) {
            throw $fail;
        }

        return $customer;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
