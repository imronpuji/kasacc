<?php

use App\Domain\Payroll\HrService;
use App\Domain\Payroll\PayrollService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Journal;
use App\Models\PayrollRun;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

function fixUser(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function fixTicket(int $branchId, int $techId, int $fee, string $closed): ServiceTicket
{
    return ServiceTicket::create([
        'branch_id' => $branchId, 'code' => 'F'.uniqid(), 'customer_name' => 'X', 'device' => 'iPhone',
        'technician_id' => $techId, 'status' => 'selesai', 'labor_fee' => $fee, 'received_on' => $closed, 'closed_on' => $closed,
    ]);
}

function fixAttendance(array $over = []): void
{
    $t = test();
    foreach ($t->months ?? [1] as $m) {
        $t->hr->recordAttendance($t->finance, $t->emp, 2027, $m, $over + ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    }
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-12-01', 100_000_000, 'kas');
    $tx->depositCapital($this->branch->id, '2026-12-01', 100_000_000, 'bank');
    $this->hr = app(HrService::class);
    $this->pay = app(PayrollService::class);
    $this->finance = fixUser('finance_admin');
    $this->approver = fixUser('approver');
    $this->tech = fixUser('technician');
    $this->emp = Employee::create([
        'branch_id' => $this->branch->id, 'user_id' => $this->tech->id, 'name' => 'Andi', 'position' => 'Teknisi', 'hired_on' => '2025-03-01',
        'base_salary' => 4_500_000, 'fixed_allowance' => 500_000, 'ptkp' => 'TK/0', 'pay_method' => 'bank', 'commission_bps' => 1000,
    ]);
});

it('R1 tiket yang selesai sesudah draf dihitung tidak ikut ditandai terbayar, dan dibayar di gaji bulan depan', function () {
    $this->months = [1, 2];
    fixAttendance();
    $early = fixTicket($this->branch->id, $this->tech->id, 300_000, '2027-01-10');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $late = fixTicket($this->branch->id, $this->tech->id, 200_000, '2027-01-28');

    expect($early->fresh()->payroll_slip_id)->not->toBeNull()->and($late->fresh()->payroll_slip_id)->toBeNull();

    $this->pay->approve($this->approver, $run, '2027-01-31');
    expect($late->fresh()->payroll_slip_id)->toBeNull();

    $feb = $this->pay->generate($this->finance, $this->branch->id, 2027, 2);
    expect($feb->slips()->first()->commission)->toBe(20_000)
        ->and($late->fresh()->payroll_slip_id)->toBe($feb->slips()->first()->id);
});

it('R1 tiket dari sebelum bulan gaji pertama tidak ikut dibayar', function () {
    $this->months = [2];
    fixAttendance();
    $old = fixTicket($this->branch->id, $this->tech->id, 900_000, '2026-11-20');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 2);

    expect($run->slips()->first()->commission)->toBe(0)->and($old->fresh()->payroll_slip_id)->toBeNull();
});

it('R1 membatalkan draf melepas tiket', function () {
    $this->months = [1];
    fixAttendance();
    $t = fixTicket($this->branch->id, $this->tech->id, 300_000, '2027-01-10');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    expect($t->fresh()->payroll_slip_id)->not->toBeNull();

    $this->pay->cancel($this->approver, $run, '2027-01-31');
    expect($t->fresh()->payroll_slip_id)->toBeNull();
});

it('R3 persetujuan kedua dari data basi ditolak dan jurnal hanya satu', function () {
    $this->months = [1];
    fixAttendance();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $stale = PayrollRun::find($run->id);
    $this->pay->approve($this->approver, $run, '2027-01-31');

    expect(fn () => $this->pay->approve($this->approver, $stale, '2027-01-31'))->toThrow(DomainException::class, 'sudah diproses');
    expect(Journal::where('reference', 'PAYROLL-2027-01')->count())->toBe(1);
});

it('R3 pembayaran kedua dari data basi ditolak dan jurnal bayar hanya satu', function () {
    $this->months = [1];
    fixAttendance();
    $run = $this->pay->approve($this->approver, $this->pay->generate($this->finance, $this->branch->id, 2027, 1), '2027-01-31');
    $stale = PayrollRun::find($run->id);
    $this->pay->pay($this->finance, $run, '2027-02-01');

    expect(fn () => $this->pay->pay($this->finance, $stale, '2027-02-01'))->toThrow(DomainException::class, 'sudah dibayar');
    expect(Journal::where('source', 'payroll_payment')->count())->toBe(1);
});

