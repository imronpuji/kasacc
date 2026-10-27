<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $guarded = [];

    public function slips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PayrollSlip::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
