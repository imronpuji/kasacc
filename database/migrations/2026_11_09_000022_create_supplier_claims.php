<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Klaim barang rusak ke pemasok. Nilai barang dipindah dari persediaan ke akun "barang klaim di pemasok"
        // sampai pemasok menyelesaikan: ganti baru, uang kembali, atau menolak (jadi kerugian).
        Schema::create('supplier_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->unsignedInteger('qty');
            $t->bigInteger('cost'); // nilai barang saat dipindah (rupiah)
            $t->string('status', 20)->default('open'); // open | replaced | refunded | rejected
            $t->string('reason', 200);
            $t->foreignId('pos_sale_id')->nullable()->constrained('pos_sales')->restrictOnDelete();
            $t->foreignId('complaint_id')->nullable()->constrained('complaints')->nullOnDelete();
            $t->foreignId('claim_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->foreignId('resolve_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->bigInteger('refund_amount')->default(0);
            $t->date('opened_on');
            $t->date('resolved_on')->nullable();
            $t->string('resolve_note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_claims');
    }
};