it('R4 kasbon berubah sesudah gaji dihitung: persetujuan ditolak', function () {
    $this->months = [1];
    fixAttendance();
    $this->hr->giveAdvance($this->finance, $this->emp, '2027-01-02', 1_200_000, 300_000, 'kas');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    expect($run->slips()->first()->advance_deduction)->toBe(300_000);

    EmployeeAdvance::first()->update(['installment' => 100_000]);

    expect(fn () => $this->pay->approve($this->approver, $run, '2027-01-31'))->toThrow(DomainException::class, 'berubah sejak gaji dihitung');
    expect(Journal::where('source', 'payroll')->count())->toBe(0);
});

it('R4 potongan kasbon yang dikecilkan karena gaji rendah tetap bisa disetujui', function () {
    $this->months = [1];
    $this->emp->update(['base_salary' => 4_000_000, 'fixed_allowance' => 0]);
    fixAttendance(['present' => 6, 'absent' => 20]);
    $this->hr->giveAdvance($this->finance, $this->emp, '2027-01-02', 5_700_000, 1_900_000, 'kas');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $slip = $run->slips()->first();
    expect($slip->advance_deduction)->toBeLessThan(1_900_000);

    $run = $this->pay->approve($this->approver, $run, '2027-01-31');
    expect($run->status)->toBe('disetujui');
});

it('R2/R9 karyawan berhenti ikut gaji sampai bulan terakhir dengan koreksi pajak tahunan, lalu tidak ikut lagi', function () {
    $this->emp->update(['base_salary' => 9_000_000, 'fixed_allowance' => 1_000_000, 'commission_bps' => 0]);
    $ctrl = Employee::create([
        'branch_id' => $this->branch->id, 'name' => 'Rina', 'position' => 'Kepala cabang', 'hired_on' => '2024-01-01',
        'base_salary' => 9_000_000, 'fixed_allowance' => 1_000_000, 'ptkp' => 'TK/0', 'pay_method' => 'bank', 'commission_bps' => 0,
    ]);
    foreach ([1, 2, 3, 4] as $m) {
        foreach ([$this->emp, $ctrl] as $e) {
            $this->hr->recordAttendance($this->finance, $e, 2027, $m, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
        }
    }
    foreach ([1, 2] as $m) {
        $this->pay->approve($this->approver, $this->pay->generate($this->finance, $this->branch->id, 2027, $m), "2027-0{$m}-28");
    }
    $this->emp->update(['resigned_on' => '2027-03-10', 'is_active' => false]);

    $mar = $this->pay->generate($this->finance, $this->branch->id, 2027, 3);
    $s = $mar->slips()->where('employee_id', $this->emp->id)->first();
    $c = $mar->slips()->where('employee_id', $ctrl->id)->first();
    expect($s)->not->toBeNull()->and($s->ter_bps)->toBe(0)->and($c->ter_bps)->toBeGreaterThan(0);

    $prior = \App\Models\PayrollSlip::where('employee_id', $this->emp->id)->whereHas('run', fn ($q) => $q->where('status', 'disetujui'))->get();
    $expected = max(app(\App\Domain\Payroll\TaxService::class)->annualTax(
        (int) ($prior->sum('tax_gross') + $s->tax_gross), (int) ($prior->sum(fn ($x) => $x->emp_jht + $x->emp_jp) + $s->emp_jht + $s->emp_jp), $this->emp->ptkp
    ) - (int) $prior->sum('pph21'), 0);
    expect($s->pph21)->toBe($expected);

    $this->pay->approve($this->approver, $mar, '2027-03-28');
    $apr = $this->pay->generate($this->finance, $this->branch->id, 2027, 4);
    expect($apr->slips()->count())->toBe(1)->and($apr->slips()->where('employee_id', $this->emp->id)->exists())->toBeFalse();
});

it('R7 data bukti potong sama dengan jumlah slip yang disetujui tahun itu, draf tidak ikut', function () {
    $this->months = [1, 2, 3];
    $this->emp->update(['npwp' => '123456789012345', 'nik' => '3201234567890001']);
    fixAttendance();
    foreach ([1, 2] as $m) {
        $this->pay->approve($this->approver, $this->pay->generate($this->finance, $this->branch->id, 2027, $m), "2027-0{$m}-28");
    }
    $this->pay->generate($this->finance, $this->branch->id, 2027, 3);

    $rows = app(\App\Domain\Accounting\ReportService::class)->buktiPotong(null, 2027);
    $slips = \App\Models\PayrollSlip::whereHas('run', fn ($q) => $q->where('status', 'disetujui'))->get();

    expect($rows)->toHaveCount(1)->and($rows[0]['npwp'])->toBe('123456789012345')->and($rows[0]['nik'])->toBe('3201234567890001')
        ->and($rows[0]['months'])->toBe(2)
        ->and($rows[0]['gross'])->toBe((int) $slips->sum('tax_gross'))
        ->and($rows[0]['emp_jht_jp'])->toBe((int) $slips->sum(fn ($s) => $s->emp_jht + $s->emp_jp))
        ->and($rows[0]['pph21'])->toBe((int) $slips->sum('pph21'));
    expect(app(\App\Domain\Accounting\ReportService::class)->buktiPotong(null, 2026))->toBe([]);
});

it('R8 THR: 12 bulan penuh sebulan upah, ikut PPh 21 tapi tidak ikut BPJS', function () {
    $this->months = [1];
    fixAttendance();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $before = $run->slips()->first();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1, true);
    $after = $run->slips()->first();

    expect($before->thr)->toBe(0)->and($after->thr)->toBe(5_000_000)
        ->and($after->income)->toBe($before->income + 5_000_000)
        ->and($after->tax_gross)->toBe($before->tax_gross + 5_000_000)
        ->and($after->pph21)->toBeGreaterThan($before->pph21)
        ->and([$after->emp_kes, $after->emp_jht, $after->emp_jp, $after->er_kes, $after->er_jht, $after->er_jp])
        ->toBe([$before->emp_kes, $before->emp_jht, $before->emp_jp, $before->er_kes, $before->er_jht, $before->er_jp]);

    $run = $this->pay->approve($this->approver, $run, '2027-01-31');
    expect(\App\Models\PayrollSlip::first()->net_pay)->toBe($after->net_pay);
});

