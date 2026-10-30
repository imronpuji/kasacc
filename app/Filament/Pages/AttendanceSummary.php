<?php

namespace App\Filament\Pages;

use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

class AttendanceSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Rekap absen';

    protected static ?string $title = 'Rekap absen harian';

    protected static string|\UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 2;

    public function getSubheading(): ?string
    {
        return 'Daftar absen masuk dan pulang yang dilakukan karyawan sendiri lewat HP. Dipakai HR untuk memeriksa sebelum mengisi absen bulanan di menu Karyawan dan absensi.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-hr') ?? false;
    }

    public function table(Table $table): Table
    {
        $tz = config('kasir.timezone');

        return $table
            ->query(fn () => AttendanceLog::query()->with('employee.branch')->latest('work_date')->latest('id'))
            ->emptyStateHeading('Belum ada absen')
            ->emptyStateDescription('Karyawan yang sudah punya akun login bisa absen lewat menu Absen saya.')
            ->columns([
                TextColumn::make('work_date')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('employee.name')->label('Nama')->searchable(),
                TextColumn::make('employee.branch.name')->label('Cabang'),
                TextColumn::make('check_in_at')->label('Masuk')->formatStateUsing(fn ($state) => $state->setTimezone($tz)->format('H:i')),
                TextColumn::make('check_out_at')->label('Pulang')->formatStateUsing(fn ($state) => $state ? $state->setTimezone($tz)->format('H:i') : '-'),
                TextColumn::make('keterangan')->label('Keterangan')->badge()
                    ->state(fn ($record) => $record->late_minutes ? 'Terlambat '.$record->late_minutes.' menit' : 'Tepat waktu')
                    ->color(fn ($record) => $record->late_minutes ? 'warning' : 'success'),
                TextColumn::make('in_distance_m')->label('Jarak dari toko (meter)')->suffix(' m')->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('employee_id')->label('Karyawan')->placeholder('Semua karyawan')->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('branch')->label('Cabang')->placeholder('Semua cabang')->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $v)))),
                Filter::make('rentang')->label('Rentang tanggal')->schema([
                    DatePicker::make('from')->label('Dari tanggal')->placeholder('Pilih tanggal'),
                    DatePicker::make('to')->label('Sampai tanggal')->placeholder('Pilih tanggal'),
                ])->query(fn (Builder $q, array $data) => $q
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('work_date', '>=', $d))
                    ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('work_date', '<=', $d))),
            ]);
    }
}
