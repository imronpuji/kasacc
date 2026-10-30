<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

class Customers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Pelanggan';

    protected static ?string $title = 'Pelanggan';

    protected static string|\UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 3;

    public function getSubheading(): ?string
    {
        return 'Pelanggan tercatat otomatis dari email atau nomor HP di servis dan kasir. Dengan email, pelanggan bisa masuk ke halaman pelanggan (kode dikirim ke email) untuk melihat servisnya.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-services') ?? false;
    }

    /** Nomor yang diketik staf dibuat seragam (08...) dan tidak boleh sama dengan pelanggan lain. */
    private static function phoneRule(?int $ignoreId = null): ValidationRule
    {
        return self::uniqueContactRule($ignoreId, 'phone', fn ($v) => PhoneNumber::normalize($v), 'Nomor HP tidak valid. Tulis seperti 081234567890.', 'Nomor HP ini sudah dipakai pelanggan lain.');
    }

    private static function emailRule(?int $ignoreId = null): ValidationRule
    {
        return self::uniqueContactRule($ignoreId, 'email', fn ($v) => EmailAddress::normalize($v), 'Email tidak valid. Tulis seperti nama@contoh.com.', 'Email ini sudah dipakai pelanggan lain.');
    }

    /** Objek aturan, bukan closure: Filament tidak bisa mengisi parameter $attribute pada closure di dalam modal. */
    private static function uniqueContactRule(?int $ignoreId, string $column, \Closure $normalize, string $invalid, string $taken): ValidationRule
    {
        return new class($ignoreId, $column, $normalize, $invalid, $taken) implements ValidationRule {
            public function __construct(private ?int $ignoreId, private string $column, private \Closure $normalize, private string $invalid, private string $taken)
            {
            }

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (trim((string) $value) === '') {
                    return;
                }
                $clean = ($this->normalize)($value);
                if (! $clean) {
                    $fail($this->invalid);
                } elseif (Customer::where($this->column, $clean)->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))->exists()) {
                    $fail($this->taken);
                }
            }
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Customer::query()->withCount(['tickets', 'sales', 'complaints'])->withSum('points', 'points')->latest('id'))
            ->emptyStateHeading('Belum ada pelanggan')
            ->emptyStateDescription('Pelanggan muncul otomatis saat Anda mengisi nomor HP di servis atau kasir.')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('phone')->label('Nomor HP')->searchable()->placeholder('-'),
                TextColumn::make('email')->label('Email')->searchable()->placeholder('-'),
                TextColumn::make('tickets_count')->label('Servis')->alignEnd(),
                TextColumn::make('sales_count')->label('Belanja')->alignEnd(),
                TextColumn::make('complaints_count')->label('Komplain')->alignEnd(),
                TextColumn::make('points_sum_points')->label('Poin')->alignEnd()->state(fn (Customer $r) => (int) $r->points_sum_points)->numeric(),
                TextColumn::make('last_login_at')->label('Terakhir masuk')->dateTime('d/m/Y H:i')->placeholder('Belum pernah'),
            ])
            ->headerActions([
                Action::make('tambah')->label('Tambah pelanggan')->modalSubmitActionLabel('Simpan')
                    ->schema([
                        TextInput::make('name')->label('Nama')->required()->maxLength(100),
                        TextInput::make('email')->label('Email')->required()->rules([self::emailRule()])->placeholder('budi@email.com')->helperText('Email dipakai pelanggan untuk masuk ke halaman pelanggan.'),
                        TextInput::make('phone')->label('Nomor HP (boleh kosong)')->tel()->rules([self::phoneRule()])->placeholder('081234567890'),
                        Textarea::make('note')->label('Catatan (boleh kosong)')->rows(2)->maxLength(500),
                    ])
                    ->action(function (array $data) {
                        Customer::create(['name' => trim($data['name']), 'phone' => PhoneNumber::normalize($data['phone'] ?? null), 'email' => EmailAddress::normalize($data['email']), 'note' => $data['note'] ?? null]);
                        Notification::make()->title('Pelanggan disimpan')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('koreksiPoin')->label('Koreksi poin')->color('gray')->modalSubmitActionLabel('Simpan koreksi')
                    ->visible(fn () => auth()->user()?->hasRole('owner') ?? false)
                    ->modalDescription('Untuk memperbaiki salah catat atau memberi poin hadiah. Setiap koreksi tercatat dengan alasannya.')
                    ->schema([
                        TextInput::make('points')->label('Jumlah poin (minus untuk mengurangi)')->numeric()->integer()->required()->placeholder('Contoh: 50 atau -20'),
                        TextInput::make('reason')->label('Alasan')->required()->maxLength(200)->placeholder('Contoh: poin hadiah ulang tahun'),
                    ])
                    ->action(function (Customer $r, array $data) {
                        try {
                            app(\App\Domain\Loyalty\LoyaltyService::class)->adjust($r, (int) $data['points'], $data['reason'], auth()->user());
                        } catch (\DomainException $e) {
                            Notification::make()->title('Poin belum dikoreksi')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Poin dikoreksi')->success()->send();
                    }),
                Action::make('ubah')->label('Ubah')->modalSubmitActionLabel('Simpan perubahan')
                    ->fillForm(fn (Customer $r) => $r->only(['name', 'phone', 'email', 'note']))
                    ->schema(fn (Customer $r) => [
                        TextInput::make('name')->label('Nama')->required()->maxLength(100),
                        TextInput::make('email')->label('Email')->rules([self::emailRule($r->id)])->helperText('Email ini dipakai pelanggan untuk masuk. Mengubahnya berarti pelanggan masuk dengan email baru.'),
                        TextInput::make('phone')->label('Nomor HP')->tel()->rules([self::phoneRule($r->id)]),
                        Textarea::make('note')->label('Catatan (boleh kosong)')->rows(2)->maxLength(500),
                    ])
                    ->action(function (Customer $r, array $data) {
                        $r->update(['name' => trim($data['name']), 'phone' => PhoneNumber::normalize($data['phone'] ?? null), 'email' => EmailAddress::normalize($data['email'] ?? null), 'note' => $data['note'] ?? null]);
                        Notification::make()->title('Perubahan disimpan')->success()->send();
                    }),
            ]);
    }
}
