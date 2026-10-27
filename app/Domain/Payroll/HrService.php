<?php

namespace App\Domain\Payroll;

use App\Domain\Accounting\LedgerService;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class HrService
{
    public function __construct(private LedgerService $ledger)
    {
    }

    public function authorize(User $user, string $permission = 'manage-hr'): void
    {
        if (! $user->can($permission)) {
            throw new DomainException('Anda tidak berwenang untuk tindakan ini.');
        }
    }

    public function recordAttendance(User $user, Employee $e, int $year, int $month, array $d): Attendance
    {
        $this->authorize($user);
        $days = PayrollSettings::get('work_days');
        foreach (['present', 'leave', 'sick', 'absent', 'late', 'overtime_hours'] as $k) {
            if (! is_numeric($d[$k] ?? 0) || (int) ($d[$k] ?? 0) < 0) {
                throw new DomainException('Angka absensi tidak boleh negatif atau bukan angka.');
            }
        }
        $total = $d['present'] + $d['leave'] + $d['sick'] + $d['absent'];
        if ($total !== $days) {
            throw new DomainException("Jumlah hadir, izin, sakit, dan alpa harus {$days} hari (sekarang {$total}).");
        }
        $locked = PayrollRun::where('branch_id', $e->branch_id)->where('year', $year)->where('month', $month)
            ->whereIn('status', ['disetujui', 'dibayar'])->exists();
        if ($locked) {
            throw new DomainException('Absensi tidak bisa diubah karena payroll bulan ini sudah disetujui. Batalkan payroll dulu.');
        }

        return Attendance::updateOrCreate(['employee_id' => $e->id, 'year' => $year, 'month' => $month], [
            'present' => $d['present'], 'leave' => $d['leave'], 'sick' => $d['sick'], 'absent' => $d['absent'],
            'late' => $d['late'] ?? 0, 'overtime_hours' => $d['overtime_hours'] ?? 0,
        ]);
    }

    public function leaveBalance(Employee $e, int $year, bool $includePending = false): int
    {
        $statuses = $includePending ? ['disetujui', 'menunggu'] : ['disetujui'];
        $used = (int) LeaveRequest::where('employee_id', $e->id)->whereIn('status', $statuses)
            ->whereYear('start_date', $year)->sum('days');

        return PayrollSettings::get('leave_quota') - $used;
    }

    public function requestLeave(User $user, Employee $e, string $start, int $days): LeaveRequest
    {
        $this->authorize($user);
        $startDate = CarbonImmutable::parse($start);
        if ($e->hired_on->addMonths(12)->gt($startDate)) {
            throw new DomainException('Cuti baru berlaku setelah 12 bulan masa kerja.');
        }
        if ($days < 1 || $days > $this->leaveBalance($e, $startDate->year, true)) {
            throw new DomainException('Pengajuan melebihi saldo cuti tahun ini.');
        }

        return LeaveRequest::create(['employee_id' => $e->id, 'start_date' => $start, 'days' => $days, 'requested_by' => $user->id])->fresh();
    }

    public function approveLeave(User $user, LeaveRequest $req): LeaveRequest
    {
        if ($req->requested_by === $user->id) {
            throw new DomainException('Pengaju tidak boleh menyetujui cuti sendiri.');
        }
        $this->authorize($user, 'approve-payroll');
        if ($req->status !== 'menunggu') {
            throw new DomainException('Pengajuan ini sudah diproses.');
        }

        return DB::transaction(function () use ($user, $req) {
            $req->update(['status' => 'disetujui', 'approved_by' => $user->id]);
            $start = CarbonImmutable::parse($req->start_date);
            $att = Attendance::firstOrNew(['employee_id' => $req->employee_id, 'year' => $start->year, 'month' => $start->month]);
            $workDays = PayrollSettings::get('work_days');
            $att->present = ($att->exists ? $att->present : $workDays) - $req->days;
            $att->leave = ($att->exists ? $att->leave : 0) + $req->days;
            $att->save();

            return $req;
        });
    }

    /** Perkiraan gaji bersih sebelum kasbon (upah dikurangi iuran karyawan). */
    public function estimatedNet(Employee $e): int
    {
        $upah = $e->upah();
        $deduct = PayrollSettings::pct(min($upah, PayrollSettings::get('kes_cap')), PayrollSettings::get('kes_emp'))
            + PayrollSettings::pct($upah, PayrollSettings::get('jht_emp'))
            + PayrollSettings::pct(min($upah, PayrollSettings::get('jp_cap')), PayrollSettings::get('jp_emp'));

        return $upah - $deduct;
    }

    public function giveAdvance(User $user, Employee $e, string $date, int $amount, int $installment, string $method = 'kas'): EmployeeAdvance
    {
        $this->authorize($user);
        if ($amount <= 0 || $installment <= 0 || $installment > $amount) {
            throw new DomainException('Nominal kasbon dan cicilan harus lebih dari nol, cicilan tidak boleh melebihi kasbon.');
        }
        $max = PayrollSettings::pct($this->estimatedNet($e), PayrollSettings::get('advance_max_bps'));
        if ($installment > $max) {
            throw new DomainException('Cicilan melebihi 50% gaji bersih sebelum kasbon (maksimal Rp'.number_format($max, 0, ',', '.').').');
        }

        return DB::transaction(function () use ($user, $e, $date, $amount, $installment, $method) {
            $journal = $this->ledger->post([
                'branch_id' => $e->branch_id, 'date' => $date, 'source' => 'kasbon',
                'description' => "Kasbon {$e->name}", 'created_by' => $user->id,
                'lines' => [
                    ['account' => '1-1300', 'debit' => $amount],
                    ['account' => $method === 'kas' ? '1-1100' : '1-1110', 'credit' => $amount],
                ],
            ]);

            return EmployeeAdvance::create([
                'employee_id' => $e->id, 'given_on' => $date, 'amount' => $amount, 'installment' => $installment,
                'balance' => $amount, 'journal_id' => $journal->id,
            ]);
        });
    }
}
