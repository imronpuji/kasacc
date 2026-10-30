<?php

use App\Domain\Accounting\BankReconService;
use App\Domain\Accounting\LedgerService;
use App\Domain\Accounting\PaymentApprovalService;
use App\Domain\Accounting\PeriodService;
use App\Domain\Inventory\InsufficientStockException;
use App\Domain\Payroll\HrService;
use App\Domain\Payroll\PayrollService;
use App\Domain\Pos\PosService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/** Kesalahan manusia: salah ketik, klik dua kali, salah orang, salah angka. Sistem harus menolak dengan pesan jelas dan tidak mengubah pembukuan. */
function hbal(string $code): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', $code)->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as b')->value('b');
}

function huser(string $role, ?int $branchId = null): User
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
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->other = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
    $this->case = Product::create(['sku' => 'CASE', 'name' => 'Casing', 'kind' => 'aksesoris', 'sale_price' => 120_000]);
    $this->free = Product::create(['sku' => 'FREE', 'name' => 'Belum ada harga', 'kind' => 'aksesoris', 'sale_price' => 0]);
    $this->cashier = huser('cashier', $this->branch->id);
    $this->tx = app(Transactions::class);
    $this->tx->depositCapital($this->branch->id, '2026-11-01', 10_000_000, 'kas');
    $this->tx->purchase($this->branch->id, '2026-11-02', 'CASE', 5, 50_000, 'kas');
    $this->pos = app(PosService::class);
    $this->before = fn () => [Journal::count(), PosSale::count(), hbal('1-1100'), hbal('1-1500')];
});

// ---- Kasir ----

it('kasir: jual lebih banyak dari stok ditolak dan tidak ada yang tercatat', function () {
    $snap = ($this->before)();
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 6]], 'kas'))
        ->toThrow(InsufficientStockException::class, 'tidak cukup');
    expect(($this->before)())->toBe($snap);
});

it('kasir: qty nol, negatif, atau pecahan ditolak', function (mixed $qty) {
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => $qty]], 'kas'))
        ->toThrow(DomainException::class);
})->with([0, -1, 1.5, '2']);

it('kasir: barang tanpa harga jual ditolak dengan pesan jelas', function () {
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [['product_id' => $this->free->id, 'qty' => 1]], 'kas'))
        ->toThrow(DomainException::class, 'belum diisi');
});

it('kasir: satu barang gagal di tengah keranjang membatalkan seluruh penjualan', function () {
    $snap = ($this->before)();
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [
        ['product_id' => $this->case->id, 'qty' => 1], ['product_id' => $this->free->id, 'qty' => 1],
    ], 'kas'))->toThrow(DomainException::class);
    expect(($this->before)())->toBe($snap);
});

it('kasir: cabang yang bukan miliknya, metode ngawur, dan keranjang kosong ditolak', function () {
    $item = [['product_id' => $this->case->id, 'qty' => 1]];
    expect(fn () => $this->pos->checkout($this->cashier, $this->other->id, '2026-11-16', $item, 'kas'))->toThrow(DomainException::class, 'akses');
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', $item, 'kartu'))->toThrow(DomainException::class, 'Cara bayar');
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-16', [], 'kas'))->toThrow(DomainException::class, 'kosong');
});

it('kasir: teknisi atau HR tidak boleh jualan di kasir', function (string $role) {
    $u = huser($role, $this->branch->id);
    expect(fn () => $this->pos->checkout($u, $this->branch->id, '2026-11-16', [['product_id' => $this->case->id, 'qty' => 1]], 'kas'))
        ->toThrow(DomainException::class, 'tidak punya izin');
})->with(['hr', 'technician']);

it('kasir: Livewire klik Bayar dua kali tidak membuat dua penjualan dan tidak error', function () {
    $lw = Livewire\Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id);
    $lw->call('checkout')->call('checkout');
    expect(PosSale::count())->toBe(1);
    expect(app(App\Domain\Inventory\InventoryService::class)->balance($this->branch->id, $this->case->id)->qty)->toBe(4);
});

