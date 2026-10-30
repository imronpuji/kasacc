<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/struk/{receipt}', function (string $receipt) {
    $sale = App\Models\PosSale::with(['items.product', 'branch', 'cashier'])->where('receipt_no', $receipt)->firstOrFail();
    abort_unless(auth()->user()?->can('post-sales') && auth()->user()->canAccessBranch($sale->branch_id), 403);

    return view('struk', compact('sale'));
})->middleware('web')->name('struk');

Route::get('/nota/{code}', function (string $code) {
    $t = App\Models\ServiceTicket::with(['branch', 'technician'])->where('code', $code)->firstOrFail();
    abort_unless(auth()->user()?->can('manage-services') && auth()->user()->canAccessBranch($t->branch_id), 403);

    return view('nota-servis', compact('t'));
})->middleware('web')->name('nota');

// Pelanggan toko: tautan lacak (tanpa masuk) dan portal (HP + kode sekali pakai). Dibatasi lajunya agar kode tidak bisa ditebak.
Route::get('/lacak/{token}', [App\Http\Controllers\CustomerPortalController::class, 'track'])->middleware('throttle:60,1')->name('lacak');
Route::prefix('pelanggan')->group(function () {
    $c = App\Http\Controllers\CustomerPortalController::class;
    Route::get('/masuk', [$c, 'loginForm'])->name('pelanggan.masuk');
    Route::post('/masuk', [$c, 'sendCode'])->middleware('throttle:5,1')->name('pelanggan.kode');
    Route::get('/verifikasi', [$c, 'verifyForm'])->name('pelanggan.verifikasi');
    Route::post('/verifikasi', [$c, 'verify'])->middleware('throttle:10,1')->name('pelanggan.verifikasi.kirim');
    Route::get('/', [$c, 'home'])->name('pelanggan.beranda');
    Route::post('/keluar', [$c, 'logout'])->name('pelanggan.keluar');
});

Route::get('/slip/{run}', function (int $run) {
    $user = auth()->user();
    if (! $user) {
        return redirect()->guest(url('/admin/login'));
    }
    $employeeIds = App\Models\Employee::where('user_id', $user->id)->pluck('id');
    $manager = $user->can('manage-hr') || $user->can('approve-payroll');
    $run = App\Models\PayrollRun::with(['branch', 'slips.employee'])->findOrFail($run);
    $slips = $manager ? $run->slips : (in_array($run->status, ['disetujui', 'dibayar']) ? $run->slips->whereIn('employee_id', $employeeIds) : collect());
    abort_if($slips->isEmpty(), 403);

    return view('slip', ['run' => $run, 'slips' => $slips]);
})->middleware('web')->name('slip');
