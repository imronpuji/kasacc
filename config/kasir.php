<?php

return [
    // Biaya QRIS (MDR) dalam basis poin: 70 = 0,7%. Sesuaikan dengan kontrak penyedia pembayaran.
    'qris_mdr_bps' => (int) env('KASIR_QRIS_MDR_BPS', 70),
];
