<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 20)->nullable()->unique(); // 08xxxxxxxxxx; dipakai untuk masuk ke portal pelanggan
            $t->text('note')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });

        Schema::create('customer_otps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('code_hash');
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['customer_id', 'created_at']);
        });

        Schema::table('service_tickets', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
            $t->string('public_token', 40)->nullable()->unique();
            $t->string('progress', 30)->default('diterima'); // diterima, dikerjakan, menunggu_sparepart, siap_diambil
        });

        Schema::table('pos_sales', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
        });

        Schema::create('service_progress_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $t->string('progress', 30);
            $t->string('note', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index('service_ticket_id');
        });

        Schema::create('complaints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('service_ticket_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('pos_sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 20); // garansi, produk, layanan, lainnya
            $t->text('description');
            $t->string('status', 20)->default('baru'); // baru, diproses, selesai
            $t->text('resolution')->nullable();
            $t->date('received_on');
            $t->date('resolved_on')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['branch_id', 'received_on']);
        });

        $this->backfill();
    }

    /** Pelanggan dibuat dari tiket lama yang punya nomor HP valid (satu pelanggan per nomor); tiket lama dapat token lacak. */
    private function backfill(): void
    {
        $normalize = function (?string $raw): ?string {
            $d = preg_replace('/\D+/', '', (string) $raw);
            if ($d === '') {
                return null;
            }
            $d = str_starts_with($d, '62') ? '0'.substr($d, 2) : (str_starts_with($d, '8') ? '0'.$d : $d);

            return preg_match('/^08\d{7,12}$/', $d) ? $d : null;
        };

        foreach (DB::table('service_tickets')->orderBy('id')->get(['id', 'customer_name', 'customer_phone']) as $t) {
            $update = ['public_token' => Str::random(32)];
            if ($phone = $normalize($t->customer_phone)) {
                $id = DB::table('customers')->where('phone', $phone)->value('id')
                    ?? DB::table('customers')->insertGetId(['name' => $t->customer_name, 'phone' => $phone, 'created_at' => now(), 'updated_at' => now()]);
                $update['customer_id'] = $id;
            }
            DB::table('service_tickets')->where('id', $t->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('service_progress_logs');
        Schema::table('pos_sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id'));
        Schema::table('service_tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['public_token', 'progress']);
        });
        Schema::dropIfExists('customer_otps');
        Schema::dropIfExists('customers');
    }
};
