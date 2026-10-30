# Design system iZone

Aplikasi ini dipakai pemilik toko dan karyawan yang tidak terbiasa dengan software akuntansi. Tujuan utama: orang awam bisa bekerja tanpa bingung dan tanpa pelatihan.
Semua gaya ada di `public/css/izone.css` (murni CSS, tanpa build). Tema Filament diatur di `app/Providers/Filament/AdminPanelProvider.php`.

## Prinsip
1. **Satu halaman, satu pekerjaan.** Setiap halaman punya satu tombol utama yang jelas.
2. **Bahasa sehari-hari.** Tulis seperti bicara ke pemilik toko. Hindari istilah akuntansi (debit, kredit, jurnal, HPP). Kalau istilah teknis tidak bisa dihindari (SKU, PPh 21, NPWP), beri penjelasan satu kalimat di bawah isiannya.
3. **Jangan tanya yang sudah jelas.** Toko satu cabang tidak ditanya cabang. Pilihan bawaan harus pilihan yang paling sering benar.
4. **Pilih dari daftar, jangan mengetik.** Struk, pelanggan, barang, dan pemasok dipilih dari daftar yang bisa dicari. Mengetik nomor panjang adalah sumber salah.
5. **Selalu ada langkah berikutnya.** Keadaan kosong menjelaskan apa yang harus ditekan. Setelah berhasil, notifikasi menjelaskan apa yang terjadi.
6. **Tindakan yang tidak bisa diulang harus dikonfirmasi** lewat jendela dengan tombol yang menyebut akibatnya ("Ya, batalkan struk"), bukan "OK".

## Warna
| Peran | Nilai | Dipakai untuk |
|---|---|---|
| Utama | `#0B63F6` (`--iz-blue`) | Tombol utama, tautan, menu aktif |
| Utama gelap | `#0A4FC4` | Hover |
| Utama lembut | `#EAF1FF` | Latar menu aktif, kotak info |
| Teks | `#0F172A` (`--iz-ink`) | Teks utama |
| Teks redup | `#586174` (`--iz-muted`) | Keterangan, label tabel |
| Garis | `#E3E7EE` | Batas kartu |
| Latar | `#F4F6FA` | Latar halaman |
| Berhasil | `#0E7A4F` | Uang masuk, selesai |
| Peringatan | `#B45309` | Perlu perhatian |
| Bahaya | `#B42318` | Terlambat, batal, merugikan |

Jangan menulis kode warna baru langsung di tampilan. Pakai variabel atau kelas yang ada.

## Tipografi dan ukuran
- Huruf: Inter. Judul halaman 30px tebal, judul kartu 18px, teks 15px, keterangan 14px.
- Angka uang memakai `font-variant-numeric: tabular-nums`. Format: `Rp1.250.000`.
- Sasaran sentuh minimal 44px (tombol, menu, isian 48px). Dipakai di HP dan tablet kasir.

## Komponen
- **Tombol**: `x-filament::button` atau `Action`. Utama = biru, sekunder = `color('gray')`, berbahaya = `color('danger')`, hati-hati = `color('warning')`. Satu tombol utama per area. Tombol besar (`size="xl"`) hanya untuk pekerjaan utama peran itu (Buka Kasir, Terima HP baru).
- **Kartu**: kelas `iz-card`. Angka ringkasan: `iz-kpi`. Daftar tugas: `iz-task`. Kotak penjelasan: `iz-info`. Keadaan kosong: `iz-empty`.
- **Form dalam jendela (modal)**: judul menyebut pekerjaan, tombol kirim menyebut hasil ("Simpan faktur"). Isian wajib bertanda bintang, tiap isian punya contoh (`placeholder`) dan, kalau perlu, satu kalimat penjelasan (`helperText`).
- **Pilihan cabang**: selalu `App\Support\BranchField::make()`. Disembunyikan otomatis kalau hanya ada satu pilihan.
- **Tabel halaman**: `filament.pages.table-page` dengan `getSubheading()` yang menjelaskan halaman dalam satu-dua kalimat, judul kosong yang menyuruh melakukan sesuatu, dan tombol di kepala tabel.
- **Bantuan**: tiap halaman utama punya tautan "Bingung? Lihat cara memakai halaman ini" (otomatis, dari `Help::TOPIC_BY_PAGE`). Halaman baru ditambahkan ke daftar itu dan ke `Help::topics()`.

## Menu
Urutan grup: Beranda, Penjualan, Barang, Keuangan, Karyawan, Data master, Pengaturan, Bantuan.
- **Penjualan**: Kasir, Servis, Pelanggan, Komplain, Tutup kas.
- **Barang**: Stok dan pembelian, Faktur pembelian, Pemasok, Klaim pemasok, Stok opname.
- Nomor urut di grup tidak boleh kembar. Nama menu dan judul halaman harus sama.

## Cara menulis teks
- Tombol: kata kerja + benda ("Catat faktur", "Tutup kas hari ini"). Bukan "Submit", "OK".
- Galat: katakan apa yang salah dan apa yang harus dilakukan ("Nomor faktur harus diisi. Salin dari faktur pemasok.").
- Jangan menyalahkan pengguna dan jangan memakai kode galat.

## Sebelum menambah halaman baru
1. Taruh di grup menu yang benar dengan nomor urut unik.
2. Tambahkan `getSubheading()`.
3. Tambahkan bagian di `Help::topics()` dan alamatnya di `TOPIC_BY_PAGE`.
4. Pakai `BranchField`, pilihan dari daftar, dan kelas CSS yang ada.
5. Jalankan tes, lalu buka halamannya di HP (lebar 375px) dan di desktop.
