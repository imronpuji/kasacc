<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            // asset, liability, equity, revenue, cogs, expense, other_expense
            $t->string('type');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->timestamp('locked_at')->nullable();
            $t->unsignedBigInteger('locked_by')->nullable();
            $t->timestamps();
            $t->unique(['year', 'month']);
        });

        Schema::create('journals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->date('entry_date');
            $t->string('reference')->nullable();
            $t->string('source')->default('manual'); // manual, pos, service, purchase, ...
            $t->string('description')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->foreignId('reversal_of')->nullable()->constrained('journals')->restrictOnDelete();
            $t->timestamps();
            $t->index(['branch_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('debit')->default(0);
            $t->unsignedBigInteger('credit')->default(0);
            $t->index('account_id');
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        // Jurnal yang sudah diposting tidak boleh diubah atau dihapus (koreksi lewat jurnal pembalik).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_journal_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Jurnal yang sudah diposting tidak boleh diubah atau dihapus; gunakan jurnal pembalik';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journals_immutable BEFORE UPDATE OR DELETE ON journals
    FOR EACH ROW EXECUTE FUNCTION forbid_journal_change();
CREATE TRIGGER journal_lines_immutable BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION forbid_journal_change();

CREATE OR REPLACE FUNCTION check_journal_balanced() RETURNS trigger AS $$
DECLARE diff bigint;
BEGIN
    SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) INTO diff
    FROM journal_lines WHERE journal_id = NEW.journal_id;
    IF diff <> 0 THEN
        RAISE EXCEPTION 'Jurnal % tidak seimbang (selisih %)', NEW.journal_id, diff;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION check_journal_balanced();
SQL);

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->string('kind')->default('sparepart'); // sparepart, aksesoris
            $t->timestamps();
        });

        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->integer('qty')->default(0);
            $t->bigInteger('value')->default(0); // total nilai persediaan (rupiah)
            $t->timestamps();
            $t->unique(['branch_id', 'product_id']);
        });
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_non_negative CHECK (qty >= 0 AND value >= 0)');

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->date('movement_date');
            $t->string('direction'); // in, out
            $t->integer('qty');
            $t->bigInteger('total_cost');
            $t->string('reason')->nullable(); // pembelian, retur, penjualan, garansi, ...
            $t->string('reference')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('products');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balanced ON journal_lines; DROP FUNCTION IF EXISTS check_journal_balanced();');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        DB::unprepared('DROP FUNCTION IF EXISTS forbid_journal_change();');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('branches');
    }
};
