<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->date('line_date');
            $t->string('description');
            $t->bigInteger('amount'); // positif = uang masuk, negatif = uang keluar
            $t->foreignId('journal_line_id')->nullable()->unique()->constrained('journal_lines')->restrictOnDelete();
            $t->foreignId('imported_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['branch_id', 'line_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
