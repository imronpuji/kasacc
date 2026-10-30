<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slips', function (Blueprint $t) {
            $t->unsignedBigInteger('thr')->default(0)->after('commission');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $t) {
            $t->dropColumn('thr');
        });
    }
};
