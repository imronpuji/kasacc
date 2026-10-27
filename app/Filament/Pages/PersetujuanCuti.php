<?php

namespace App\Filament\Pages;

use App\Domain\Payroll\HrService;
use App\Models\LeaveRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class PersetujuanCuti extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Persetujuan cuti';

    protected static ?string $title = 'Persetujuan Cuti';

    protected static string|\UnitEnum|null $navigationGroup = 'SDM';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('approve-payroll') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => LeaveRequest::query()->with('employee')->latest())
            ->columns([
                TextColumn::make('employee.name')->label('Karyawan'),
                TextColumn::make('start_date')->label('Mulai')->date('d M Y'),
                TextColumn::make('days')->label('Hari'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('setujui')->label('Setujui')->visible(fn ($record) => $record->status === 'menunggu')->action(function ($record) {
                    try {
                        app(HrService::class)->approveLeave(auth()->user(), $record);
                        Notification::make()->title('Cuti disetujui')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    }
                }),
            ]);
    }
}
