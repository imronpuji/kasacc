<?php

namespace App\Domain\Customer;

use App\Models\Customer;
use App\Support\PhoneNumber;
use DomainException;

class CustomerService
{
    /**
     * Cari pelanggan menurut nomor HP, atau buat baru. Tanpa nomor HP tidak ada pelanggan (null): servis/struk tetap jalan tanpa akun.
     * Nama pelanggan yang sudah ada tidak ditimpa dari transaksi.
     */
    public function resolve(?string $name, ?string $rawPhone): ?Customer
    {
        if (trim((string) $rawPhone) === '') {
            return null;
        }
        $phone = PhoneNumber::normalize($rawPhone);
        if (! $phone) {
            throw new DomainException('Nomor HP pelanggan tidak valid. Tulis seperti 081234567890 atau kosongkan.');
        }

        return Customer::firstOrCreate(['phone' => $phone], ['name' => trim((string) $name) !== '' ? trim((string) $name) : $phone]);
    }
}