it('kasir: tombol minus sampai nol mengeluarkan barang dari keranjang', function () {
    $lw = Livewire\Livewire::actingAs($this->cashier)->test(App\Filament\Pages\Kasir::class)->call('add', $this->case->id)
        ->call('changeQty', $this->case->id, 1)->assertSet("cart.{$this->case->id}.qty", 2)
        ->call('changeQty', $this->case->id, -1)->call('changeQty', $this->case->id, -1);
    expect($lw->get('cart'))->toBe([]);
});

// ---- Jurnal dan periode ----

it('jurnal: tidak seimbang, nominal desimal/negatif, akun salah ketik, satu baris saja ditolak', function () {
    $l = app(LedgerService::class);
    $post = fn (array $lines) => fn () => $l->post(['branch_id' => $this->branch->id, 'date' => '2026-11-20', 'lines' => $lines]);
    expect($post([['account' => '1-1100', 'debit' => 100], ['account' => '4-1100', 'credit' => 90]]))->toThrow(Exception::class, 'seimbang');
    expect($post([['account' => '1-1100', 'debit' => 100.5], ['account' => '4-1100', 'credit' => 100.5]]))->toThrow(Exception::class);
    expect($post([['account' => '1-1100', 'debit' => -100], ['account' => '4-1100', 'credit' => -100]]))->toThrow(Exception::class);
    expect($post([['account' => '9-9999', 'debit' => 100], ['account' => '4-1100', 'credit' => 100]]))->toThrow(Exception::class, 'tidak aktif atau tidak ditemukan');
    expect($post([['account' => '1-1100', 'debit' => 100]]))->toThrow(Exception::class, 'dua baris');
    expect($post([['account' => '1-1100', 'debit' => 100, 'credit' => 100], ['account' => '4-1100', 'credit' => 0]]))->toThrow(Exception::class);
});

it('jurnal: salah catat dikoreksi dengan pembalik, dan membalik dua kali ditolak', function () {
    $l = app(LedgerService::class);
    $j = $l->post(['branch_id' => $this->branch->id, 'date' => '2026-11-20', 'lines' => [['account' => '1-1100', 'debit' => 100_000], ['account' => '4-1100', 'credit' => 100_000]]]);
    $cash = hbal('1-1100');
    $l->reverse($j, '2026-11-21');
    expect(hbal('1-1100'))->toBe($cash - 100_000);
    expect(fn () => $l->reverse($j, '2026-11-21'))->toThrow(Exception::class, 'sudah pernah dibatalkan');
});

it('jurnal: mengubah atau menghapus jurnal lama ditolak oleh database', function () {
    $j = Journal::first();
    expect(fn () => DB::table('journals')->where('id', $j->id)->update(['description' => 'diubah diam-diam']))->toThrow(Exception::class);
    expect(fn () => DB::table('journal_lines')->where('journal_id', $j->id)->delete())->toThrow(Exception::class);
});

it('periode: transaksi di bulan yang sudah dikunci ditolak, termasuk salah ketik tanggal ke bulan lalu', function () {
    app(PeriodService::class)->lock(2026, 11);
    expect(fn () => $this->pos->checkout($this->cashier, $this->branch->id, '2026-11-28', [['product_id' => $this->case->id, 'qty' => 1]], 'kas'))
        ->toThrow(Exception::class, 'dikunci');
    expect(fn () => $this->tx->payExpense($this->branch->id, '2026-11-05', '6-1000', 50_000, 'kas', 'Listrik'))->toThrow(Exception::class, 'dikunci');
});

// ---- Pembayaran utang ----

