<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value');
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('name');
            $t->string('position')->nullable();
            $t->date('hired_on');
            $t->unsignedBigInteger('base_salary');
            $t->unsignedBigInteger('fixed_allowance')->default(0);
            $t->string('ptkp')->nullable(); // TK/0, K/1, dst
            $t->string('pay_method')->default('bank'); // bank, tunai
            $t->unsignedInteger('commission_bps')->default(0); // 1000 = 10% dari biaya jasa
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedSmallInteger('month');
            $t->unsignedSmallInteger('present')->default(0);
            $t->unsignedSmallInteger('leave')->default(0);
            $t->unsignedSmallInteger('sick')->default(0);
            $t->unsignedSmallInteger('absent')->default(0);
            $t->unsignedSmallInteger('late')->default(0);
            $t->unsignedSmallInteger('overtime_hours')->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'year', 'month']);
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('start_date');
            $t->unsignedSmallInteger('days');
            $t->string('status')->default('menunggu'); // menunggu, disetujui
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('employee_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('given_on');
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('installment');
            $t->unsignedBigInteger('balance');
            $t->unsignedSmallInteger('installments_paid')->default(0);
            $t->string('status')->default('aktif'); // aktif, lunas
            $t->foreignId('journal_id')->constrained('journals')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedSmallInteger('month');
            $t->string('status')->default('draft'); // draft, disetujui, dibayar, batal
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->foreignId('payment_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_active ON payroll_runs (branch_id, year, month) WHERE status <> 'batal'");

        Schema::create('payroll_slips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            foreach (['base', 'allowance', 'overtime', 'commission', 'absence_deduction', 'late_deduction', 'income',
                'emp_kes', 'emp_jht', 'emp_jp', 'er_kes', 'er_jht', 'er_jp', 'er_jkk', 'er_jkm',
                'tax_gross', 'pph21', 'advance_deduction', 'total_deductions', 'net_pay'] as $c) {
                $t->unsignedBigInteger($c)->default(0);
            }
            $t->string('pay_method');
            $t->string('tax_category', 1);
            $t->unsignedInteger('ter_bps')->default(0);
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('advance_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_advance_id')->constrained()->restrictOnDelete();
            $t->foreignId('payroll_slip_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('amount');
        });

        Schema::table('service_tickets', function (Blueprint $t) {
            $t->foreignId('payroll_slip_id')->nullable()->constrained('payroll_slips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', fn (Blueprint $t) => $t->dropConstrainedForeignId('payroll_slip_id'));
        foreach (['advance_payments', 'payroll_slips', 'payroll_runs', 'employee_advances', 'leave_requests', 'attendances', 'employees', 'payroll_settings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
