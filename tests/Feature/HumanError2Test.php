<?php

use App\Domain\Accounting\BankReconService;
use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\PaymentApprovalService;
use App\Domain\Accounting\PeriodService;
use App\Domain\Inventory\InsufficientStockException;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payroll\HrService;
use App\Domain\Payroll\PayrollService;
use App\Domain\Pos\PosService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Journal;
use App\Models\PaymentRequest;
use App\Models\PayrollRun;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Gelombang 2 tes kesalahan manusia: input aneh, orang salah peran, urutan langkah terbalik, dan data yang dipalsukan lewat form. */
function ebal(string $code): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', $code)->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as b')->value('b');
}

function euser(string $role, ?int $branchId = null): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    if ($branchId) {
        $u->branches()->attach($branchId);
    }

    return $u;
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->a = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->b = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
    $this->case = Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    $this->bat = Product::create(['sku' => 'BAT12', 'name' => 'Baterai iPhone 12', 'kind' => 'sparepart', 'sale_price' => 700_000]);
    $this->cashier = euser('cashier', $this->a->id);
    $this->tech = euser('technician', $this->a->id);
    $this->owner = euser('owner');
    $this->tx = app(Transactions::class);
    $this->tx->depositCapital($this->a->id, '2026-11-01', 20_000_000, 'kas');
    $this->tx->depositCapital($this->a->id, '2026-11-01', 20_000_000, 'bank');
    $this->tx->purchase($this->a->id, '2026-11-02', 'CASE', 5, 50_000, 'kas');
    $this->tx->purchase($this->a->id, '2026-11-02', 'BAT12', 3, 400_000, 'kas');
    $this->inv = app(InventoryService::class);
    $this->pos = app(PosService::class);
    $this->snap = fn () => [Journal::count(), PosSale::count(), ebal('1-1100'), ebal('1-1110'), ebal('1-1500'), $this->inv->balance($this->a->id, $this->case->id)->qty];
});

// ---- Transaksi dasar ----

it('setor modal dan biaya dengan nominal nol, negatif, atau akun ngawur ditolak', function () {
    $s = ($this->snap)();
    expect(fn () => $this->tx->depositCapital($this->a->id, '2026-11-05', 0, 'kas'))->toThrow(Exception::class);
    expect(fn () => $this->tx->depositCapital($this->a->id, '2026-11-05', -5_000, 'bank'))->toThrow(Exception::class);
    expect(fn () => $this->tx->payExpense($this->a->id, '2026-11-05', '8-9999', 10_000, 'kas', 'Listrik'))->toThrow(Exception::class, 'tidak aktif atau tidak ditemukan');
    expect(fn () => $this->tx->payExpense($this->a->id, '2026-11-05', '6-1000', 0, 'kas', 'Listrik'))->toThrow(Exception::class);
    expect(($this->snap)())->toBe($s);
});

