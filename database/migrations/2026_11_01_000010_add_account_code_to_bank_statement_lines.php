<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $t) {
            $t->string('account_code', 10)->default('1-1110');
            $t->index(['branch_id', 'account_code']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $t) {
            $t->dropIndex(['branch_id', 'account_code']);
            $t->dropColumn('account_code');
        });
    }
};
