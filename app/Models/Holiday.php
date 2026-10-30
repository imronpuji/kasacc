<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $guarded = [];

    protected $casts = ['date' => 'date', 'store_off' => 'boolean'];
}
