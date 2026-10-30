<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Klaim garansi servis mengingat barang dan jumlahnya, supaya bisa ditagihkan ke pemasok.
        Schema::table('warranty_claims', function (Blueprint $t) {
            $t->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->unsignedInteger('qty')->nullable();
        });

        // Klaim ke pemasok yang berasal dari garansi servis. Satu klaim garansi hanya boleh ditagihkan sekali.
        Schema::table('supplier_claims', function (Blueprint $t) {
            $t->foreignId('service_ticket_id')->nullable()->constrained('service_tickets')->restrictOnDelete();
            $t->foreignId('warranty_claim_id')->nullable()->unique()->constrained('warranty_claims')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_claims', fn (Blueprint $t) => $t->dropConstrainedForeignId('warranty_claim_id'));
        Schema::table('supplier_claims', fn (Blueprint $t) => $t->dropConstrainedForeignId('service_ticket_id'));
        Schema::table('warranty_claims', fn (Blueprint $t) => $t->dropConstrainedForeignId('product_id'));
        Schema::table('warranty_claims', fn (Blueprint $t) => $t->dropColumn('qty'));
    }
};
