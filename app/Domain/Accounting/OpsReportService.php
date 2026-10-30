<?php

namespace App\Domain\Accounting;

use Illuminate\Support\Facades\DB;

class OpsReportService
{
    /** Penjualan kasir per hari: jumlah struk, total, MDR, per metode bayar. */
    public function dailySales(?int $branchId, string $from, string $to): array
    {
        return DB::table('pos_sales')->whereNull('voided_at')
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

    /** Refund dan komplain: struk dibatalkan, servis batal (DP dikembalikan), dan klaim garansi menurut tanggal klaim. */
    public function problemsReport(?int $branchId, string $from, string $to): array
    {
        $voids = DB::table('pos_sales')->whereNotNull('voided_at')
            ->whereBetween(DB::raw('DATE(voided_at)'), [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('voided_at')->get(['receipt_no', 'sold_on', 'voided_at', 'total', 'void_reason'])
            ->map(fn ($r) => ['receipt_no' => $r->receipt_no, 'sold_on' => (string) $r->sold_on, 'voided_on' => substr((string) $r->voided_at, 0, 10), 'total' => (int) $r->total, 'reason' => $r->void_reason])->all();
        $receipts = (int) DB::table('pos_sales')->whereBetween('sold_on', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count();

        $tickets = fn (string $status) => DB::table('service_tickets')->where('status', $status)
            ->whereBetween('closed_on', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $cancels = $tickets('batal')->orderBy('closed_on')->get(['code', 'customer_name', 'device', 'closed_on', 'deposit', 'labor_fee'])
            ->map(fn ($r) => ['code' => $r->code, 'customer' => $r->customer_name, 'device' => $r->device, 'date' => (string) $r->closed_on, 'refund' => max((int) $r->deposit - (int) $r->labor_fee, 0)])->all();
        $warranty = DB::table('warranty_claims as w')->join('service_tickets as t', 't.id', '=', 'w.service_ticket_id')
            ->whereBetween('w.claimed_on', [$from, $to])->when($branchId, fn ($q) => $q->where('t.branch_id', $branchId))
            ->orderBy('w.claimed_on')->orderBy('w.id')->get(['t.code', 't.customer_name', 't.device', 'w.claimed_on', 'w.cost'])
            ->map(fn ($r) => ['code' => $r->code, 'customer' => $r->customer_name, 'device' => $r->device, 'date' => (string) $r->claimed_on, 'cost' => (int) $r->cost])->all();
        $done = (int) $tickets('selesai')->count();

        $complaints = DB::table('complaints')->whereBetween('received_on', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return [
            'complaints' => $complaints, 'complaint_count' => array_sum($complaints),
            'voids' => $voids, 'void_count' => count($voids), 'void_total' => array_sum(array_column($voids, 'total')), 'receipts' => $receipts,
            'cancels' => $cancels, 'cancel_total' => array_sum(array_column($cancels, 'refund')),
            'warranty' => $warranty, 'warranty_total' => array_sum(array_column($warranty, 'cost')), 'services_done' => $done,
        ];
    }

    /** Arus kas (metode langsung): kas + bank, saldo awal, masuk/keluar per sumber jurnal, saldo akhir. */
    public function cashFlow(?int $branchId, string $from, string $to): array
    {
        $cashIds = DB::table('accounts')->whereIn('code', \App\Domain\Accounting\MoneyAccounts::codes())->pluck('id');
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
            $rows[] = ['date' => $r->entry_date, 'journal_id' => $r->id, 'description' => $r->description, 'debit' => (int) $r->debit, 'credit' => (int) $r->credit, 'balance' => $balance];
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

    /**
     * Umur utang per pembelian kredit. Utang dicatat tanpa nomor faktur, jadi pembayaran dan retur
     * dialokasikan ke pembelian tertua lebih dulu (FIFO). Umur dihitung dari tanggal pembelian.
     */
    public function payablesAging(?int $branchId, string $asOf): array
    {
        $id = DB::table('accounts')->where('code', '2-1100')->value('id');
        $lines = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $id)->where('j.entry_date', '<=', $asOf)
            ->when($branchId, fn ($q) => $q->where('j.branch_id', $branchId))
            ->orderBy('j.entry_date')->orderBy('j.id')
            ->get(['j.id as journal_id', 'j.entry_date', 'j.description', 'l.debit', 'l.credit']);

        $paid = (int) $lines->sum('debit');
        $open = [];
        foreach ($lines as $l) {
            if ($l->credit <= 0) {
                continue;
            }
            $take = min($paid, (int) $l->credit);
            $paid -= $take;
            $left = (int) $l->credit - $take;
            if ($left > 0) {
                $open[] = ['journal_id' => $l->journal_id, 'date' => (string) $l->entry_date, 'description' => $l->description, 'open' => $left,
                    'age_days' => (int) \Carbon\CarbonImmutable::parse($l->entry_date)->diffInDays(\Carbon\CarbonImmutable::parse($asOf))];
            }
        }

        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0];
        foreach ($open as $r) {
            $key = $r['age_days'] <= 30 ? '0-30' : ($r['age_days'] <= 60 ? '31-60' : ($r['age_days'] <= 90 ? '61-90' : '>90'));
            $buckets[$key] += $r['open'];
        }

        return ['rows' => $open, 'buckets' => $buckets, 'total' => array_sum($buckets)];
    }
}
