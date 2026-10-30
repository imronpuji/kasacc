<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\BankReconService;
use App\Domain\Accounting\MoneyAccounts;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class BankReconciliation extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.bank-reconciliation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Cocokkan rekening bank';

    protected static ?string $title = 'Cocokkan rekening bank';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 5;

    public ?int $branchId = null;

    public string $accountCode = '1-1110';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reconcile-bank') ?? false;
    }

    public function mount(): void
    {
        $this->branchId = $this->branches()->keys()->first();
        $this->accountCode = array_key_exists('1-1110', $this->bankAccounts()) ? '1-1110' : (string) (array_key_first($this->bankAccounts()) ?? '1-1110');
    }

    public function getSubheading(): ?string
    {
        return 'Pilih rekening banknya, lalu cek apakah catatan rekening itu di aplikasi sama dengan rekening koran. Caranya: 1) Unggah mutasi bank (file CSV dari internet banking), 2) Tekan "Cocokkan otomatis", 3) Selesaikan baris yang belum cocok.';
    }

    /** Rekening bank yang bisa dipilih (kas tidak termasuk). */
    public function bankAccounts(): array
    {
        return collect(MoneyAccounts::all())->except(MoneyAccounts::KAS)->all();
    }

    private function branches()
    {
        $u = auth()->user();

        return $u->hasRole('owner') ? Branch::orderBy('name')->pluck('name', 'id') : $u->branches()->orderBy('name')->pluck('branches.name', 'branches.id');
    }

    protected function getViewData(): array
    {
        return [
            'branches' => $this->branches(),
            'bankAccounts' => $this->bankAccounts(),
            'summary' => $this->branchId ? app(BankReconService::class)->summary($this->branchId, $this->accountCode) : null,
        ];
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $result = $fn(auth()->user());
            Notification::make()->title('Berhasil')->body(is_string($result) ? $result : $ok)->success()->send();
        } catch (\DomainException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Terjadi gangguan. Coba lagi sebentar lagi. Kalau masih gagal, hubungi admin atau teknisi aplikasi.')->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        $rp = fn ($n) => ($n < 0 ? '-' : '').'Rp'.number_format(abs((int) $n), 0, ',', '.');

        return $table
            ->query(fn () => BankStatementLine::query()->where('branch_id', $this->branchId ?? 0)->where('account_code', $this->accountCode)->orderByDesc('line_date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('line_date')->label('Tanggal')->date('d M Y'),
                TextColumn::make('description')->label('Keterangan di rekening bank'),
                TextColumn::make('amount')->label('Jumlah (minus = uang keluar)')->formatStateUsing($rp),
                TextColumn::make('status')->label('Status')->state(fn ($record) => $record->journal_line_id ? 'Cocok' : 'Belum cocok')->badge()
                    ->color(fn ($state) => $state === 'Cocok' ? 'success' : 'warning'),
            ])
            ->emptyStateHeading('Belum ada mutasi bank')->emptyStateDescription('Tekan "Unggah mutasi bank (CSV)" di kanan atas untuk memulai.')
            ->filters([
                TernaryFilter::make('journal_line_id')->label('Status')->nullable()->placeholder('Semua')->trueLabel('Sudah cocok')->falseLabel('Belum cocok')->default(false),
            ])
            ->headerActions([
                Action::make('impor')->label('Unggah mutasi bank (CSV)')->modalHeading('Unggah mutasi bank')->modalSubmitActionLabel('Unggah')->icon(Heroicon::OutlinedArrowUpTray)->schema([
                    FileUpload::make('file')->label('File CSV dari internet banking')->disk('local')->directory('bank-csv')->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->helperText('Baris pertama berisi judul kolom: tanggal, keterangan, jumlah; uang keluar ditulis minus. Contoh: 2026-11-30, Biaya admin, -6500. Baris yang sudah pernah diunggah dilewati otomatis.'),
                ])->action(fn (array $data) => $this->run(function ($u) use ($data) {
                    $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $r = app(BankReconService::class)->import($u, (int) $this->branchId, Storage::disk('local')->get($path), $this->accountCode);
                    Storage::disk('local')->delete($path);

                    return "{$r['imported']} baris baru masuk, {$r['skipped']} dilewati karena sudah pernah diunggah.";
                }, 'Selesai')),
                Action::make('otomatis')->label('Cocokkan otomatis')->icon(Heroicon::OutlinedSparkles)
                    ->modalDescription('Aplikasi mencari mutasi bank yang jumlahnya sama dengan catatan di aplikasi, dengan selisih tanggal paling banyak 3 hari.')
                    ->action(fn () => $this->run(function () {
                        $n = app(BankReconService::class)->autoMatch((int) $this->branchId, $this->accountCode)['matched'];

                        return $n > 0 ? "{$n} baris berhasil dicocokkan." : 'Tidak ada baris yang cocok. Cek tanggal dan jumlahnya.';
                    }, 'Selesai')),
            ])
            ->recordActions([
                Action::make('catat')->label('Catat mutasi ini')->modalSubmitActionLabel('Catat')->visible(fn ($record) => ! $record->journal_line_id)
                    ->modalDescription('Pakai ini untuk mutasi yang belum tercatat di aplikasi, misalnya biaya admin bank atau bunga. Aplikasi mencatatnya dan langsung mencocokkannya.')
                    ->schema([Select::make('account')->label('Masuk ke pos apa?')->searchable()->required()->placeholder('Contoh: Biaya admin bank')
                        ->options(fn () => Account::whereIn('type', ['expense', 'other_expense', 'revenue'])->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => $a->name]))
                        ->default('8-1100')->helperText('Biaya admin bank: pilih "Beban administrasi bank". Untuk bunga atau uang masuk lain, pilih pos pendapatan yang sesuai.')])
                    ->action(fn (array $data, $record) => $this->run(fn ($u) => app(BankReconService::class)->recordBankOnly($u, $record, $data['account'], $record->account_code), 'Dicatat dan dicocokkan')),
                Action::make('manual')->label('Cocokkan manual')->modalSubmitActionLabel('Cocokkan')->visible(fn ($record) => ! $record->journal_line_id)
                    ->modalDescription('Pilih catatan di aplikasi yang jumlahnya sama, misalnya transfer atau cek yang baru muncul di rekening bank bulan berikutnya.')
                    ->schema([Select::make('journal_line_id')->label('Catatan di aplikasi')->required()->placeholder('Pilih catatan')->helperText('Hanya catatan dengan jumlah yang sama yang muncul di sini.')
                        ->options(fn ($record) => collect(app(BankReconService::class)->summary($record->branch_id, $record->account_code)['book_only'])->where('amount', (int) $record->amount)
                            ->mapWithKeys(fn ($b) => [$b['journal_line_id'] => $b['date'].' - '.$b['description']]))])
                    ->action(fn (array $data, $record) => $this->run(fn ($u) => app(BankReconService::class)->match($u, $record, (int) $data['journal_line_id']), 'Dicocokkan')),
                Action::make('batal')->label('Batalkan kecocokan')->color('gray')->visible(fn ($record) => (bool) $record->journal_line_id)->requiresConfirmation()
                    ->modalHeading('Batalkan kecocokan?')->modalDescription('Mutasi ini kembali berstatus "Belum cocok". Catatan di aplikasi tidak dihapus, jadi bisa dicocokkan ulang.')
                    ->action(fn ($record) => $this->run(fn ($u) => app(BankReconService::class)->unmatch($u, $record), 'Kecocokan dibatalkan')),
            ]);
    }
}
