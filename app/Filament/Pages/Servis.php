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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    private static function run(callable $fn, string $title, callable $body): void
    {
        try {
            $result = $fn();
            Notification::make()->title($title)->body($body($result))->success()->send();
        } catch (\DomainException|\InvalidArgumentException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (ModelNotFoundException $e) {
            $msg = str_starts_with($e->getMessage(), 'Kode barang') ? $e->getMessage() : 'Data tidak ditemukan. Muat ulang halaman lalu coba lagi.';
            Notification::make()->title('Belum berhasil')->body($msg)->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Terjadi kesalahan. Coba lagi, kalau masih gagal hubungi pemilik toko.')->danger()->send();
        }
    }

    /** Pilihan sparepart di cabang tiket: hanya yang stoknya ada, dengan sisa stok di labelnya. */
    private static function stockOptions(int $branchId): array
    {
        return StockBalance::with('product')->where('branch_id', $branchId)->where('qty', '>', 0)->get()
            ->mapWithKeys(fn ($b) => [$b->product->sku => "{$b->product->name} ({$b->product->sku}) - sisa {$b->qty}"])->all();
    }

    private static function rp(int|float|null $n): string
    {
        return 'Rp '.number_format((int) $n, 0, ',', '.');
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $branchOptions = fn () => $user->hasRole('owner') ? Branch::orderBy('name')->pluck('name', 'id') : $user->branches()->orderBy('name')->pluck('branches.name', 'branches.id');
        $statusLabel = ['diterima' => 'Sedang dikerjakan', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan'];

        return $table
            ->query(fn (): Builder => ServiceTicket::query()
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
                ->latest('id'))
            ->emptyStateHeading('Belum ada servis')
            ->emptyStateDescription('Ketuk "Terima HP baru" untuk mencatat HP pelanggan yang masuk.')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable(),
                TextColumn::make('received_on')->label('Tanggal masuk')->date('d/m/Y')->sortable(),
                TextColumn::make('customer_name')->label('Pelanggan')->searchable(),
                TextColumn::make('device')->label('Tipe HP'),
                TextColumn::make('technician.name')->label('Teknisi')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => $statusLabel[$state] ?? $state)
                    ->color(fn ($state) => ['diterima' => 'warning', 'selesai' => 'success', 'batal' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('progress')->label('Proses')->formatStateUsing(fn ($state, ServiceTicket $r) => $r->status === 'diterima' ? (ServiceTicketService::PROGRESS[$state] ?? $state) : '-'),
                TextColumn::make('deposit')->label('Uang muka (DP)')->money('IDR', locale: 'id'),
                TextColumn::make('total')->label('Total biaya')->money('IDR', locale: 'id')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options($statusLabel),
            ])
            ->headerActions([
                Action::make('terima')->label('Terima HP baru')->modalHeading('Terima HP baru')->modalSubmitActionLabel('Simpan dan buat nomor servis')
                    ->schema([
                        Select::make('branch_id')->label('Cabang')->required()->options($branchOptions)
                            ->default(fn () => $branchOptions()->keys()->first())->visible(fn () => $branchOptions()->count() > 1),
                        TextInput::make('customer_name')->label('Nama pelanggan')->placeholder('Contoh: Budi Santoso')->required(),
                        TextInput::make('customer_phone')->label('Nomor WhatsApp / HP')->tel()->placeholder('Contoh: 081234567890')->helperText('Dipakai untuk menghubungi pelanggan dan supaya pelanggan bisa masuk melihat servisnya. Boleh kosong.'),
                        TextInput::make('device')->label('Tipe HP')->placeholder('Contoh: iPhone 11 64GB hitam')->helperText('Tulis tipe dan warna supaya tidak tertukar dengan HP lain.')->required(),
                        Textarea::make('complaint')->label('Kerusakan / keluhan')->placeholder('Contoh: layar pecah, touchscreen tidak jalan')->helperText('Ceritakan apa yang rusak menurut pelanggan.')->required()->rows(3),
                        Select::make('technician_id')->label('Teknisi yang mengerjakan')->placeholder('Pilih teknisi')->options(fn () => User::role('technician')->orderBy('name')->pluck('name', 'id'))->helperText('Boleh dikosongkan dan diisi nanti.'),
                        TextInput::make('deposit')->label('Uang muka / DP (Rp)')->prefix('Rp')->inputMode('numeric')->numeric()->minValue(0)->default(0)->placeholder('Contoh: 100000')->helperText('Isi 0 kalau pelanggan belum bayar apa-apa di muka.'),
                        Select::make('method')->label('DP dibayar dengan')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options())->default('kas')->helperText('Pilih kas atau rekening bank tempat DP masuk. Contoh: Tunai masuk laci kas, transfer masuk rekening toko.'),
                    ])
                    ->action(function (array $data) use ($branchOptions) {
                        $branchId = (int) ($data['branch_id'] ?? $branchOptions()->keys()->first());
                        self::run(
                            fn () => app(ServiceTicketService::class)->receive(auth()->user(), $branchId, now()->toDateString(), $data, (int) ($data['deposit'] ?? 0), $data['method'] ?? 'kas'),
                            'HP sudah diterima',
                            fn ($t) => "Nomor servis: {$t->code}. Beri tahu pelanggan nomor ini.",
                        );
                    }),
            ])
            ->recordActions([
                Action::make('nota')->label('Cetak nota')->icon(\Filament\Support\Icons\Heroicon::OutlinedPrinter)
                    ->url(fn (ServiceTicket $r) => route('nota', $r->code), shouldOpenInNewTab: true),
                Action::make('proses')->label('Ubah proses')->visible(fn (ServiceTicket $r) => $r->status === 'diterima')
                    ->modalHeading(fn (ServiceTicket $r) => "Proses servis {$r->code}")
                    ->modalDescription('Pelanggan bisa melihat proses ini di tautan lacak pada nota.')
                    ->modalSubmitActionLabel('Simpan proses')
                    ->fillForm(fn (ServiceTicket $r) => ['progress' => $r->progress])
                    ->schema([
                        Select::make('progress')->label('Proses sekarang')->options(ServiceTicketService::PROGRESS)->required(),
                        TextInput::make('note')->label('Catatan untuk pelanggan (boleh kosong)')->maxLength(200)->placeholder('Contoh: layar dipesan, datang besok'),
                    ])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(
                        fn () => app(ServiceTicketService::class)->updateProgress($r, auth()->user(), $data['progress'], $data['note'] ?? null),
                        'Proses disimpan',
                        fn ($t) => "Servis {$t->code}: ".ServiceTicketService::PROGRESS[$t->progress].'.',
                    )),
                Action::make('selesai')->label('Selesai diperbaiki')->visible(fn (ServiceTicket $r) => $r->status === 'diterima')
                    ->modalHeading(fn (ServiceTicket $r) => "Selesai diperbaiki: {$r->code}")
                    ->modalDescription(fn (ServiceTicket $r) => 'Stok sparepart akan berkurang dan pembayaran dicatat. '
                        .($r->deposit > 0 ? 'DP '.self::rp($r->deposit).' dihitung sebagai pembayaran, jadi pelanggan hanya membayar sisanya.' : 'Pelanggan belum membayar DP.'))
                    ->modalSubmitActionLabel('Simpan dan catat pembayaran')
                    ->schema([
                        Repeater::make('parts')->label('Sparepart yang dipakai')->defaultItems(0)->addActionLabel('Tambah sparepart')->schema([
                            Select::make('sku')->label('Sparepart')->required()->searchable()->live()->placeholder('Pilih sparepart')
                                ->options(fn (ServiceTicket $record) => self::stockOptions($record->branch_id))
                                ->afterStateUpdated(fn ($state, $set) => $set('price', (int) Product::where('sku', $state)->value('sale_price') ?: null)),
                            TextInput::make('qty')->label('Jumlah')->numeric()->inputMode('numeric')->minValue(1)->default(1)->required(),
                            TextInput::make('price')->label('Harga ke pelanggan (Rp)')->prefix('Rp')->inputMode('numeric')->numeric()->minValue(1)->placeholder('Contoh: 350000')->required(),
                        ])->columns(['default' => 1, 'sm' => 3])->helperText('Kosongkan kalau tidak ada sparepart yang diganti.'),
                        TextInput::make('labor')->label('Ongkos servis / jasa (Rp)')->prefix('Rp')->inputMode('numeric')->numeric()->minValue(0)->default(0)->required()->placeholder('Contoh: 100000')->helperText('Upah teknisi, di luar harga sparepart.'),
                        Select::make('method')->label('Sisa dibayar dengan')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options(['qris' => 'QRIS']))->default('kas')->required()->helperText('Tunai masuk laci kas. Transfer dan QRIS masuk rekening toko.'),
                    ])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(
                        fn () => app(ServiceTicketService::class)->complete(
                            $r, auth()->user(), now()->toDateString(),
                            array_map(fn ($p) => ['sku' => $p['sku'], 'qty' => (int) $p['qty'], 'price' => (int) $p['price']], $data['parts'] ?? []),
                            (int) $data['labor'], $data['method'],
                        ),
                        'Servis selesai',
                        fn ($t) => "Servis {$t->code} selesai. Total biaya ".self::rp($t->total).'.',
                    )),
                Action::make('batal')->label('Batalkan servis')->color('danger')->visible(fn (ServiceTicket $r) => $r->status === 'diterima')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ServiceTicket $r) => "Batalkan servis {$r->code}?")
                    ->modalDescription(fn (ServiceTicket $r) => 'Servis ini tidak bisa dibuka lagi. '
                        .($r->deposit > 0 ? 'DP '.self::rp($r->deposit).' dikembalikan ke pelanggan, dikurangi biaya pengecekan kalau ada.' : 'Tidak ada DP yang perlu dikembalikan.'))
                    ->modalSubmitActionLabel('Ya, batalkan servis')
                    ->schema([
                        TextInput::make('fee')->label('Biaya pengecekan (Rp)')->prefix('Rp')->inputMode('numeric')->numeric()->minValue(0)->default(0)->required()->placeholder('Contoh: 50000')
                            ->helperText('Biaya yang tetap dibayar pelanggan walau batal. Diambil dari DP, tidak boleh lebih besar dari DP.'),
                        Select::make('refund_method')->label('Sisa DP dikembalikan lewat')->options(fn () => \App\Domain\Accounting\MoneyAccounts::options())->default('kas')->required()->helperText('Pilih kas atau rekening bank asal uang. Contoh: Tunai diambil dari laci kas, transfer keluar dari rekening toko.'),
                    ])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(
                        fn () => app(ServiceTicketService::class)->cancel($r, auth()->user(), now()->toDateString(), (int) $data['fee'], $data['refund_method'] ?? 'kas'),
                        'Servis dibatalkan',
                        fn ($t) => "Servis {$t->code} sudah dibatalkan.",
                    )),
                Action::make('garansi')->label('Garansi: ganti sparepart')->visible(fn (ServiceTicket $r) => $r->status === 'selesai')
                    ->modalHeading(fn (ServiceTicket $r) => "Garansi servis {$r->code}")
                    ->modalDescription('Sparepart pengganti diambil dari stok dan dicatat sebagai biaya garansi. Pelanggan tidak dikenai biaya.')
                    ->modalSubmitActionLabel('Catat garansi')
                    ->schema([
                        Select::make('sku')->label('Sparepart pengganti')->required()->searchable()->placeholder('Pilih sparepart')->options(fn (ServiceTicket $record) => self::stockOptions($record->branch_id)),
                        TextInput::make('qty')->label('Jumlah')->numeric()->inputMode('numeric')->minValue(1)->default(1)->required(),
                    ])
                    ->action(fn (ServiceTicket $r, array $data) => self::run(
                        fn () => app(ServiceTicketService::class)->claimWarranty($r, auth()->user(), now()->toDateString(), $data['sku'], (int) $data['qty']),
                        'Garansi dicatat',
                        fn ($t) => "Garansi servis {$t->code} sudah dicatat dan stok sparepart berkurang.",
                    )),
            ]);
    }
}
