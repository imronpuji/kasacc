<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retur sebagian satu struk. Struk asli tidak diubah; setiap retur punya jurnal sendiri.
        Schema::create('pos_sale_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_sale_id')->constrained('pos_sales')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->date('returned_on');
            $t->bigInteger('gross')->default(0);          // harga barang yang dikembalikan, sebelum potongan poin
            $t->bigInteger('discount_share')->default(0); // bagian potongan poin yang ikut dikembalikan
            $t->bigInteger('refund')->default(0);         // uang yang dikembalikan ke pelanggan
            $t->bigInteger('cost')->default(0);           // modal barang yang kembali (bagus atau rusak)
            $t->string('reason', 200);
            $t->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('pos_sale_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_sale_return_id')->constrained('pos_sale_returns')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->unsignedInteger('qty');
            $t->bigInteger('line_gross');
            $t->bigInteger('cost');
            $t->boolean('defective')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_return_items');
        Schema::dropIfExists('pos_sale_returns');
    }
};
