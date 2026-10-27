<?php

namespace App\Filament\Pages;

use App\Domain\Accounting\BankReconService;
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

class RekonsiliasiBank extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.rekonsiliasi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Rekonsiliasi bank';

    protected static ?string $title = 'Rekonsiliasi Bank';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    public ?int $branchId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reconcile-bank') ?? false;
    }

    public function mount(): void
    {
        $this->branchId = $this->branches()->keys()->first();
    }

    public function getSubheading(): ?string
    {
        return 'Mencocokkan catatan Bank di pembukuan dengan rekening koran dari bank. Langkah: 1) Impor mutasi bank (CSV), 2) Cocokkan otomatis, 3) Selesaikan baris yang tersisa.';
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
            'summary' => $this->branchId ? app(BankReconService::class)->summary($this->branchId) : null,
        ];
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $result = $fn(auth()->user());
            Notification::make()->title(is_string($result) ? $result : $ok)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        $rp = fn ($n) => ($n < 0 ? '-' : '').'Rp'.number_format(abs((int) $n), 0, ',', '.');

        return $table
            ->query(fn () => BankStatementLine::query()->where('branch_id', $this->branchId ?? 0)->orderByDesc('line_date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('line_date')->label('Tanggal')->date('d M Y'),
                TextColumn::make('description')->label('Keterangan di rekening koran'),
                TextColumn::make('amount')->label('Jumlah')->formatStateUsing($rp),
                TextColumn::make('status')->label('Status')->state(fn ($record) => $record->journal_line_id ? 'Cocok' : 'Belum cocok')->badge()
                    ->color(fn ($state) => $state === 'Cocok' ? 'success' : 'warning'),
            ])
            ->filters([
                TernaryFilter::make('journal_line_id')->label('Status')->nullable()->placeholder('Semua')->trueLabel('Sudah cocok')->falseLabel('Belum cocok')->default(false),
            ])
            ->headerActions([
                Action::make('impor')->label('Impor mutasi bank (CSV)')->icon(Heroicon::OutlinedArrowUpTray)->schema([
                    FileUpload::make('file')->label('File CSV dari internet banking')->disk('local')->directory('bank-csv')->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->helperText('Judul kolom wajib: tanggal, keterangan, jumlah (uang keluar bertanda minus). Atau kolom debit dan kredit terpisah. Baris yang sudah pernah diimpor otomatis dilewati.'),
                ])->action(fn (array $data) => $this->run(function ($u) use ($data) {
                    $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $r = app(BankReconService::class)->import($u, (int) $this->branchId, Storage::disk('local')->get($path));
                    Storage::disk('local')->delete($path);

                    return "{$r['imported']} baris diimpor, {$r['skipped']} dilewati (sudah pernah masuk)";
                }, 'Selesai')),
                Action::make('otomatis')->label('Cocokkan otomatis')->icon(Heroicon::OutlinedSparkles)
                    ->action(fn () => $this->run(fn () => app(BankReconService::class)->autoMatch((int) $this->branchId)['matched'].' baris berhasil dicocokkan', 'Selesai')),
            ])
            ->recordActions([
                Action::make('catat')->label('Catat ke pembukuan')->visible(fn ($record) => ! $record->journal_line_id)
                    ->modalDescription('Pakai ini untuk mutasi yang belum ada di pembukuan, misalnya biaya admin bank atau bunga. Sistem membuat jurnalnya dan langsung mencocokkannya.')
                    ->schema([Select::make('account')->label('Akun lawan')->searchable()->required()
                        ->options(fn () => Account::whereIn('type', ['expense', 'other_expense', 'revenue'])->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => $a->code.' '.$a->name]))
                        ->default('8-1100')->helperText('Biaya admin bank: pilih 8-1100.')])
                    ->action(fn (array $data, $record) => $this->run(fn ($u) => app(BankReconService::class)->recordBankOnly($u, $record, $data['account']), 'Dicatat dan dicocokkan')),
                Action::make('manual')->label('Cocokkan manual')->visible(fn ($record) => ! $record->journal_line_id)
                    ->modalDescription('Pilih catatan di pembukuan yang sama nominalnya, misalnya cek yang baru cair di bulan berikutnya.')
                    ->schema([Select::make('journal_line_id')->label('Catatan pembukuan')->required()
                        ->options(fn ($record) => collect(app(BankReconService::class)->summary($record->branch_id)['book_only'])->where('amount', (int) $record->amount)
                            ->mapWithKeys(fn ($b) => [$b['journal_line_id'] => $b['date'].' - '.$b['description']]))])
                    ->action(fn (array $data, $record) => $this->run(fn ($u) => app(BankReconService::class)->match($u, $record, (int) $data['journal_line_id']), 'Dicocokkan')),
                Action::make('batal')->label('Batalkan kecocokan')->color('gray')->visible(fn ($record) => (bool) $record->journal_line_id)->requiresConfirmation()
                    ->action(fn ($record) => $this->run(fn ($u) => app(BankReconService::class)->unmatch($u, $record), 'Kecocokan dibatalkan')),
            ]);
    }
}
