<?php

use App\Domain\Payroll\HrService;
use App\Domain\Payroll\PayrollService;
use App\Domain\Payroll\PayrollSettings;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function bal(string $code): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', $code)->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as b')->value('b');
}

function user(string $role, array $attrs = []): User
{
    $u = User::factory()->create($attrs);
    $u->assignRole($role);

    return $u;
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
    $this->finance = user('finance_admin');
    $this->approver = user('approver');
    $this->cashier = user('cashier');

    $mk = fn ($name, $pos, $hired, $base, $allow, $ptkp, $method, $bps = 0, $uid = null) => Employee::create([
        'branch_id' => $this->branch->id, 'user_id' => $uid, 'name' => $name, 'position' => $pos, 'hired_on' => $hired,
        'base_salary' => $base, 'fixed_allowance' => $allow, 'ptkp' => $ptkp, 'pay_method' => $method, 'commission_bps' => $bps,
    ]);
    $this->uAndi = user('technician');
    $this->uBudi = user('technician');
    $this->andi = $mk('Andi', 'Teknisi', '2025-03-01', 4_500_000, 500_000, 'TK/0', 'bank', 1000, $this->uAndi->id);
    $this->budi = $mk('Budi', 'Teknisi', '2024-08-01', 5_000_000, 500_000, 'K/1', 'bank', 1000, $this->uBudi->id);
    $this->sari = $mk('Sari', 'Kasir', '2025-06-01', 4_000_000, 325_000, 'TK/0', 'tunai');
    $this->rina = $mk('Rina', 'Kepala cabang', '2024-01-01', 9_000_000, 1_000_000, 'TK/0', 'bank');
});