it('R8 THR proporsional untuk 1-11 bulan dan nol untuk kurang dari 1 bulan', function () {
    $this->months = [1];
    $this->emp->update(['hired_on' => '2026-08-01']);
    $b = Employee::create([
        'branch_id' => $this->branch->id, 'name' => 'Baru', 'position' => 'Kasir', 'hired_on' => '2027-01-15',
        'base_salary' => 4_000_000, 'fixed_allowance' => 0, 'ptkp' => 'TK/0', 'pay_method' => 'tunai', 'commission_bps' => 0,
    ]);
    fixAttendance();
    $this->hr->recordAttendance($this->finance, $b, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1, true);

    expect($run->slips()->where('employee_id', $this->emp->id)->first()->thr)->toBe(2_500_000)
        ->and($run->slips()->where('employee_id', $b->id)->first()->thr)->toBe(0);
});

it('R8 THR kedua dalam setahun ditolak, hitung ulang draf yang sama boleh', function () {
    $this->months = [1, 2];
    fixAttendance();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1, true);
    $this->pay->generate($this->finance, $this->branch->id, 2027, 1, true);
    $this->pay->approve($this->approver, $run, '2027-01-31');

    expect(fn () => $this->pay->generate($this->finance, $this->branch->id, 2027, 2, true))->toThrow(DomainException::class, 'sekali setahun');
    expect($this->pay->generate($this->finance, $this->branch->id, 2027, 2)->slips()->first()->thr)->toBe(0);
});

it('R2 karyawan dengan tanggal berhenti di masa depan masih boleh absen, setelah tanggalnya lewat ditolak', function () {
    $svc = app(\App\Domain\Payroll\AttendanceLogService::class);
    $today = $svc->now()->toDateString();

    $this->emp->update(['resigned_on' => $svc->now()->addDays(10)->toDateString()]);
    expect($svc->employeeFor($this->tech)->id)->toBe($this->emp->id);

    $this->emp->update(['resigned_on' => $today]);
    expect($svc->employeeFor($this->tech)->id)->toBe($this->emp->id);

    $this->emp->update(['resigned_on' => $svc->now()->subDay()->toDateString()]);
    expect(fn () => $svc->employeeFor($this->tech))->toThrow(DomainException::class, 'nonaktif');
});

