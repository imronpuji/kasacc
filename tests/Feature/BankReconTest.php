<?php

use App\Domain\Accounting\BankReconService;
use App\Domain\Transactions;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $this->fin = User::factory()->create(); $this->fin->assignRole('finance_admin');
    $this->rec = app(BankReconService::class);
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 10_000_000, 'bank');   // masuk 10.000.000
    $tx->transferCash($this->branch->id, '2026-11-05', 2_000_000, 'bank', 'kas'); // keluar 2.000.000
    $tx->payExpense($this->branch->id, '2026-11-10', '6-2000', 3_000_000, 'bank', 'Sewa'); // keluar 3.000.000
    $tx->payExpense($this->branch->id, '2026-11-20', '6-2100', 500_000, 'bank', 'Internet'); // di buku saja, belum cair
});

function csv(): string
{
    return "tanggal,keterangan,jumlah\n"
        ."2026-11-01,Setoran modal,10000000\n"
        ."06/11/2026,Tarik tunai ATM,-2000000\n"
        ."2026-11-11,Bayar sewa toko,-3.000.000\n"
        ."2026-11-30,Biaya admin bank,-15000\n";
}

it('impor CSV membaca tanggal dan angka berbagai format, dan melewati baris duplikat', function () {
    expect($this->rec->import($this->fin, $this->branch->id, csv()))->toBe(['imported' => 4, 'skipped' => 0]);
    expect($this->rec->import($this->fin, $this->branch->id, csv()))->toBe(['imported' => 0, 'skipped' => 4]);
    $l = BankStatementLine::orderBy('id')->get();
    expect($l->pluck('amount')->all())->toBe([10_000_000, -2_000_000, -3_000_000, -15_000]);
    expect($l[1]->line_date->toDateString())->toBe('2026-11-06');
});

it('impor menolak CSV yang kolomnya tidak dikenal dan pengguna tanpa wewenang', function () {
    expect(fn () => $this->rec->import($this->fin, $this->branch->id, "a,b\n1,2\n"))->toThrow(DomainException::class, 'kolom');
    $kasir = User::factory()->create(); $kasir->assignRole('cashier');
    expect(fn () => $this->rec->import($kasir, $this->branch->id, csv()))->toThrow(DomainException::class, 'berwenang');
});

it('pencocokan otomatis: sama nominal dan tanggal berdekatan; sisanya tetap terbuka', function () {
    $this->rec->import($this->fin, $this->branch->id, csv());
    $r = $this->rec->autoMatch($this->branch->id);
    expect($r)->toBe(['matched' => 3]);

    $s = $this->rec->summary($this->branch->id);
    expect($s['bank_only'])->toHaveCount(1)->and($s['bank_only'][0]['amount'])->toBe(-15_000);
    expect($s['book_only'])->toHaveCount(1)->and($s['book_only'][0]['amount'])->toBe(-500_000);
    expect($s['bank_only_total'])->toBe(-15_000)->and($s['book_only_total'])->toBe(-500_000)->and($s['reconciled'])->toBeFalse();
    // saldo buku 1-1110 = 10.000.000 - 2.000.000 - 3.000.000 - 500.000
    expect($s['book_balance'])->toBe(4_500_000);
});

it('biaya admin bank dicatat dari baris mutasi: jurnal terbentuk dan baris langsung cocok', function () {
    $this->rec->import($this->fin, $this->branch->id, csv());
    $this->rec->autoMatch($this->branch->id);
    $line = BankStatementLine::where('amount', -15_000)->first();
    $j = $this->rec->recordBankOnly($this->fin, $line, '8-1100');

    expect($j->source)->toBe('bank_recon');
    $bal = fn ($c) => (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.code', $c)->selectRaw('coalesce(sum(debit)-sum(credit),0) n')->value('n');
    expect($bal('8-1100'))->toBe(15_000)->and($bal('1-1110'))->toBe(4_485_000);
    expect($line->fresh()->journal_line_id)->not->toBeNull();
    expect(fn () => $this->rec->recordBankOnly($this->fin, $line->fresh(), '8-1100'))->toThrow(DomainException::class, 'sudah');

    $s = $this->rec->summary($this->branch->id);
    expect($s['bank_only'])->toBe([])->and($s['book_only_total'])->toBe(-500_000);
});

it('cek yang akhirnya cair dicocokkan manual, lalu rekonsiliasi selesai', function () {
    $this->rec->import($this->fin, $this->branch->id, csv()."2026-12-02,Bayar internet,-500000\n");
    $this->rec->autoMatch($this->branch->id); // tanggal 2 Des jauh dari 20 Nov: tidak otomatis
    $bank = BankStatementLine::where('amount', -500_000)->first();
    expect($bank->journal_line_id)->toBeNull();

    $book = collect($this->rec->summary($this->branch->id)['book_only'])->first();
    $this->rec->match($this->fin, $bank, $book['journal_line_id']);
    $this->rec->recordBankOnly($this->fin, BankStatementLine::where('amount', -15_000)->first(), '8-1100');

    expect($this->rec->summary($this->branch->id)['reconciled'])->toBeTrue();
    expect(fn () => $this->rec->match($this->fin, $bank->fresh(), $book['journal_line_id']))->toThrow(DomainException::class);

    $this->rec->unmatch($this->fin, $bank->fresh());
    expect($this->rec->summary($this->branch->id)['reconciled'])->toBeFalse();
});

it('nominal tidak sama tidak boleh dicocokkan manual', function () {
    $this->rec->import($this->fin, $this->branch->id, csv());
    $bank = BankStatementLine::where('amount', -15_000)->first();
    $book = collect($this->rec->summary($this->branch->id)['book_only'])->first();
    expect(fn () => $this->rec->match($this->fin, $bank, $book['journal_line_id']))->toThrow(DomainException::class, 'nominal');
});
