<?php

namespace App\Domain\Accounting;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Data bukti potong PPh 21 tahunan (1721-A1) per karyawan, dijumlah dari slip gaji yang disetujui atau dibayar.
     *
     * @return list<array{employee_id:int, name:string, npwp:?string, nik:?string, ptkp:string, months:int, gross:int, emp_jht_jp:int, pph21:int}>
     */
    public function buktiPotong(?int $branchId, int $year): array
    {
        $q = DB::table('payroll_slips as s')
            ->join('payroll_runs as r', 'r.id', '=', 's.payroll_run_id')
            ->join('employees as e', 'e.id', '=', 's.employee_id')
            ->whereIn('r.status', ['disetujui', 'dibayar'])->where('r.year', $year)
            ->groupBy('e.id', 'e.name', 'e.npwp', 'e.nik', 'e.ptkp')->orderBy('e.name')
            ->selectRaw('e.id, e.name, e.npwp, e.nik, e.ptkp, count(*) as months, sum(s.tax_gross) as gross, sum(s.emp_jht + s.emp_jp) as emp_jht_jp, sum(s.pph21) as pph21');
        if ($branchId) {
            $q->where('r.branch_id', $branchId);
        }

        return $q->get()->map(fn ($r) => [
            'employee_id' => (int) $r->id, 'name' => $r->name, 'npwp' => $r->npwp, 'nik' => $r->nik, 'ptkp' => $r->ptkp,
            'months' => (int) $r->months, 'gross' => (int) $r->gross, 'emp_jht_jp' => (int) $r->emp_jht_jp, 'pph21' => (int) $r->pph21,
        ])->all();
    }

    /** Saldo per akun (debit - kredit) sampai tanggal tertentu; $branchId null = konsolidasi semua cabang. */
    private function netByAccount(?int $branchId, ?string $from, ?string $to): array
    {
        $q = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->select('l.account_id', DB::raw('SUM(l.debit) - SUM(l.credit) as net'))
            ->groupBy('l.account_id');

        if ($branchId) {
            $q->where('j.branch_id', $branchId);
        }
        if ($from) {
            $q->where('j.entry_date', '>=', $from);
        }
        if ($to) {
            $q->where('j.entry_date', '<=', $to);
        }

        return $q->pluck('net', 'account_id')->map(fn ($v) => (int) $v)->all();
    }

    public function trialBalance(?int $branchId = null, ?string $to = null): array
    {
        $net = $this->netByAccount($branchId, null, $to);
        $rows = [];
        $totalDebit = $totalCredit = 0;

        foreach (Account::orderBy('code')->get() as $a) {
            $n = $net[$a->id] ?? 0;
            $debit = max($n, 0);
            $credit = max(-$n, 0);
            $totalDebit += $debit;
            $totalCredit += $credit;
            $rows[$a->code] = ['name' => $a->name, 'debit' => $debit, 'credit' => $credit];
        }

        return ['rows' => $rows, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    public function incomeStatement(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $net = $this->netByAccount($branchId, $from, $to);
        $sum = function (array $types) use ($net) {
            $total = 0;
            $rows = [];
            foreach (Account::whereIn('type', $types)->orderBy('code')->get() as $a) {
                $n = $net[$a->id] ?? 0;
                $value = in_array($a->type, ['revenue'], true) ? -$n : $n;
                $rows[$a->code] = $value;
                $total += $value;
            }

            return [$rows, $total];
        };

        [$revenueRows, $revenue] = $sum(['revenue']);
        [$cogsRows, $cogs] = $sum(['cogs']);
        [$expenseRows, $expense] = $sum(['expense']);
        [$otherRows, $other] = $sum(['other_expense']);

        return [
            'revenue_rows' => $revenueRows, 'revenue' => $revenue,
            'cogs' => $cogs, 'gross_profit' => $revenue - $cogs,
            'expense_rows' => $expenseRows + $otherRows,
            'expenses' => $expense + $other,
            'net_income' => $revenue - $cogs - $expense - $other,
        ];
    }

    public function balanceSheet(?int $branchId = null, ?string $to = null): array
    {
        $net = $this->netByAccount($branchId, null, $to);
        $sum = function (string $type, int $sign) use ($net) {
            $rows = [];
            $total = 0;
            foreach (Account::where('type', $type)->orderBy('code')->get() as $a) {
                $v = $sign * ($net[$a->id] ?? 0);
                $rows[$a->code] = $v;
                $total += $v;
            }

            return [$rows, $total];
        };

        [$assetRows, $assets] = $sum('asset', 1);
        [$liabRows, $liabilities] = $sum('liability', -1);
        [$equityRows, $equity] = $sum('equity', -1);
        $earnings = $this->incomeStatement($branchId, null, $to)['net_income'];

        return [
            'asset_rows' => $assetRows, 'assets' => $assets,
            'liability_rows' => $liabRows, 'liabilities' => $liabilities,
            'equity_rows' => $equityRows, 'equity' => $equity,
            'current_earnings' => $earnings,
            'liabilities_and_equity' => $liabilities + $equity + $earnings,
        ];
    }
}
