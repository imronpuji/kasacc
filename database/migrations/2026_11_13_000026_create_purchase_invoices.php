<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Faktur pembelian dari pemasok: banyak barang, satu nomor faktur, satu tanggal jatuh tempo. Jurnalnya satu
        // (persediaan lawan utang atau kas/bank) dan menunjuk pemasok, jadi saldo utang tetap dihitung dari buku besar.
        Schema::create('purchase_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('invoice_no', 60);
            $t->date('invoice_date');
            $t->date('due_date')->nullable(); // kosong untuk faktur yang langsung dibayar
            $t->string('method', 30);         // kredit | kas | bank | kode rekening
            $t->bigInteger('total');
            $t->string('note', 200)->nullable();
            $t->foreignId('journal_id')->constrained('journals')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['supplier_id', 'invoice_no']);
            $t->index('journal_id');
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->unsignedInteger('qty');
            $t->bigInteger('unit_cost');
            $t->bigInteger('line_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');
    }
};
