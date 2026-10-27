<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    protected $guarded = [];

    protected $casts = ['line_date' => 'date'];
}
