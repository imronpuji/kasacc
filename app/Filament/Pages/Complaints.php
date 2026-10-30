<?php

namespace App\Filament\Pages;

use App\Domain\Customer\ComplaintService;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Complaints extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Komplain';

    protected static ?string $title = 'Komplain pelanggan';

    public function getSubheading(): ?string
    {
        return 'Catat keluhan pelanggan, lalu tangani sampai selesai supaya tidak ada yang terlupa.';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-services') ?? false;
    }

    private static function run(callable $fn, string $title, string $body): void
    {
        try {
            $fn();
            Notification::make()->title($title)->body($body)->success()->send();
        } catch (\DomainException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Terjadi kesalahan. Coba lagi, kalau masih gagal hubungi pemilik toko.')->danger()->send();
        }
    }

    private static function branchIds(?int $branchId): array
    {
        return $branchId ? [$branchId] : array_keys(\App\Support\BranchField::options());
    }

    /** Servis terbaru di cabang yang dipilih, bisa dicari dengan nomor, nama pelanggan, atau HP. */
    private static function ticketSelect(): Select
    {
        $label = fn (\App\Models\ServiceTicket $t) => $t->code.' · '.$t->customer_name.' · '.$t->device;
        $query = fn ($get) => \App\Models\ServiceTicket::query()->whereIn('branch_id', self::branchIds($get('branch_id') ? (int) $get('branch_id') : null))->latest('id');

        return Select::make('ticket_code')->label('Nomor servis (boleh kosong)')->searchable()->placeholder('Pilih, atau ketik nomor, nama, atau HP')
            ->getSearchResultsUsing(fn (string $search, $get) => $query($get)->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('customer_name', 'ilike', "%{$search}%")->orWhere('customer_phone', 'like', "%{$search}%"))->limit(20)->get()->mapWithKeys(fn ($t) => [$t->code => $label($t)])->all())
            ->options(fn ($get) => $query($get)->limit(20)->get()->mapWithKeys(fn ($t) => [$t->code => $label($t)])->all())
            ->getOptionLabelUsing(fn ($value) => ($t = \App\Models\ServiceTicket::where('code', $value)->first()) ? $label($t) : $value)
            ->helperText('Ada di nota servis.');
    }

    /** Struk terbaru di cabang yang dipilih, bisa dicari dengan nomor atau jumlah uang. */
    private static function receiptSelect(): Select
    {
        $label = fn (\App\Models\PosSale $s) => $s->receipt_no.' · '.$s->sold_on->format('d/m/Y').' · Rp'.number_format((int) $s->total, 0, ',', '.');
        $query = fn ($get) => \App\Models\PosSale::query()->whereIn('branch_id', self::branchIds($get('branch_id') ? (int) $get('branch_id') : null))->latest('id');

        return Select::make('receipt_no')->label('Nomor struk (boleh kosong)')->searchable()->placeholder('Pilih, atau ketik nomor atau jumlah uangnya')
            ->getSearchResultsUsing(fn (string $search, $get) => $query($get)->where(fn ($q) => $q->where('receipt_no', 'ilike', "%{$search}%")->orWhereRaw('CAST(total AS TEXT) LIKE ?', ["%{$search}%"]))->limit(20)->get()->mapWithKeys(fn ($x) => [$x->receipt_no => $label($x)])->all())
            ->options(fn ($get) => $query($get)->limit(20)->get()->mapWithKeys(fn ($x) => [$x->receipt_no => $label($x)])->all())
            ->getOptionLabelUsing(fn ($value) => ($x = \App\Models\PosSale::where('receipt_no', $value)->first()) ? $label($x) : $value)
            ->helperText('Ada di bagian atas struk.');
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $branchOptions = fn () => $user->hasRole('owner') ? Branch::orderBy('name')->pluck('name', 'id') : $user->branches()->orderBy('name')->pluck('branches.name', 'branches.id');

        return $table
            ->query(fn (): Builder => Complaint::query()->with(['customer', 'ticket', 'sale'])
                ->when(! $user->hasRole('owner'), fn ($q) => $q->whereIn('branch_id', $user->branches()->pluck('branches.id')))
                ->latest('id'))
            ->emptyStateHeading('Belum ada komplain')
            ->emptyStateDescription('Ketuk "Catat komplain" saat pelanggan menyampaikan keluhan.')
            ->columns([
                TextColumn::make('received_on')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('customer.name')->label('Pelanggan')->placeholder('-')->searchable(),
                TextColumn::make('type')->label('Jenis')->formatStateUsing(fn ($state) => ComplaintService::TYPES[$state] ?? $state),
                TextColumn::make('description')->label('Keluhan')->limit(60)->wrap(),
                TextColumn::make('ticket.code')->label('Servis')->placeholder('-'),
                TextColumn::make('sale.receipt_no')->label('Struk')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => ComplaintService::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['baru' => 'warning', 'diproses' => 'info', 'selesai' => 'success'][$state] ?? 'gray'),
                TextColumn::make('resolution')->label('Penyelesaian')->limit(60)->wrap()->placeholder('-'),
            ])
            ->filters([SelectFilter::make('status')->label('Status')->options(ComplaintService::STATUSES)])
            ->headerActions([
                Action::make('catat')->label('Catat komplain')->modalSubmitActionLabel('Simpan komplain')
                    ->schema([
                        \App\Support\BranchField::make()->live(),
                        Select::make('type')->label('Jenis komplain')->options(ComplaintService::TYPES)->required(),
                        Textarea::make('description')->label('Cerita keluhan pelanggan')->required()->rows(3)->maxLength(1000),
                        self::ticketSelect(),
                        self::receiptSelect(),
                        Select::make('customer_id')->label('Pelanggan (boleh kosong)')->searchable()->placeholder('Pilih, atau ketik nama atau nomor HP')
                            ->getSearchResultsUsing(fn (string $search) => Customer::where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->orderBy('name')->limit(20)->get()
                                ->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->phone ? ' · '.$c->phone : '')])->all())
                            ->options(fn () => Customer::orderBy('name')->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->phone ? ' · '.$c->phone : '')])->all())
                            ->getOptionLabelUsing(fn ($value) => ($c = Customer::find($value)) ? $c->name.($c->phone ? ' · '.$c->phone : '') : $value)
                            ->helperText('Kalau nomor servis atau struk dipilih dan punya pelanggan, pelanggan diambil otomatis.'),
                    ])
                    ->action(function (array $data) use ($branchOptions) {
                        $branchId = (int) ($data['branch_id'] ?? $branchOptions()->keys()->first());
                        self::run(fn () => app(ComplaintService::class)->create(auth()->user(), $branchId, now()->toDateString(), $data), 'Komplain dicatat', 'Komplain masuk dengan status Baru.');
                    }),
            ])
            ->recordActions([
                Action::make('tangani')->label('Tangani')->visible(fn (Complaint $r) => $r->status !== 'selesai')
                    ->modalHeading('Tangani komplain')->modalSubmitActionLabel('Simpan')
                    ->fillForm(fn (Complaint $r) => ['status' => $r->status === 'baru' ? 'diproses' : $r->status, 'resolution' => $r->resolution])
                    ->schema([
                        Select::make('status')->label('Status')->options(['diproses' => 'Sedang diproses', 'selesai' => 'Selesai'])->required(),
                        Textarea::make('resolution')->label('Penyelesaian')->rows(3)->maxLength(1000)->helperText('Wajib diisi kalau status Selesai. Contoh: baterai diganti baru, uang dikembalikan.'),
                    ])
                    ->action(fn (Complaint $r, array $data) => self::run(
                        fn () => app(ComplaintService::class)->handle($r, auth()->user(), now()->toDateString(), $data['status'], $data['resolution'] ?? null),
                        'Komplain disimpan', 'Perubahan komplain tersimpan.',
                    )),
            ]);
    }
}
