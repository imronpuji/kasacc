<?php

return [
    // Biaya QRIS (MDR) dalam basis poin: 70 = 0,7%. Sesuaikan dengan kontrak penyedia pembayaran.
    'qris_mdr_bps' => (int) env('KASIR_QRIS_MDR_BPS', 70),
    // Zona waktu untuk absensi (tanggal dan jam kerja karyawan), terpisah dari zona waktu aplikasi.
    'timezone' => env('KASIR_TIMEZONE', 'Asia/Jakarta'),
    // Pengirim kode masuk pelanggan (dikirim ke email). 'mail' memakai mailer aplikasi (MAIL_MAILER=resend + RESEND_API_KEY);
    // 'log' hanya menulis kode ke log (pengembangan) dan DITOLAK di produksi.
    'otp_driver' => env('KASIR_OTP_DRIVER', 'log'),
];
