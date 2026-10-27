<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSlip extends Model
{
    protected $guarded = [];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function run(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }
}
