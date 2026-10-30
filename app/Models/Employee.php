<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $guarded = [];

    protected $casts = ['hired_on' => 'date', 'resigned_on' => 'date', 'is_active' => 'boolean'];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function upah(): int
    {
        return (int) $this->base_salary + (int) $this->fixed_allowance;
    }
}
