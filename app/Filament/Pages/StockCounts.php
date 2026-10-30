<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\StockCountService;
use App\Models\Branch;
use App\Models\StockCount;
use App\Models\StockCountLine;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockCounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Stok opname';

    protected static ?string $title = 'Stok opname';

    protected static string|\UnitEnum|null $navigationGroup = 'Barang';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    /** Hitungan yang sedang berjalan di cabang yang boleh dilihat pengguna (paling baru). */
    public function current(): ?StockCount
    {
        $user = auth()->user();

        return StockCount::with('branch')->where('status', 'draft')
            ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
            ->latest('id')->first();
    }

    public function getSubheading(): ?string
    {
        $c = $this->current();
        if (! $c) {
            return 'Hitung stok fisik di rak, isi hasilnya di sini, lalu pemilik menyetujui. Stok dan pembukuan baru berubah setelah disetujui.';
        }
        $total = $c->lines()->count();
        $done = $c->lines()->whereNotNull('counted_qty')->count();

        return "Hitungan {$c->branch->name} tanggal {$c->counted_on->format('d/m/Y')}: {$done} dari {$total} barang sudah diisi. Barang yang dikosongkan dilewati.";
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

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(function (): Builder {
                $c = $this->current();

                return StockCountLine::query()->with('product')->where('stock_count_id', $c?->id ?? 0)->orderBy('id');
            })
            ->emptyStateHeading('Tidak ada hitungan yang berjalan')
            ->emptyStateDescription('Ketuk "Mulai hitung stok" untuk membuat daftar barang yang akan dihitung.')
            ->paginated([25, 50, 100])
            ->filters([Filter::make('belum')->label('Belum diisi')->query(fn (Builder $q) => $q->whereNull('counted_qty'))])
            ->columns([
                TextColumn::make('product.sku')->label('Kode')->searchable(),
                TextColumn::make('product.name')->label('Barang')->searchable(),
                TextColumn::make('system_qty')->label('Menurut sistem')->alignEnd(),
                TextInputColumn::make('counted_qty')->label('Hasil hitung')->type('number')->inputMode('numeric')->rules(['nullable', 'integer', 'min:0'])
                    ->updateStateUsing(function (StockCountLine $record, $state) {
                        try {
                            app(StockCountService::class)->setCounted($record->load('stockCount'), ($state === null || $state === '') ? null : (int) $state);
                        } catch (DomainException $e) {
                            Notification::make()->title('Hasil hitung belum tersimpan')->body($e->getMessage())->danger()->send();

                            return $record->counted_qty;
                        }

                        return ($state === null || $state === '') ? null : (int) $state;
                    }),
                TextColumn::make('selisih')->label('Selisih')->alignEnd()
                    ->state(fn (StockCountLine $r) => $r->counted_qty === null ? '-' : sprintf('%+d', $r->counted_qty - $r->system_qty))
                    ->color(fn (StockCountLine $r) => $r->counted_qty === null || $r->counted_qty === $r->system_qty ? null : 'danger'),
            ])
            ->headerActions([
                Action::make('mulai')->label('Mulai hitung stok')->modalSubmitActionLabel('Mulai')
                    ->modalDescription('Daftar barang diambil dari stok yang tercatat saat ini. Hasil tiap barang dibandingkan dengan stok sistem saat Anda mengisinya, jadi isi hasilnya segera setelah barang itu dihitung.')
                    ->schema([
                        \App\Support\BranchField::make('Cabang yang dihitung'),
                        TextInput::make('note')->label('Catatan (boleh kosong)')->maxLength(200)->placeholder('Contoh: opname akhir bulan'),
                    ])
                    ->action(fn (array $data) => self::run(fn () => app(StockCountService::class)->start(auth()->user(), (int) $data['branch_id'], now()->toDateString(), null, $data['note'] ?? null), 'Hitungan dimulai')),
                Action::make('setujui')->label('Setujui hasil dan sesuaikan stok')->color('success')
                    ->visible(fn () => ($c = $this->current()) && StockCountService::canApprove(auth()->user(), (int) $c->created_by))
                    ->requiresConfirmation()->modalHeading('Sesuaikan stok dengan hasil hitung?')
                    ->modalDescription('Stok dan pembukuan berubah: barang kurang dicatat sebagai rugi, barang lebih sebagai untung. Tidak bisa dibatalkan, hanya dikoreksi dengan hitungan baru.')
                    ->modalSubmitActionLabel('Ya, sesuaikan')
                    ->action(fn () => self::run(fn () => app(StockCountService::class)->post($this->current() ?? throw new DomainException('Tidak ada hitungan yang berjalan.'), auth()->user(), now()->toDateString()), 'Stok disesuaikan')),
                Action::make('batal')->label('Batalkan hitungan')->color('gray')->visible(fn () => (bool) $this->current())
                    ->requiresConfirmation()->modalHeading('Batalkan hitungan ini?')->modalDescription('Hasil yang sudah diisi dibuang. Stok tidak berubah.')->modalSubmitActionLabel('Ya, batalkan')
                    ->action(fn () => self::run(fn () => app(StockCountService::class)->cancel($this->current() ?? throw new DomainException('Tidak ada hitungan yang berjalan.'), auth()->user()), 'Hitungan dibatalkan')),
            ]);
    }
}
