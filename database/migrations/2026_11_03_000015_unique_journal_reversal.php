<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Satu jurnal hanya boleh dibatalkan sekali; pengaman terakhir kalau dua permintaan lolos bersamaan.
        DB::statement('CREATE UNIQUE INDEX journals_one_reversal ON journals (reversal_of) WHERE reversal_of IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS journals_one_reversal');
    }
};
