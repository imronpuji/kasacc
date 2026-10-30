<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Retur sebagian atas satu struk kasir. Lihat PosService::partialReturn. */
class PosSaleReturn extends Model
{
    protected $guarded = [];

    protected $casts = ['returned_on' => 'date'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosSaleReturnItem::class);
    }
}
