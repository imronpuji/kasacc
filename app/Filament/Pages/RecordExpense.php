<?php

namespace App\Filament\Pages;

use App\Domain\Transactions;
use App\Models\Account;
use App\Models\Branch;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RecordExpense extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.record-expense';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Catat biaya';

    protected static ?string $title = 'Catat biaya';

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('post-purchases') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Untuk biaya yang sudah Anda bayar, misalnya listrik, sewa, internet, atau beli sabun. Biaya masuk ke laporan untung rugi.';
    }

    public function mount(): void
    {
        $this->form->fill(['date' => now(config('kasir.timezone'))->toDateString(), 'branch_id' => Branch::orderBy('id')->value('id'), 'method' => 'kas']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Biaya yang dibayar')->schema([
                Select::make('account')->label('Biaya apa?')->required()->searchable()->placeholder('Pilih, contoh: Beban listrik dan internet')
                    ->options(fn () => Account::whereIn('type', ['expense', 'other_expense'])->where('is_active', true)->orderBy('name')->pluck('name', 'code'))
                    ->helperText('Tidak ada yang cocok? Tambahkan di menu Daftar akun, pilih "biaya".'),
                TextInput::make('amount')->label('Berapa rupiah?')->numeric()->minValue(1)->required()->prefix('Rp')->placeholder('500000')->helperText('Angka saja, tanpa titik. Contoh: 500000.'),
                Select::make('method')->label('Dibayar pakai apa?')->required()->native(false)->options(fn () => \App\Domain\Accounting\MoneyAccounts::options(['kredit' => 'Belum dibayar (jadi utang)']))->helperText('Pilih kas atau rekening bank yang dipakai. Pilih "Belum dibayar" untuk tagihan yang akan dilunasi nanti lewat menu Pembayaran utang. Rekening baru ditambah di Daftar akun.'),
                DatePicker::make('date')->label('Tanggal bayar')->required()->helperText('Tanggal uang keluar.'),
                \App\Support\BranchField::make('Untuk cabang mana?', true)->helperText('Pilih toko yang menanggung biaya ini.'),
                Textarea::make('note')->label('Catatan (boleh dikosongkan)')->rows(2)->maxLength(200)->placeholder('Contoh: listrik bulan Oktober')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public function save(): void
    {
        try {
            $d = $this->form->getState();
            $name = Account::where('code', $d['account'])->value('name');
            $desc = trim($name.($d['note'] ? ' - '.$d['note'] : ''));
            app(Transactions::class)->payExpense((int) $d['branch_id'], $d['date'], $d['account'], (int) $d['amount'], $d['method'], $desc);
            Notification::make()->title('Biaya tercatat')->body($name.' sebesar Rp '.number_format((int) $d['amount'], 0, ',', '.').' sudah dicatat.')->success()->send();
            $this->form->fill(['date' => $d['date'], 'branch_id' => $d['branch_id'], 'method' => $d['method']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\DomainException $e) {
            Notification::make()->title('Belum berhasil')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Belum berhasil')->body('Biaya belum tersimpan. Coba lagi. Kalau masih gagal, hubungi admin atau teknisi aplikasi.')->danger()->send();
        }
    }
}
