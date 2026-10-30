<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\CashClosingService;
use App\Models\Branch;
use App\Models\CashClosing;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashClosings extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Tutup kas';

    protected static ?string $title = 'Tutup kas';

    protected static string|\UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 5;

    private const STATUS = ['closed' => 'Selesai', 'pending' => 'Menunggu persetujuan', 'rejected' => 'Ditolak'];

    public function getSubheading(): ?string
    {
        return 'Di akhir hari, hitung uang tunai di laci dan isi jumlahnya. Jumlah menurut catatan baru terlihat setelah Anda menyimpan, supaya hitungannya jujur.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-sales') ?? false;
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn();
        } catch (DomainException $e) {
            Notification::make()->title('Belum tersimpan')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($ok)->success()->send();
    }

    private static function rp(int $n): string
    {
        return ($n < 0 ? '-' : '').'Rp'.number_format(abs($n), 0, ',', '.');
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $canSee = $user->can('approve-adjustments');
        $canDecide = fn (CashClosing $r) => $r->status === 'pending' && $canSee && ($user->hasRole('owner') || $user->id !== (int) $r->created_by);

        return $table
            ->query(fn (): Builder => CashClosing::query()->with(['branch', 'creator'])
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
                ->latest('closing_date')->latest('id'))
            ->emptyStateHeading('Belum ada penutupan kas')
            ->emptyStateDescription('Ketuk "Tutup kas hari ini" setelah menghitung uang di laci.')
            ->columns([
                TextColumn::make('closing_date')->label('Tanggal')->date('d/m/Y'),
                TextColumn::make('branch.name')->label('Cabang'),
                TextColumn::make('counted')->label('Uang dihitung')->alignEnd()->state(fn (CashClosing $r) => self::rp((int) $r->counted)),
                TextColumn::make('expected')->label('Menurut catatan')->alignEnd()->visible($canSee)->state(fn (CashClosing $r) => self::rp((int) $r->expected)),
                TextColumn::make('diff')->label('Selisih')->alignEnd()->state(fn (CashClosing $r) => self::rp((int) $r->diff))
                    ->color(fn (CashClosing $r) => $r->diff === 0 ? 'success' : 'danger'),
                TextColumn::make('creator.name')->label('Ditutup oleh'),
                TextColumn::make('note')->label('Alasan')->limit(40)->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state) => self::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => ['closed' => 'success', 'pending' => 'warning', 'rejected' => 'danger'][$state] ?? 'gray'),
            ])
            ->headerActions([
                Action::make('tutup')->label('Tutup kas hari ini')->modalHeading('Tutup kas')->modalSubmitActionLabel('Simpan penutupan')
                    ->schema([
                        \App\Support\BranchField::make('Cabang yang ditutup'),
                        DatePicker::make('date')->label('Tanggal')->default(now())->maxDate(now())->required(),
                        TextInput::make('counted')->label('Uang tunai di laci (Rp)')->prefix('Rp')->numeric()->integer()->minValue(0)->inputMode('numeric')->required()->placeholder('Contoh: 1250000')
                            ->helperText('Hitung semua uang tunai di laci, termasuk uang kembalian yang disiapkan.'),
                        TextInput::make('note')->label('Alasan kalau ada selisih (boleh kosong kalau pas)')->maxLength(200)->placeholder('Contoh: kembalian salah'),
                    ])
                    ->action(function (array $data) {
                        try {
                            $c = app(CashClosingService::class)->close(auth()->user(), (int) $data['branch_id'], (string) $data['date'], (int) $data['counted'], $data['note'] ?? null);
                        } catch (DomainException $e) {
                            Notification::make()->title('Kas belum ditutup')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        $msg = $c->diff === 0 ? 'Uang di laci sama dengan catatan.' : 'Ada selisih '.self::rp((int) $c->diff).' (catatan '.self::rp((int) $c->expected).'). Menunggu persetujuan pemilik.';
                        Notification::make()->title('Kas ditutup')->body($msg)->color($c->diff === 0 ? 'success' : 'warning')->send();
                    }),
            ])
            ->recordActions([
                Action::make('setujui')->label('Setujui selisih')->color('success')->visible($canDecide)
                    ->requiresConfirmation()->modalHeading('Setujui selisih kas?')
                    ->modalDescription(fn (CashClosing $r) => 'Selisih '.self::rp((int) $r->diff).' dibukukan sebagai '.($r->diff < 0 ? 'kerugian' : 'kelebihan').' kas.')
                    ->modalSubmitActionLabel('Ya, bukukan')
                    ->action(fn (CashClosing $r) => self::run(fn () => app(CashClosingService::class)->approve($r, auth()->user(), now()->toDateString()), 'Selisih dibukukan')),
                Action::make('tolak')->label('Tolak')->color('danger')->visible($canDecide)
                    ->modalHeading('Tolak penutupan ini?')->modalDescription('Tidak ada yang dibukukan. Kasir menghitung ulang lalu menutup kas lagi.')->modalSubmitActionLabel('Tolak')
                    ->schema([TextInput::make('reason')->label('Alasan (boleh kosong)')->maxLength(100)])
                    ->action(fn (CashClosing $r, array $data) => self::run(fn () => app(CashClosingService::class)->reject($r, auth()->user(), $data['reason'] ?? null), 'Penutupan ditolak')),
            ]);
    }
}
