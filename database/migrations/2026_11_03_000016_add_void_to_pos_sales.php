<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason', 200)->nullable();
            $t->foreignId('void_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('void_journal_id');
            $t->dropConstrainedForeignId('voided_by');
            $t->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