it('pembelian: qty nol, harga negatif, dan SKU salah ketik ditolak tanpa mengubah stok', function () {
    $s = ($this->snap)();
    expect(fn () => $this->tx->purchase($this->a->id, '2026-11-05', 'CASE', 0, 50_000, 'kas'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->tx->purchase($this->a->id, '2026-11-05', 'CASE', 1, -50_000, 'kas'))->toThrow(Exception::class);
    expect(fn () => $this->tx->purchase($this->a->id, '2026-11-05', 'CSAE', 1, 50_000, 'kas'))->toThrow(ModelNotFoundException::class);
    expect(($this->snap)())->toBe($s);
});

it('retur ke supplier: lebih dari stok atau SKU salah ditolak, stok dan kas tetap', function () {
    $s = ($this->snap)();
    expect(fn () => $this->tx->returnToSupplier($this->a->id, '2026-11-05', 'CASE', 6, 'kas'))->toThrow(InsufficientStockException::class);
    expect(fn () => $this->tx->returnToSupplier($this->a->id, '2026-11-05', 'XXXX', 1, 'kas'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->tx->returnToSupplier($this->a->id, '2026-11-05', 'CASE', 0, 'kas'))->toThrow(InvalidArgumentException::class);
    expect(($this->snap)())->toBe($s);
});

it('transfer stok: SKU salah, qty nol, atau melebihi stok ditolak', function () {
    $s = ($this->snap)();
    expect(fn () => $this->tx->transferStock($this->a->id, $this->b->id, '2026-11-05', 'XXXX', 1))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->tx->transferStock($this->a->id, $this->b->id, '2026-11-05', 'CASE', 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->tx->transferStock($this->a->id, $this->b->id, '2026-11-05', 'CASE', 6))->toThrow(InsufficientStockException::class);
    expect(($this->snap)())->toBe($s);
});

it('transfer stok yang valid tidak merusak keseimbangan: akun antar cabang saling meniadakan', function () {
    $this->tx->transferStock($this->a->id, $this->b->id, '2026-11-05', 'CASE', 2);
    expect(ebal('1-1600'))->toBe(0);
    expect($this->inv->balance($this->b->id, $this->case->id)->qty)->toBe(2);
});

it('SKU kembar dan akun kembar ditolak database', function () {
    expect(fn () => Product::create(['sku' => 'CASE', 'name' => 'Duplikat', 'kind' => 'aksesoris']))->toThrow(Exception::class);
    expect(fn () => App\Models\Account::create(['code' => '1-1100', 'name' => 'Kas dobel', 'type' => 'asset']))->toThrow(Exception::class);
});

it('akun yang dinonaktifkan tidak bisa dipakai di jurnal baru', function () {
    App\Models\Account::where('code', '6-1000')->update(['is_active' => false]);
    expect(fn () => $this->tx->payExpense($this->a->id, '2026-11-05', '6-1000', 10_000, 'kas', 'Listrik'))->toThrow(Exception::class, 'tidak aktif');
});

// ---- Periode ----

it('periode: non-owner tidak bisa buka kunci, periode yang tak pernah ada ditolak, kunci dua kali aman, buka kunci memulihkan transaksi', function () {
    $p = app(PeriodService::class);
    expect(fn () => $p->unlock(2026, 11, false))->toThrow(DomainException::class, 'pemilik');
    expect(fn () => $p->unlock(2030, 1, true))->toThrow(ModelNotFoundException::class);
    $p->lock(2026, 11);
    $p->lock(2026, 11);
    expect(App\Models\Period::where('year', 2026)->where('month', 11)->count())->toBe(1);
    expect(fn () => $this->tx->payExpense($this->a->id, '2026-11-20', '6-1000', 10_000, 'kas', 'x'))->toThrow(Exception::class, 'dikunci');
    $p->unlock(2026, 11, true);
    expect($this->tx->payExpense($this->a->id, '2026-11-20', '6-1000', 10_000, 'kas', 'x'))->toBeInstanceOf(Journal::class);
});

it('periode: membalik jurnal pada bulan terkunci ditolak', function () {
    $j = $this->tx->payExpense($this->a->id, '2026-11-10', '6-1000', 10_000, 'kas', 'Listrik');
    app(PeriodService::class)->lock(2026, 11);
    expect(fn () => app(LedgerService::class)->reverse($j, '2026-11-25'))->toThrow(Exception::class, 'dikunci');
});

// ---- Kasir lewat layar (Livewire) ----

it('kasir layar: barang tanpa harga tidak masuk keranjang, keranjang kosong tidak bisa dibayar', function () {
    $free = Product::create(['sku' => 'FREE', 'name' => 'Belum ada harga', 'kind' => 'aksesoris', 'sale_price' => 0]);
    $s = ($this->snap)();
    $lw = Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $free->id);
    expect($lw->get('cart'))->toBe([]);
    $lw->call('checkout');
    expect(($this->snap)())->toBe($s);
});

it('kasir layar: ganti cabang ke cabang lain lewat data palsu, metode palsu, atau qty palsu tidak membuat penjualan', function () {
    $s = ($this->snap)();
    Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id)->set('branchId', $this->b->id)->call('checkout');
    Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id)->set('method', 'kartu-kredit')->call('checkout');
    Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id)->set("cart.{$this->case->id}.qty", -5)->call('checkout');
    Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id)->set("cart.{$this->case->id}.qty", 'banyak')->call('checkout');
    Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id)->set("cart.{$this->case->id}.price", 1)->call('checkout');
    expect(PosSale::where('total', '<', 100_000)->exists())->toBeFalse(); // harga tidak bisa diakali murah lewat data palsu
});

