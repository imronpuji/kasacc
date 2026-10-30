<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris di buku poin pelanggan. Lihat LoyaltyService; baris tidak pernah diubah atau dihapus. */
class LoyaltyPoint extends Model
{
    protected $guarded = [];

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
