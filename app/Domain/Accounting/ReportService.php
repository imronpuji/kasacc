<?php

namespace App\Domain\Accounting;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

class ReportService
{
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
