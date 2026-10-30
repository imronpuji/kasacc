<?php

use App\Domain\Accounting\ReportExporter;
use App\Domain\Transactions;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;

function exportUser(string $role, Branch $branch): User
{
    $u = User::factory()->create();
    $u->assignRole($role);
    $u->branches()->attach($branch->id);

    return $u;
}

beforeEach(function () {
    $this->seed(AccountSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::create(['code' => 'PUSAT', 'name' => 'Cabang Pusat']);
    $this->owner = exportUser('owner', $this->branch);
    $this->today = now()->toDateString();
    app(Transactions::class)->depositCapital($this->branch->id, $this->today, 1_000_000, 'kas');
});

it('builds every report as a flat table with a header row', function () {
    foreach (array_keys(ReportExporter::REPORTS) as $key) {
        $data = app(ReportExporter::class)->build($key, null, $this->today, $this->today);
        expect($data['rows'])->not->toBeEmpty()->and($data['title'])->not->toBe('');
    }
    expect(fn () => app(ReportExporter::class)->build('nope', null, $this->today, $this->today))->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('downloads Excel and PDF for the owner and keeps the numbers equal to the screen', function () {
    $xlsx = $this->actingAs($this->owner)->get(route('reports.export', ['report' => 'kas', 'format' => 'xlsx', 'from' => $this->today, 'to' => $this->today]));
    $xlsx->assertOk()->assertDownload();
    $pdf = $this->actingAs($this->owner)->get(route('reports.export', ['report' => 'untung', 'format' => 'pdf']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('pdf');

    $cash = app(ReportExporter::class)->build('kas', null, $this->today, $this->today)['rows'];
    expect(collect($cash)->firstWhere(0, 'Saldo akhir')[1])->toBe(1_000_000);
});

it('refuses guests, users without report permission, bad formats and other branches', function () {
    $this->get(route('reports.export', ['report' => 'kas', 'format' => 'xlsx']))->assertRedirect();
    $cashier = exportUser('cashier', $this->branch);
    $this->actingAs($cashier)->get(route('reports.export', ['report' => 'kas', 'format' => 'xlsx']))->assertForbidden();
    $this->actingAs($this->owner)->get(route('reports.export', ['report' => 'kas', 'format' => 'csv']))->assertNotFound();
    $this->actingAs($this->owner)->get(route('reports.export', ['report' => 'xyz', 'format' => 'pdf']))->assertNotFound();

    $other = Branch::create(['code' => 'B2', 'name' => 'Cabang Dua']);
    $manager = exportUser('finance_admin', $this->branch);
    if (true) {
        $this->actingAs($manager)->get(route('reports.export', ['report' => 'kas', 'format' => 'xlsx', 'branch' => $other->id]))->assertForbidden();
    }
});

it('shows download links on the report page', function () {
    Livewire\Livewire::actingAs($this->owner)->test(App\Filament\Pages\Reports::class)->assertSee('Unduh laporan ini')->assertSee('export/untung.xlsx', false);
});
