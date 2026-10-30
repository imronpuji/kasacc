<?php

namespace App\Domain\Accounting;

use App\Models\BankStatementLine;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Rekonsiliasi bank: mencocokkan mutasi rekening koran dengan catatan di satu akun bank (bawaan 1-1110). */
class BankReconService
{
    private const BANK = '1-1110';

    private const DATE_TOLERANCE_DAYS = 3;

    public function __construct(private LedgerService $ledger)
    {
    }

    private function authorize(User $user): void
    {
        if (! $user->can('reconcile-bank')) {
            throw new DomainException('Anda tidak berwenang mencocokkan rekening bank. Minta pemilik atau admin keuangan.');
        }
    }

    private function bankAccountId(string $bankCode = self::BANK): int
    {
        return (int) DB::table('accounts')->where('code', $bankCode)->value('id');
    }

    /** CSV dengan kolom tanggal, keterangan, dan jumlah (atau kolom debit/kredit terpisah). Pemisah koma atau titik koma. */
    public function import(User $user, int $branchId, string $csv, string $accountCode = self::BANK): array
    {
        $this->authorize($user);
        $rows = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($csv)), fn ($l) => trim($l) !== ''));
        if (count($rows) < 2) {
            throw new DomainException('File kosong atau belum ada baris datanya. Periksa file dari internet banking lalu coba lagi.');
        }
        $delimiter = substr_count($rows[0], ';') > substr_count($rows[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim($h, " \t\"\xEF\xBB\xBF")), str_getcsv(array_shift($rows), $delimiter));
        $find = fn (array $names) => collect($header)->search(fn ($h) => in_array($h, $names, true));
        $iDate = $find(['tanggal', 'date', 'tgl']);
        $iDesc = $find(['keterangan', 'description', 'uraian', 'deskripsi']);
        $iAmount = $find(['jumlah', 'amount', 'nominal']);
        $iDebit = $find(['debit', 'keluar']);
        $iCredit = $find(['kredit', 'credit', 'masuk']);
        if ($iDate === false || $iDesc === false || ($iAmount === false && $iDebit === false && $iCredit === false)) {
            throw new DomainException('Kolom di file tidak dikenali. Baris pertama harus berisi nama kolom: tanggal, keterangan, jumlah (atau debit dan kredit).');
        }

        $parsed = [];
        foreach ($rows as $n => $line) {
            $c = str_getcsv($line, $delimiter);
            $amount = $iAmount !== false
                ? $this->parseAmount($c[$iAmount] ?? '')
                : $this->parseAmount($c[$iCredit] ?? '0') - abs($this->parseAmount($c[$iDebit] ?? '0'));
            if ($amount === 0) {
                continue;
            }
            $parsed[] = ['date' => $this->parseDate($c[$iDate] ?? '', $n + 2), 'desc' => trim($c[$iDesc] ?? ''), 'amount' => $amount];
        }

        return DB::transaction(function () use ($parsed, $branchId, $user, $accountCode) {
            $imported = 0;
            $skipped = 0;
            $seenInFile = [];
            foreach ($parsed as $p) {
                $key = $p['date'].'|'.$p['desc'].'|'.$p['amount'];
                $seenInFile[$key] = ($seenInFile[$key] ?? 0) + 1;
                $existing = BankStatementLine::where('branch_id', $branchId)->where('account_code', $accountCode)->whereDate('line_date', $p['date'])
                    ->where('description', $p['desc'])->where('amount', $p['amount'])->count();
                if ($existing >= $seenInFile[$key]) {
                    $skipped++;

                    continue;
                }
                BankStatementLine::create(['branch_id' => $branchId, 'line_date' => $p['date'], 'description' => $p['desc'], 'amount' => $p['amount'], 'imported_by' => $user->id, 'account_code' => $accountCode]);
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    private function parseAmount(string $raw): int
    {
        $s = strtolower(str_replace(['rp', ' '], '', trim($raw)));
        if ($s === '') {
            return 0;
        }
        $negative = str_starts_with($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')')) || str_ends_with($s, '-');
        $s = preg_replace('/[.,]\d{2}$/', '', $s); // buang sen
        $digits = preg_replace('/\D/', '', $s);

        return $digits === '' ? 0 : ($negative ? -1 : 1) * (int) $digits;
    }

    private function parseDate(string $raw, int $line): string
    {
        $raw = trim($raw);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!'.$fmt, $raw);
            if ($d && $d->format($fmt) === $raw) {
                return $d->format('Y-m-d');
            }
        }
        throw new DomainException("Tanggal di baris {$line} tidak dikenali: \"{$raw}\". Tulis seperti 2026-11-30 atau 30/11/2026.");
    }

    private function unmatchedBookLines(int $branchId, string $accountCode = self::BANK)
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $this->bankAccountId($accountCode))->where('j.branch_id', $branchId)
            ->whereNotIn('l.id', DB::table('bank_statement_lines')->whereNotNull('journal_line_id')->select('journal_line_id'))
            ->orderBy('j.entry_date')->orderBy('l.id')
            ->get(['l.id as journal_line_id', 'j.id as journal_id', 'j.entry_date as date', 'j.description', DB::raw('(l.debit - l.credit) as amount')])
            ->map(fn ($r) => ['journal_line_id' => (int) $r->journal_line_id, 'journal_id' => (int) $r->journal_id, 'date' => (string) $r->date, 'description' => $r->description, 'amount' => (int) $r->amount]);
    }

    /** Cocokkan otomatis: nominal sama persis dan tanggal berselisih paling banyak 3 hari; satu lawan satu. */
    public function autoMatch(int $branchId, string $accountCode = self::BANK): array
    {
        return DB::transaction(function () use ($branchId, $accountCode) {
            $book = $this->unmatchedBookLines($branchId, $accountCode)->all();
            $matched = 0;
            foreach (BankStatementLine::where('branch_id', $branchId)->where('account_code', $accountCode)->whereNull('journal_line_id')->orderBy('line_date')->orderBy('id')->get() as $line) {
                $best = null;
                $bestGap = PHP_INT_MAX;
                foreach ($book as $i => $b) {
                    if ($b['amount'] !== (int) $line->amount) {
                        continue;
                    }
                    $gap = abs((int) CarbonImmutable::parse($b['date'])->diffInDays($line->line_date));
                    if ($gap <= self::DATE_TOLERANCE_DAYS && $gap < $bestGap) {
                        $best = $i;
                        $bestGap = $gap;
                    }
                }
                if ($best !== null) {
                    $line->update(['journal_line_id' => $book[$best]['journal_line_id']]);
                    unset($book[$best]);
                    $matched++;
                }
            }

            return ['matched' => $matched];
        });
    }

    public function summary(int $branchId, string $accountCode = self::BANK): array
    {
        $bookOnly = $this->unmatchedBookLines($branchId, $accountCode)->all();
        $bankOnly = BankStatementLine::where('branch_id', $branchId)->where('account_code', $accountCode)->whereNull('journal_line_id')->orderBy('line_date')->orderBy('id')->get()
            ->map(fn ($l) => ['id' => $l->id, 'date' => $l->line_date->toDateString(), 'description' => $l->description, 'amount' => (int) $l->amount])->all();
        $balance = (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $this->bankAccountId($accountCode))->where('j.branch_id', $branchId)
            ->selectRaw('coalesce(sum(l.debit) - sum(l.credit), 0) as n')->value('n');

        return [
            'book_only' => $bookOnly, 'bank_only' => $bankOnly,
            'book_only_total' => array_sum(array_column($bookOnly, 'amount')),
            'bank_only_total' => array_sum(array_column($bankOnly, 'amount')),
            'book_balance' => $balance,
            'reconciled' => $bookOnly === [] && $bankOnly === [],
        ];
    }

    /** Mutasi yang belum ada di buku (misalnya biaya admin bank): buat jurnalnya dari baris mutasi, lalu cocokkan. */
    public function recordBankOnly(User $user, BankStatementLine $line, string $accountCode, ?string $bankCode = null): Journal
    {
        $this->authorize($user);
        if ($line->journal_line_id) {
            throw new DomainException('Mutasi ini sudah dicocokkan.');
        }
        $bankCode ??= $line->account_code ?: self::BANK;
        if ($accountCode === $bankCode) {
            throw new DomainException('Pilih pos lawan selain rekening bank yang sedang dicocokkan.');
        }
        $amount = abs((int) $line->amount);

        return DB::transaction(function () use ($user, $line, $accountCode, $amount, $bankCode) {
            $out = $line->amount < 0;
            $journal = $this->ledger->post([
                'branch_id' => $line->branch_id, 'date' => $line->line_date->toDateString(), 'source' => 'bank_recon',
                'description' => 'Mutasi bank: '.$line->description, 'created_by' => $user->id,
                'lines' => $out
                    ? [['account' => $accountCode, 'debit' => $amount], ['account' => $bankCode, 'credit' => $amount]]
                    : [['account' => $bankCode, 'debit' => $amount], ['account' => $accountCode, 'credit' => $amount]],
            ]);
            $lineId = DB::table('journal_lines')->where('journal_id', $journal->id)->where('account_id', $this->bankAccountId($bankCode))->value('id');
            $line->update(['journal_line_id' => $lineId]);

            return $journal;
        });
    }

    public function match(User $user, BankStatementLine $line, int $journalLineId): void
    {
        $this->authorize($user);
        if ($line->journal_line_id) {
            throw new DomainException('Mutasi ini sudah dicocokkan.');
        }
        $book = $this->unmatchedBookLines($line->branch_id, $line->account_code ?: self::BANK)->firstWhere('journal_line_id', $journalLineId);
        if (! $book) {
            throw new DomainException('Catatan itu tidak ditemukan atau sudah dicocokkan dengan mutasi lain. Muat ulang halaman lalu coba lagi.');
        }
        if ($book['amount'] !== (int) $line->amount) {
            throw new DomainException('Belum bisa dicocokkan: nominal tidak sama (mutasi bank Rp'.number_format($line->amount, 0, ',', '.').', catatan aplikasi Rp'.number_format($book['amount'], 0, ',', '.').'). Pilih catatan yang jumlahnya sama.');
        }
        $line->update(['journal_line_id' => $journalLineId]);
    }

    public function unmatch(User $user, BankStatementLine $line): void
    {
        $this->authorize($user);
        $line->update(['journal_line_id' => null]);
    }
}
