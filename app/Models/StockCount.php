<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sesi stok opname. Lihat StockCountService. */
class StockCount extends Model
{
    protected $guarded = [];

    protected $casts = ['counted_on' => 'date', 'posted_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
