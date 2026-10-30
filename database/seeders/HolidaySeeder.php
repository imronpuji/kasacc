<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Tanggal merah Indonesia menurut SKB 3 Menteri (Setneg). Aman dijalankan berulang.
 * Libur nasional: toko libur secara bawaan. Cuti bersama: bawaan tidak libur (usaha swasta menentukan sendiri),
 * pemilik bisa menyalakannya di menu Kalender libur.
 * Data 2027 belum dicek silang dengan dokumen SKB asli (Isra Mikraj tercantum dua kali di sumber berita): periksa lagi.
 */
class HolidaySeeder extends Seeder
{
    public const DATA = [
        // 2026 (SKB 3 Menteri, setneg.go.id)
        ['2026-01-01', 'Tahun Baru Masehi', 'nasional'], ['2026-01-16', 'Isra Mikraj Nabi Muhammad SAW', 'nasional'],
        ['2026-02-16', 'Cuti bersama Tahun Baru Imlek', 'cuti_bersama'], ['2026-02-17', 'Tahun Baru Imlek 2577', 'nasional'],
        ['2026-03-18', 'Cuti bersama Nyepi', 'cuti_bersama'], ['2026-03-19', 'Hari Suci Nyepi', 'nasional'],
        ['2026-03-20', 'Cuti bersama Idulfitri', 'cuti_bersama'], ['2026-03-21', 'Idulfitri 1447 H (hari pertama)', 'nasional'],
        ['2026-03-22', 'Idulfitri 1447 H (hari kedua)', 'nasional'], ['2026-03-23', 'Cuti bersama Idulfitri', 'cuti_bersama'],
        ['2026-03-24', 'Cuti bersama Idulfitri', 'cuti_bersama'], ['2026-04-03', 'Wafat Yesus Kristus', 'nasional'],
        ['2026-04-05', 'Paskah', 'nasional'], ['2026-05-01', 'Hari Buruh Internasional', 'nasional'],
        ['2026-05-14', 'Kenaikan Yesus Kristus', 'nasional'], ['2026-05-15', 'Cuti bersama Kenaikan Yesus Kristus', 'cuti_bersama'],
        ['2026-05-27', 'Iduladha 1447 H', 'nasional'], ['2026-05-28', 'Cuti bersama Iduladha', 'cuti_bersama'],
        ['2026-05-31', 'Hari Raya Waisak 2570', 'nasional'], ['2026-06-01', 'Hari Lahir Pancasila', 'nasional'],
        ['2026-06-16', 'Tahun Baru Islam 1448 H', 'nasional'], ['2026-08-17', 'Hari Proklamasi Kemerdekaan', 'nasional'],
        ['2026-08-25', 'Maulid Nabi Muhammad SAW', 'nasional'], ['2026-12-24', 'Cuti bersama Natal', 'cuti_bersama'],
        ['2026-12-25', 'Natal (Kelahiran Yesus Kristus)', 'nasional'],
        // 2027 (berita atas SKB; perlu dicek ulang)
        ['2027-01-01', 'Tahun Baru 2027 Masehi', 'nasional'], ['2027-01-05', 'Isra Mikraj Nabi Muhammad SAW', 'nasional'],
        ['2027-02-05', 'Cuti bersama Tahun Baru Imlek', 'cuti_bersama'], ['2027-02-06', 'Tahun Baru Imlek 2578', 'nasional'],
        ['2027-03-08', 'Hari Suci Nyepi', 'nasional'], ['2027-03-09', 'Cuti bersama Idulfitri', 'cuti_bersama'],
        ['2027-03-10', 'Idulfitri 1448 H', 'nasional'], ['2027-03-11', 'Idulfitri 1448 H', 'nasional'],
        ['2027-03-12', 'Cuti bersama Idulfitri', 'cuti_bersama'], ['2027-03-15', 'Cuti bersama Idulfitri', 'cuti_bersama'],
        ['2027-03-25', 'Cuti bersama Wafat Yesus Kristus', 'cuti_bersama'], ['2027-03-26', 'Wafat Yesus Kristus', 'nasional'],
        ['2027-03-28', 'Paskah', 'nasional'], ['2027-05-01', 'Hari Buruh Internasional', 'nasional'],
        ['2027-05-06', 'Kenaikan Yesus Kristus', 'nasional'], ['2027-05-17', 'Iduladha 1448 H', 'nasional'],
        ['2027-05-18', 'Cuti bersama Iduladha', 'cuti_bersama'], ['2027-05-19', 'Cuti bersama Waisak', 'cuti_bersama'],
        ['2027-05-20', 'Hari Raya Waisak 2571', 'nasional'], ['2027-06-01', 'Hari Lahir Pancasila', 'nasional'],
        ['2027-06-06', 'Tahun Baru Islam 1449 H', 'nasional'], ['2027-08-15', 'Maulid Nabi Muhammad SAW', 'nasional'],
        ['2027-08-17', 'Hari Proklamasi Kemerdekaan', 'nasional'], ['2027-12-24', 'Cuti bersama Natal', 'cuti_bersama'],
        ['2027-12-25', 'Natal (Kelahiran Yesus Kristus)', 'nasional'],
    ];

    public function run(): void
    {
        foreach (self::DATA as [$date, $name, $kind]) {
            // jangan timpa pilihan pemilik (store_off) kalau tanggalnya sudah ada
            $h = Holiday::firstOrNew(['date' => $date]);
            if (! $h->exists) {
                $h->fill(['name' => $name, 'kind' => $kind, 'store_off' => $kind === 'nasional'])->save();
            }
        }
    }
}
