<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Panduan singkat untuk pekerjaan sehari-hari. Satu bagian per halaman; tautan "Cara memakai halaman ini" di tiap halaman menuju bagian yang sesuai. */
class Help extends Page
{
    protected string $view = 'filament.pages.help';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $navigationLabel = 'Bantuan';

    protected static ?string $title = 'Bantuan';

    protected static ?string $slug = 'bantuan';

    protected static string|\UnitEnum|null $navigationGroup = 'Bantuan';

    protected static ?int $navigationSort = 1;

    public function getSubheading(): ?string
    {
        return 'Langkah mudah untuk pekerjaan sehari-hari. Pilih bagian yang Anda butuhkan.';
    }

    /** Halaman (alamat setelah /admin/) => bagian panduan. Dipakai oleh tautan di atas tiap halaman. */
    public const TOPIC_BY_PAGE = [
        'pos' => 'kasir', 'service-tickets' => 'servis', 'stock' => 'stok', 'purchase-invoices' => 'faktur', 'suppliers' => 'pemasok',
        'supplier-claims' => 'klaim', 'stock-counts' => 'opname', 'cash-closings' => 'tutup-kas', 'customers' => 'pelanggan', 'complaints' => 'komplain',
        'reports' => 'laporan', 'record-expense' => 'biaya', 'my-attendance' => 'absen',
    ];

    protected function getViewData(): array
    {
        return ['topics' => self::topics()];
    }

