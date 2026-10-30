<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persen komisi saat gaji dihitung, supaya rincian slip lama tetap cocok kalau persennya diubah. Kosong = slip lama.
        Schema::table('payroll_slips', function (Blueprint $t) {
            $t->unsignedInteger('commission_bps')->nullable()->after('commission');
        });

        // Satu baris per klaim garansi, supaya laporan komplain menghitung menurut tanggal klaim.
        Schema::create('warranty_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_ticket_id')->constrained()->restrictOnDelete();
            $t->date('claimed_on');
            $t->unsignedBigInteger('cost');
            $t->timestamps();
            $t->index('claimed_on');
        });

        // Klaim lama hanya punya total biaya; dicatat sebagai satu klaim pada tanggal servis ditutup.
        foreach (DB::table('service_tickets')->where('warranty_cost', '>', 0)->get(['id', 'closed_on', 'warranty_cost']) as $t) {
            DB::table('warranty_claims')->insert(['service_ticket_id' => $t->id, 'claimed_on' => $t->closed_on, 'cost' => $t->warranty_cost, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::table('payroll_slips', fn (Blueprint $t) => $t->dropColumn('commission_bps'));
    }
};