it('kasir layar: pencarian dengan karakter aneh tidak membuat error', function (string $q) {
    Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->set('search', $q)->assertSuccessful();
})->with(['%', "'; DROP TABLE products;--", '\\', str_repeat('x', 500), '   ', '😀']);

it('kasir: jual persis sebanyak stok berhasil, satu lagi ditolak (batas stok)', function () {
    $this->pos->checkout($this->cashier, $this->a->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 5]], 'kas');
    expect($this->inv->balance($this->a->id, $this->case->id)->qty)->toBe(0);
    expect(fn () => $this->pos->checkout($this->cashier, $this->a->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas'))
        ->toThrow(InsufficientStockException::class);
});

it('kasir: harga override nol atau negatif ditolak', function (int $price) {
    expect(fn () => $this->pos->checkout($this->cashier, $this->a->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1, 'price' => $price]], 'kas'))->toThrow(DomainException::class);
})->with([0, -100]);

it('struk: pengguna cabang lain, teknisi, tamu, dan nomor ngawur tidak bisa membuka', function () {
    $sale = $this->pos->checkout($this->cashier, $this->a->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas');
    $this->actingAs($this->cashier)->get(route('struk', $sale->receipt_no))->assertOk();
    $this->actingAs(euser('cashier', $this->b->id))->get(route('struk', $sale->receipt_no))->assertForbidden();
    $this->actingAs($this->tech)->get(route('struk', $sale->receipt_no))->assertForbidden();
    $this->actingAs($this->cashier)->get(route('struk', 'POS-NGAWUR-0001'))->assertNotFound();
    auth()->logout();
    $this->get(route('struk', $sale->receipt_no))->assertForbidden();
});

// ---- Pembayaran utang ----

it('utang: dua pengajuan yang jumlahnya melebihi utang ditolak, dan utang yang sudah dibayar orang lain menggagalkan persetujuan', function () {
    $this->tx->purchase($this->a->id, '2026-11-03', 'CASE', 2, 50_000, 'kredit'); // utang 100.000
    $maker = euser('finance_admin', $this->a->id);
    $approver = euser('approver', $this->a->id);
    $svc = app(PaymentApprovalService::class);
    $r1 = $svc->request($maker, $this->a->id, 60_000, 'kas');
    expect(fn () => $svc->request($maker, $this->a->id, 60_000, 'kas'))->toThrow(DomainException::class, 'melebihi');
    $this->tx->payPayable($this->a->id, '2026-11-04', 80_000, 'kas'); // dibayar lewat jalur lain
    expect(fn () => $svc->approve($r1, $approver, '2026-11-05'))->toThrow(DomainException::class, 'sudah berkurang');
    expect(PaymentRequest::find($r1->id)->status)->toBe('pending');
});

it('utang: pengaju di cabang yang bukan miliknya ditolak', function () {
    $maker = euser('finance_admin', $this->a->id);
    expect(fn () => app(PaymentApprovalService::class)->request($maker, $this->b->id, 1_000, 'kas'))->toThrow(DomainException::class, 'akses');
});

// ---- Servis ----

it('servis: peran HR dan staf cabang lain tidak boleh, kasir cabang sendiri boleh', function () {
    $svc = app(ServiceTicketService::class);
    $data = ['customer_name' => 'Rina', 'device' => 'iPhone 12'];
    expect(fn () => $svc->receive(euser('hr', $this->a->id), $this->a->id, '2026-11-17', $data))->toThrow(DomainException::class, 'izin');
    expect(fn () => $svc->receive(euser('technician', $this->b->id), $this->a->id, '2026-11-17', $data))->toThrow(DomainException::class, 'akses');
    expect($svc->receive($this->cashier, $this->a->id, '2026-11-17', $data))->toBeInstanceOf(ServiceTicket::class);
});

it('garansi: tiket belum selesai, stok kurang, atau SKU salah ditolak, biaya garansi tidak berubah', function () {
    $svc = app(ServiceTicketService::class);
    $t = $svc->receive($this->tech, $this->a->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12']);
    expect(fn () => $svc->claimWarranty($t, $this->tech, '2026-11-18', 'BAT12'))->toThrow(DomainException::class, 'sudah selesai');
    $t = $svc->complete($t, $this->tech, '2026-11-18', [['sku' => 'BAT12', 'qty' => 1, 'price' => 700_000]], 100_000, 'kas');
    expect(fn () => $svc->claimWarranty($t, $this->tech, '2026-11-19', 'BAT12', 5))->toThrow(InsufficientStockException::class);
    expect(fn () => $svc->claimWarranty($t, $this->tech, '2026-11-19', 'NGAWUR'))->toThrow(ModelNotFoundException::class);
    expect((int) $t->fresh()->warranty_cost)->toBe(0);
});

it('servis layar: nama kosong, DP negatif, dan harga sparepart nol ditolak oleh form', function () {
    $lw = fn () => Livewire::actingAs($this->owner)->test(App\Filament\Pages\Servis::class);
    $lw()->callAction(TestAction::make('terima')->table(), ['branch_id' => $this->a->id, 'customer_name' => '', 'device' => 'iPhone'])->assertHasActionErrors(['customer_name']);
    $lw()->callAction(TestAction::make('terima')->table(), ['branch_id' => $this->a->id, 'customer_name' => 'Rina', 'device' => '', 'deposit' => -5])->assertHasActionErrors(['device', 'deposit']);
    expect(ServiceTicket::count())->toBe(0);
});

// ---- Stok layar ----

it('stok layar: qty nol, harga beli nol, dan retur melebihi stok tidak mengubah apa pun', function () {
    $s = ($this->snap)();
    $lw = fn () => Livewire::actingAs($this->owner)->test(App\Filament\Pages\Stok::class);
    $lw()->callAction(TestAction::make('pembelian')->table(), ['branch_id' => $this->a->id, 'sku' => 'CASE', 'qty' => 0, 'unit_cost' => 0, 'method' => 'kas'])->assertHasActionErrors(['qty', 'unit_cost']);
    $lw()->callAction(TestAction::make('retur')->table(), ['branch_id' => $this->a->id, 'sku' => 'CASE', 'qty' => 99, 'method' => 'kas']);
    $lw()->callAction(TestAction::make('transfer')->table(), ['from' => $this->a->id, 'to' => $this->a->id, 'sku' => 'CASE', 'qty' => 1]);
    $lw()->callAction(TestAction::make('pembelian')->table(), ['branch_id' => $this->a->id, 'sku' => 'SALAHKETIK', 'qty' => 1, 'unit_cost' => 1000, 'method' => 'kas']);
    expect(($this->snap)())->toBe($s);
});

it('stok layar: staf pembelian cabang lain tidak bisa membeli untuk cabang yang bukan miliknya', function () {
    $fin = euser('finance_admin', $this->b->id);
    $s = ($this->snap)();
    Livewire::actingAs($fin)->test(App\Filament\Pages\Stok::class)
        ->callAction(TestAction::make('pembelian')->table(), ['branch_id' => $this->a->id, 'sku' => 'CASE', 'qty' => 1, 'unit_cost' => 1000, 'method' => 'kas']);
    expect(($this->snap)())->toBe($s);
});

// ---- HR dan payroll ----

beforeEach(function () {
    $this->hr = euser('hr', $this->a->id);
    $this->fin = euser('finance_admin');
    $this->boss = euser('approver');
    $this->hrs = app(HrService::class);
    $this->payroll = app(PayrollService::class);
    $this->mk = fn (string $n, string $hired = '2025-06-01', int $base = 4_000_000, array $x = []) => Employee::create($x + [
        'branch_id' => $this->a->id, 'name' => $n, 'position' => 'Kasir', 'hired_on' => $hired, 'base_salary' => $base,
        'fixed_allowance' => 0, 'ptkp' => 'TK/0', 'pay_method' => 'tunai', 'commission_bps' => 0]);
    $this->full = ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0];
});

it('payroll: karyawan nonaktif dan yang baru masuk setelah akhir bulan tidak ikut dihitung', function () {
    $sari = ($this->mk)('Sari');
    $lama = ($this->mk)('Lama', '2024-01-01', 4_000_000, ['is_active' => false]);
    $baru = ($this->mk)('Baru', '2027-02-10');
    $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, $this->full);
    $run = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    expect($run->slips)->toHaveCount(1)->and($run->slips->first()->employee_id)->toBe($sari->id);
});

