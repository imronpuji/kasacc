<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email dipakai pelanggan untuk masuk ke portal (kode dikirim ke email). Huruf kecil semua; boleh kosong.
        Schema::table('customers', function (Blueprint $t) {
            $t->string('email', 120)->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('email'));
    }
};
