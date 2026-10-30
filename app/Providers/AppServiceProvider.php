<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pengirim kode masuk pelanggan: 'mail' (email lewat mailer aplikasi, mis. Resend) atau 'log' (hanya pengembangan).
        $this->app->bind(\App\Domain\Customer\OtpSender::class, fn () => match (config('kasir.otp_driver')) {
            'log' => new \App\Domain\Customer\LogOtpSender,
            'mail' => new \App\Domain\Customer\MailOtpSender,
            default => throw new \RuntimeException('KASIR_OTP_DRIVER tidak dikenal: '.config('kasir.otp_driver')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Seluruh teks bawaan (tombol, pesan, tanggal) memakai bahasa Indonesia.
        $this->app->setLocale('id');
        \Carbon\Carbon::setLocale('id');

        // Di produksi: migrate:fresh, db:wipe, migrate:reset dan sejenisnya ditolak supaya data klien tidak terhapus.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
