<?php

use App\Domain\Accounting\ReportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\Product;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;

/** Jurnal -> ['Dr 1-1100' => 100, 'Cr 3-1000' => 100] supaya mudah dibandingkan dengan dokumen skenario. */
function entries(Journal $journal): array
{
    return $journal->lines()->with('account')->get()
        ->mapWithKeys(fn ($l) => [($l->debit > 0 ? 'Dr ' : 'Cr ').$l->account->code => (int) ($l->debit ?: $l->credit)])
        ->all();
}

function balanceOf(string $code): int
{
    $a = Account::where('code', $code)->firstOrFail();
    $row = DB::table('journal_lines')->where('account_id', $a->id)->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

    return (int) ($row->d - $row->c);
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->b = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat'])->id;
    $this->lcd = Product::create(['sku' => 'LCD13', 'name' => 'LCD iPhone 13', 'kind' => 'sparepart'])->id;
    $this->bat = Product::create(['sku' => 'BAT12', 'name' => 'Baterai iPhone 12', 'kind' => 'sparepart'])->id;
    $this->case = Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris'])->id;
    $this->tx = app(Transactions::class);
    $this->inv = app(InventoryService::class);
});

it('menjalankan T1 sampai T19 dan menghasilkan laporan yang persis sama dengan dokumen skenario', function () {
    $b = $this->b;
    $tx = $this->tx;

    // Pembelian dan persediaan
    expect(entries($tx->depositCapital($b, '2026-11-01', 50_000_000)))->toBe(['Dr 1-1110' => 50_000_000, 'Cr 3-1000' => 50_000_000]); // T1
    expect(entries($tx->transferCash($b, '2026-11-02', 5_000_000, from: 'bank', to: 'kas')))->toBe(['Dr 1-1100' => 5_000_000, 'Cr 1-1110' => 5_000_000]); // T2
    expect(entries($tx->purchase($b, '2026-11-03', 'LCD13', 10, 1_000_000, 'bank')))->toBe(['Dr 1-1500' => 10_000_000, 'Cr 1-1110' => 10_000_000]); // T3
    expect(entries($tx->purchase($b, '2026-11-05', 'LCD13', 10, 1_100_000, 'kredit')))->toBe(['Dr 1-1500' => 11_000_000, 'Cr 2-1100' => 11_000_000]); // T4
    $lcd = $this->inv->balance($b, $this->lcd);
    expect([$lcd->qty, $lcd->value])->toBe([20, 21_000_000]);
    expect(entries($tx->purchase($b, '2026-11-06', 'BAT12', 5, 400_000, 'kas')))->toBe(['Dr 1-1500' => 2_000_000, 'Cr 1-1100' => 2_000_000]); // T5
    expect(entries($tx->returnToSupplier($b, '2026-11-07', 'BAT12', 1, 'kas')))->toBe(['Dr 1-1100' => 400_000, 'Cr 1-1500' => 400_000]); // T6
    $bat = $this->inv->balance($b, $this->bat);
    expect([$bat->qty, $bat->value])->toBe([4, 1_600_000]);
    expect(entries($tx->payPayable($b, '2026-11-08', 6_000_000, 'bank')))->toBe(['Dr 2-1100' => 6_000_000, 'Cr 1-1110' => 6_000_000]); // T7
    expect(-balanceOf('2-1100'))->toBe(5_000_000);

    // Servis
    expect(entries($tx->receiveDeposit($b, '2026-11-09', 200_000, 'kas', 'A')))->toBe(['Dr 1-1100' => 200_000, 'Cr 2-1400' => 200_000]); // T8
    expect(balanceOf('4-1000') + balanceOf('4-1100'))->toBe(0); // pendapatan masih 0
    expect(entries($tx->completeService($b, '2026-11-10', [['sku' => 'LCD13', 'qty' => 1, 'price' => 2_000_000]], 300_000, 200_000, 'kas', ticket: 'A')))
        ->toBe(['Dr 2-1400' => 200_000, 'Dr 1-1100' => 2_100_000, 'Cr 4-1100' => 2_000_000, 'Cr 4-1000' => 300_000, 'Dr 5-1000' => 1_050_000, 'Cr 1-1500' => 1_050_000]); // T9
    $lcd = $this->inv->balance($b, $this->lcd);
    expect([$lcd->qty, $lcd->value])->toBe([19, 19_950_000]);
    expect(balanceOf('2-1400'))->toBe(0);
    expect(entries($tx->receiveDeposit($b, '2026-11-11', 100_000, 'kas', 'B')))->toBe(['Dr 1-1100' => 100_000, 'Cr 2-1400' => 100_000]); // T10
    expect(entries($tx->cancelService($b, '2026-11-12', 100_000, 50_000, 'kas', 'B')))->toBe(['Dr 2-1400' => 100_000, 'Cr 4-1000' => 50_000, 'Cr 1-1100' => 50_000]); // T11

    // Kasir dan QRIS
    expect(entries($tx->completeService($b, '2026-11-13', [['sku' => 'LCD13', 'qty' => 1, 'price' => 2_000_000]], 300_000, 0, 'qris', mdrBps: 70, ticket: 'C')))
        ->toBe(['Dr 1-1110' => 2_283_900, 'Dr 8-1000' => 16_100, 'Cr 4-1100' => 2_000_000, 'Cr 4-1000' => 300_000, 'Dr 5-1000' => 1_050_000, 'Cr 1-1500' => 1_050_000]); // T12
    expect(entries($tx->warrantyClaim($b, '2026-11-15', 'LCD13', 1, 'A')))->toBe(['Dr 6-6000' => 1_050_000, 'Cr 1-1500' => 1_050_000]); // T13
    $lcd = $this->inv->balance($b, $this->lcd);
    expect([$lcd->qty, $lcd->value])->toBe([17, 17_850_000]);
    expect(entries($tx->purchase($b, '2026-11-16', 'CASE', 20, 50_000, 'kas')))->toBe(['Dr 1-1500' => 1_000_000, 'Cr 1-1100' => 1_000_000]); // T14a
    expect(entries($tx->posSale($b, '2026-11-16', [['sku' => 'CASE', 'qty' => 3, 'price' => 120_000]], 'kas')))
        ->toBe(['Dr 1-1100' => 360_000, 'Cr 4-1100' => 360_000, 'Dr 5-1000' => 150_000, 'Cr 1-1500' => 150_000]); // T14b
    $case = $this->inv->balance($b, $this->case);
    expect([$case->qty, $case->value])->toBe([17, 850_000]);

    // Biaya dan bank
    $tx->payExpense($b, '2026-11-20', '6-2000', 3_000_000, 'bank', 'Sewa November'); // T16
    expect(balanceOf('1-1110'))->toBe(28_283_900);
    $tx->payExpense($b, '2026-11-25', '6-1000', 4_000_000, 'bank', 'Gaji teknisi'); // T17
    expect(balanceOf('1-1110'))->toBe(24_283_900);
    $tx->payExpense($b, '2026-11-26', '6-2100', 500_000, 'kas', 'Listrik dan internet'); // T18
    expect(balanceOf('1-1100'))->toBe(4_610_000);
    $tx->payExpense($b, '2026-11-30', '8-1100', 15_000, 'bank', 'Biaya administrasi bank'); // T19
    expect(balanceOf('1-1110'))->toBe(24_268_900);

    // Laporan akhir
    $reports = app(ReportService::class);

    $is = $reports->incomeStatement(null, '2026-11-01', '2026-11-30');
    expect($is['revenue_rows'])->toBe(['4-1000' => 650_000, '4-1100' => 4_360_000]);
    expect($is['revenue'])->toBe(5_010_000);
    expect($is['cogs'])->toBe(2_250_000);
    expect($is['gross_profit'])->toBe(2_760_000);
    expect($is['expense_rows'])->toBe(['6-1000' => 4_000_000, '6-2000' => 3_000_000, '6-2100' => 500_000, '6-6000' => 1_050_000, '8-1000' => 16_100, '8-1100' => 15_000]);
    expect($is['net_income'])->toBe(-5_821_100);

    $bs = $reports->balanceSheet(null, '2026-11-30');
    expect($bs['asset_rows'])->toBe(['1-1100' => 4_610_000, '1-1110' => 24_268_900, '1-1500' => 20_300_000, '1-1600' => 0]);
    expect($bs['assets'])->toBe(49_178_900);
    expect($bs['liability_rows'])->toBe(['2-1100' => 5_000_000, '2-1400' => 0]);
    expect($bs['equity_rows'])->toBe(['3-1000' => 50_000_000]);
    expect($bs['current_earnings'])->toBe(-5_821_100);
    expect($bs['liabilities_and_equity'])->toBe(49_178_900)->and($bs['assets'])->toBe($bs['liabilities_and_equity']);

    $tb = $reports->trialBalance(null, '2026-11-30');
    expect($tb['total_debit'])->toBe(60_010_000)->and($tb['total_credit'])->toBe(60_010_000);

    // Persediaan di buku besar harus sama dengan nilai kartu stok.
    $stockValue = (int) DB::table('stock_balances')->sum('value');
    expect($stockValue)->toBe(20_300_000)->and(balanceOf('1-1500'))->toBe($stockValue);

    // Laporan operasional harus konsisten dengan buku besar dan dokumen skenario.
    $ops = app(App\Domain\Accounting\OpsReportService::class);
    $cf = $ops->cashFlow(null, '2026-11-01', '2026-11-30');
    $net = fn (array $r) => $r['inflow'] - $r['outflow'];
    $operasional = collect($cf['rows'])->sum($net) - 50_000_000; // di luar setoran modal
    expect($cf['opening'])->toBe(0)->and($cf['closing'])->toBe(28_878_900)
        ->and(collect($cf['rows'])->sum($net))->toBe(28_878_900)
        ->and($operasional)->toBe(-21_121_100);
    expect($cf['closing'])->toBe(balanceOf('1-1100') + balanceOf('1-1110'));

    expect($ops->payables(null, '2026-11-30'))->toBe(5_000_000);

    $card = $ops->stockCard($b, $this->lcd);
    expect(end($card))->toMatchArray(['balance_qty' => 17, 'balance_value' => 17_850_000]);

    $gl = $ops->generalLedger('1-1110', null, '2026-11-10', '2026-11-30');
    expect($gl['opening'])->toBe(50_000_000 - 5_000_000 - 10_000_000 - 6_000_000)
        ->and($gl['closing'])->toBe(24_268_900)->and(end($gl['rows'])['balance'])->toBe(24_268_900);
});