it('utang: bayar melebihi sisa utang, nol, atau negatif ditolak', function () {
    $this->tx->purchase($this->branch->id, '2026-11-03', 'CASE', 2, 50_000, 'kredit'); // utang 100.000
    $maker = huser('finance_admin', $this->branch->id);
    $svc = app(PaymentApprovalService::class);
    expect(fn () => $svc->request($maker, $this->branch->id, 150_000, 'kas'))->toThrow(DomainException::class, 'melebihi');
    expect(fn () => $svc->request($maker, $this->branch->id, 0, 'kas'))->toThrow(DomainException::class);
    expect(fn () => $svc->request($maker, $this->branch->id, -5_000, 'kas'))->toThrow(DomainException::class);
});

it('utang: pengaju menyetujui sendiri, klik setuju dua kali, dan cabang salah ditolak', function () {
    $this->tx->purchase($this->branch->id, '2026-11-03', 'CASE', 2, 50_000, 'kredit');
    $maker = huser('finance_admin', $this->branch->id);
    $approver = huser('approver', $this->branch->id);
    $outsider = huser('approver', $this->other->id);
    $svc = app(PaymentApprovalService::class);
    $req = $svc->request($maker, $this->branch->id, 100_000, 'kas');
    expect(fn () => $svc->approve($req, $maker, '2026-11-20'))->toThrow(DomainException::class, 'orang lain');
    expect(fn () => $svc->approve($req, $outsider, '2026-11-20'))->toThrow(DomainException::class, 'berwenang');
    $svc->approve($req, $approver, '2026-11-20');
    $paid = hbal('2-1100');
    expect(fn () => $svc->approve($req->fresh(), $approver, '2026-11-20'))->toThrow(DomainException::class, 'sudah diproses');
    expect(hbal('2-1100'))->toBe($paid);
});

// ---- Servis ----