function full26(int $month, array $skip = []): void
{
    $t = test();
    foreach ([$t->andi, $t->budi, $t->sari, $t->rina] as $e) {
        if (! in_array($e->name, $skip)) {
            $t->hr->recordAttendance($t->finance, $e, 2027, $month, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
        }
    }
}

function januari(): void
{
    $t = test();
    $rec = fn ($e, $h, $i, $s, $a, $l, $o) => $t->hr->recordAttendance($t->finance, $e, 2027, 1, ['present' => $h, 'leave' => $i, 'sick' => $s, 'absent' => $a, 'late' => $l, 'overtime_hours' => $o]);
    $rec($t->andi, 24, 0, 1, 1, 2, 0);
    $rec($t->budi, 26, 0, 0, 0, 0, 0);
    $rec($t->sari, 25, 1, 0, 0, 0, 4);
    $rec($t->rina, 26, 0, 0, 0, 0, 0);

    $tk = fn ($uid, $fee, $status, $closed) => ServiceTicket::create([
        'branch_id' => $t->branch->id, 'code' => 'T'.uniqid(), 'customer_name' => 'X', 'device' => 'iPhone',
        'technician_id' => $uid, 'status' => $status, 'labor_fee' => $fee, 'received_on' => '2027-01-02', 'closed_on' => $closed,
    ]);
    foreach ([[300_000, '2027-01-05'], [400_000, '2027-01-12'], [200_000, '2027-01-20'], [300_000, '2027-01-31']] as [$fee, $d]) {
        $tk($t->uAndi->id, $fee, 'selesai', $d);
    }
    $tk($t->uAndi->id, 50_000, 'batal', '2027-01-15');
    foreach ([[250_000, '2027-01-08'], [350_000, '2027-01-18'], [300_000, '2027-01-27']] as [$fee, $d]) {
        $tk($t->uBudi->id, $fee, 'selesai', $d);
    }
    $tk($t->uBudi->id, 500_000, 'selesai', '2027-02-01');
    $tk(null, 200_000, 'selesai', '2027-01-10');

    $t->hr->giveAdvance($t->finance, $t->budi, '2027-01-02', 1_200_000, 300_000, 'kas');
}

it('slip gaji Januari 2027 sesuai skenario', function () {
    januari();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $s = fn ($e) => $run->slips()->where('employee_id', $e->id)->first();

    expect($run->status)->toBe('draft');
    $a = $s($this->andi);
    expect([$a->commission, $a->absence_deduction, $a->late_deduction, $a->income])->toBe([120_000, 173_077, 40_000, 4_906_923]);
    expect([$a->emp_kes, $a->emp_jht, $a->emp_jp, $a->pph21, $a->net_pay])->toBe([50_000, 100_000, 50_000, 0, 4_706_923]);
    expect($a->tax_gross)->toBe(5_126_923);

    $b = $s($this->budi);
    expect([$b->commission, $b->income, $b->advance_deduction, $b->total_deductions, $b->net_pay])->toBe([90_000, 5_590_000, 300_000, 520_000, 5_070_000]);
    expect($b->tax_gross)->toBe(5_832_000)->and($b->tax_category)->toBe('B')->and($b->pph21)->toBe(0);

    $r = $s($this->sari);
    expect([$r->overtime, $r->income, $r->total_deductions, $r->net_pay, $r->tax_gross])->toBe([187_500, 4_512_500, 173_000, 4_339_500, 4_702_800]);
    expect([$r->er_kes, $r->er_jht, $r->er_jp, $r->er_jkk, $r->er_jkm])->toBe([173_000, 160_025, 86_500, 4_325, 12_975]);

    $n = $s($this->rina);
    expect([$n->income, $n->tax_gross, $n->ter_bps, $n->pph21, $n->total_deductions, $n->net_pay])->toBe([10_000_000, 10_440_000, 250, 261_000, 661_000, 9_339_000]);

    $slips = $run->slips;
    expect($slips->sum('income'))->toBe(25_009_423)->and($slips->sum('net_pay'))->toBe(23_455_423)
        ->and($slips->sum(fn ($x) => $x->er_kes + $x->er_jht + $x->er_jp + $x->er_jkk + $x->er_jkm))->toBe(2_507_325);
});

it('persetujuan memposting jurnal payroll seimbang dan memotong kasbon', function () {
    januari();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $run = $this->pay->approve($this->approver, $run, '2027-01-31');

    expect($run->status)->toBe('disetujui');
    expect(bal('6-1000'))->toBe(25_009_423)->and(bal('6-1100'))->toBe(2_507_325)
        ->and(bal('2-1200'))->toBe(-23_455_423)->and(bal('2-1300'))->toBe(-3_500_325)->and(bal('2-1500'))->toBe(-261_000);
    expect(bal('1-1300'))->toBe(900_000);
    $adv = EmployeeAdvance::first();
    expect($adv->balance)->toBe(900_000)->and($adv->installments_paid)->toBe(1)->and($adv->status)->toBe('aktif');
    expect(ServiceTicket::whereNotNull('payroll_slip_id')->count())->toBe(7);
});

it('pembayaran melunasi utang gaji, BPJS, PPh dan tidak bisa dua kali', function () {
    januari();
    $kasAwal = bal('1-1100'); $bankAwal = bal('1-1110');
    $run = $this->pay->approve($this->approver, $this->pay->generate($this->finance, $this->branch->id, 2027, 1), '2027-01-31');
    $run = $this->pay->pay($this->finance, $run, '2027-02-01');

    expect($run->status)->toBe('dibayar');
    expect(bal('2-1200'))->toBe(0)->and(bal('2-1300'))->toBe(0)->and(bal('2-1500'))->toBe(0);
    expect(bal('1-1100'))->toBe($kasAwal - 4_339_500);
    expect(bal('1-1110'))->toBe($bankAwal - 19_115_923 - 3_500_325 - 261_000);
    expect(fn () => $this->pay->pay($this->finance, $run, '2027-02-01'))->toThrow(DomainException::class, 'sudah dibayar');
});

it('K1 payroll bulan sama dua kali ditolak, K5 draft dihitung ulang, K9 kasir tak berwenang', function () {
    januari();
    $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    expect(\App\Models\PayrollRun::count())->toBe(1)->and($run->slips()->count())->toBe(4);
    $this->pay->approve($this->approver, $run, '2027-01-31');
    expect(fn () => $this->pay->generate($this->finance, $this->branch->id, 2027, 1))->toThrow(DomainException::class, 'sudah ada');
    expect(fn () => $this->pay->generate($this->cashier, $this->branch->id, 2027, 3))->toThrow(DomainException::class, 'tidak berwenang');
});

it('K2 pembuat payroll tidak boleh menyetujui sendiri', function () {
    januari();
    $owner = user('owner');
    $run = $this->pay->generate($owner, $this->branch->id, 2027, 1);
    expect(fn () => $this->pay->approve($owner, $run, '2027-01-31'))->toThrow(DomainException::class, 'yang menghitungnya');
    expect(fn () => $this->pay->approve($this->cashier, $run, '2027-01-31'))->toThrow(DomainException::class, 'tidak berwenang');
});

it('K4 periode terkunci menolak persetujuan', function () {
    januari();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    \App\Models\Period::create(['year' => 2027, 'month' => 1, 'locked_at' => now()]);
    expect(fn () => $this->pay->approve($this->approver, $run, '2027-01-31'))->toThrow(\App\Domain\Accounting\Exceptions\PeriodLockedException::class);
    expect(bal('6-1000'))->toBe(0);
});

it('K6 absensi tak boleh diubah setelah disetujui, boleh setelah dibatalkan; komisi dilepas lagi', function () {
    januari();
    $run = $this->pay->approve($this->approver, $this->pay->generate($this->finance, $this->branch->id, 2027, 1), '2027-01-31');
    expect(fn () => $this->hr->recordAttendance($this->finance, $this->sari, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]))
        ->toThrow(DomainException::class, 'gaji');
    $this->pay->cancel($this->approver, $run, '2027-02-01');
    expect(bal('6-1000'))->toBe(0)->and(bal('1-1300'))->toBe(1_200_000)->and(EmployeeAdvance::first()->balance)->toBe(1_200_000);
    expect(ServiceTicket::whereNotNull('payroll_slip_id')->count())->toBe(0);
    $this->hr->recordAttendance($this->finance, $this->sari, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    $again = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    expect($again->status)->toBe('draft');
});

it('K7 absensi yang totalnya bukan 26 hari ditolak', function () {
    expect(fn () => $this->hr->recordAttendance($this->finance, $this->sari, 2027, 1, ['present' => 26, 'leave' => 1, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]))
        ->toThrow(DomainException::class, '26');
});

it('cuti C1-C4', function () {
    $this->hr->recordAttendance($this->finance, $this->sari, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    $req = $this->hr->requestLeave($this->finance, $this->sari, '2027-01-12', 1);
    $this->hr->approveLeave($this->approver, $req);
    expect($this->hr->leaveBalance($this->sari, 2027))->toBe(11);
    $att = \App\Models\Attendance::where('employee_id', $this->sari->id)->first();
    expect([$att->present, $att->leave])->toBe([25, 1]);

    expect(fn () => $this->hr->requestLeave($this->finance, $this->andi, '2027-01-04', 13))->toThrow(DomainException::class, 'melebihi');
    $own = $this->hr->requestLeave($this->finance, $this->budi, '2027-01-04', 1);
    expect(fn () => $this->hr->approveLeave($this->finance, $own))->toThrow(DomainException::class, 'sendiri');
    $baru = Employee::create(['branch_id' => $this->branch->id, 'name' => 'Baru', 'hired_on' => '2026-11-01', 'base_salary' => 3_000_000, 'ptkp' => 'TK/0', 'pay_method' => 'tunai']);
    expect(fn () => $this->hr->requestLeave($this->finance, $baru, '2027-01-05', 1))->toThrow(DomainException::class, '12 bulan');
});

it('kasbon B1, B3, B4', function () {
    $this->hr->giveAdvance($this->finance, $this->budi, '2027-01-02', 1_200_000, 300_000, 'kas');
    expect(bal('1-1300'))->toBe(1_200_000);
    expect(fn () => $this->hr->giveAdvance($this->finance, $this->sari, '2027-01-02', 6_000_000, 3_000_000, 'kas'))->toThrow(DomainException::class, '50%');

    full26(1); full26(2);
    $adv = EmployeeAdvance::first();
    $adv->update(['balance' => 300_000, 'installments_paid' => 3]);
    $run = $this->pay->approve($this->approver, $this->pay->generate($this->finance, $this->branch->id, 2027, 1), '2027-01-31');
    expect($adv->fresh()->balance)->toBe(0)->and($adv->fresh()->status)->toBe('lunas');
    $feb = $this->pay->generate($this->finance, $this->branch->id, 2027, 2);
    expect($feb->slips()->where('employee_id', $this->budi->id)->first()->advance_deduction)->toBe(0);
});

it('K11 karyawan tanpa PTKP ditolak', function () {
    full26(1);
    $this->sari->update(['ptkp' => null]);
    expect(fn () => $this->pay->generate($this->finance, $this->branch->id, 2027, 1))->toThrow(DomainException::class, 'PTKP');
});

it('K12 tarif di pengaturan dipakai payroll berikutnya', function () {
    PayrollSettings::set('kes_emp', 200);
    januari();
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    expect($run->slips()->where('employee_id', $this->sari->id)->first()->emp_kes)->toBe(86_500);
});

it('gaji 2,5 juta: PPh 21 nol, BPJS tetap dihitung', function () {
    $e = Employee::create(['branch_id' => $this->branch->id, 'name' => 'Kecil', 'hired_on' => '2026-01-01', 'base_salary' => 2_500_000, 'ptkp' => 'TK/0', 'pay_method' => 'tunai']);
    $this->hr->recordAttendance($this->finance, $e, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    full26(1);
    $s = $this->pay->generate($this->finance, $this->branch->id, 2027, 1)->slips()->where('employee_id', $e->id)->first();
    expect([$s->tax_gross, $s->pph21, $s->net_pay])->toBe([2_610_000, 0, 2_400_000]);
});

it('Desember: true-up PPh 21 Rina 381.000', function () {
    $rina = $this->rina;
    foreach (range(1, 12) as $m) {
        foreach ([$this->andi, $this->budi, $this->sari] as $x) {
            $x->update(['is_active' => false]);
        }
        $this->hr->recordAttendance($this->finance, $rina, 2027, $m, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
        $run = $this->pay->generate($this->finance, $this->branch->id, 2027, $m);
        $this->pay->approve($this->approver, $run, sprintf('2027-%02d-28', $m));
        $pph = $run->slips()->first()->pph21;
        expect($pph)->toBe($m < 12 ? 261_000 : 381_000);
    }
});

it('usaha belum terdaftar: BPJS dan PPh 21 dimatikan, slip tanpa potongan dan tidak ada utang BPJS/PPh', function () {
    PayrollSettings::set('bpjs_on', 0);
    PayrollSettings::set('pph21_on', 0);
    full26(1);
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    foreach ($run->slips as $s) {
        expect($s->emp_kes + $s->emp_jht + $s->emp_jp + $s->er_kes + $s->er_jht + $s->er_jp + $s->er_jkk + $s->er_jkm + $s->pph21)->toBe(0)
            ->and($s->net_pay)->toBe($s->income);
    }
    $this->pay->approve($this->approver, $run, '2027-01-31');
    expect(bal('2-1300'))->toBe(0)->and(bal('2-1500'))->toBe(0);
});

it('gaji dibayar dari rekening bank pilihan, bukan hanya akun Bank bawaan', function () {
    \App\Models\Account::create(['code' => '1-1120', 'name' => 'Bank BCA', 'type' => 'asset', 'is_active' => true]);
    app(Transactions::class)->depositCapital($this->branch->id, '2026-12-02', 100_000_000, '1-1120');
    full26(1);
    $run = $this->pay->generate($this->finance, $this->branch->id, 2027, 1);
    $this->pay->approve($this->approver, $run, '2027-01-31');
    $before = bal('1-1110');
    $this->pay->pay($this->finance, $run->fresh(), '2027-02-01', '1-1120');
    expect(bal('1-1120'))->toBeLessThan(100_000_000)->and(bal('1-1110'))->toBe($before);
});
