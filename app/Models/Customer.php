<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Pelanggan toko. Masuk ke portal pelanggan (guard "customer") dengan nomor HP dan kode sekali pakai; tidak pernah masuk ke panel staf. */
class Customer extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['last_login_at' => 'datetime'];

    public function tickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(LoyaltyPoint::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}
