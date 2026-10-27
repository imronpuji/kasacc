<?php

use App\Domain\Accounting\FinalTaxService;
use App\Domain\Accounting\LedgerService;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Database\Seeders\AccountSeeder;

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    CarbonImmutable::setTestNow('2027-03-10');
    $this->b = Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $this->u = User::factory()->create();
    $this->svc = app(FinalTaxService::class);
    $jual = fn (int $amt, string $date) => app(LedgerService::class)->post(['branch_id' => $this->b->id, 'date' => $date,
        'lines' => [['account' => '1-1100', 'debit' => $amt], ['account' => '4-1000', 'credit' => $amt]]]);
    $jual(10_000_000, '2027-02-05');
    $jual(5_000_000, '2027-02-20');
    $jual(9_000_000, '2027-03-01');
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('pajak 0,5% dari omzet bulan itu saja', function () {
    expect($this->svc->turnover($this->b->id, 2027, 2))->toBe(15_000_000)
        ->and($this->svc->due($this->b->id, 2027, 2))->toBe(75_000);
});

it('mencatat beban dan utang, lalu setoran melunasi utang', function () {
    expect($this->svc->accrue($this->u, $this->b, 2027, 2))->toBe(75_000);
    expect($this->svc->pay($this->u, $this->b, 2027, 2, '2027-03-10'))->toBe(75_000);
    $net = fn ($code) => (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)->selectRaw('SUM(l.debit)-SUM(l.credit) n')->value('n');
    expect($net('2-1600'))->toBe(0)->and($net('8-1200'))->toBe(75_000);
});

it('tidak bisa dicatat dua kali, bulan berjalan, tanpa omzet, atau setor sebelum dicatat', function () {
    expect(fn () => $this->svc->pay($this->u, $this->b, 2027, 2, '2027-03-10'))->toThrow(DomainException::class);
    expect(fn () => $this->svc->accrue($this->u, $this->b, 2027, 3))->toThrow(DomainException::class, 'belum selesai');
    expect(fn () => $this->svc->accrue($this->u, $this->b, 2027, 1))->toThrow(DomainException::class, 'Tidak ada pajak');
    $this->svc->accrue($this->u, $this->b, 2027, 2);
    expect(fn () => $this->svc->accrue($this->u, $this->b, 2027, 2))->toThrow(DomainException::class, 'sudah dicatat');
    $this->svc->pay($this->u, $this->b, 2027, 2, '2027-03-10');
    expect(fn () => $this->svc->pay($this->u, $this->b, 2027, 2, '2027-03-10'))->toThrow(DomainException::class, 'sudah dicatat');
});

it('setoran hanya dari kas atau bank', function () {
    $this->svc->accrue($this->u, $this->b, 2027, 2);
    expect(fn () => $this->svc->pay($this->u, $this->b, 2027, 2, '2027-03-10', '6-1000'))->toThrow(DomainException::class);
});

it('tarif bisa diubah, dan PPh badan dihitung dari laba', function () {
    \App\Domain\Accounting\TaxSettings::set('final_bps', 100);
    expect($this->svc->due($this->b->id, 2027, 2))->toBe(150_000);
    \App\Domain\Accounting\TaxSettings::set('regime', 'badan');
    \App\Domain\Accounting\TaxSettings::set('badan_bps', 2200);
    expect($this->svc->due($this->b->id, 2027, 2))->toBe(3_300_000); // 22% x laba 15 juta (belum ada biaya)
    \App\Domain\Accounting\TaxSettings::set('regime', 'none');
    expect($this->svc->due($this->b->id, 2027, 2))->toBe(0);
});

it('perorangan: omzet 500 juta pertama setahun bebas pajak', function () {
    \App\Domain\Accounting\TaxSettings::set('exempt', 20_000_000);
    // Jan 0, Feb 15 juta (masih bebas), Mar 9 juta -> kumulatif 24 juta, 4 juta kena pajak
    expect($this->svc->due($this->b->id, 2027, 2))->toBe(0);
    expect($this->svc->base($this->b->id, 2027, 3))->toBe(4_000_000);
});

it('QRIS bisa diubah dan peringatan masa tarif final muncul', function () {
    expect(\App\Domain\Accounting\TaxSettings::qrisBps())->toBe(70);
    \App\Domain\Accounting\TaxSettings::setQrisBps(100);
    expect(\App\Domain\Accounting\TaxSettings::qrisBps())->toBe(100);
    \App\Domain\Accounting\TaxSettings::set('start_year', 2022);
    \App\Domain\Accounting\TaxSettings::set('max_years', 4);
    expect(\App\Domain\Accounting\TaxSettings::expiryNote(2025))->toContain('tahun terakhir');
    expect(\App\Domain\Accounting\TaxSettings::expiryNote(2026))->toContain('sudah lewat');
    expect(\App\Domain\Accounting\TaxSettings::expiryNote(2024))->toBeNull();
});

it('halaman pengaturan: pilih PT mengisi tarif awal PT, simpan mengubah tarif dan QRIS', function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $o = User::factory()->create();
    $o->assignRole('owner');
    $this->actingAs($o);
    $c = \Livewire\Livewire::test(\App\Filament\Pages\PengaturanPajak::class)
        ->fillForm(['entity' => 'pt'])->assertFormSet(['max_years' => 3, 'regime' => 'final']);
    $c->fillForm(['entity' => 'pt', 'regime' => 'final', 'final_pct' => 1, 'qris_pct' => 0.9])->call('save')->assertHasNoFormErrors();
    expect(\App\Domain\Accounting\TaxSettings::entity())->toBe('pt')->and(\App\Domain\Accounting\TaxSettings::bps())->toBe(100)->and(\App\Domain\Accounting\TaxSettings::qrisBps())->toBe(90);
    $c->fillForm(['qris_pct' => 9])->call('save')->assertHasFormErrors(['qris_pct']);
});

it('halaman Pajak: catat dan setor lewat tombol', function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $o = User::factory()->create();
    $o->assignRole('owner');
    $this->actingAs($o);
    \Livewire\Livewire::test(\App\Filament\Pages\Pajak::class)->set('bulan', '2027-02')
        ->callAction(\Filament\Actions\Testing\TestAction::make('catat')->table($this->b))->assertNotified();
    expect($this->svc->accrued($this->b, 2027, 2))->toBeTrue();
});
