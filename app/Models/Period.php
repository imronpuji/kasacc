<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Period extends Model
{
    protected $guarded = [];

    protected $casts = ['locked_at' => 'datetime'];
}
