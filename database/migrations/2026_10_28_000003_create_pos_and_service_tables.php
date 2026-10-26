<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('sale_price')->default(0)->after('kind');
            $t->string('barcode')->nullable()->unique()->after('sku');
        });

        Schema::create('pos_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('receipt_no')->unique();
            $t->foreignId('cashier_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('sold_on');
            $t->string('payment_method'); // kas, bank, qris
            $t->unsignedBigInteger('total');
            $t->unsignedBigInteger('mdr')->default(0);
            $t->foreignId('journal_id')->constrained('journals')->restrictOnDelete();
            $t->timestamps();
            $t->index(['branch_id', 'sold_on']);
        });

        Schema::create('pos_sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_sale_id')->constrained('pos_sales')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('qty');
            $t->unsignedBigInteger('unit_price');
            $t->unsignedBigInteger('line_total');
        });
        DB::statement('ALTER TABLE pos_sale_items ADD CONSTRAINT pos_sale_items_qty_positive CHECK (qty > 0)');

        Schema::create('service_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('code')->unique();
            $t->string('customer_name');
            $t->string('customer_phone')->nullable();
            $t->string('device');
            $t->text('complaint')->nullable();
            $t->foreignId('technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status')->default('diterima'); // diterima, selesai, batal
            $t->unsignedBigInteger('deposit')->default(0);
            $t->unsignedBigInteger('labor_fee')->default(0);
            $t->unsignedBigInteger('parts_total')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('warranty_cost')->default(0);
            $t->string('payment_method')->nullable();
            $t->date('received_on');
            $t->date('closed_on')->nullable();
            $t->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->timestamps();
            $t->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['sale_price', 'barcode']);
        });
    }
};
