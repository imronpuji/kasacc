<?php

namespace App\Filament\Pages;

use App\Models\Customer;
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
use Illuminate\Database\Eloquent\Builder;

class Pelanggan extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.servis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Pelanggan';

    protected static ?string $title = 'Pelanggan';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 4;

    public function getSubheading(): ?string
    {
        return 'Pelanggan tercatat otomatis dari nomor HP di servis dan kasir. Dengan nomor HP, pelanggan bisa masuk ke halaman pelanggan untuk melihat servisnya.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-services') ?? false;
    }

    /** Nomor yang diketik staf dibuat seragam (08...) dan tidak boleh sama dengan pelanggan lain. */
    private static function phoneRule(?int $ignoreId = null): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($ignoreId) {
            if (trim((string) $value) === '') {
                return;
            }
            $phone = PhoneNumber::normalize($value);
            if (! $phone) {
                $fail('Nomor HP tidak valid. Tulis seperti 081234567890.');
            } elseif (Customer::where('phone', $phone)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
                $fail('Nomor HP ini sudah dipakai pelanggan lain.');
            }
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Customer::query()->withCount(['tickets', 'sales', 'complaints'])->latest('id'))
            ->emptyStateHeading('Belum ada pelanggan')
            ->emptyStateDescription('Pelanggan muncul otomatis saat Anda mengisi nomor HP di servis atau kasir.')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('phone')->label('Nomor HP')->searchable()->placeholder('-'),
                TextColumn::make('tickets_count')->label('Servis')->alignEnd(),
                TextColumn::make('sales_count')->label('Belanja')->alignEnd(),
                TextColumn::make('complaints_count')->label('Komplain')->alignEnd(),
                TextColumn::make('last_login_at')->label('Terakhir masuk')->dateTime('d/m/Y H:i')->placeholder('Belum pernah'),
            ])
            ->headerActions([
                Action::make('tambah')->label('Tambah pelanggan')->modalSubmitActionLabel('Simpan')
                    ->schema([
                        TextInput::make('name')->label('Nama')->required()->maxLength(100),
                        TextInput::make('phone')->label('Nomor HP')->tel()->required()->rules([self::phoneRule()])->placeholder('081234567890'),
                        Textarea::make('note')->label('Catatan (boleh kosong)')->rows(2)->maxLength(500),
                    ])
                    ->action(function (array $data) {
                        Customer::create(['name' => trim($data['name']), 'phone' => PhoneNumber::normalize($data['phone']), 'note' => $data['note'] ?? null]);
                        Notification::make()->title('Pelanggan disimpan')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('ubah')->label('Ubah')->modalSubmitActionLabel('Simpan perubahan')
                    ->fillForm(fn (Customer $r) => $r->only(['name', 'phone', 'note']))
                    ->schema(fn (Customer $r) => [
                        TextInput::make('name')->label('Nama')->required()->maxLength(100),
                        TextInput::make('phone')->label('Nomor HP')->tel()->rules([self::phoneRule($r->id)])->helperText('Nomor ini dipakai pelanggan untuk masuk. Mengubahnya berarti pelanggan masuk dengan nomor baru.'),
                        Textarea::make('note')->label('Catatan (boleh kosong)')->rows(2)->maxLength(500),
                    ])
                    ->action(function (Customer $r, array $data) {
                        $r->update(['name' => trim($data['name']), 'phone' => PhoneNumber::normalize($data['phone'] ?? null), 'note' => $data['note'] ?? null]);
                        Notification::make()->title('Perubahan disimpan')->success()->send();
                    }),
            ]);
    }
}
