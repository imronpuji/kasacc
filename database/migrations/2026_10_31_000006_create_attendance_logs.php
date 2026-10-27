<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();
            $t->unsignedInteger('geofence_m')->default(100);
        });

        Schema::create('attendance_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->timestampTz('check_in_at');
            $t->decimal('in_lat', 9, 6);
            $t->decimal('in_lng', 9, 6);
            $t->unsignedInteger('in_distance_m');
            $t->unsignedInteger('in_accuracy_m')->default(0);
            $t->unsignedInteger('late_minutes')->default(0);
            $t->timestampTz('check_out_at')->nullable();
            $t->decimal('out_lat', 9, 6)->nullable();
            $t->decimal('out_lng', 9, 6)->nullable();
            $t->unsignedInteger('out_distance_m')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['lat', 'lng', 'geofence_m']));
    }
};
