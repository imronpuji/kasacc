<?php

namespace App\Domain\Accounting;

use Illuminate\Support\Facades\DB;

class OpsReportService
{
    /** Penjualan kasir per hari: jumlah struk, total, MDR, per metode bayar. */
    public function dailySales(?int $branchId, string $from, string $to): array
    {
        return DB::table('pos_sales')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('sold_on', [$from, $to])
            ->groupBy('sold_on', 'payment_method')->orderBy('sold_on')
            ->selectRaw('sold_on as date, payment_method as method, COUNT(*) as receipts, SUM(total) as total, SUM(mdr) as mdr')
            ->get()->map(fn ($r) => ['date' => $r->date, 'method' => $r->method, 'receipts' => (int) $r->receipts, 'total' => (int) $r->total, 'mdr' => (int) $r->mdr])->all();
    }

    /** Servis selesai per teknisi: jumlah, jasa, sparepart, total. */
    public function technicianReport(?int $branchId, string $from, string $to): array
    {
        return DB::table('service_tickets as t')->leftJoin('users as u', 'u.id', '=', 't.technician_id')
            ->when($branchId, fn ($q) => $q->where('t.branch_id', $branchId))
            ->where('t.status', 'selesai')->whereBetween('t.closed_on', [$from, $to])
            ->groupBy('u.name')->orderBy('u.name')
            ->selectRaw("COALESCE(u.name, '(belum ditugaskan)') as technician, COUNT(*) as tickets, SUM(t.labor_fee) as labor, SUM(t.parts_total) as parts, SUM(t.total) as total")
            ->get()->map(fn ($r) => ['technician' => $r->technician, 'tickets' => (int) $r->tickets, 'labor' => (int) $r->labor, 'parts' => (int) $r->parts, 'total' => (int) $r->total])->all();
    }

    /** Arus kas (metode langsung): kas + bank, saldo awal, masuk/keluar per sumber jurnal, saldo akhir. */
    public function cashFlow(?int $branchId, string $from, string $to): array
    {
        $cashIds = DB::table('accounts')->whereIn('code', ['1-1100', '1-1110'])->pluck('id');
        $base = fn () => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->whereIn('l.account_id', $cashIds)->when($branchId, fn ($q) => $q->where('j.branch_id', $branchId));

        $opening = (int) $base()->where('j.entry_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as n')->value('n');
        $rows = $base()->whereBetween('j.entry_date', [$from, $to])->groupBy('j.source')->orderBy('j.source')
            ->selectRaw('j.source, SUM(l.debit) as inflow, SUM(l.credit) as outflow')->get()
            ->map(fn ($r) => ['source' => $r->source, 'inflow' => (int) $r->inflow, 'outflow' => (int) $r->outflow])->all();
        $in = array_sum(array_column($rows, 'inflow'));
        $out = array_sum(array_column($rows, 'outflow'));

        return ['opening' => $opening, 'rows' => $rows, 'inflow' => $in, 'outflow' => $out, 'closing' => $opening + $in - $out];
    }

    /** Kartu stok satu barang di satu cabang dengan saldo berjalan (qty dan nilai). */
    public function stockCard(int $branchId, int $productId): array
    {
        $qty = $value = 0;
        $rows = [];
        foreach (DB::table('stock_movements')->where('branch_id', $branchId)->where('product_id', $productId)->orderBy('movement_date')->orderBy('id')->get() as $m) {
            $sign = $m->direction === 'in' ? 1 : -1;
            $qty += $sign * $m->qty;
            $value += $sign * $m->total_cost;
            $rows[] = ['date' => $m->movement_date, 'reason' => $m->reason, 'direction' => $m->direction, 'qty' => (int) $m->qty, 'cost' => (int) $m->total_cost, 'balance_qty' => $qty, 'balance_value' => $value];
        }

        return $rows;
    }

    /** Buku besar satu akun dengan saldo berjalan. */
    public function generalLedger(string $accountCode, ?int $branchId, string $from, string $to): array
    {
        $accountId = DB::table('accounts')->where('code', $accountCode)->value('id');
        $q = fn () => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $accountId)->when($branchId, fn ($w) => $w->where('j.branch_id', $branchId));

        $balance = (int) $q()->where('j.entry_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as n')->value('n');
        $opening = $balance;
        $rows = [];
        foreach ($q()->whereBetween('j.entry_date', [$from, $to])->orderBy('j.entry_date')->orderBy('j.id')->get(['j.entry_date', 'j.id', 'j.description', 'l.debit', 'l.credit']) as $r) {
            $balance += $r->debit - $r->credit;
            $rows[] = ['date' => $r->entry_date, 'journal_id' => $r->id, 'memo' => $r->description, 'debit' => (int) $r->debit, 'credit' => (int) $r->credit, 'balance' => $balance];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $balance];
    }

    /** Umur utang usaha (saldo 2-1100 yang belum lunas) per cabang — total saja, karena utang dicatat tanpa faktur. */
    public function payables(?int $branchId, string $to): int
    {
        $id = DB::table('accounts')->where('code', '2-1100')->value('id');

        return -(int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $id)->where('j.entry_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('j.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as n')->value('n');
    }
}
