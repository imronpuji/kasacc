<?php

use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Accounting\ReportService;
use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;

function net(string $code): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)->selectRaw('coalesce(SUM(l.debit)-SUM(l.credit),0) n')->value('n');
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->b = Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $this->tx = app(Transactions::class);
    Account::create(['code' => '1-1120', 'name' => 'Bank BCA', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '1-1130', 'name' => 'Bank BRI', 'type' => 'asset', 'is_active' => true]);
});

it('rekening bank tambahan muncul di pilihan dan bisa dipakai membayar', function () {
    expect(MoneyAccounts::options())->toHaveKeys(['kas', 'bank', '1-1120', '1-1130']);
    $this->tx->depositCapital($this->b->id, '2026-11-01', 1_000_000, '1-1120');
    $this->tx->payExpense($this->b->id, '2026-11-02', '6-2100', 200_000, '1-1120', 'Listrik');
    expect(net('1-1120'))->toBe(800_000)->and(net('1-1110'))->toBe(0)->and(net('6-2100'))->toBe(200_000);
});

it('rekening yang dimatikan atau tidak dikenal ditolak', function () {
    Account::where('code', '1-1130')->update(['is_active' => false]);
    expect(fn () => $this->tx->depositCapital($this->b->id, '2026-11-01', 1000, '1-1130'))->toThrow(DomainException::class);
    expect(fn () => $this->tx->depositCapital($this->b->id, '2026-11-01', 1000, '9-9999'))->toThrow(DomainException::class);
    expect(fn () => $this->tx->payExpense($this->b->id, '2026-11-01', '6-2100', 0, 'kas', 'x'))->toThrow(DomainException::class);
});

it('pindah uang antar rekening menjaga total dan menolak asal sama dengan tujuan', function () {
    $this->tx->depositCapital($this->b->id, '2026-11-01', 1_000_000, '1-1120');
    $this->tx->transferCash($this->b->id, '2026-11-02', 300_000, '1-1120', 'kas');
    expect(net('1-1120'))->toBe(700_000)->and(net('1-1100'))->toBe(300_000);
    expect(fn () => $this->tx->transferCash($this->b->id, '2026-11-02', 1, 'kas', 'kas'))->toThrow(DomainException::class, 'tidak boleh sama');
});

it('biaya belum dibayar menjadi utang usaha', function () {
    $this->tx->payExpense($this->b->id, '2026-11-02', '6-2100', 500_000, 'kredit', 'Listrik Oktober');
    expect(net('2-1100'))->toBe(-500_000)->and(net('6-2100'))->toBe(500_000);
});

it('prive tidak mengurangi untung tetapi mengurangi modal', function () {
    $this->tx->depositCapital($this->b->id, '2026-11-01', 5_000_000, 'kas');
    $this->tx->ownerWithdrawal($this->b->id, '2026-11-03', 1_000_000, 'kas', 'keperluan pribadi');
    $r = app(ReportService::class);
    expect($r->incomeStatement(null, '2026-11-01', '2026-11-30')['net_income'])->toBe(0);
    $bs = $r->balanceSheet(null, '2026-11-30');
    expect($bs['equity'])->toBe(4_000_000)->and($bs['assets'])->toBe($bs['liabilities_and_equity']);
});

it('pinjaman: masuk sebagai utang, cicilan pokok kurangi utang, bunga jadi biaya', function () {
    $this->tx->receiveLoan($this->b->id, '2026-11-01', 10_000_000, '1-1120');
    expect(net('2-1700'))->toBe(-10_000_000);
    $this->tx->repayLoan($this->b->id, '2026-12-01', 1_000_000, 100_000, '1-1120');
    expect(net('2-1700'))->toBe(-9_000_000)->and(net('8-1300'))->toBe(100_000)->and(net('1-1120'))->toBe(8_900_000);
    expect(fn () => $this->tx->repayLoan($this->b->id, '2026-12-02', 20_000_000, 0, '1-1120'))->toThrow(DomainException::class, 'melebihi sisa');
});

it('beli tanah bukan biaya; penyusutan jadi biaya dan mengurangi nilai harta', function () {
    $this->tx->depositCapital($this->b->id, '2026-11-01', 300_000_000, '1-1120');
    $this->tx->buyAsset($this->b->id, '2026-11-02', '1-2000', 200_000_000, '1-1120', 'tanah toko');
    $r = app(ReportService::class);
    expect($r->incomeStatement(null, '2026-11-01', '2026-11-30')['net_income'])->toBe(0);
    $this->tx->buyAsset($this->b->id, '2026-11-03', '1-2200', 12_000_000, 'kredit');
    $this->tx->depreciate($this->b->id, '2026-11-30', 100_000);
    $is = $r->incomeStatement(null, '2026-11-01', '2026-11-30');
    $bs = $r->balanceSheet(null, '2026-11-30');
    expect($is['net_income'])->toBe(-100_000)->and($bs['asset_rows']['1-2900'])->toBe(-100_000)->and($bs['assets'])->toBe($bs['liabilities_and_equity']);
    expect(fn () => $this->tx->buyAsset($this->b->id, '2026-11-04', '1-1100', 1000, 'kas'))->toThrow(DomainException::class);
});

it('uang masuk lain menjadi pendapatan lain', function () {
    $this->tx->otherIncome($this->b->id, '2026-11-05', 50_000, '1-1120', 'bunga');
    expect(net('4-2000'))->toBe(-50_000)->and(net('1-1120'))->toBe(50_000);
});

it('usaha belum terdaftar: BPJS dan PPh 21 bisa dimatikan sehingga slip tanpa potongan', function () {
    \App\Domain\Payroll\PayrollSettings::set('bpjs_on', 0);
    \App\Domain\Payroll\PayrollSettings::set('pph21_on', 0);
    expect(\App\Domain\Payroll\PayrollSettings::get('bpjs_on'))->toBe(0)->and(\App\Domain\Payroll\PayrollSettings::get('pph21_on'))->toBe(0);
});
