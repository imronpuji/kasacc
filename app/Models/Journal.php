<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    protected $guarded = [];

    protected $casts = ['entry_date' => 'date'];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
