<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Posting jurnal double-entry.
     *
     * $entry: branch_id (wajib), date (Y-m-d), lines: [['account' => '1-1100', 'debit' => 100] | ['account' => ..., 'credit' => 100]],
     * opsional: reference, source, description, created_by, reversal_of.
     * Semua nominal bilangan bulat rupiah. Gagal = tidak ada yang tersimpan.
     */
    public function post(array $entry): Journal
    {
        $lines = $entry['lines'] ?? [];
        if (count($lines) < 2) {
            throw new InvalidJournalException('Jurnal minimal terdiri dari dua baris.');
        }

        $debit = 0;
        $credit = 0;
        $prepared = [];
        foreach ($lines as $line) {
            $d = $line['debit'] ?? 0;
            $c = $line['credit'] ?? 0;
            if (! is_int($d) || ! is_int($c) || $d < 0 || $c < 0 || ($d > 0) === ($c > 0)) {
                throw new InvalidJournalException('Setiap baris harus berisi satu sisi (debit atau kredit) dengan nominal bulat lebih dari nol.');
            }
            $account = Account::where('code', $line['account'])->where('is_active', true)->first();
            if (! $account) {
                throw new InvalidJournalException("Akun {$line['account']} tidak ditemukan atau tidak aktif.");
            }
            $debit += $d;
            $credit += $c;
            $prepared[] = ['account_id' => $account->id, 'debit' => $d, 'credit' => $c];
        }

        if ($debit !== $credit) {
            throw new UnbalancedJournalException("Jurnal tidak seimbang: debit {$debit}, kredit {$credit}.");
        }

        $date = CarbonImmutable::parse($entry['date']);
        $this->assertPeriodOpen($date);

        return DB::transaction(function () use ($entry, $prepared, $date) {
            $journal = Journal::create([
                'branch_id' => $entry['branch_id'],
                'entry_date' => $date->toDateString(),
                'reference' => $entry['reference'] ?? null,
                'source' => $entry['source'] ?? 'manual',
                'description' => $entry['description'] ?? null,
                'created_by' => $entry['created_by'] ?? null,
                'reversal_of' => $entry['reversal_of'] ?? null,
            ]);

            // Pemeriksaan keseimbangan di database ditunda sampai semua baris masuk,
            // lalu dipaksa jalan sekarang (bukan menunggu commit) dan dikembalikan ke mode tunda.
            DB::statement('SET CONSTRAINTS journal_lines_balanced DEFERRED');
            $journal->lines()->createMany($prepared);
            DB::statement('SET CONSTRAINTS journal_lines_balanced IMMEDIATE');
            DB::statement('SET CONSTRAINTS journal_lines_balanced DEFERRED');

            // K8: audit log siapa, kapan, dan dari mana jurnal ini berasal.
            activity('journal')
                ->performedOn($journal)
                ->causedBy(isset($entry['created_by']) ? \App\Models\User::find($entry['created_by']) : null)
                ->withProperties([
                    'source' => $journal->source,
                    'branch_id' => $journal->branch_id,
                    'entry_date' => $date->toDateString(),
                    'total' => array_sum(array_column($prepared, 'debit')),
                ])
                ->event('posted')
                ->log('Jurnal diposting');

            return $journal->load('lines');
        });
    }

    /** Koreksi = jurnal baru dengan sisi dibalik; jurnal asli tidak disentuh. */
    public function reverse(Journal $journal, string $date, ?int $userId = null): Journal
    {
        if (Journal::where('reversal_of', $journal->id)->exists()) {
            throw new InvalidJournalException('Jurnal ini sudah pernah dibalik.');
        }

        $lines = $journal->lines()->with('account')->get()->map(fn ($l) => [
            'account' => $l->account->code,
            'debit' => (int) $l->credit,
            'credit' => (int) $l->debit,
        ])->all();

        return $this->post([
            'branch_id' => $journal->branch_id,
            'date' => $date,
            'lines' => $lines,
            'reference' => $journal->reference,
            'source' => 'reversal',
            'description' => 'Pembalik jurnal #'.$journal->id,
            'created_by' => $userId,
            'reversal_of' => $journal->id,
        ]);
    }

    public function assertPeriodOpen(CarbonImmutable $date): void
    {
        $locked = Period::where('year', $date->year)->where('month', $date->month)
            ->whereNotNull('locked_at')->exists();

        if ($locked) {
            throw new PeriodLockedException("Periode {$date->format('m/Y')} sudah dikunci; transaksi ditolak.");
        }
    }
}
