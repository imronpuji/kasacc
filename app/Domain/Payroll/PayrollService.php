<?php

namespace App\Domain\Payroll;

use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Transactions;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Journal;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\ServiceTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(private LedgerService $ledger, private TaxService $tax, private HrService $hr, private Transactions $transactions)
    {
    }

    /** @param bool $withThr true = gaji bulan ini memuat THR (maksimal sekali setahun per cabang). */
    public function generate(User $user, int $branchId, int $year, int $month, bool $withThr = false): PayrollRun
    {
        $this->hr->authorize($user);

        return DB::transaction(function () use ($user, $branchId, $year, $month, $withThr) {
            $run = PayrollRun::where('branch_id', $branchId)->where('year', $year)->where('month', $month)->where('status', '<>', 'batal')->lockForUpdate()->first();
            if ($run && $run->status !== 'draft') {
                throw new DomainException('Gaji bulan ini sudah ada dan berstatus '.($run->status === 'disetujui' ? 'disetujui' : 'sudah dibayar').'. Minta penyetuju membatalkannya dulu kalau perlu dihitung ulang.');
            }
            if ($withThr) {
                $other = PayrollRun::where('branch_id', $branchId)->where('year', $year)->where('status', '<>', 'batal')
                    ->when($run, fn ($q) => $q->where('id', '<>', $run->id))->whereHas('slips', fn ($q) => $q->where('thr', '>', 0))->first();
                if ($other) {
                    throw new DomainException(sprintf('THR tahun %d sudah dibayarkan di gaji %02d/%d. THR hanya sekali setahun; batalkan gaji itu dulu kalau salah.', $year, $other->month, $other->year));
                }
            }
            $run ??= PayrollRun::create(['branch_id' => $branchId, 'year' => $year, 'month' => $month, 'created_by' => $user->id]);
            $run->slips()->delete();

            $monthEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth();
            $monthStart = $monthEnd->startOfMonth()->toDateString();
            // Bila tanggal berhenti terisi, ia yang menentukan ikut tidaknya; is_active hanya dipakai kalau kosong.
            $employees = Employee::where('branch_id', $branchId)->whereDate('hired_on', '<=', $monthEnd)
                ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('resigned_on')->where('is_active', true))->orWhereDate('resigned_on', '>=', $monthStart))
                ->orderBy('id')->get();
            if ($employees->isEmpty()) {
                throw new DomainException('Belum ada karyawan aktif di cabang ini. Tambahkan karyawan di menu Karyawan dan absensi, atau pilih cabang lain.');
            }
            foreach ($employees as $e) {
                $slip = $run->slips()->create($this->computeSlip($e, $year, $month, $withThr));
                if ($slip->commission > 0) {
                    // Tiket ditandai saat draf dihitung supaya tiket yang selesai sesudahnya tidak ikut ditandai terbayar.
                    $this->commissionTickets($e, $year, $month)->update(['payroll_slip_id' => $slip->id]);
                }
            }

            return $run->fresh();
        });
    }

    /** THR: satu bulan upah untuk masa kerja 12 bulan atau lebih, masa kerja/12 untuk 1-11 bulan, nol di bawah 1 bulan. */
    private function thrAmount(Employee $e, int $year, int $month): int
    {
        $monthEnd = CarbonImmutable::create($year, $month, 1)->endOfMonth();
        $months = (int) $e->hired_on->startOfDay()->diffInMonths($monthEnd->addDay()->startOfDay(), true);
        if ($months < 1) {
            return 0;
        }

        return $months >= 12 ? $e->upah() : intdiv($e->upah() * $months * 2 + 12, 24);
    }

    private function computeSlip(Employee $e, int $year, int $month, bool $withThr = false): array
    {
        $att = Attendance::where('employee_id', $e->id)->where('year', $year)->where('month', $month)->first();
        if (! $att) {
            throw new DomainException("Absensi {$e->name} bulan {$month}/{$year} belum diisi. Isi dulu lewat menu Karyawan dan absensi, tombol Isi absen bulan ini.");
        }
        $category = $this->tax->category($e->ptkp);
        $g = fn (string $k) => PayrollSettings::get($k);
        $pct = fn (int $a, int $bps) => PayrollSettings::pct($a, $bps);

        $upah = $e->upah();
        $workDays = $g('work_days');
        $absence = intdiv((int) $e->base_salary * $att->absent * 2 + $workDays, 2 * $workDays);
        $late = $att->late * $g('late_fee');
        $h = $att->overtime_hours;
        $halfUnits = $h >= 1 ? 3 + 4 * ($h - 1) : 0; // jam pertama 1,5x, berikutnya 2x (dalam setengah)
        $overtime = intdiv($upah * $halfUnits + $g('overtime_divisor'), 2 * $g('overtime_divisor'));
        $commission = 0;
        if ($e->commission_bps > 0 && $e->user_id) {
            $fees = (int) $this->commissionTickets($e, $year, $month)->sum('labor_fee');
            $commission = $pct($fees, $e->commission_bps);
        }
        $thr = $withThr ? $this->thrAmount($e, $year, $month) : 0;
        // THR menambah penghasilan bruto untuk PPh 21, tetapi dasar BPJS tetap dari upah ($upah).
        $income = (int) $e->base_salary + (int) $e->fixed_allowance + $overtime + $commission + $thr - $absence - $late;

        $kesBase = min($upah, $g('kes_cap'));
        $jpBase = min($upah, $g('jp_cap'));
        $slip = [
            'employee_id' => $e->id, 'base' => (int) $e->base_salary, 'allowance' => (int) $e->fixed_allowance,
            'overtime' => $overtime, 'commission' => $commission, 'thr' => $thr, 'absence_deduction' => $absence, 'late_deduction' => $late,
            'income' => $income,
            'emp_kes' => $pct($kesBase, $g('kes_emp')), 'emp_jht' => $pct($upah, $g('jht_emp')), 'emp_jp' => $pct($jpBase, $g('jp_emp')),
            'er_kes' => $pct($kesBase, $g('kes_er')), 'er_jht' => $pct($upah, $g('jht_er')), 'er_jp' => $pct($jpBase, $g('jp_er')),
            'er_jkk' => $pct($upah, $g('jkk')), 'er_jkm' => $pct($upah, $g('jkm')),
            'pay_method' => $e->pay_method, 'tax_category' => $category,
        ];
        if (! $g('bpjs_on')) {
            foreach (['emp_kes', 'emp_jht', 'emp_jp', 'er_kes', 'er_jht', 'er_jp', 'er_jkk', 'er_jkm'] as $k) {
                $slip[$k] = 0;
            }
        }
        $taxGross = $income + $slip['er_kes'] + $slip['er_jkk'] + $slip['er_jkm'];
        $slip['tax_gross'] = $taxGross;

        if (! $g('pph21_on')) {
            $slip['ter_bps'] = 0;
            $slip['pph21'] = 0;
        } elseif ($month < 12 && ! ($e->resigned_on && $e->resigned_on->year === $year && $e->resigned_on->month === $month)) {
            $slip['ter_bps'] = $this->tax->terBps($category, $taxGross);
            $slip['pph21'] = $pct($taxGross, $slip['ter_bps']);
        } else {
            $prior = PayrollSlip::where('employee_id', $e->id)
                ->whereHas('run', fn ($q) => $q->whereIn('status', ['disetujui', 'dibayar'])->where('year', $year)->where('month', '<', $month))->get();
            $annual = $prior->sum('tax_gross') + $taxGross;
            $jhtJp = $prior->sum(fn ($s) => $s->emp_jht + $s->emp_jp) + $slip['emp_jht'] + $slip['emp_jp'];
            $slip['ter_bps'] = 0;
            $slip['pph21'] = max($this->tax->annualTax((int) $annual, (int) $jhtJp, $e->ptkp) - (int) $prior->sum('pph21'), 0);
        }

        $advance = $this->advanceDeductions($e)->sum('amount');
        $advance = min($advance, max($income - $slip['emp_kes'] - $slip['emp_jht'] - $slip['emp_jp'] - $slip['pph21'], 0));
        $slip['advance_deduction'] = $advance;
        $slip['total_deductions'] = $slip['emp_kes'] + $slip['emp_jht'] + $slip['emp_jp'] + $slip['pph21'] + $advance;
        $slip['net_pay'] = $income - $slip['total_deductions'];

        return $slip;
    }

    private function commissionTickets(Employee $e, int $year, int $month)
    {
        $start = CarbonImmutable::create($year, $month, 1);

        // Tiket telat dari bulan sebelumnya ikut dibayar, tapi hanya sejak bulan gaji pertama karyawan ini lewat sistem
        // (tiket yang lebih lama dibayar lewat cara lama dan tidak boleh membanjiri gaji pertama).
        $first = PayrollRun::where('status', '<>', 'batal')->whereHas('slips', fn ($q) => $q->where('employee_id', $e->id))
            ->orderBy('year')->orderBy('month')->first();
        $from = $first ? CarbonImmutable::create($first->year, $first->month, 1) : $start;
        if ($from->gt($start)) {
            $from = $start;
        }

        return ServiceTicket::where('technician_id', $e->user_id)->where('status', 'selesai')
            ->whereNull('payroll_slip_id')->whereBetween('closed_on', [$from->toDateString(), $start->endOfMonth()->toDateString()]);
    }

    /** Daftar cicilan kasbon aktif yang akan dipotong: [advance, amount]. */
    private function advanceDeductions(Employee $e)
    {
        return EmployeeAdvance::where('employee_id', $e->id)->where('status', 'aktif')->orderBy('id')->get()
            ->map(fn ($a) => ['advance' => $a, 'amount' => min($a->installment, $a->balance)]);
    }

    public function approve(User $user, PayrollRun $run, string $date): PayrollRun
    {
        $this->hr->authorize($user, 'approve-payroll');
        if ($run->created_by === $user->id) {
            throw new DomainException('Gaji ini tidak bisa Anda setujui sendiri karena Anda yang menghitungnya. Minta orang lain menyetujuinya.');
        }
        if ($run->status !== 'draft') {
            throw new DomainException('Hanya gaji yang masih konsep yang bisa disetujui. Muat ulang halaman untuk melihat status terbaru.');
        }

        return DB::transaction(function () use ($user, $run, $date) {
            // Muat ulang dengan kunci baris supaya dua penyetuju yang menekan bersamaan tidak sama-sama lolos.
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'draft') {
                throw new DomainException('Gaji ini sudah diproses orang lain. Muat ulang halaman untuk melihat status terbaru.');
            }
            $slips = $run->slips()->with('employee')->get();
            foreach ($slips as $slip) {
                if ($slip->advance_deduction > 0 && (int) $this->advanceDeductions($slip->employee)->sum('amount') < $slip->advance_deduction) {
                    throw new DomainException("Kasbon {$slip->employee->name} berubah sejak gaji dihitung. Hitung ulang gaji dulu.");
                }
            }
            $sum = fn (string $c) => (int) $slips->sum($c);
            $erBpjs = $sum('er_kes') + $sum('er_jht') + $sum('er_jp') + $sum('er_jkk') + $sum('er_jkm');
            $empBpjs = $sum('emp_kes') + $sum('emp_jht') + $sum('emp_jp');

            $lines = array_values(array_filter([
                ['account' => '6-1000', 'debit' => $sum('income')],
                ['account' => '6-1100', 'debit' => $erBpjs],
                ['account' => '2-1200', 'credit' => $sum('net_pay')],
                ['account' => '2-1300', 'credit' => $erBpjs + $empBpjs],
                ['account' => '2-1500', 'credit' => $sum('pph21')],
                ['account' => '1-1300', 'credit' => $sum('advance_deduction')],
            ], fn ($l) => ($l['debit'] ?? $l['credit']) > 0));

            $journal = $this->ledger->post([
                'branch_id' => $run->branch_id, 'date' => $date, 'lines' => $lines, 'source' => 'payroll',
                'reference' => sprintf('PAYROLL-%04d-%02d', $run->year, $run->month),
                'description' => sprintf('Payroll %02d/%04d', $run->month, $run->year), 'created_by' => $user->id,
            ]);

            foreach ($slips as $slip) {
                if ($slip->advance_deduction > 0) {
                    $left = $slip->advance_deduction;
                    foreach ($this->advanceDeductions($slip->employee) as $row) {
                        $take = min($row['amount'], $left);
                        if ($take <= 0) {
                            continue;
                        }
                        $a = $row['advance'];
                        DB::table('advance_payments')->insert(['employee_advance_id' => $a->id, 'payroll_slip_id' => $slip->id, 'amount' => $take]);
                        $a->balance -= $take;
                        $a->installments_paid += 1;
                        $a->status = $a->balance === 0 ? 'lunas' : 'aktif';
                        $a->save();
                        $left -= $take;
                    }
                }
            }

            $run->update(['status' => 'disetujui', 'approved_by' => $user->id, 'journal_id' => $journal->id]);

            return $run->fresh();
        });
    }

    public function pay(User $user, PayrollRun $run, string $date, string $bankSource = 'bank', string $cashSource = 'kas'): PayrollRun
    {
        $this->hr->authorize($user, 'pay-payroll');
        if ($run->status === 'dibayar') {
            throw new DomainException('Gaji ini sudah dibayar. Tidak perlu dibayar lagi.');
        }
        if ($run->status !== 'disetujui') {
            throw new DomainException('Gaji harus disetujui dulu sebelum dibayar. Minta penyetuju menyetujuinya.');
        }

        return DB::transaction(function () use ($user, $run, $date, $bankSource, $cashSource) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);
            if ($run->status === 'dibayar') {
                throw new DomainException('Gaji ini sudah dibayar. Tidak perlu dibayar lagi.');
            }
            if ($run->status !== 'disetujui') {
                throw new DomainException('Gaji ini sudah diproses orang lain. Muat ulang halaman untuk melihat status terbaru.');
            }
            $slips = $run->slips;
            $bank = (int) $slips->where('pay_method', 'bank')->sum('net_pay');
            $tunai = (int) $slips->where('pay_method', '<>', 'bank')->sum('net_pay');
            $bpjs = (int) $slips->sum(fn ($s) => $s->emp_kes + $s->emp_jht + $s->emp_jp + $s->er_kes + $s->er_jht + $s->er_jp + $s->er_jkk + $s->er_jkm);
            $pph = (int) $slips->sum('pph21');

            // Uang keluar harus ada saldonya; kalau bank dan kas ternyata satu akun, jumlahnya digabung.
            $fromBank = $bank + $bpjs + $pph;
            if (MoneyAccounts::resolve($bankSource) === MoneyAccounts::resolve($cashSource)) {
                $this->transactions->assertFunds($run->branch_id, $date, $bankSource, $fromBank + $tunai);
            } else {
                $this->transactions->assertFunds($run->branch_id, $date, $bankSource, $fromBank);
                $this->transactions->assertFunds($run->branch_id, $date, $cashSource, $tunai);
            }

            $lines = array_values(array_filter([
                ['account' => '2-1200', 'debit' => $bank + $tunai],
                ['account' => '2-1300', 'debit' => $bpjs],
                ['account' => '2-1500', 'debit' => $pph],
                ['account' => MoneyAccounts::resolve($bankSource), 'credit' => $bank + $bpjs + $pph],
                ['account' => MoneyAccounts::resolve($cashSource), 'credit' => $tunai],
            ], fn ($l) => ($l['debit'] ?? $l['credit']) > 0));

            $journal = $this->ledger->post([
                'branch_id' => $run->branch_id, 'date' => $date, 'lines' => $lines, 'source' => 'payroll_payment',
                'reference' => sprintf('PAYROLL-%04d-%02d', $run->year, $run->month),
                'description' => sprintf('Pembayaran payroll %02d/%04d', $run->month, $run->year), 'created_by' => $user->id,
            ]);
            $run->update(['status' => 'dibayar', 'payment_journal_id' => $journal->id]);

            return $run->fresh();
        });
    }

    public function cancel(User $user, PayrollRun $run, string $date): PayrollRun
    {
        $this->hr->authorize($user, 'approve-payroll');
        if ($run->status === 'dibayar') {
            throw new DomainException('Gaji yang sudah dibayar tidak bisa dibatalkan.');
        }
        if ($run->status === 'batal') {
            throw new DomainException('Gaji ini sudah dibatalkan sebelumnya.');
        }

        return DB::transaction(function () use ($user, $run, $date) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);
            if ($run->status === 'dibayar') {
                throw new DomainException('Gaji yang sudah dibayar tidak bisa dibatalkan.');
            }
            if ($run->status === 'batal') {
                throw new DomainException('Gaji ini sudah dibatalkan sebelumnya.');
            }
            $slipIds = $run->slips()->pluck('id');
            if ($run->journal_id) {
                $this->ledger->reverse(Journal::findOrFail($run->journal_id), $date, $user->id);
                foreach (DB::table('advance_payments')->whereIn('payroll_slip_id', $slipIds)->get() as $p) {
                    $a = EmployeeAdvance::findOrFail($p->employee_advance_id);
                    $a->update(['balance' => $a->balance + $p->amount, 'installments_paid' => $a->installments_paid - 1, 'status' => 'aktif']);
                }
                DB::table('advance_payments')->whereIn('payroll_slip_id', $slipIds)->delete();
            }
            // Tiket ditandai sejak draf dihitung, jadi dilepas juga saat draf dibatalkan.
            ServiceTicket::whereIn('payroll_slip_id', $slipIds)->update(['payroll_slip_id' => null]);
            $run->update(['status' => 'batal']);

            return $run->fresh();
        });
    }
}
