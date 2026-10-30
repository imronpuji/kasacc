<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buku poin: setiap perubahan saldo satu baris (positif = dapat, negatif = dipakai), tidak pernah diubah.
        // Saldo = jumlah seluruh baris. Satu transaksi hanya boleh punya satu baris per jenis, jadi tidak bisa dihitung dua kali.
        Schema::create('loyalty_points', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->integer('points');
            $t->string('kind', 20); // earn | redeem | void_earn | void_redeem | adjust
            $t->string('source_type', 20)->nullable(); // pos_sale | service_ticket
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['customer_id', 'id']);
            $t->unique(['source_type', 'source_id', 'kind']);
        });

        Schema::table('pos_sales', function (Blueprint $t) {
            $t->bigInteger('discount')->default(0);
            $t->integer('points_redeemed')->default(0);
        });

        Schema::table('service_tickets', function (Blueprint $t) {
            $t->bigInteger('discount')->default(0);
            $t->integer('points_redeemed')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', fn (Blueprint $t) => $t->dropColumn(['discount', 'points_redeemed']));
        Schema::table('pos_sales', fn (Blueprint $t) => $t->dropColumn(['discount', 'points_redeemed']));
        Schema::dropIfExists('loyalty_points');
    }
};