it('gaji tidak bisa dibayar kalau saldo bank cabang itu tidak cukup', function () {
    $poor = Branch::create(['code' => 'CAB2', 'name' => 'Cabang Dua']);
    $e = Employee::create([
        'branch_id' => $poor->id, 'name' => 'Dewi', 'position' => 'Kasir', 'hired_on' => '2025-01-01',
        'base_salary' => 4_000_000, 'fixed_allowance' => 0, 'ptkp' => 'TK/0', 'pay_method' => 'bank', 'commission_bps' => 0,
    ]);
    $this->hr->recordAttendance($this->finance, $e, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    $run = $this->pay->approve($this->approver, $this->pay->generate($this->finance, $poor->id, 2027, 1), '2027-01-31');

    expect(fn () => $this->pay->pay($this->finance, $run, '2027-02-01'))->toThrow(DomainException::class, 'Saldo');
    expect($run->fresh()->status)->toBe('disetujui');
});

it('rincian komisi di slip cocok dengan total, termasuk karyawan tanpa gaji pokok', function () {
    $this->emp->update(['base_salary' => 0, 'fixed_allowance' => 0, 'commission_bps' => 4000]);
    $this->months = [1];
    fixAttendance();
    fixTicket($this->branch->id, $this->tech->id, 300_000, '2027-01-10');
    fixTicket($this->branch->id, $this->tech->id, 200_000, '2027-01-12');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $slip = $run->slips()->first();

    $tickets = $this->pay->slipTickets($slip);
    expect($slip->commission)->toBe(200_000)
        ->and($tickets)->toHaveCount(2)
        ->and((int) $tickets->sum('labor_fee') * 4000 / 10000)->toEqual($slip->commission);

    $this->actingAs($this->finance)->get(route('payslip', $run->id))->assertOk()
        ->assertSee('Rincian komisi servis (40% dari ongkos servis)')->assertSee('2 unit');
});

it('karyawan komisi-saja tidak kena potongan telat', function () {
    $this->emp->update(['base_salary' => 0, 'fixed_allowance' => 0, 'commission_bps' => 4000]);
    $this->months = [1];
    fixAttendance(['late' => 15]);
    fixTicket($this->branch->id, $this->tech->id, 500_000, '2027-01-10');
    $slip = $this->pay->generate($this->finance, $this->branch->id, 2027, 1)->slips()->first();

    expect($slip->late_deduction)->toBe(0)->and($slip->income)->toBe(200_000)->and($slip->net_pay)->toBe(200_000);
});

it('potongan telat gaji pokok kecil dibatasi sebesar penghasilan, tidak negatif', function () {
    $this->emp->update(['base_salary' => 100_000, 'fixed_allowance' => 0, 'commission_bps' => 0]);
    $this->months = [1];
    fixAttendance(['late' => 15]);
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $slip = $run->slips()->first();

    expect($slip->late_deduction)->toBe(100_000)->and($slip->income)->toBe(0);
    $this->actingAs($this->finance)->get(route('payslip', $run->id))->assertSee('dibatasi sebesar penghasilan');
});

it('potongan telat yang lebih kecil dari penghasilan tidak diubah', function () {
    $this->months = [1];
    fixAttendance(['late' => 2]);
    $slip = $this->pay->generate($this->finance, $this->branch->id, 2027, 1)->slips()->first();

    expect($slip->late_deduction)->toBe(40_000)->and($slip->income)->toBe(5_000_000 - 40_000);
});

it('persen komisi tersimpan di slip: mengubah persen karyawan tidak mengubah rincian slip lama', function () {
    $this->emp->update(['base_salary' => 0, 'fixed_allowance' => 0, 'commission_bps' => 4000]);
    $this->months = [1];
    fixAttendance();
    fixTicket($this->branch->id, $this->tech->id, 500_000, '2027-01-10');
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $this->emp->update(['commission_bps' => 1000]);

    expect($run->slips()->first()->commission_bps)->toBe(4000);
    $this->actingAs($this->finance)->get(route('payslip', $run->id))->assertSee('(40% dari ongkos servis)')->assertDontSee('(10% dari ongkos servis)');
});