it('payroll: tidak ada karyawan aktif memberi pesan jelas', function () {
    expect(fn () => $this->payroll->generate($this->hr, $this->a->id, 2027, 1))->toThrow(DomainException::class, 'Belum ada karyawan');
});

it('payroll: salah gaji di draft bisa dikoreksi dengan hitung ulang, slip memakai gaji baru', function () {
    $sari = ($this->mk)('Sari', '2025-06-01', 4_000_000);
    $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, $this->full);
    $run = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    $before = (int) $run->slips->first()->income;
    $sari->update(['base_salary' => 4_500_000]);
    $run = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    expect((int) $run->slips->first()->income)->toBeGreaterThan($before);
    expect(PayrollRun::count())->toBe(1);
});

it('payroll: batal setelah disetujui membalik jurnal, mengembalikan kasbon, dan boleh dihitung ulang', function () {
    $sari = ($this->mk)('Sari');
    $adv = $this->hrs->giveAdvance($this->hr, $sari, '2027-01-05', 600_000, 300_000);
    $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, $this->full);
    $run = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    $this->payroll->approve($this->boss, $run, '2027-01-31');
    expect((int) EmployeeAdvance::find($adv->id)->balance)->toBe(300_000);
    $this->payroll->cancel($this->boss, $run->fresh(), '2027-02-01');
    expect((int) EmployeeAdvance::find($adv->id)->balance)->toBe(600_000);
    expect(ebal('2-1200'))->toBe(0); // utang gaji nol lagi
    $again = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    expect($again->status)->toBe('draft')->and($again->id)->not->toBe($run->id);
    expect(fn () => $this->payroll->cancel($this->boss, $run->fresh(), '2027-02-01'))->toThrow(DomainException::class, 'sudah dibatalkan');
});

