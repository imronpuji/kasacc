<?php

return [
    // Biaya QRIS (MDR) dalam basis poin: 70 = 0,7%. Sesuaikan dengan kontrak penyedia pembayaran.
    'qris_mdr_bps' => (int) env('KASIR_QRIS_MDR_BPS', 70),
    // Zona waktu untuk absensi (tanggal dan jam kerja karyawan), terpisah dari zona waktu aplikasi.
    'timezone' => env('KASIR_TIMEZONE', 'Asia/Jakarta'),
];
