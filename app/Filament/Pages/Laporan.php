<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\ReportService;
use App\Models\Branch;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Laporan extends Page
{
    protected string $view = 'filament.pages.laporan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    public function getSubheading(): ?string
    {
        return 'Angka di sini diambil langsung dari pembukuan. Pilih cabang dan tanggal, laporan ikut berubah.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    public ?int $branchId = null;

    public string $from = '';

    public string $to = '';

    public string $accountCode = '1-1100';

    public ?int $productId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    protected function getViewData(): array
    {
        $reports = app(ReportService::class);
        $branch = $this->branchId ?: null;
        $accounts = \App\Models\Account::pluck('name', 'code');

        $ops = app(\App\Domain\Accounting\OpsReportService::class);
        // Tanggal salah ketik atau kosong jatuh kembali ke awal bulan / hari ini, bukan error 500.
        $valid = fn (string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $from = $valid($this->from) ? $this->from : now()->startOfMonth()->toDateString();
        $to = $valid($this->to) ? $this->to : now()->toDateString();
        $stockBranch = $branch ?: Branch::orderBy('id')->value('id');

        return [
            'from' => $from, 'to' => $to, 'fromLabel' => date('d/m/Y', strtotime($from)), 'toLabel' => date('d/m/Y', strtotime($to)),
            'ops' => [
                'daily' => $ops->dailySales($branch, $from, $to),
                'technicians' => $ops->technicianReport($branch, $from, $to),
                'cashflow' => $ops->cashFlow($branch, $from, $to),
                'payables' => $ops->payables($branch, $to),
                'aging' => $ops->payablesAging($branch, $to),
                'ledger' => $ops->generalLedger($this->accountCode, $branch, $from, $to),
                'stockCard' => $this->productId ? $ops->stockCard($stockBranch, $this->productId) : [],
                'stockBranchName' => Branch::find($stockBranch)?->name,
            ],
            'products' => \App\Models\Product::orderBy('name')->pluck('name', 'id'),
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'accounts' => $accounts,
            'income' => $reports->incomeStatement($branch, $from, $to),
            'balance' => $reports->balanceSheet($branch, $to),
            'trial' => $reports->trialBalance($branch, $to),
        ];
    }
}
