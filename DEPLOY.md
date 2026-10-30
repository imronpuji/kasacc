# Setup Produksi (Neon) — langkah demi langkah

Prinsip: Dev dan Prod memakai database terpisah dan role (user) database yang berbeda. Jangan pernah menempel password di chat atau commit ke git.

## 1. Ganti password Neon (sekali, wajib)
Password lama pernah tertempel di chat. Di console Neon: Project → Roles → pada role `neondb_owner` klik **Reset password**.
Lebih baik lagi buat role terpisah: `kasir_dev` (untuk Dev) dan `kasir_prod` (untuk Prod), lalu pakai connection string masing-masing.

## 2. File .env di server produksi
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://alamat-klien.com
DB_CONNECTION=pgsql
DB_URL=postgresql://kasir_prod:PASSWORD_BARU@ep-little-sound-...-pooler.../neondb?sslmode=require
KASIR_OWNER_EMAIL=pemilik@klien.com
KASIR_OWNER_PASSWORD=minimal-12-karakter
KASIR_OWNER_NAME=Nama Pemilik
KASIR_QRIS_MDR_BPS=70
```
`.env` tidak boleh masuk git. Dev memakai `.env` sendiri dengan connection string Dev.

## 3. Kosongkan database Prod (sekali, karena masih berisi data demo)
Aplikasi sengaja menolak `migrate:fresh` di produksi. Kosongkan lewat SQL Editor di console Neon, pada database Prod saja:
```
DROP SCHEMA public CASCADE;
CREATE SCHEMA public;
```
Periksa dua kali bahwa yang terbuka adalah project/branch Prod (ep-little-sound), bukan Dev.

## 4. Pasang struktur dan data awal
```
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
ProductionSeeder membuat bagan akun, peran (owner, approver, finance_admin, hr, cashier, technician), dan satu akun pemilik. Tidak ada data demo.

## 5. Setelah login pertama sebagai pemilik
1. Buat Cabang (menu Cabang).
2. Buat Produk dan harga jual (menu Products).
3. Buat akun staf, beri peran, hubungkan ke cabang.
4. Tambah karyawan (menu SDM).
5. Setor modal awal lewat jurnal pembukaan.
6. Ganti password pemilik.

## Portal pelanggan (masuk dengan HP + kode)
- Pelanggan masuk di `/pelanggan/masuk` dengan nomor HP + kode 6 angka. Kode dikirim lewat `OtpSender` (lihat `AppServiceProvider`).
- Bawaan `KASIR_OTP_DRIVER=log` hanya menulis kode ke log dan **ditolak di produksi**, jadi portal otomatis menolak masuk sampai pengirim WhatsApp/SMS sungguhan didaftarkan.
- Tautan lacak servis (`/lacak/{token}`) tidak butuh masuk dan sudah aktif; hanya menampilkan status.

## Pemeriksaan sebelum klien memakai
- Login sebagai tiap peran, pastikan menu yang tampil sesuai tugasnya.
- Satu transaksi kasir, satu servis, satu payroll uji di Dev dulu, bukan di Prod.
- Jadwalkan backup Neon (point-in-time restore aktif secara bawaan, cek masa simpannya di plan klien).

## Yang perlu dikonfirmasi konsultan pajak klien
- Tarif JKK dan JKM sesuai kelas risiko usaha klien (default uji 0,10% dan 0,30%, ubah di tabel `payroll_settings`).
- Premi BPJS yang dibayar pemberi kerja dihitung sebagai bagian penghasilan bruto PPh 21.
- Biaya jabatan 5% maksimum Rp6.000.000 setahun untuk perhitungan Desember.
- Upah minimum daerah cabang klien (gaji pokok tidak boleh di bawah UMK).