    public static function topics(): array
    {
        return [
            ['id' => 'kasir', 'title' => 'Menjual barang di Kasir', 'steps' => [
                'Buka menu Kasir.', 'Ketuk barang yang dibeli. Ketuk lagi untuk menambah jumlahnya.', 'Pilih cara pelanggan membayar: Kas, Bank, atau QRIS.',
                'Isi email atau nomor HP pelanggan kalau ada (supaya dapat poin dan bisa melihat belanjanya). Boleh dikosongkan.', 'Tekan "Selesaikan pembayaran", lalu "Cetak struk".',
            ], 'tip' => 'Salah input atau pelanggan batal? Gunakan "Batalkan struk". Kalau hanya sebagian barang yang dikembalikan, gunakan "Retur sebagian".'],
            ['id' => 'servis', 'title' => 'Menerima dan menyelesaikan servis HP', 'steps' => [
                'Menu Servis, tekan "Terima HP baru". Isi nama pelanggan, tipe HP, dan keluhannya. Kalau pelanggan pernah datang, cari namanya supaya terisi sendiri.',
                'Simpan, lalu cetak nota dan berikan ke pelanggan. Nomor servis ada di nota.', 'Saat HP sedang dikerjakan, tekan "Ubah proses" supaya pelanggan tahu perkembangannya.',
                'Kalau sudah beres, tekan "Selesai diperbaiki": isi sparepart yang dipakai dan biaya jasanya.', 'Pelanggan yang punya email otomatis diberi tahu saat HP siap diambil.',
            ], 'tip' => 'Pelanggan komplain setelah servis? Tekan "Garansi: ganti sparepart". Kalau sparepart rusak karena pemasok, centang agar ditagihkan ke pemasok.'],
            ['id' => 'stok', 'title' => 'Mengelola stok barang', 'steps' => [
                'Menu Stok dan pembelian menampilkan stok tiap barang.', '"Catat pembelian barang" dipakai saat membeli satu jenis barang.', 'Beli banyak barang sekaligus dari satu faktur? Pakai menu Faktur pembelian.',
                '"Pindahkan stok ke cabang lain" dipakai kalau barang dikirim antar toko.',
            ], 'tip' => 'Stok di sistem tidak sama dengan di rak? Pakai menu Stok opname.'],
            ['id' => 'faktur', 'title' => 'Mencatat faktur dari pemasok', 'steps' => [
                'Menu Faktur pembelian, tekan "Catat faktur".', 'Pilih pemasok, isi nomor faktur dari kertas faktur, dan tanggalnya.', 'Pilih "Belum dibayar" kalau akan dibayar nanti, lalu isi tanggal jatuh tempo. Kalau langsung dibayar, pilih kas atau rekening.',
                'Tambahkan barang satu per satu: nama barang, jumlah, dan harga beli per buah.', 'Simpan. Stok bertambah dan utang tercatat otomatis.',
            ], 'tip' => 'Nomor faktur yang sama dari pemasok yang sama tidak bisa dicatat dua kali, supaya tidak dobel.'],
            ['id' => 'pemasok', 'title' => 'Daftar pemasok', 'steps' => ['Menu Pemasok, tekan "Tambah pemasok".', 'Isi nama (wajib), nomor HP dan alamat boleh dikosongkan.', 'Di daftar terlihat berapa utang ke tiap pemasok.'], 'tip' => null],
            ['id' => 'klaim', 'title' => 'Barang rusak: klaim ke pemasok', 'steps' => [
                'Klaim muncul otomatis saat struk dibatalkan dengan barang rusak, atau saat garansi servis ditagihkan ke pemasok.', 'Barang rusak dari stok sendiri: menu Stok dan pembelian, "Klaim barang rusak ke pemasok".',
                'Kalau pemasok sudah menjawab, tekan "Catat hasil": diganti baru, uang kembali, atau ditolak.',
            ], 'tip' => 'Selama klaim belum dijawab, nilai barangnya dicatat sebagai "barang klaim di pemasok", bukan kerugian.'],
            ['id' => 'opname', 'title' => 'Stok opname (menghitung stok sebenarnya)', 'steps' => [
                'Menu Stok opname, tekan "Mulai hitung stok".', 'Hitung barang di rak, lalu isi jumlahnya di daftar. Barang yang belum dihitung boleh dilewati.', 'Tekan "Setujui hasil dan sesuaikan stok". Selisihnya dicatat sebagai kerugian atau kelebihan stok.',
            ], 'tip' => 'Isi hasil segera setelah menghitung tiap barang, jangan ditunda sampai besok.'],
            ['id' => 'tutup-kas', 'title' => 'Tutup kas di akhir hari', 'steps' => [
                'Hitung semua uang tunai di laci.', 'Menu Tutup kas, tekan "Tutup kas hari ini", isi jumlah uang yang dihitung.', 'Kalau ada selisih dengan catatan, isi alasannya. Pemilik akan memeriksa dan menyetujui.',
            ], 'tip' => 'Jumlah menurut catatan sengaja disembunyikan sampai Anda menyimpan, supaya hitungannya jujur.'],
            ['id' => 'pelanggan', 'title' => 'Pelanggan dan poin', 'steps' => ['Pelanggan tercatat otomatis saat email atau nomor HP diisi di Kasir atau Servis.', 'Tiap Rp10.000 yang dibayar memberi 1 poin. Poin dipakai sebagai potongan di Kasir.', 'Pemilik bisa mengoreksi poin lewat tombol "Koreksi poin".'], 'tip' => null],
            ['id' => 'komplain', 'title' => 'Komplain pelanggan', 'steps' => ['Menu Komplain, tekan "Catat komplain", ceritakan keluhannya.', 'Tekan "Tangani" saat Anda mulai mengurus, dan catat hasilnya saat selesai.'], 'tip' => null],
            ['id' => 'laporan', 'title' => 'Membaca laporan', 'steps' => [
                'Pilih periode di atas (bulan ini, hari ini, bulan lalu, atau pilih tanggal).', 'Pilih pertanyaan yang ingin dijawab, misalnya "Untung atau rugi?" atau "Utang ke pemasok".', 'Tekan "Excel" atau "PDF" untuk mengunduh laporan yang sedang dilihat.',
            ], 'tip' => 'Angka merah berarti perlu perhatian, misalnya utang yang lewat jatuh tempo.'],
            ['id' => 'biaya', 'title' => 'Mencatat biaya', 'steps' => ['Menu Catat biaya.', 'Pilih jenis biaya (listrik, sewa, dan lainnya), isi jumlah, dan dari mana uangnya.', 'Pilih "Belum dibayar" untuk tagihan yang akan dilunasi nanti.'], 'tip' => null],
            ['id' => 'absen', 'title' => 'Absen karyawan', 'steps' => ['Menu Absen saya.', 'Tekan "Absen masuk" saat tiba di toko dan "Absen pulang" saat pulang.'], 'tip' => null],
        ];
    }
}
