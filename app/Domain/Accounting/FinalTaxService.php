<?php

namespace App\Domain\Accounting;

use App\Domain\Payroll\PayrollSettings;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Pajak penghasilan usaha per bulan per cabang. Cara hitung dan tarif mengikuti TaxSettings (final atas omzet, atau badan atas laba). */
class FinalTaxService
{
    public function __construct(private LedgerService $ledger)
    {
    }

    /** Omzet bruto = total pendapatan (akun 4-xxxx) bulan itu, sudah dikurangi retur/pembalikan. */
    public function turnover(int $branchId, int $year, int $month): int
    {
        $from = CarbonImmutable::create($year, $month, 1);
        $net = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', 'revenue')->where('j.branch_id', $branchId)
            ->whereBetween('j.entry_date', [$from->toDateString(), $from->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) as n')->value('n');

        return max((int) $net, 0);
    }

    /** Dasar pajak bulan itu. Final: omzet (setelah bagian bebas pajak tahunan, bila ada). Badan: laba bersih (tidak negatif). */
    public function base(int $branchId, int $year, int $month): int
    {
        if (TaxSettings::regime() === 'none') {
            return 0;
        }
        $from = CarbonImmutable::create($year, $month, 1);
        if (TaxSettings::regime() === 'badan') {
            $is = app(ReportService::class)->incomeStatement($branchId, $from->toDateString(), $from->endOfMonth()->toDateString());

            return max($is['net_income'], 0);
        }
        $turnover = $this->turnover($branchId, $year, $month);
        $exempt = TaxSettings::exempt();
        if ($exempt <= 0 || $turnover === 0) {
            return $turnover;
        }
        // Bagian omzet setahun yang bebas pajak dihitung untuk seluruh usaha, lalu dibagi ke cabang sesuai porsi omzetnya.
        $all = fn (int $m) => (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', 'revenue')->whereBetween('j.entry_date', [CarbonImmutable::create($year, 1, 1)->toDateString(), CarbonImmutable::create($year, $m, 1)->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) as n')->value('n');
        $cum = max($all($month), 0);
        $prev = $month > 1 ? max($all($month - 1), 0) : 0;
        $allMonth = max($cum - $prev, 0);
        $taxableMonth = max($cum - $exempt, 0) - max($prev - $exempt, 0);

        return $allMonth > 0 ? intdiv($turnover * $taxableMonth + intdiv($allMonth, 2), $allMonth) : 0;
    }

    public function due(int $branchId, int $year, int $month): int
    {
        return PayrollSettings::pct($this->base($branchId, $year, $month), TaxSettings::bps());
    }

    public function accrualRef(Branch $b, int $y, int $m): string
    {
        return sprintf('PPHFINAL-%04d-%02d-%s', $y, $m, $b->code);
    }

    public function accrued(Branch $b, int $y, int $m): bool
    {
        return DB::table('journals')->where('reference', $this->accrualRef($b, $y, $m))->exists();
    }

    public function paid(Branch $b, int $y, int $m): bool
    {
        return DB::table('journals')->where('reference', $this->accrualRef($b, $y, $m).'-BAYAR')->exists();
    }

    /** Catat beban dan utang pajak bulan itu. Sekali saja per cabang per bulan. */
    public function accrue(User $user, Branch $b, int $y, int $m): int
    {
        if ($this->accrued($b, $y, $m)) {
            throw new \DomainException('Pajak bulan ini untuk cabang ini sudah dicatat.');
        }
        if (CarbonImmutable::create($y, $m, 1)->endOfMonth()->isFuture()) {
            throw new \DomainException('Bulan ini belum selesai. Catat pajak setelah akhir bulan.');
        }
        $tax = $this->due($b->id, $y, $m);
        if ($tax <= 0) {
            throw new \DomainException('Tidak ada pajak yang perlu dicatat bulan ini (tidak ada omzet/laba, bebas pajak, atau pajak tidak dihitung di aplikasi).');
        }
        $this->ledger->post([
            'branch_id' => $b->id, 'date' => CarbonImmutable::create($y, $m, 1)->endOfMonth()->toDateString(),
            'source' => 'tax', 'reference' => $this->accrualRef($b, $y, $m), 'created_by' => $user->id,
            'description' => sprintf('Pajak penghasilan usaha %02d/%04d', $m, $y),
            'lines' => [['account' => '8-1200', 'debit' => $tax], ['account' => '2-1600', 'credit' => $tax]],
        ]);

        return $tax;
    }

    /** Catat setoran ke kas negara (paling lambat tanggal 15 bulan berikutnya). */
    public function pay(User $user, Branch $b, int $y, int $m, string $date, string $account = '1-1110'): int
    {
        if (! $this->accrued($b, $y, $m)) {
            throw new \DomainException('Catat pajak bulan ini dulu sebelum mencatat setoran.');
        }
        if ($this->paid($b, $y, $m)) {
            throw new \DomainException('Setoran bulan ini sudah dicatat.');
        }
        if (! in_array($account, ['1-1100', '1-1110'], true)) {
            throw new \DomainException('Setoran harus dari Kas atau Bank.');
        }
        $tax = (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.reference', $this->accrualRef($b, $y, $m))->sum('l.debit');
        $this->ledger->post([
            'branch_id' => $b->id, 'date' => $date, 'source' => 'tax', 'created_by' => $user->id,
            'reference' => $this->accrualRef($b, $y, $m).'-BAYAR', 'description' => sprintf('Setor PPh final %02d/%04d', $m, $y),
            'lines' => [['account' => '2-1600', 'debit' => $tax], ['account' => $account, 'credit' => $tax]],
        ]);

        return $tax;
    }
}
