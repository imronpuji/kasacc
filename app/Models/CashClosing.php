<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penutupan kas harian satu cabang. Lihat CashClosingService. */
class CashClosing extends Model
{
    protected $guarded = [];

    protected $casts = ['closing_date' => 'date'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