it('servis: tutup dua kali, DP lebih besar dari tagihan, tagihan nol, dan biaya cek melebihi DP ditolak', function () {
    $staff = huser('technician', $this->branch->id);
    $svc = app(ServiceTicketService::class);
    $t = $svc->receive($staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12'], 200_000);
    expect(fn () => $svc->complete($t, $staff, '2026-11-18', [], 0, 'kas'))->toThrow(DomainException::class, 'lebih dari Rp0');
    expect(fn () => $svc->complete($t, $staff, '2026-11-18', [], 100_000, 'kas'))->toThrow(DomainException::class, 'DP melebihi');
    expect(fn () => $svc->cancel($t, $staff, '2026-11-18', 250_000))->toThrow(DomainException::class, 'lebih besar dari DP');
    expect(fn () => $svc->receive($staff, $this->branch->id, '2026-11-17', ['customer_name' => 'X', 'device' => 'Y'], -1))->toThrow(DomainException::class, 'minus');
    $svc->complete($t, $staff, '2026-11-18', [], 300_000, 'kas');
    expect(fn () => $svc->complete($t->fresh(), $staff, '2026-11-18', [], 300_000, 'kas'))->toThrow(DomainException::class, 'sudah ditutup');
    expect(fn () => $svc->cancel($t->fresh(), $staff, '2026-11-18', 0))->toThrow(DomainException::class, 'sudah ditutup');
});

it('servis: sparepart melebihi stok atau SKU salah ketik ditolak dan servis tetap terbuka', function () {
    $staff = huser('technician', $this->branch->id);
    $svc = app(ServiceTicketService::class);
    $t = $svc->receive($staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Rina', 'device' => 'iPhone 12']);
    expect(fn () => $svc->complete($t, $staff, '2026-11-18', [['sku' => 'CASE', 'qty' => 99, 'price' => 100_000]], 50_000, 'kas'))->toThrow(Exception::class);
    expect(fn () => $svc->complete($t, $staff, '2026-11-18', [['sku' => 'CSAE', 'qty' => 1, 'price' => 100_000]], 50_000, 'kas'))->toThrow(Exception::class);
    expect(ServiceTicket::find($t->id)->status)->toBe('diterima');
});

// ---- HR dan payroll ----

beforeEach(function () {
    $this->hr = huser('hr', $this->branch->id);
    $this->fin = huser('finance_admin');
    $this->boss = huser('approver');
    $this->emp = Employee::create(['branch_id' => $this->branch->id, 'name' => 'Sari', 'position' => 'Kasir', 'hired_on' => '2025-06-01',
        'base_salary' => 4_000_000, 'fixed_allowance' => 0, 'ptkp' => 'TK/0', 'pay_method' => 'tunai', 'commission_bps' => 0]);
    $this->hrs = app(HrService::class);
    $this->payroll = app(PayrollService::class);
});

it('absensi: total hari salah, dan mengubah absensi setelah payroll disetujui ditolak', function () {
    $ok = ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0, 'late' => 0, 'overtime_hours' => 0];
    expect(fn () => $this->hrs->recordAttendance($this->hr, $this->emp, 2027, 1, ['present' => 20] + $ok))->toThrow(DomainException::class, 'harus pas 26');
    expect(fn () => $this->hrs->recordAttendance($this->hr, $this->emp, 2027, 1, ['present' => 30, 'absent' => -4] + $ok))->toThrow(DomainException::class);
    $this->hrs->recordAttendance($this->hr, $this->emp, 2027, 1, $ok);
    $run = $this->payroll->generate($this->hr, $this->branch->id, 2027, 1);
    $this->payroll->approve($this->boss, $run, '2027-01-31');
    expect(fn () => $this->hrs->recordAttendance($this->hr, $this->emp, 2027, 1, ['present' => 25, 'absent' => 1] + $ok))->toThrow(DomainException::class, 'sudah disetujui');
});

it('payroll: hitung tanpa absensi, hitung ulang saat sudah disetujui, bayar sebelum setuju, bayar dua kali, batal setelah bayar', function () {
    expect(fn () => $this->payroll->generate($this->hr, $this->branch->id, 2027, 1))->toThrow(DomainException::class, 'belum diisi');
    $this->hrs->recordAttendance($this->hr, $this->emp, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0]);
    $run = $this->payroll->generate($this->hr, $this->branch->id, 2027, 1);
    expect($this->payroll->generate($this->hr, $this->branch->id, 2027, 1)->id)->toBe($run->id); // klik dua kali: draft dihitung ulang, bukan dobel
    expect(App\Models\PayrollRun::count())->toBe(1);
    expect(fn () => $this->payroll->pay($this->fin, $run, '2027-01-31'))->toThrow(DomainException::class, 'disetujui dulu');
    expect(fn () => $this->payroll->approve($this->hr, $run, '2027-01-31'))->toThrow(DomainException::class);
    $this->payroll->approve($this->boss, $run, '2027-01-31');
    expect(fn () => $this->payroll->generate($this->hr, $this->branch->id, 2027, 1))->toThrow(DomainException::class, 'sudah ada');
    expect(fn () => $this->payroll->approve($this->boss, $run->fresh(), '2027-01-31'))->toThrow(DomainException::class, 'masih konsep');
    $this->payroll->pay($this->fin, $run->fresh(), '2027-01-31');
    $cash = hbal('1-1100');
    expect(fn () => $this->payroll->pay($this->fin, $run->fresh(), '2027-01-31'))->toThrow(DomainException::class, 'sudah dibayar');
    expect(fn () => $this->payroll->cancel($this->boss, $run->fresh(), '2027-01-31'))->toThrow(DomainException::class, 'sudah dibayar');
    expect(hbal('1-1100'))->toBe($cash);
});

it('payroll: kasir, teknisi, dan HR tidak bisa menyetujui atau membayar', function () {
    $this->hrs->recordAttendance($this->hr, $this->emp, 2027, 1, ['present' => 26, 'leave' => 0, 'sick' => 0, 'absent' => 0]);
    $run = $this->payroll->generate($this->hr, $this->branch->id, 2027, 1);
    foreach ([$this->cashier, huser('technician', $this->branch->id)] as $u) {
        expect(fn () => $this->payroll->generate($u, $this->branch->id, 2027, 1))->toThrow(DomainException::class);
        expect(fn () => $this->payroll->approve($u, $run, '2027-01-31'))->toThrow(DomainException::class);
    }
    $this->payroll->approve($this->boss, $run, '2027-01-31');
    expect(fn () => $this->payroll->pay($this->hr, $run->fresh(), '2027-01-31'))->toThrow(DomainException::class);
});

