<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\ReportService;
use App\Models\Branch;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Laporan extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.laporan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    public function getSubheading(): ?string
    {
        return 'Pilih periode, lalu pilih pertanyaan yang ingin Anda jawab.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    public ?int $branchId = null;

    public string $from = '';

    public string $to = '';

    public string $accountCode = '1-1100'; // kode internal akun Kas, tidak ditampilkan ke pengguna

    public ?int $productId = null;

    public string $report = 'untung'; // untung | uang | penjualan | kas | utang | admin

    public string $preset = 'bulan_ini'; // bulan_ini | hari_ini | bulan_lalu | tanggal

    public function setReport(string $report): void
    {
        $this->report = in_array($report, ['untung', 'uang', 'penjualan', 'kas', 'utang', 'masalah', 'bukti', 'admin'], true) ? $report : 'untung';
    }

    public function setPreset(string $preset): void
    {
        if (! in_array($preset, ['bulan_ini', 'hari_ini', 'bulan_lalu', 'tanggal'], true)) {
            return;
        }
        $this->preset = $preset;
        match ($preset) {
            'bulan_ini' => [$this->from, $this->to] = [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'hari_ini' => [$this->from, $this->to] = [now()->toDateString(), now()->toDateString()],
            'bulan_lalu' => [$this->from, $this->to] = [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => null,
        };
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('productId')->label('Pilih barang')->options(fn () => \App\Models\Product::orderBy('name')->pluck('name', 'id'))
                ->searchable()->live()->placeholder('Ketik nama barang, contoh: Casing iPhone')
                ->noSearchResultsMessage('Barang tidak ditemukan. Coba kata lain.')
                ->helperText('Riwayat barang masuk dan keluar. Kalau cabang "Semua cabang", dipakai cabang pertama.'),
        ]);
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
        $valid = fn (string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $dateWarning = null;
        $from = $this->from;
        $to = $this->to;
        if (! $valid($from) || ! $valid($to)) {
            // Tetap tampilkan laporan bulan ini supaya halaman tidak error, tapi beri tahu penggunanya.
            $dateWarning = 'Tanggal belum diisi atau tidak dikenali. Sementara laporan menampilkan bulan ini. Pilih tanggal yang benar.';
            $from = now()->startOfMonth()->toDateString();
            $to = now()->toDateString();
        } elseif ($from > $to) {
            $dateWarning = 'Tanggal awal tidak boleh setelah tanggal akhir.';
            [$from, $to] = [$to, $from];
        }
        $stockBranch = $branch ?: Branch::orderBy('id')->value('id');

        return [
            'report' => $this->report, 'preset' => $this->preset,
            'dateWarning' => $dateWarning, 'from' => $from, 'to' => $to, 'fromLabel' => date('d/m/Y', strtotime($from)), 'toLabel' => date('d/m/Y', strtotime($to)),
            'ops' => [
                'daily' => $ops->dailySales($branch, $from, $to),
                'technicians' => $ops->technicianReport($branch, $from, $to),
                'problems' => $ops->problemsReport($branch, $from, $to),
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
            'buktiYear' => (int) substr($to, 0, 4),
            'bukti' => $this->report === 'bukti' ? $reports->buktiPotong($branch, (int) substr($to, 0, 4)) : [],
        ];
    }
}
