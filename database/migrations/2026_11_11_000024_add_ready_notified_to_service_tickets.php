<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pemberitahuan "siap diambil" dikirim sekali per servis, walau prosesnya diubah bolak-balik.
        Schema::table('service_tickets', fn (Blueprint $t) => $t->timestamp('ready_notified_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('service_tickets', fn (Blueprint $t) => $t->dropColumn('ready_notified_at'));
    }
};
