<?php

use App\Domain\Customer\OtpSender;
use App\Domain\Service\ServiceTicketService;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

class RecordingSender implements OtpSender
{
    public static array $sent = [];

    public static bool $fail = false;

    public function send(string $to, string $subject, string $message): void
    {
        if (self::$fail) {
            throw new RuntimeException('mail down');
        }
        self::$sent[] = [$to, $subject, $message];
    }
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    RecordingSender::$sent = [];
    RecordingSender::$fail = false;
    app()->bind(OtpSender::class, RecordingSender::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->user = User::factory()->create();
    $this->user->assignRole('cashier');
    $this->user->branches()->attach($this->branch->id);
    $this->svc = app(ServiceTicketService::class);
    $this->receive = fn (array $extra = []) => $this->svc->receive($this->user, $this->branch->id, now()->toDateString(), ['customer_name' => 'Rina', 'device' => 'iPhone 12', 'complaint' => 'layar'] + $extra);
});

it('emails the customer once when the phone is ready for pickup, with the tracking link', function () {
    $t = ($this->receive)(['customer_email' => 'rina@example.com']);

    $this->svc->updateProgress($t, $this->user, 'dikerjakan');
    expect(RecordingSender::$sent)->toBe([]);

    $this->svc->updateProgress($t, $this->user, 'siap_diambil');
    $this->svc->updateProgress($t, $this->user, 'dikerjakan');
    $this->svc->updateProgress($t, $this->user, 'siap_diambil'); // toggled back and forth: still one email

    expect(RecordingSender::$sent)->toHaveCount(1);
    [$to, $subject, $message] = RecordingSender::$sent[0];
    expect($to)->toBe('rina@example.com')->and($subject)->toContain('siap diambil')
        ->and($message)->toContain('iPhone 12')->and($message)->toContain(route('track', $t->public_token))->and($message)->toContain('Cabang Pusat');
});

it('sends nothing without an email but still updates the progress', function () {
    $t = ($this->receive)(['customer_phone' => '081234567890']);

    $this->svc->updateProgress($t, $this->user, 'siap_diambil');

    expect(RecordingSender::$sent)->toBe([])->and($t->fresh()->progress)->toBe('siap_diambil');
});

it('keeps the progress change and allows a retry when the email fails', function () {
    $t = ($this->receive)(['customer_email' => 'rina@example.com']);
    RecordingSender::$fail = true;

    $this->svc->updateProgress($t, $this->user, 'siap_diambil');
    expect($t->fresh()->progress)->toBe('siap_diambil')->and($t->fresh()->ready_notified_at)->toBeNull();

    RecordingSender::$fail = false;
    $this->svc->updateProgress($t, $this->user, 'dikerjakan');
    $this->svc->updateProgress($t, $this->user, 'siap_diambil');
    expect(RecordingSender::$sent)->toHaveCount(1)->and($t->fresh()->ready_notified_at)->not->toBeNull();
});
