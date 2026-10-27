<?php

use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

it('semua halaman panel Filament terbuka tanpa error untuk pemilik', function (string $path) {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)->get($path)->assertOk();
})->with([
    '/admin', '/admin/laporan', '/admin/journals', '/admin/accounts', '/admin/products',
    '/admin/branches', '/admin/payment-requests', '/admin/payment-requests/create', '/admin/kasir', '/admin/servis', '/admin/stok', '/admin/rekonsiliasi-bank',
]);

it('halaman panel menolak pengunjung yang belum login', function () {
    $this->get('/admin/laporan')->assertRedirect();
});

it('kasir: halaman Kasir bisa checkout lewat Livewire dan struk dapat dibuka', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $branch = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $p = App\Models\Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    $tx = app(App\Domain\Transactions::class);
    $tx->depositCapital($branch->id, now()->toDateString(), 5_000_000, 'kas');
    $tx->purchase($branch->id, now()->toDateString(), 'CASE', 10, 50_000, 'kas');
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $cashier->branches()->attach($branch->id);

    Livewire\Livewire::actingAs($cashier)->test(App\Filament\Pages\Kasir::class)
        ->call('add', $p->id)->set('cart.'.$p->id.'.qty', 2)->set('method', 'qris')->call('checkout')
        ->assertSet('cart', [])->assertSet('lastReceipt', 'POS-PUSAT-'.now()->format('Ymd').'-0001');

    $this->actingAs($cashier)->get('/struk/POS-PUSAT-'.now()->format('Ymd').'-0001')->assertOk()->assertSee('Casing');
    $this->get('/admin/kasir')->assertOk();
});

it('kasir: peran tanpa izin post-sales tidak bisa membuka halaman Kasir', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $u = User::factory()->create();
    $u->assignRole('approver');
    $this->actingAs($u)->get('/admin/kasir')->assertForbidden();
});

it('servis & stok: alur lewat halaman Filament (terima, selesai; beli, transfer)', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $b1 = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $b2 = App\Models\Branch::create(['code' => 'BDG', 'name' => 'Bandung']);
    App\Models\Product::create(['sku' => 'BAT12', 'name' => 'Baterai', 'kind' => 'sparepart']);
    $owner = User::factory()->create();
    $owner->assignRole('owner');
    app(App\Domain\Transactions::class)->depositCapital($b1->id, now()->toDateString(), 5_000_000, 'kas');

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Stok::class)
        ->callTableAction('pembelian', data: ['branch_id' => $b1->id, 'sku' => 'BAT12', 'qty' => 4, 'unit_cost' => 400_000, 'method' => 'kas'])
        ->callTableAction('transfer', data: ['from' => $b1->id, 'to' => $b2->id, 'sku' => 'BAT12', 'qty' => 1]);
    expect(App\Models\StockBalance::where('branch_id', $b1->id)->value('qty'))->toBe(3)
        ->and(App\Models\StockBalance::where('branch_id', $b2->id)->value('qty'))->toBe(1);

    $lw = Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Servis::class)
        ->callTableAction('terima', data: ['branch_id' => $b1->id, 'customer_name' => 'Rina', 'device' => 'iPhone 12', 'deposit' => 100_000, 'method' => 'kas']);
    $ticket = App\Models\ServiceTicket::firstOrFail();
    $lw->callTableAction('selesai', $ticket, data: ['parts' => [['sku' => 'BAT12', 'qty' => 1, 'price' => 700_000]], 'labor' => 100_000, 'method' => 'kas']);
    expect($ticket->fresh()->status)->toBe('selesai')->and($ticket->fresh()->total)->toBe(800_000);
});

it('menu data master hanya untuk pemilik; kasir tidak bisa membukanya', function (string $path) {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $this->actingAs($cashier)->get($path)->assertForbidden();
})->with(['/admin/accounts', '/admin/branches', '/admin/products', '/admin/laporan', '/admin/stok']);

it('Laporan menampilkan bagian operasional (arus kas, kartu stok, buku besar) tanpa error', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $b = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $p = App\Models\Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    $tx = app(App\Domain\Transactions::class);
    $tx->depositCapital($b->id, '2026-11-01', 5_000_000, 'kas');
    $tx->purchase($b->id, '2026-11-02', 'CASE', 10, 50_000, 'kas');
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    Livewire\Livewire::actingAs($owner)->test(App\Filament\Pages\Laporan::class)
        ->set('from', '2026-11-01')->set('to', '2026-11-30')->set('productId', $p->id)
        ->assertSee('Arus kas')->assertSee('Penjualan kasir harian')->assertSee('Kartu stok')
        ->assertSee('4.500.000')->assertSee('+10');
});

it('halaman SDM terbuka untuk pemilik, 403 untuk kasir', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $owner = User::factory()->create(); $owner->assignRole('owner');
    $cashier = User::factory()->create(); $cashier->assignRole('cashier');
    foreach (['/admin/karyawan', '/admin/payroll', '/admin/persetujuan-cuti'] as $path) {
        $this->actingAs($owner)->get($path)->assertOk();
        $this->actingAs($cashier)->get($path)->assertForbidden();
    }
});

