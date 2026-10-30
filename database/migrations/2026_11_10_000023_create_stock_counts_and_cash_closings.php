<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stok opname: hitung fisik lalu cocokkan dengan sistem. system_qty adalah stok saat sesi dimulai;
        // selisih = hitungan - system_qty, jadi penjualan yang terjadi selama menghitung tidak merusak hasilnya.
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->date('counted_on');
            $t->string('status', 20)->default('draft'); // draft | posted | cancelled
            $t->string('note', 200)->nullable();
            $t->bigInteger('diff_value')->default(0); // negatif = rugi (barang kurang)
            $t->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->index(['branch_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->integer('system_qty');
            $t->integer('counted_qty')->nullable(); // kosong = belum dihitung, dilewati
            $t->bigInteger('diff_value')->default(0);
            $t->unique(['stock_count_id', 'product_id']);
        });

        // Tutup kas harian: uang di laci yang dihitung vs saldo kas menurut pembukuan.
        Schema::create('cash_closings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->date('closing_date');
            $t->bigInteger('expected');
            $t->bigInteger('counted');
            $t->bigInteger('diff'); // counted - expected; negatif = uang kurang
            $t->string('status', 20); // closed | pending | rejected
            $t->string('note', 200)->nullable();
            $t->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        // Satu penutupan aktif per cabang per hari; yang ditolak boleh diulang.
        DB::statement("CREATE UNIQUE INDEX cash_closings_active_day ON cash_closings (branch_id, closing_date) WHERE status <> 'rejected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
