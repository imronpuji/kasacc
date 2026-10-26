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

    protected static ?string $title = 'Laporan Keuangan';

    protected static ?int $navigationSort = 10;

    public ?int $branchId = null;

    public string $from = '';

    public string $to = '';

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

        return [
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'accounts' => $accounts,
            'income' => $reports->incomeStatement($branch, $this->from ?: null, $this->to ?: null),
            'balance' => $reports->balanceSheet($branch, $this->to ?: null),
            'trial' => $reports->trialBalance($branch, $this->to ?: null),
        ];
    }
}
