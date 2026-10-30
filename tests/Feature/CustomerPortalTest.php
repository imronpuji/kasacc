<?php

use App\Domain\Customer\OtpSender;
use App\Domain\Customer\OtpService;
use App\Domain\Service\ServiceTicketService;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

class FakeOtpSender implements OtpSender
{
    public static array $sent = [];

    public function send(string $phone, string $message): void
    {
        self::$sent[] = [$phone, $message];
    }

    public static function lastCode(): ?string
    {
        $last = end(self::$sent);

        return $last && preg_match('/(\d{6})/', $last[1], $m) ? $m[1] : null;
    }
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    FakeOtpSender::$sent = [];
    app()->bind(OtpSender::class, FakeOtpSender::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->staff = User::factory()->create();
    $this->staff->assignRole('cashier');
    $this->staff->branches()->attach($this->branch->id);
    $this->svc = app(ServiceTicketService::class);
    $this->ticket = fn (string $phone = '0812-3456-7890', string $name = 'Rina') => $this->svc->receive(
        $this->staff, $this->branch->id, '2026-11-17', ['customer_name' => $name, 'customer_phone' => $phone, 'device' => 'iPhone 12'], 200_000,
    );
});

it('terima HP dengan nomor: pelanggan dibuat sekali per nomor, token lacak dan riwayat proses tercatat', function () {
    $a = ($this->ticket)('0812-3456-7890');
    $b = ($this->ticket)('+62 812 3456 7890', 'Rina S');

    expect(Customer::count())->toBe(1)->and(Customer::first()->phone)->toBe('081234567890')->and(Customer::first()->name)->toBe('Rina')
        ->and($a->customer_id)->toBe($b->customer_id)->and(strlen($a->public_token))->toBe(32)->and($a->public_token)->not->toBe($b->public_token)
        ->and(DB::table('service_progress_logs')->where('service_ticket_id', $a->id)->count())->toBe(1);
});

it('terima HP tanpa nomor tetap jalan tanpa pelanggan, nomor ngawur ditolak', function () {
    $t = $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'Anon', 'device' => 'iPhone 11']);
    expect($t->customer_id)->toBeNull()->and($t->public_token)->not->toBeNull();
    expect(fn () => $this->svc->receive($this->staff, $this->branch->id, '2026-11-17', ['customer_name' => 'X', 'customer_phone' => '12345', 'device' => 'Y']))
        ->toThrow(DomainException::class, 'tidak valid');
});

it('proses servis diubah staf, tercatat, dan ditolak kalau servis sudah ditutup atau proses ngawur', function () {
    $t = ($this->ticket)();
    $t = $this->svc->updateProgress($t, $this->staff, 'menunggu_sparepart', 'Layar dipesan');
    expect($t->progress)->toBe('menunggu_sparepart')->and(ServiceTicketService::publicStatus($t))->toBe('Menunggu sparepart');
    expect(fn () => $this->svc->updateProgress($t, $this->staff, 'ngawur'))->toThrow(DomainException::class, 'tidak dikenal');
    $this->svc->cancel($t, $this->staff, '2026-11-18', 0);
    expect(fn () => $this->svc->updateProgress($t->fresh(), $this->staff, 'dikerjakan'))->toThrow(DomainException::class, 'ditutup');
});

it('kode masuk: sekali pakai, salah 5 kali hangus, nomor tak terdaftar tidak mengirim apa pun', function () {
    ($this->ticket)();
    $otp = app(OtpService::class);

    $otp->request('0899999999');
    expect(FakeOtpSender::$sent)->toBe([]);

    $otp->request('081234567890');
    $code = FakeOtpSender::lastCode();
    expect($code)->toMatch('/^\d{6}$/');
    expect($otp->verify('+62 812-3456-7890', $code)->phone)->toBe('081234567890');
    expect(fn () => $otp->verify('081234567890', $code))->toThrow(DomainException::class, 'Kode salah');

    $otp->request('081234567890');
    $good = FakeOtpSender::lastCode();
    $wrong = $good === '000000' ? '111111' : '000000';
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $otp->verify('081234567890', $wrong))->toThrow(DomainException::class);
    }
    expect(fn () => $otp->verify('081234567890', $good))->toThrow(DomainException::class, 'Kode salah');
});

