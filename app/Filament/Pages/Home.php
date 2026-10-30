<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\OpsReportService;
use App\Models\LeaveRequest;
use App\Models\PaymentRequest;
use App\Models\PayrollRun;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class Home extends Dashboard
{
    protected string $view = 'filament.pages.home';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Beranda';

    protected static ?string $title = 'Beranda';

    protected static ?int $navigationSort = -10;

    public function getHeading(): string
    {
        $h = (int) now()->format('G');
        $sapa = $h < 11 ? 'Selamat pagi' : ($h < 15 ? 'Selamat siang' : ($h < 18 ? 'Selamat sore' : 'Selamat malam'));

        return $sapa.', '.auth()->user()->name;
    }

    public function getSubheading(): ?string
    {
        return 'Ringkasan hari ini. Pakai menu untuk mulai bekerja.';
    }

    protected function getViewData(): array
    {
        $u = auth()->user();
        $today = now()->toDateString();
        $branch = $u->hasRole('owner') ? null : $u->branches()->value('branches.id');
        $ops = app(OpsReportService::class);
        $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
        $scope = fn ($q, $col = 'branch_id') => $branch ? $q->where($col, $branch) : $q;

        $kpis = [];
        if ($u->can('post-sales') || $u->can('view-reports')) {
            $sales = $scope(DB::table('pos_sales'))->whereNull('voided_at')->where('sold_on', $today);
            $kpis[] = ['label' => 'Penjualan hari ini', 'value' => $rp((clone $sales)->sum('total')), 'note' => (clone $sales)->count().' transaksi kasir', 'tone' => '#0E7A4F'];
        }
        if ($u->can('manage-services') || $u->can('view-reports')) {
            $open = $scope(DB::table('service_tickets'))->where('status', 'diterima')->count();
            $done = $scope(DB::table('service_tickets'))->where('status', 'selesai')->where('closed_on', $today)->count();
            $kpis[] = ['label' => 'Servis dikerjakan', 'value' => $open.' HP', 'note' => $done.' HP selesai hari ini', 'tone' => '#0B4FC4'];
        }
        $aging = null;
        if ($u->can('view-reports')) {
            $bal = fn ($code) => (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('a.code', $code)->when($branch, fn ($q) => $q->where('j.branch_id', $branch))->selectRaw('coalesce(sum(l.debit)-sum(l.credit),0) n')->value('n');
            $kas = $bal('1-1100');
            $bank = collect(\App\Domain\Accounting\MoneyAccounts::codes())->reject(fn ($c) => $c === '1-1100')->sum(fn ($c) => $bal($c));
            $kpis[] = ['label' => 'Uang di laci dan rekening', 'value' => $rp($kas + $bank), 'note' => 'Kas '.$rp($kas).', rekening bank '.$rp($bank), 'tone' => '#5B6472'];
            $aging = $ops->payablesAging($branch, $today);
            $oldest = collect($aging['rows'])->max('age_days');
            $kpis[] = ['label' => 'Utang ke pemasok', 'value' => $rp($aging['total']), 'note' => $oldest !== null ? 'Yang terlama '.$oldest.' hari' : 'Tidak ada utang', 'tone' => '#B45309'];
        }

        $tasks = [];
        $emp = \App\Models\Employee::where('user_id', $u->id)->where('is_active', true)->first();
        if ($emp && ! app(\App\Domain\Payroll\AttendanceLogService::class)->today($u)) {
            $tasks[] = ['tag' => 'Absen', 'bg' => '#E8F0FE', 'fg' => '#0B4FC4', 'title' => 'Anda belum absen masuk hari ini', 'sub' => 'Tekan tombol Absen masuk saat sudah di toko', 'cta' => 'Absen', 'url' => MyAttendance::getUrl()];
        }
        if ($u->can('approve-payroll')) {
            foreach (PayrollRun::where('status', 'draft')->get() as $r) {
                $tasks[] = ['tag' => 'Gaji', 'bg' => '#FEF3E2', 'fg' => '#B45309', 'title' => sprintf('Gaji bulan %02d/%04d menunggu persetujuan', $r->month, $r->year), 'sub' => 'Buka menu Gaji karyawan. Harus disetujui orang lain, bukan yang membuatnya', 'cta' => 'Tinjau', 'url' => Payroll::getUrl()];
            }
            $n = LeaveRequest::where('status', 'menunggu')->count();
            if ($n) {
                $tasks[] = ['tag' => 'Cuti', 'bg' => '#E8F0FE', 'fg' => '#0B4FC4', 'title' => $n.' pengajuan cuti belum diproses', 'sub' => 'Buka menu Persetujuan cuti', 'cta' => 'Setujui', 'url' => LeaveApprovals::getUrl()];
            }
        }
        if ($u->can('reconcile-bank')) {
            $n = DB::table('bank_statement_lines')->whereNull('journal_line_id')->when($branch, fn ($q) => $q->where('branch_id', $branch))->count();
            if ($n) {
                $tasks[] = ['tag' => 'Rekening', 'bg' => '#FDECEA', 'fg' => '#B42318', 'title' => $n.' transaksi rekening belum dicek', 'sub' => 'Buka menu Cocokkan rekening bank', 'cta' => 'Cocokkan', 'url' => BankReconciliation::getUrl()];
            }
        }
        if ($u->can('approve-payments')) {
            $n = PaymentRequest::where('status', 'pending')->count();
            if ($n) {
                $tasks[] = ['tag' => 'Utang', 'bg' => '#FEF3E2', 'fg' => '#B45309', 'title' => $n.' pembayaran utang ke pemasok menunggu persetujuan', 'sub' => 'Buka menu Pembayaran utang', 'cta' => 'Buka', 'url' => \App\Filament\Resources\PaymentRequests\PaymentRequestResource::getUrl()];
            }
        }

        if ($u->can('post-purchases')) {
            $overdue = $ops->payablesAging($branch, $today);
            if ($overdue['overdue'] > 0) {
                $tasks[] = ['tag' => 'Faktur', 'bg' => '#FDECEA', 'fg' => '#B42318', 'title' => 'Ada faktur pemasok yang lewat jatuh tempo: '.$rp($overdue['overdue']), 'sub' => 'Buka Laporan, pilih "Utang ke pemasok", lalu ajukan pembayaran', 'cta' => 'Lihat', 'url' => PurchaseInvoices::getUrl()];
            } elseif ($overdue['due_soon'] > 0) {
                $tasks[] = ['tag' => 'Faktur', 'bg' => '#FEF3E2', 'fg' => '#B45309', 'title' => 'Faktur senilai '.$rp($overdue['due_soon']).' jatuh tempo dalam 7 hari', 'sub' => 'Siapkan uangnya supaya tidak telat bayar', 'cta' => 'Lihat', 'url' => PurchaseInvoices::getUrl()];
            }
            $n = $scope(DB::table('supplier_claims'))->where('status', 'open')->count();
            if ($n) {
                $tasks[] = ['tag' => 'Klaim', 'bg' => '#FEF3E2', 'fg' => '#B45309', 'title' => $n.' klaim barang rusak menunggu jawaban pemasok', 'sub' => 'Catat hasilnya kalau pemasok sudah menjawab', 'cta' => 'Buka', 'url' => SupplierClaims::getUrl()];
            }
        }
        if ($u->can('post-sales')) {
            $closed = $scope(DB::table('cash_closings'), 'branch_id')->where('closing_date', $today)->whereIn('status', ['closed', 'pending'])->exists();
            $soldToday = $scope(DB::table('pos_sales'))->whereNull('voided_at')->where('sold_on', $today)->exists();
            if ($soldToday && ! $closed && (int) now()->format('G') >= 17) {
                $tasks[] = ['tag' => 'Kas', 'bg' => '#E8F0FE', 'fg' => '#0B4FC4', 'title' => 'Kas hari ini belum ditutup', 'sub' => 'Hitung uang tunai di laci, lalu isi jumlahnya', 'cta' => 'Tutup kas', 'url' => CashClosings::getUrl()];
            }
        }
        if ($u->can('approve-adjustments')) {
            $n = DB::table('cash_closings')->where('status', 'pending')->count();
            if ($n) {
                $tasks[] = ['tag' => 'Selisih', 'bg' => '#FDECEA', 'fg' => '#B42318', 'title' => $n.' selisih kas menunggu persetujuan', 'sub' => 'Lihat alasannya, lalu setujui atau tolak', 'cta' => 'Tinjau', 'url' => CashClosings::getUrl()];
            }
            $n = DB::table('stock_counts')->where('status', 'draft')->whereExists(fn ($q) => $q->from('stock_count_lines')->whereColumn('stock_count_lines.stock_count_id', 'stock_counts.id')->whereNotNull('counted_qty'))->count();
            if ($n) {
                $tasks[] = ['tag' => 'Stok', 'bg' => '#FEF3E2', 'fg' => '#B45309', 'title' => $n.' hitungan stok sudah diisi dan menunggu persetujuan', 'sub' => 'Setujui supaya stok di sistem mengikuti hasil hitungan', 'cta' => 'Tinjau', 'url' => StockCounts::getUrl()];
            }
        }

        $agingView = null;
        if ($aging) {
            $max = max(array_sum($aging['buckets']), 1);
            $labels = ['0-30' => '0-30 hari', '31-60' => '31-60 hari', '61-90' => '61-90 hari', '>90' => 'Lebih dari 90 hari'];
            $colors = ['0-30' => '#0B63F6', '31-60' => '#B45309', '61-90' => '#B45309', '>90' => '#B42318'];
            $agingView = collect($aging['buckets'])->map(fn ($v, $k) => ['label' => $labels[$k], 'value' => $rp($v), 'pct' => round($v / $max * 100).'%', 'color' => $colors[$k]])->values()->all();
        }

        $quick = [];
        if ($u->can('post-sales')) {
            $quick[] = ['label' => 'Buka Kasir', 'url' => Pos::getUrl()];
        }
        if ($u->can('manage-services')) {
            $quick[] = ['label' => 'Terima HP baru', 'url' => ServiceTickets::getUrl()];
        }

        $setup = [];
        if ($u->hasRole('owner')) {
            $setup = [
                ['done' => \App\Models\Branch::exists(), 'title' => 'Isi data cabang (toko)', 'sub' => 'Menu Data master, Cabang', 'url' => \App\Filament\Resources\Branches\BranchResource::getUrl()],
                ['done' => \App\Models\Product::exists(), 'title' => 'Isi daftar barang dan harga jual', 'sub' => 'Menu Data master, Produk', 'url' => \App\Filament\Resources\Products\ProductResource::getUrl()],
                ['done' => \App\Models\Supplier::exists(), 'title' => 'Isi daftar pemasok (tempat Anda membeli barang)', 'sub' => 'Menu Barang, Pemasok', 'url' => Suppliers::getUrl()],
                ['done' => DB::table('journals')->exists(), 'title' => 'Catat uang awal toko (modal)', 'sub' => 'Menu Keuangan, Modal, pinjaman, dan harta', 'url' => RecordFinance::getUrl()],
                ['done' => \App\Models\Employee::exists(), 'title' => 'Isi data karyawan', 'sub' => 'Menu Karyawan, Karyawan dan absensi', 'url' => Employees::getUrl()],
            ];
            if (collect($setup)->every(fn ($x) => $x['done'])) {
                $setup = [];
            }
        }

        return ['kpis' => $kpis, 'tasks' => $tasks, 'aging' => $agingView, 'quick' => $u->can('view-reports') ? [] : $quick, 'noKpi' => $kpis === [], 'setup' => $setup];
    }
}
