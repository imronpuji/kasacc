<?php

namespace App\Domain\Customer;

use App\Models\Customer;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use DomainException;

class CustomerService
{
    /**
     * Cari pelanggan menurut email, lalu nomor HP, atau buat baru. Tanpa email dan HP tidak ada pelanggan (null):
     * servis/struk tetap jalan tanpa akun. Nama pelanggan yang sudah ada tidak ditimpa; email/HP yang kosong
     * dilengkapi selama belum dipakai pelanggan lain.
     */
    public function resolve(?string $name, ?string $rawPhone, ?string $rawEmail = null): ?Customer
    {
        $hasPhone = trim((string) $rawPhone) !== '';
        $hasEmail = trim((string) $rawEmail) !== '';
        if (! $hasPhone && ! $hasEmail) {
            return null;
        }
        $phone = $hasPhone ? PhoneNumber::normalize($rawPhone) : null;
        $email = $hasEmail ? EmailAddress::normalize($rawEmail) : null;
        if ($hasPhone && ! $phone) {
            throw new DomainException('Nomor HP pelanggan tidak valid. Tulis seperti 081234567890 atau kosongkan.');
        }
        if ($hasEmail && ! $email) {
            throw new DomainException('Email pelanggan tidak valid. Tulis seperti nama@contoh.com atau kosongkan.');
        }

        $customer = ($email ? Customer::where('email', $email)->first() : null) ?? ($phone ? Customer::where('phone', $phone)->first() : null);
        if (! $customer) {
            $label = trim((string) $name);

            return Customer::create(['name' => $label !== '' ? $label : ($email ?? $phone), 'phone' => $phone, 'email' => $email]);
        }

        $fill = [];
        if ($phone && ! $customer->phone && ! Customer::where('phone', $phone)->exists()) {
            $fill['phone'] = $phone;
        }
        if ($email && ! $customer->email && ! Customer::where('email', $email)->exists()) {
            $fill['email'] = $email;
        }
        if ($fill) {
            $customer->update($fill);
        }

        return $customer;
    }

    /** Satu isian yang bisa berisi email atau nomor HP (mis. di kasir). */
    public function resolveContact(?string $contact): ?Customer
    {
        $contact = trim((string) $contact);

        return str_contains($contact, '@') ? $this->resolve(null, null, $contact) : $this->resolve(null, $contact);
    }
}