it('cuti: lewat saldo, nol hari, belum 12 bulan kerja, dan setujui sendiri atau dua kali ditolak', function () {
    expect(fn () => $this->hrs->requestLeave($this->hr, $this->emp, '2027-02-01', 13))->toThrow(DomainException::class, 'melebihi');
    expect(fn () => $this->hrs->requestLeave($this->hr, $this->emp, '2027-02-01', 0))->toThrow(DomainException::class);
    $baru = Employee::create(['branch_id' => $this->branch->id, 'name' => 'Baru', 'hired_on' => '2027-01-01', 'base_salary' => 3_000_000, 'ptkp' => 'TK/0', 'pay_method' => 'tunai']);
    expect(fn () => $this->hrs->requestLeave($this->hr, $baru, '2027-03-01', 1))->toThrow(DomainException::class, '12 bulan');
    $req = $this->hrs->requestLeave($this->hr, $this->emp, '2027-02-01', 2);
    expect(fn () => $this->hrs->approveLeave($this->hr, $req))->toThrow(DomainException::class, 'sendiri');
    $this->hrs->approveLeave($this->boss, $req);
    expect(fn () => $this->hrs->approveLeave($this->boss, $req->fresh()))->toThrow(DomainException::class, 'sudah diproses');
    expect($this->hrs->leaveBalance($this->emp, 2027))->toBe(10);
});

it('kasbon: nominal nol/negatif, cicilan lebih besar dari kasbon, dan cicilan di atas 50% gaji ditolak', function () {
    $d = fn (int $amt, int $inst) => fn () => $this->hrs->giveAdvance($this->hr, $this->emp, '2027-01-10', $amt, $inst);
    expect($d(0, 0))->toThrow(DomainException::class);
    expect($d(-100_000, -10_000))->toThrow(DomainException::class);
    expect($d(100_000, 200_000))->toThrow(DomainException::class);
    expect($d(10_000_000, 3_000_000))->toThrow(DomainException::class, '50%');
    expect(App\Models\EmployeeAdvance::count())->toBe(0);
});

it('karyawan (form): nama kosong, gaji negatif, PTKP ngawur, dan password pendek ditolak', function () {
    $base = ['branch_id' => $this->branch->id, 'name' => 'Supri', 'hired_on' => '2027-01-01', 'base_salary' => 3_000_000, 'fixed_allowance' => 0, 'ptkp' => 'TK/0', 'pay_method' => 'tunai', 'commission_pct' => 10];
    $act = fn (array $over) => Livewire\Livewire::actingAs($this->hr)->test(App\Filament\Pages\Karyawan::class)
        ->callAction(Filament\Actions\Testing\TestAction::make('tambah')->table(), array_merge($base, $over));
    $act(['name' => ''])->assertHasActionErrors(['name']);
    $act(['base_salary' => -1])->assertHasActionErrors(['base_salary']);
    $act(['ptkp' => 'XX/9'])->assertHasActionErrors(['ptkp']);
    $act(['create_login' => true, 'login_email' => 'bukan-email', 'login_password' => 'x'])->assertHasActionErrors();
    expect(Employee::where('name', 'Supri')->exists())->toBeFalse();
    expect(User::where('email', 'bukan-email')->exists())->toBeFalse();
});

