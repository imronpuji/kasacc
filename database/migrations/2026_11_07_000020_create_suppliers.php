<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('phone', 30)->nullable();
            $t->string('address', 250)->nullable();
            $t->string('note', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Saldo utang per supplier dihitung dari jurnal (sumber kebenaran tetap buku besar), jadi jurnal cukup menunjuk supplier.
        // Kolom boleh kosong supaya jurnal lama tetap valid; utang lama tampil sebagai "Tanpa pemasok".
        Schema::table('journals', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->after('reversal_of')->constrained('suppliers')->restrictOnDelete();
            $t->index('supplier_id');
        });

        Schema::table('payment_requests', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::table('journals', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::dropIfExists('suppliers');
    }
};
