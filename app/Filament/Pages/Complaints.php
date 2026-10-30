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

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 5;

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
                        Select::make('branch_id')->label('Cabang')->required()->options($branchOptions)
                            ->default(fn () => $branchOptions()->keys()->first())->visible(fn () => $branchOptions()->count() > 1),
                        Select::make('type')->label('Jenis komplain')->options(ComplaintService::TYPES)->required(),
                        Textarea::make('description')->label('Cerita keluhan pelanggan')->required()->rows(3)->maxLength(1000),
                        TextInput::make('ticket_code')->label('Nomor servis (boleh kosong)')->placeholder('Contoh: SRV-PUSAT-20271001-001')->helperText('Ada di nota servis.'),
                        TextInput::make('receipt_no')->label('Nomor struk (boleh kosong)')->placeholder('Contoh: POS-PUSAT-20271001-0007')->helperText('Ada di bagian atas struk.'),
                        Select::make('customer_id')->label('Pelanggan (boleh kosong)')->searchable()->placeholder('Ketik nama atau nomor HP')
                            ->getSearchResultsUsing(fn (string $s) => Customer::where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->limit(20)->pluck('name', 'id'))
                            ->helperText('Kalau nomor servis atau struk diisi dan punya pelanggan, pelanggan diambil otomatis.'),
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