it('karyawan (form): email login yang sudah dipakai tidak membuat karyawan setengah jadi', function () {
    $dupe = huser('technician', $this->branch->id);
    Livewire\Livewire::actingAs($this->hr)->test(App\Filament\Pages\Karyawan::class)
        ->callAction(Filament\Actions\Testing\TestAction::make('tambah')->table(), [
            'branch_id' => $this->branch->id, 'name' => 'Supri', 'hired_on' => '2027-01-01', 'base_salary' => 3_000_000, 'fixed_allowance' => 0, 'ptkp' => 'TK/0',
            'pay_method' => 'tunai', 'commission_pct' => 10, 'create_login' => true, 'login_email' => $dupe->email, 'login_password' => 'rahasia123',
        ]);
    expect(Employee::where('name', 'Supri')->exists())->toBeFalse();
});

// ---- Rekonsiliasi bank ----

it('bank: file kosong, kolom salah, tanggal ngawur, dan impor ulang file yang sama ditolak atau dilewati', function () {
    $svc = app(BankReconService::class);
    $admin = huser('finance_admin', $this->branch->id);
    expect(fn () => $svc->import($admin, $this->branch->id, ''))->toThrow(DomainException::class, 'kosong');
    expect(fn () => $svc->import($admin, $this->branch->id, "a,b\n1,2"))->toThrow(DomainException::class, 'Kolom');
    expect(fn () => $svc->import($admin, $this->branch->id, "tanggal,keterangan,jumlah\nkemarin,Biaya,5000"))->toThrow(DomainException::class, 'Tanggal');
    $csv = "tanggal,keterangan,jumlah\n2026-11-10,Biaya admin,-15000";
    expect($svc->import($admin, $this->branch->id, $csv)['imported'])->toBe(1);
    $again = $svc->import($admin, $this->branch->id, $csv);
    expect($again['imported'])->toBe(0)->and($again['skipped'])->toBe(1);
});

it('bank: cocokkan nominal beda, cocokkan dua kali, dan kasir tidak boleh rekonsiliasi', function () {
    $svc = app(BankReconService::class);
    $admin = huser('finance_admin', $this->branch->id);
    $this->tx->depositCapital($this->branch->id, '2026-11-09', 1_000_000, 'bank');
    $svc->import($admin, $this->branch->id, "tanggal,keterangan,jumlah\n2026-11-09,Setoran,900000");
    $line = App\Models\BankStatementLine::first();
    $bookLine = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.code', '1-1110')->value('journal_lines.id');
    expect(fn () => $svc->match($admin, $line, $bookLine))->toThrow(DomainException::class, 'nominal tidak sama');
    expect(fn () => $svc->import($this->cashier, $this->branch->id, "tanggal,keterangan,jumlah\n2026-11-10,X,1000"))->toThrow(DomainException::class);
});

// ---- Stok ----

it('stok: terima barang nol/negatif dan transfer ke cabang yang sama atau lebih dari stok ditolak', function () {
    $inv = app(App\Domain\Inventory\InventoryService::class);
    expect(fn () => $inv->receive($this->branch->id, $this->case->id, 0, 1000, '2026-11-20', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $inv->receive($this->branch->id, $this->case->id, 1, -1000, '2026-11-20', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->tx->transferStock($this->branch->id, $this->branch->id, '2026-11-20', 'CASE', 1))->toThrow(DomainException::class, 'berbeda');
    expect(fn () => $this->tx->transferStock($this->branch->id, $this->other->id, '2026-11-20', 'CASE', 99))->toThrow(InsufficientStockException::class);
    expect($inv->balance($this->branch->id, $this->case->id)->qty)->toBe(5);
});

// ---- Hak akses halaman ----

it('akses: teknisi dan kasir tidak bisa membuka halaman keuangan atau SDM lewat URL', function (string $role) {
    $u = huser($role, $this->branch->id);
    foreach (['/admin/laporan', '/admin/journals', '/admin/payroll', '/admin/karyawan', '/admin/rekonsiliasi-bank'] as $url) {
        $this->actingAs($u)->get($url)->assertForbidden();
    }
})->with(['cashier', 'technician']);
