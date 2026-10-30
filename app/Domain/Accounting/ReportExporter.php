<?php

namespace App\Domain\Accounting;

use App\Models\Account;

/** Menyusun laporan jadi tabel datar (judul + baris) supaya bisa diekspor ke Excel atau PDF dengan isi yang sama dengan layar. */
class ReportExporter
{
    public const REPORTS = [
        'untung' => 'Untung atau rugi', 'uang' => 'Posisi uang, barang, dan utang', 'penjualan' => 'Penjualan dan servis',
        'kas' => 'Uang masuk dan keluar', 'utang' => 'Utang ke pemasok', 'masalah' => 'Refund dan komplain',
    ];

    public function __construct(private ReportService $reports, private OpsReportService $ops)
    {
    }

    /** @return array{title:string, period:string, rows:array<int,array<int,string|int>>, money_columns:array<int,int>} */
    public function build(string $report, ?int $branchId, string $from, string $to): array
    {
        $names = Account::pluck('name', 'code');
        $label = fn (string $code) => $code.' '.($names[$code] ?? '');
        $rows = [];
        $money = [];

        switch ($report) {
            case 'untung':
                $r = $this->reports->incomeStatement($branchId, $from, $to);
                $rows[] = ['Pos', 'Jumlah (Rp)'];
                $rows[] = ['PENDAPATAN', ''];
                foreach ($r['revenue_rows'] as $code => $v) {
                    $rows[] = [$label($code), $v];
                }
                $rows[] = ['Total pendapatan', $r['revenue']];
                $rows[] = ['Modal barang terjual', $r['cogs']];
                $rows[] = ['Untung kotor', $r['gross_profit']];
                $rows[] = ['BIAYA', ''];
                foreach ($r['expense_rows'] as $code => $v) {
                    $rows[] = [$label($code), $v];
                }
                $rows[] = ['Total biaya', $r['expenses']];
                $rows[] = ['Untung bersih', $r['net_income']];
                $money = [1];
                break;
            case 'uang':
                $r = $this->reports->balanceSheet($branchId, $to);
                $rows[] = ['Pos', 'Jumlah (Rp)'];
                foreach ([['HARTA', 'asset_rows', 'Total harta', 'assets'], ['UTANG', 'liability_rows', 'Total utang', 'liabilities'], ['MODAL', 'equity_rows', 'Total modal', 'equity']] as [$head, $key, $totalLabel, $totalKey]) {
                    $rows[] = [$head, ''];
                    foreach ($r[$key] as $code => $v) {
                        $rows[] = [$label($code), $v];
                    }
                    $rows[] = [$totalLabel, $r[$totalKey]];
                }
                $rows[] = ['Untung berjalan', $r['current_earnings']];
                $rows[] = ['Total utang + modal', $r['liabilities_and_equity']];
                $money = [1];
                break;
            case 'penjualan':
                $rows[] = ['Tanggal', 'Cara bayar', 'Jumlah struk', 'Total (Rp)', 'Biaya kartu/QRIS (Rp)'];
                foreach ($this->ops->dailySales($branchId, $from, $to) as $d) {
                    $rows[] = [$d['date'], $d['method'], $d['receipts'], $d['total'], $d['mdr']];
                }
                $rows[] = ['', '', '', '', ''];
                $rows[] = ['Teknisi', '', 'Servis selesai', 'Jasa (Rp)', 'Sparepart (Rp)'];
                foreach ($this->ops->technicianReport($branchId, $from, $to) as $t) {
                    $rows[] = [$t['technician'], '', $t['tickets'], $t['labor'], $t['parts']];
                }
                $money = [3, 4];
                break;
            case 'kas':
                $c = $this->ops->cashFlow($branchId, $from, $to);
                $rows[] = ['Sumber', 'Masuk (Rp)', 'Keluar (Rp)'];
                $rows[] = ['Saldo awal', $c['opening'], ''];
                foreach ($c['rows'] as $x) {
                    $rows[] = [$x['source'], $x['inflow'], $x['outflow']];
                }
                $rows[] = ['Total', $c['inflow'], $c['outflow']];
                $rows[] = ['Saldo akhir', $c['closing'], ''];
                $money = [1, 2];
                break;
            case 'utang':
                $a = $this->ops->payablesAging($branchId, $to);
                $rows[] = ['Tanggal beli', 'Faktur / keterangan', 'Jatuh tempo', 'Umur (hari)', 'Belum dibayar (Rp)'];
                foreach ($a['rows'] as $x) {
                    $rows[] = [$x['date'], $x['invoice_no'] ? 'Faktur '.$x['invoice_no'].($x['supplier'] ? ' - '.$x['supplier'] : '') : $x['description'], $x['due_date'] ?? '-', $x['age_days'], $x['open']];
                }
                $rows[] = ['Total', '', '', '', $a['total']];
                $rows[] = ['Lewat jatuh tempo', '', '', '', $a['overdue']];
                $money = [4];
                break;
            case 'masalah':
                $p = $this->ops->problemsReport($branchId, $from, $to);
                $rows[] = ['Jenis', 'Nomor', 'Tanggal', 'Keterangan', 'Jumlah (Rp)'];
                foreach ($p['voids'] as $v) {
                    $rows[] = ['Struk dibatalkan', $v['receipt_no'], $v['voided_on'], $v['reason'] ?? '', $v['total']];
                }
                foreach ($p['cancels'] as $v) {
                    $rows[] = ['Servis batal', $v['code'], $v['date'], $v['customer'].' - '.$v['device'], $v['refund']];
                }
                foreach ($p['warranty'] as $v) {
                    $rows[] = ['Klaim garansi', $v['code'], $v['date'], $v['customer'].' - '.$v['device'], $v['cost']];
                }
                $rows[] = ['Retur sebagian', '', '', $p['partial_returns'].' retur', $p['partial_return_total']];
                $money = [4];
                break;
            default:
                abort(404);
        }

        return ['title' => self::REPORTS[$report], 'period' => $report === 'uang' || $report === 'utang' ? 'Per '.date('d/m/Y', strtotime($to)) : date('d/m/Y', strtotime($from)).' sampai '.date('d/m/Y', strtotime($to)), 'rows' => $rows, 'money_columns' => $money];
    }
}