it('absensi: teks, lembur negatif, dan terlambat negatif ditolak', function () {
    $sari = ($this->mk)('Sari');
    expect(fn () => $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, ['present' => 'abc'] + $this->full))->toThrow(DomainException::class);
    expect(fn () => $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, ['overtime_hours' => -3] + $this->full))->toThrow(DomainException::class);
    expect(fn () => $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, ['late' => -1] + $this->full))->toThrow(DomainException::class);
    expect(App\Models\Attendance::count())->toBe(0);
});

it('absensi: kasir dan teknisi tidak boleh mengisi absensi', function () {
    $sari = ($this->mk)('Sari');
    expect(fn () => $this->hrs->recordAttendance($this->cashier, $sari, 2027, 1, $this->full))->toThrow(DomainException::class, 'berwenang');
    expect(fn () => $this->hrs->recordAttendance($this->tech, $sari, 2027, 1, $this->full))->toThrow(DomainException::class, 'berwenang');
});

it('cuti: dua pengajuan berturut yang jumlahnya melebihi saldo, dan HR tanpa hak setuju, ditolak', function () {
    $sari = ($this->mk)('Sari');
    $this->hrs->requestLeave($this->hr, $sari, '2027-02-01', 8);
    expect(fn () => $this->hrs->requestLeave($this->hr, $sari, '2027-03-01', 5))->toThrow(DomainException::class, 'melebihi');
    $req = $this->hrs->requestLeave($this->hr, $sari, '2027-03-01', 4);
    expect(fn () => $this->hrs->approveLeave(euser('hr', $this->a->id), $req))->toThrow(DomainException::class, 'berwenang');
});

