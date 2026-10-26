<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/struk/{receipt}', function (string $receipt) {
    $sale = App\Models\PosSale::with(['items.product', 'branch', 'cashier'])->where('receipt_no', $receipt)->firstOrFail();
    abort_unless(auth()->user()?->can('post-sales') && auth()->user()->canAccessBranch($sale->branch_id), 403);

    return view('struk', compact('sale'));
})->middleware('web')->name('struk');