it('kode masuk kedaluwarsa ditolak dan permintaan kode dibatasi', function () {
    ($this->ticket)();
    $otp = app(OtpService::class);
    $otp->request('081234567890');
    $code = FakeOtpSender::lastCode();
    DB::table('customer_otps')->update(['expires_at' => now()->subMinute()]);
    expect(fn () => $otp->verify('081234567890', $code))->toThrow(DomainException::class, 'kedaluwarsa');

    $otp->request('081234567890');
    $otp->request('081234567890');
    expect(fn () => $otp->request('081234567890'))->toThrow(DomainException::class, 'Terlalu sering');
});

it('pengirim log ditolak di produksi supaya kode tidak hilang diam-diam', function () {
    ($this->ticket)();
    config(['kasir.otp_driver' => 'log']);
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(OtpService::class)->request('081234567890'))->toThrow(DomainException::class, 'belum aktif');
});

it('portal: masuk dengan kode, lihat servis sendiri saja, keluar, dan tidak bisa membuka panel staf', function () {
    ($this->ticket)('081234567890', 'Rina');
    ($this->ticket)('081311112222', 'Budi');

    $this->get('/pelanggan')->assertRedirect('/pelanggan/masuk');
    $this->post('/pelanggan/masuk', ['phone' => '081234567890'])->assertRedirect('/pelanggan/verifikasi');
    $this->post('/pelanggan/verifikasi', ['phone' => '081234567890', 'code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/pelanggan/verifikasi', ['phone' => '081234567890', 'code' => FakeOtpSender::lastCode()])->assertRedirect('/pelanggan');

    $this->get('/pelanggan')->assertOk()->assertSee('Halo, Rina')->assertSee('SRV-PUSAT-20261117-001')->assertDontSee('SRV-PUSAT-20261117-002')->assertDontSee('Budi');
    $this->get('/admin')->assertRedirect(); // bukan staf: diarahkan ke login staf

    $this->post('/pelanggan/keluar')->assertRedirect('/pelanggan/masuk');
    $this->get('/pelanggan')->assertRedirect('/pelanggan/masuk');
});

it('tautan lacak menampilkan status tanpa nominal atau nomor HP, dan token ngawur 404', function () {
    $t = ($this->ticket)('081234567890', 'Rina');
    $this->svc->updateProgress($t, $this->staff, 'dikerjakan', 'Mulai bongkar');

    $this->get('/lacak/'.$t->public_token)->assertOk()->assertSee('Sedang dikerjakan')->assertSee('Mulai bongkar')->assertSee('iPhone 12')
        ->assertDontSee('081234567890')->assertDontSee('200.000')->assertDontSee('Rina');
    $this->get('/lacak/tokenngawur')->assertNotFound();
});

it('komplain: dicatat dengan pelanggan dari servis, ditangani, dan masuk laporan; izin dan cabang dijaga', function () {
    $t = ($this->ticket)('081234567890', 'Rina');
    $svc = app(\App\Domain\Customer\ComplaintService::class);

    $c = $svc->create($this->staff, $this->branch->id, '2026-11-20', ['type' => 'garansi', 'description' => 'Layar mati lagi', 'ticket_code' => $t->code]);
    expect($c->status)->toBe('baru')->and($c->customer_id)->toBe($t->customer_id)->and($c->service_ticket_id)->toBe($t->id);

    expect(fn () => $svc->create($this->staff, $this->branch->id, '2026-11-20', ['type' => 'ngawur', 'description' => 'x']))->toThrow(DomainException::class, 'Jenis');
    expect(fn () => $svc->create($this->staff, $this->branch->id, '2026-11-20', ['type' => 'produk', 'description' => '  ']))->toThrow(DomainException::class, 'cerita');
    expect(fn () => $svc->create($this->staff, $this->branch->id, '2026-11-20', ['type' => 'produk', 'description' => 'x', 'ticket_code' => 'TIDAK-ADA']))->toThrow(DomainException::class, 'tidak ditemukan');
    $other = Branch::create(['code' => 'BDG', 'name' => 'Bandung']);
    expect(fn () => $svc->create($this->staff, $other->id, '2026-11-20', ['type' => 'produk', 'description' => 'x']))->toThrow(DomainException::class, 'akses');
    $fin = User::factory()->create();
    $fin->assignRole('finance_admin');
    $fin->branches()->attach($this->branch->id);
    expect(fn () => $svc->create($fin, $this->branch->id, '2026-11-20', ['type' => 'produk', 'description' => 'x']))->toThrow(DomainException::class, 'izin');

    expect(fn () => $svc->handle($c, $this->staff, '2026-11-21', 'selesai', ' '))->toThrow(DomainException::class, 'penyelesaiannya');
    $svc->handle($c, $this->staff, '2026-11-21', 'diproses', null);
    $c = $svc->handle($c->fresh(), $this->staff, '2026-11-22', 'selesai', 'Layar diganti baru');
    expect($c->status)->toBe('selesai')->and($c->resolved_on->toDateString())->toBe('2026-11-22');
    expect(fn () => $svc->handle($c->fresh(), $this->staff, '2026-11-23', 'diproses', 'lagi'))->toThrow(DomainException::class, 'sudah selesai');

    $r = app(\App\Domain\Accounting\OpsReportService::class)->problemsReport($this->branch->id, '2026-11-01', '2026-11-30');
    expect($r['complaint_count'])->toBe(1)->and($r['complaints'])->toBe(['selesai' => 1]);
    expect(app(\App\Domain\Accounting\OpsReportService::class)->problemsReport($other->id, '2026-11-01', '2026-11-30')['complaint_count'])->toBe(0);
});

it('halaman Pelanggan dan Komplain terbuka untuk kasir, tertutup untuk keuangan, dan struk kasir menautkan pelanggan', function () {
    ($this->ticket)('081234567890', 'Rina');
    $this->actingAs($this->staff)->get('/admin/pelanggan')->assertOk()->assertSee('Rina');
    $this->actingAs($this->staff)->get('/admin/komplain')->assertOk();
    $fin = User::factory()->create();
    $fin->assignRole('finance_admin');
    $this->actingAs($fin)->get('/admin/pelanggan')->assertForbidden();
    $this->actingAs($fin)->get('/admin/komplain')->assertForbidden();

    $nota = $this->actingAs($this->staff)->get(route('nota', ServiceTicket::first()->code));
    $nota->assertOk()->assertSee('/lacak/'.ServiceTicket::first()->public_token, false)->assertSee('/pelanggan/masuk', false);
});

it('kasir: struk dengan nomor HP tertaut ke pelanggan dan tampil di portal', function () {
    $tx = app(Transactions::class);
    $tx->depositCapital($this->branch->id, '2026-11-01', 5_000_000, 'kas');
    \App\Models\Product::create(['sku' => 'CS1', 'name' => 'Casing', 'kind' => 'barang', 'sale_price' => 50_000]);
    $tx->purchase($this->branch->id, '2026-11-01', 'CS1', 10, 10_000, 'kas');
    $pid = \App\Models\Product::where('sku', 'CS1')->value('id');
    $sale = app(\App\Domain\Pos\PosService::class)->checkout($this->staff, $this->branch->id, '2026-11-05', [['product_id' => $pid, 'qty' => 2]], 'kas', '0821-1111-2222');

    expect($sale->customer_id)->not->toBeNull()->and(Customer::find($sale->customer_id)->phone)->toBe('082111112222');
    $this->actingAs(Customer::find($sale->customer_id), 'customer')->get('/pelanggan')->assertOk()->assertSee($sale->receipt_no);
    $none = app(\App\Domain\Pos\PosService::class)->checkout($this->staff, $this->branch->id, '2026-11-05', [['product_id' => $pid, 'qty' => 1]], 'kas');
    expect($none->customer_id)->toBeNull();
});