it('kasbon: cicilan tidak pernah memotong gaji melebihi saldo kasbon', function () {
    $sari = ($this->mk)('Sari');
    $this->hrs->giveAdvance($this->hr, $sari, '2027-01-05', 400_000, 300_000);
    $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, $this->full);
    $run = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    $this->payroll->approve($this->boss, $run, '2027-01-31');
    $this->hrs->recordAttendance($this->hr, $sari, 2027, 2, $this->full);
    $run2 = $this->payroll->generate($this->hr, $this->a->id, 2027, 2);
    $this->payroll->approve($this->boss, $run2, '2027-02-28');
    expect((int) EmployeeAdvance::first()->balance)->toBe(0)->and(EmployeeAdvance::first()->status)->not->toBe('aktif');
});

it('slip gaji: tamu, teknisi lain, dan slip yang belum disetujui tidak bisa dibuka karyawan; nomor ngawur 404', function () {
    $uSari = euser('cashier', $this->a->id);
    $sari = ($this->mk)('Sari', '2025-06-01', 4_000_000, ['user_id' => $uSari->id]);
    $this->hrs->recordAttendance($this->hr, $sari, 2027, 1, $this->full);
    $run = $this->payroll->generate($this->hr, $this->a->id, 2027, 1);
    $this->actingAs($uSari)->get(route('slip', $run->id))->assertForbidden(); // masih draft
    $this->payroll->approve($this->boss, $run, '2027-01-31');
    $this->actingAs($uSari)->get(route('slip', $run->id))->assertOk();
    $this->actingAs($this->tech)->get(route('slip', $run->id))->assertForbidden(); // slip orang lain
    $this->actingAs($this->hr)->get(route('slip', $run->id))->assertOk();
    $this->actingAs($this->boss)->get(route('slip', 99999))->assertNotFound();
    auth()->logout();
    $this->get(route('slip', $run->id))->assertRedirect(); // tamu diarahkan ke halaman masuk
});

// ---- Rekonsiliasi bank ----

it('bank: format Indonesia (titik koma, Rp, titik ribuan, tanda kurung) dibaca dengan benar', function () {
    $svc = app(BankReconService::class);
    $fin = euser('finance_admin', $this->a->id);
    $csv = "Tanggal;Keterangan;Jumlah\n30/11/2026;Setoran tunai;Rp 1.500.000\n01/12/2026;Biaya admin;(15.000)\n02/12/2026;Bunga;2.500,00\n03/12/2026;Nol;0";
    $svc->import($fin, $this->a->id, $csv);
    expect(BankStatementLine::orderBy('line_date')->pluck('amount')->map(fn ($v) => (int) $v)->all())->toBe([1_500_000, -15_000, 2_500]);
});

it('bank: kolom debit dan kredit terpisah dibaca dengan tanda yang benar', function () {
    $svc = app(BankReconService::class);
    $fin = euser('finance_admin', $this->a->id);
    $svc->import($fin, $this->a->id, "tanggal,keterangan,debit,kredit\n2026-11-10,Bayar listrik,200000,\n2026-11-11,Setoran,,500000");
    expect(BankStatementLine::orderBy('line_date')->pluck('amount')->map(fn ($v) => (int) $v)->all())->toBe([-200_000, 500_000]);
});

it('bank: dua baris identik dalam satu file dua-duanya masuk, impor ulang keduanya dilewati; file hanya judul ditolak', function () {
    $svc = app(BankReconService::class);
    $fin = euser('finance_admin', $this->a->id);
    $csv = "tanggal,keterangan,jumlah\n2026-11-10,Biaya admin,-15000\n2026-11-10,Biaya admin,-15000";
    expect($svc->import($fin, $this->a->id, $csv))->toBe(['imported' => 2, 'skipped' => 0]);
    expect($svc->import($fin, $this->a->id, $csv))->toBe(['imported' => 0, 'skipped' => 2]);
    expect(fn () => $svc->import($fin, $this->a->id, 'tanggal,keterangan,jumlah'))->toThrow(DomainException::class);
});

