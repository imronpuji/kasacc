<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\MoneyAccounts;
use App\Domain\Accounting\OpsReportService;
use App\Domain\Purchasing\PurchaseInvoiceService;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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

class PurchaseInvoices extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Faktur pembelian';

    protected static ?string $title = 'Faktur pembelian';

    protected static string|\UnitEnum|null $navigationGroup = 'Barang';

    protected static ?int $navigationSort = 2;

    public function getSubheading(): ?string
    {
        return 'Catat faktur dari pemasok: beberapa barang sekaligus, lengkap dengan nomor faktur dan tanggal jatuh tempo. Stok dan utang ikut tercatat.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    /** Sisa utang tiap faktur, dari laporan umur utang (menurut buku besar). */
    private function leftMap(): array
    {
        $user = auth()->user();
        $branches = $user->hasRole('owner') ? [null] : $user->branches()->pluck('branches.id')->all();
        $map = [];
        foreach ($branches as $b) {
            foreach (app(OpsReportService::class)->payablesAging($b, now()->addYears(10)->toDateString())['rows'] as $r) {
                $map[$r['journal_id']] = $r['open'];
            }
        }

        return $map;
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $left = $this->leftMap();

        return $table
            ->query(fn (): Builder => PurchaseInvoice::query()->with(['supplier', 'branch'])
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
                ->orderByDesc('invoice_date')->orderByDesc('id'))
            ->emptyStateHeading('Belum ada faktur')
            ->emptyStateDescription('Ketuk "Catat faktur" untuk mencatat faktur pembelian pertama.')
            ->columns([
                TextColumn::make('invoice_no')->label('No. faktur')->searchable(),
                TextColumn::make('supplier.name')->label('Pemasok')->searchable(),
                TextColumn::make('invoice_date')->label('Tanggal')->date('d/m/Y'),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d/m/Y')->placeholder('Lunas')
                    ->color(fn (PurchaseInvoice $r) => $r->due_date && ($left[$r->journal_id] ?? 0) > 0 && $r->due_date->isPast() ? 'danger' : null),
                TextColumn::make('total')->label('Total')->alignEnd()->formatStateUsing(fn ($state) => 'Rp'.number_format((int) $state, 0, ',', '.')),
                TextColumn::make('left')->label('Sisa utang')->alignEnd()
                    ->state(fn (PurchaseInvoice $r) => 'Rp'.number_format($left[$r->journal_id] ?? 0, 0, ',', '.')),
            ])
            ->headerActions([
                Action::make('record')->label('Catat faktur')->modalSubmitActionLabel('Simpan faktur')->modalWidth('4xl')
                    ->schema([
                        \App\Support\BranchField::make(),
                        Select::make('supplier_id')->label('Pemasok')->required()->searchable()->placeholder('Pilih pemasok')
                            ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                        TextInput::make('invoice_no')->label('Nomor faktur')->required()->maxLength(60),
                        DatePicker::make('date')->label('Tanggal faktur')->required()->default(now())->live(),
                        Select::make('method')->label('Cara bayar')->required()->live()->default('kredit')
                            ->options(fn () => MoneyAccounts::options(['kredit' => 'Belum dibayar (jadi utang)'])),
                        DatePicker::make('due_date')->label('Jatuh tempo')->default(now()->addDays(30))
                            ->visible(fn ($get) => $get('method') === 'kredit')->required(fn ($get) => $get('method') === 'kredit'),
                        Repeater::make('lines')->label('Barang di faktur')->addActionLabel('Tambah barang')->minItems(1)->defaultItems(1)->required()
                            ->schema([
                                Select::make('product_id')->label('Barang')->required()->searchable()
                                    ->options(fn () => Product::orderBy('name')->pluck('name', 'id')),
                                TextInput::make('qty')->label('Jumlah')->numeric()->integer()->minValue(1)->required()->inputMode('numeric'),
                                TextInput::make('unit_cost')->label('Harga beli per buah (Rp)')->prefix('Rp')->numeric()->integer()->minValue(1)->required()->inputMode('numeric'),
                            ])->columns(3),
                        TextInput::make('note')->label('Catatan (boleh kosong)')->maxLength(200),
                    ])
                    ->action(function (array $data) {
                        try {
                            $invoice = app(PurchaseInvoiceService::class)->record(
                                auth()->user(), (int) $data['branch_id'], (int) $data['supplier_id'], $data['invoice_no'], $data['date'],
                                $data['method'] === 'kredit' ? ($data['due_date'] ?? null) : null, $data['method'], $data['lines'], $data['note'] ?? null,
                            );
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Faktur '.$invoice->invoice_no.' disimpan')->success()->send();
                    }),
            ]);
    }
}