it('payroll lewat Livewire dan slip gaji dapat dibuka; karyawan hanya melihat slip sendiri', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $branch = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $fin = User::factory()->create(); $fin->assignRole('finance_admin');
    $apr = User::factory()->create(); $apr->assignRole('approver');
    $tech = User::factory()->create(); $tech->assignRole('technician');
    $other = User::factory()->create(); $other->assignRole('technician');
    $mine = App\Models\Employee::create(['branch_id' => $branch->id, 'user_id' => $tech->id, 'name' => 'Andi', 'hired_on' => '2025-01-01', 'base_salary' => 3_000_000, 'ptkp' => 'TK/0']);
    App\Models\Employee::create(['branch_id' => $branch->id, 'name' => 'Sari', 'hired_on' => '2025-01-01', 'base_salary' => 3_000_000, 'ptkp' => 'TK/0']);
    foreach (App\Models\Employee::all() as $e) {
        app(App\Domain\Payroll\HrService::class)->recordAttendance($fin, $e, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    }
    Livewire\Livewire::actingAs($fin)->test(App\Filament\Pages\Payroll::class)
        ->callAction(Filament\Actions\Testing\TestAction::make('hitung')->table(), ['branch_id' => $branch->id, 'year' => 2027, 'month' => 1]);
    $run = App\Models\PayrollRun::firstOrFail();
    expect($run->slips()->count())->toBe(2);

    $this->actingAs($fin)->get('/slip/'.$run->id)->assertOk()->assertSee('Slip Gaji 01/2027');
    $this->actingAs($tech)->get('/slip/'.$run->id)->assertForbidden(); // draft belum terbit
    app(App\Domain\Payroll\PayrollService::class)->approve($apr, $run, '2027-01-31');
    $this->actingAs($tech)->get('/slip/'.$run->id)->assertOk()->assertSee('Andi')->assertDontSee('Sari');
    $this->actingAs($other)->get('/slip/'.$run->id)->assertForbidden();
});

it('peran HR: kelola karyawan dan hitung payroll, tapi tidak bisa bayar, setujui, atau buka laporan', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $branch = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $hr = User::factory()->create(); $hr->assignRole('hr');
    $e = App\Models\Employee::create(['branch_id' => $branch->id, 'name' => 'Sari', 'hired_on' => '2025-01-01', 'base_salary' => 3_000_000, 'ptkp' => 'TK/0']);
    $this->actingAs($hr)->get('/admin/karyawan')->assertOk();
    $this->actingAs($hr)->get('/admin/payroll')->assertOk();
    $this->actingAs($hr)->get('/admin/laporan')->assertForbidden();
    $this->actingAs($hr)->get('/admin/journals')->assertForbidden();
    $this->actingAs($hr)->get('/admin/persetujuan-cuti')->assertForbidden();

    Livewire\Livewire::actingAs($hr)->test(App\Filament\Pages\Karyawan::class)
        ->callAction(Filament\Actions\Testing\TestAction::make('tambah')->table(), [
            'branch_id' => $branch->id, 'name' => 'Rina', 'hired_on' => '2024-01-01', 'base_salary' => 9_000_000, 'fixed_allowance' => 1_000_000,
            'ptkp' => 'TK/0', 'pay_method' => 'bank', 'commission_pct' => 10, 'create_login' => false,
        ]);
    expect(App\Models\Employee::where('name', 'Rina')->value('commission_bps'))->toBe(1000);

    Livewire\Livewire::actingAs($hr)->test(App\Filament\Pages\Karyawan::class)
        ->callAction(Filament\Actions\Testing\TestAction::make('tambah')->table(), [
            'branch_id' => $branch->id, 'name' => 'Supri', 'hired_on' => '2027-01-01', 'base_salary' => 3_000_000, 'fixed_allowance' => 0,
            'ptkp' => 'TK/0', 'pay_method' => 'tunai', 'commission_pct' => 10,
            'create_login' => true, 'login_role' => 'technician', 'login_email' => 'supri@kasir.test', 'login_password' => 'rahasia123',
        ])->assertHasNoActionErrors();
    $supri = App\Models\Employee::where('name', 'Supri')->first();
    $login = User::where('email', 'supri@kasir.test')->first();
    expect($supri->user_id)->toBe($login->id)->and($login->hasRole('technician'))->toBeTrue()->and($login->canAccessBranch($branch->id))->toBeTrue();
    $supri->update(['is_active' => false]);

    $hrs = app(App\Domain\Payroll\HrService::class);
    foreach (App\Models\Employee::all() as $x) {
        $hrs->recordAttendance($hr, $x, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0]);
    }
    $pay = app(App\Domain\Payroll\PayrollService::class);
    $run = $pay->generate($hr, $branch->id, 2027, 1);
    $apr = User::factory()->create(); $apr->assignRole('approver');
    $run = $pay->approve($apr, $run, '2027-01-31');
    expect(fn () => $pay->approve($hr, $run, '2027-01-31'))->toThrow(DomainException::class, 'berwenang');
    expect(fn () => $pay->pay($hr, $run, '2027-02-01'))->toThrow(DomainException::class, 'berwenang');
});

it('halaman rekonsiliasi bank: impor lewat halaman dan pencocokan otomatis', function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $branch = App\Models\Branch::create(['code' => 'PUSAT', 'name' => 'Pusat']);
    $fin = User::factory()->create(); $fin->assignRole('finance_admin'); $fin->branches()->attach($branch->id);
    $cashier = User::factory()->create(); $cashier->assignRole('cashier');
    app(App\Domain\Transactions::class)->depositCapital($branch->id, '2026-11-01', 1_000_000, 'bank');
    $this->actingAs($cashier)->get('/admin/rekonsiliasi-bank')->assertForbidden();
    App\Models\BankStatementLine::create(['branch_id' => $branch->id, 'line_date' => '2026-11-02', 'description' => 'Setoran', 'amount' => 1_000_000]);
    Livewire\Livewire::actingAs($fin)->test(App\Filament\Pages\RekonsiliasiBank::class)
        ->callAction(Filament\Actions\Testing\TestAction::make('otomatis')->table())->assertHasNoActionErrors();
    expect(App\Models\BankStatementLine::whereNotNull('journal_line_id')->count())->toBe(1);
});