it('bank: catat ke pembukuan dengan akun Bank, akun ngawur, atau diulang ditolak', function () {
    $svc = app(BankReconService::class);
    $fin = euser('finance_admin', $this->a->id);
    $svc->import($fin, $this->a->id, "tanggal,keterangan,jumlah\n2026-11-10,Biaya admin,-15000");
    $line = BankStatementLine::first();
    expect(fn () => $svc->recordBankOnly($fin, $line, '1-1110'))->toThrow(DomainException::class, 'selain rekening bank');
    expect(fn () => $svc->recordBankOnly($fin, $line, '9-9999'))->toThrow(Exception::class);
    expect(Journal::where('source', 'bank_recon')->count())->toBe(0);
    $svc->recordBankOnly($fin, $line, '6-1000');
    expect(fn () => $svc->recordBankOnly($fin, $line->fresh(), '6-1000'))->toThrow(DomainException::class, 'sudah dicocokkan');
    expect(Journal::where('source', 'bank_recon')->count())->toBe(1);
});

it('bank: cocok otomatis tidak melewati toleransi 3 hari dan tidak memakai satu catatan buku untuk dua mutasi', function () {
    $svc = app(BankReconService::class);
    $fin = euser('finance_admin', $this->a->id);
    $this->tx->depositCapital($this->a->id, '2026-11-10', 777_000, 'bank');
    $svc->import($fin, $this->a->id, "tanggal,keterangan,jumlah\n2026-11-20,Terlalu jauh,777000");
    expect($svc->autoMatch($this->a->id)['matched'])->toBe(0);
    $svc->import($fin, $this->a->id, "tanggal,keterangan,jumlah\n2026-11-11,Setoran,777000\n2026-11-12,Setoran lagi,777000");
    expect($svc->autoMatch($this->a->id)['matched'])->toBe(1);
});

it('bank: batal cocok lalu cocokkan ulang ke catatan yang benar berjalan, kasir tidak berwenang', function () {
    $svc = app(BankReconService::class);
    $fin = euser('finance_admin', $this->a->id);
    $this->tx->depositCapital($this->a->id, '2026-11-10', 300_000, 'bank');
    $svc->import($fin, $this->a->id, "tanggal,keterangan,jumlah\n2026-11-10,Setoran,300000");
    $svc->autoMatch($this->a->id);
    $line = BankStatementLine::first();
    expect($line->journal_line_id)->not->toBeNull();
    expect(fn () => $svc->unmatch($this->cashier, $line))->toThrow(DomainException::class);
    $svc->unmatch($fin, $line);
    expect($line->fresh()->journal_line_id)->toBeNull();
    expect($svc->autoMatch($this->a->id)['matched'])->toBe(1);
});

// ---- Akses halaman dan layar ----

it('tamu yang membuka halaman admin diarahkan ke login', function () {
    auth()->logout();
    $this->get('/admin')->assertRedirect();
    $this->get('/admin/kasir')->assertRedirect();
});

it('menu data master (akun, cabang, produk) tidak terbuka untuk kasir, teknisi, dan HR', function (string $role) {
    $u = euser($role, $this->a->id);
    foreach (['/admin/accounts', '/admin/branches', '/admin/products'] as $url) {
        $this->actingAs($u)->get($url)->assertForbidden();
    }
})->with(['cashier', 'technician', 'hr']);

it('akun dan cabang hanya untuk pemilik, produk untuk yang boleh pembelian', function () {
    foreach (['/admin/accounts', '/admin/branches', '/admin/products'] as $url) {
        $this->actingAs($this->owner)->get($url)->assertOk();
    }
    $this->actingAs(euser('finance_admin', $this->a->id))->get('/admin/products')->assertOk();
    $this->actingAs(euser('finance_admin', $this->a->id))->get('/admin/accounts')->assertForbidden();
});

