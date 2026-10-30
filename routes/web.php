<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/receipt/{receipt}', function (string $receipt) {
    $sale = App\Models\PosSale::with(['items.product', 'branch', 'cashier'])->where('receipt_no', $receipt)->firstOrFail();
    abort_unless(auth()->user()?->can('post-sales') && auth()->user()->canAccessBranch($sale->branch_id), 403);

    return view('receipt', compact('sale'));
})->middleware('web')->name('receipt');

Route::get('/service-receipt/{code}', function (string $code) {
    $t = App\Models\ServiceTicket::with(['branch', 'technician'])->where('code', $code)->firstOrFail();
    abort_unless(auth()->user()?->can('manage-services') && auth()->user()->canAccessBranch($t->branch_id), 403);

    return view('service-receipt', compact('t'));
})->middleware('web')->name('service-receipt');

// Pelanggan toko: tautan lacak (tanpa masuk) dan portal (HP + kode sekali pakai). Dibatasi lajunya agar kode tidak bisa ditebak.
Route::get('/track/{token}', [App\Http\Controllers\CustomerPortalController::class, 'track'])->middleware('throttle:60,1')->name('track');
Route::prefix('customer')->group(function () {
    $c = App\Http\Controllers\CustomerPortalController::class;
    Route::get('/login', [$c, 'loginForm'])->name('customer.login');
    Route::post('/login', [$c, 'sendCode'])->middleware('throttle:5,1')->name('customer.send-code');
    Route::get('/verify', [$c, 'verifyForm'])->name('customer.verify');
    Route::post('/verify', [$c, 'verify'])->middleware('throttle:10,1')->name('customer.verify.submit');
    Route::get('/', [$c, 'home'])->name('customer.home');
    Route::post('/logout', [$c, 'logout'])->name('customer.logout');
});

Route::get('/reports/export/{report}.{format}', App\Http\Controllers\ReportExportController::class)->middleware('web')->name('reports.export');

Route::get('/payslip/{run}', function (int $run) {
    $user = auth()->user();
    if (! $user) {
        return redirect()->guest(url('/admin/login'));
    }
    $employeeIds = App\Models\Employee::where('user_id', $user->id)->pluck('id');
    $manager = $user->can('manage-hr') || $user->can('approve-payroll');
    $run = App\Models\PayrollRun::with(['branch', 'slips.employee'])->findOrFail($run);
    $slips = $manager ? $run->slips : (in_array($run->status, ['disetujui', 'dibayar']) ? $run->slips->whereIn('employee_id', $employeeIds) : collect());
    abort_if($slips->isEmpty(), 403);

    return view('payslip', ['run' => $run, 'slips' => $slips]);
})->middleware('web')->name('payslip');

// Alamat lama (bahasa Indonesia) tetap hidup supaya nota yang sudah tercetak dan email lama tidak mati.
Route::get('/lacak/{token}', fn (string $token) => redirect()->route('track', $token, 301));
Route::get('/nota/{code}', fn (string $code) => redirect()->route('service-receipt', $code, 301));
Route::get('/struk/{receipt}', fn (string $receipt) => redirect()->route('receipt', $receipt, 301));
Route::get('/slip/{run}', fn (int $run) => redirect()->route('payslip', $run, 301));
Route::redirect('/pelanggan', '/customer', 301);
Route::redirect('/pelanggan/masuk', '/customer/login', 301);
Route::redirect('/pelanggan/verifikasi', '/customer/verify', 301);
