<?php

namespace App\Filament\Pages;

use App\Domain\Service\ServiceTicketService;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ServiceTicket;
use App\Models\StockBalance;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
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

class Servis extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Servis';

    protected static ?string $title = 'Servis iPhone';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-services') ?? false;
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    /** Pilihan sparepart di cabang tiket: hanya yang stoknya ada, dengan sisa stok di labelnya. */
    private static function stockOptions(int $branchId): array
    {
        return StockBalance::with('product')->where('branch_id', $branchId)->where('qty', '>', 0)->get()
            ->mapWithKeys(fn ($b) => [$b->product->sku => "{$b->product->name} ({$b->product->sku}) — stok {$b->qty}"])->all();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(fn (): Builder => ServiceTicket::query()
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
                ->latest('id'))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable(),
                TextColumn::make('customer_name')->label('Pelanggan')->searchable(),
                TextColumn::make('device')->label('Perangkat'),
                TextColumn::make('technician.name')->label('Teknisi'),
                TextColumn::make('status')->badge(),
                TextColumn::make('deposit')->label('DP')->money('IDR', locale: 'id'),
                TextColumn::make('total')->money('IDR', locale: 'id'),
            ])
            ->headerActions([
                Action::make('terima')->label('Terima unit')
                    ->schema([
                        Select::make('branch_id')->label('Cabang')->required()
                            ->options(fn () => $user->hasRole('owner') ? Branch::pluck('name', 'id') : $user->branches()->pluck('branches.name', 'branches.id')),
                        TextInput::make('customer_name')->label('Nama pelanggan')->required(),
                        TextInput::make('customer_phone')->label('Telepon'),
                        TextInput::make('device')->label('Perangkat')->required(),
                        TextInput::make('complaint')->label('Keluhan'),
                        Select::make('technician_id')->label('Teknisi')->options(User::role('technician')->pluck('name', 'id')),
                        TextInput::make('deposit')->label('DP (Rp)')->numeric()->minValue(0)->default(0),
                        Select::make('method')->label('DP lewat')->options(['kas' => 'Tunai', 'bank' => 'Transfer'])->default('kas'),
                    ])
                    ->action(fn (array $data) => self::run(fn () => app(ServiceTicketService::class)->receive(
                        auth()->user(), (int) $data['branch_id'], now()->toDateString(), $data, (int) ($data['deposit'] ?? 0), $data['method'],
                    ), 'Unit diterima')),
            ])
            ->recordActions([
                Action::make('selesai')->label('Selesaikan')->visible(fn (ServiceTicket $r) => $r->status === 'diterima')
                    ->schema([
                        Repeater::make('parts')->label('Sparepart')->defaultItems(0)->schema([
                            Select::make('sku')->label('Sparepart')->required()->searchable()->live()
                                ->options(fn (ServiceTicket $record) => self::stockOptions($record->branch_id))
                                ->afterStateUpdated(fn ($state, $set) => $set('price', (int) Product::where('sku', $state)->value('sale_price') ?: null)),
                            TextInput::make('qty')->numeric()->minValue(1)->default(1)->required(),
                            TextInput::make('price')->label('Harga jual')->numeric()->minValue(1)->required(),
                        ])->columns(3),
                        TextInput::make('labor')->label('Biaya jasa')->numeric()->minValue(0)->default(0)->required(),
                        Select::make('method')->label('Bayar')->options(['kas' => 'Tunai', 'bank' => 'Transfer', 'qris' => 'QRIS'])->default('kas')->required(),
                    ])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(fn () => app(ServiceTicketService::class)->complete(
                        $r, auth()->user(), now()->toDateString(),
                        array_map(fn ($p) => ['sku' => $p['sku'], 'qty' => (int) $p['qty'], 'price' => (int) $p['price']], $data['parts'] ?? []),
                        (int) $data['labor'], $data['method'],
                    ), 'Servis selesai')),
                Action::make('batal')->label('Batalkan')->color('danger')->visible(fn (ServiceTicket $r) => $r->status === 'diterima')
                    ->schema([TextInput::make('fee')->label('Biaya cek (Rp)')->numeric()->minValue(0)->default(0)->required()])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(fn () => app(ServiceTicketService::class)->cancel(
                        $r, auth()->user(), now()->toDateString(), (int) $data['fee'],
                    ), 'Servis dibatalkan')),
                Action::make('garansi')->label('Klaim garansi')->visible(fn (ServiceTicket $r) => $r->status === 'selesai')
                    ->schema([Select::make('sku')->label('Sparepart pengganti')->required()->searchable()->options(fn (ServiceTicket $record) => self::stockOptions($record->branch_id)), TextInput::make('qty')->numeric()->minValue(1)->default(1)->required()])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(fn () => app(ServiceTicketService::class)->claimWarranty(
                        $r, auth()->user(), now()->toDateString(), $data['sku'], (int) $data['qty'],
                    ), 'Klaim garansi dicatat')),
            ]);
    }
}