it('halaman per peran: tiap peran hanya membuka halaman miliknya', function () {
    $matrix = [
        'cashier' => ['/admin/kasir' => 200, '/admin/servis' => 200, '/admin/stok' => 403, '/admin/laporan' => 403, '/admin/payroll' => 403, '/admin/karyawan' => 403, '/admin/persetujuan-cuti' => 403],
        'technician' => ['/admin/kasir' => 403, '/admin/servis' => 200, '/admin/stok' => 403, '/admin/laporan' => 403],
        'hr' => ['/admin/kasir' => 403, '/admin/servis' => 403, '/admin/stok' => 403, '/admin/laporan' => 403, '/admin/karyawan' => 200],
    ];
    foreach ($matrix as $role => $urls) {
        $u = euser($role, $this->a->id);
        foreach ($urls as $url => $code) {
            $this->actingAs($u)->get($url)->assertStatus($code);
        }
    }
});

it('jurnal tidak bisa dibuat atau diubah lewat layar', function () {
    $j = Journal::first();
    $this->actingAs($this->owner)->get('/admin/journals/create')->assertNotFound();
    $this->actingAs($this->owner)->get("/admin/journals/{$j->id}/edit")->assertNotFound();
});

it('dashboard: tiap peran bisa membuka tanpa error, kasir tidak melihat angka keuangan, pengguna tanpa cabang aman', function () {
    foreach (['owner', 'approver', 'finance_admin', 'hr', 'cashier', 'technician'] as $role) {
        $this->actingAs(euser($role, $role === 'owner' ? null : $this->a->id))->get('/admin')->assertOk();
    }
    $this->actingAs($this->cashier)->get('/admin')->assertDontSee('Utang usaha')->assertDontSee('Kas dan bank');
    $this->actingAs(euser('finance_admin'))->get('/admin')->assertOk(); // tanpa cabang
});

it('laporan: tanggal ngawur atau terbalik tidak membuat error', function () {
    $lw = Livewire::actingAs($this->owner)->test(App\Filament\Pages\Laporan::class);
    $lw->set('from', '2026-12-31')->set('to', '2026-01-01')->assertSuccessful();
    $lw->set('from', 'bukan tanggal')->assertSuccessful();
    $lw->set('to', '')->set('from', '')->assertSuccessful();
    $lw->set('accountCode', '9-9999')->assertSuccessful();
});

it('panel memakai tema terang tetap supaya teks tidak hilang di komputer bermode gelap', function () {
    expect(Filament\Facades\Filament::getPanel('admin')->hasDarkMode())->toBeFalse();
});

it('nota servis bisa dicetak oleh yang berhak dan menolak cabang lain atau tamu', function () {
    $t = App\Models\ServiceTicket::create([
        'branch_id' => $this->a->id, 'code' => 'SV-NOTA1', 'customer_name' => 'Budi', 'device' => 'iPhone 11', 'complaint' => 'layar pecah',
        'status' => 'diterima', 'deposit' => 100_000, 'received_on' => '2026-11-10',
    ]);
    $this->actingAs($this->owner)->get(route('nota', 'SV-NOTA1'))->assertOk()->assertSee('NOTA SERVIS')->assertSee('Budi')->assertSee('Rp 100.000');
    $this->actingAs($this->hr)->get(route('nota', 'SV-NOTA1'))->assertForbidden();
    $this->actingAs(euser('cashier', $this->b->id))->get(route('nota', 'SV-NOTA1'))->assertForbidden(); // cabang lain
    $this->actingAs($this->cashier)->get(route('nota', 'SV-NOTA1'))->assertOk();
    $this->actingAs($this->owner)->get(route('nota', 'TIDAK-ADA'))->assertNotFound();
    auth()->logout();
    $this->get(route('nota', 'SV-NOTA1'))->assertForbidden();
});
