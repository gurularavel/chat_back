<?php

use App\Http\Controllers\Billing\FakeCheckoutController;
use App\Http\Controllers\Billing\PaymentReturnController;
use App\Http\Controllers\Widget\WidgetFrameController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => config('app.name'), 'status' => 'ok']));

// Widget iframe (loader.js + assets are static files in public/widget)
Route::get('widget/frame/{key}', WidgetFrameController::class)->name('widget.frame');

// Payment gateway return
Route::get('billing/return', PaymentReturnController::class)->name('billing.return');

// Local test gateway
Route::middleware('signed')->group(function () {
    Route::get('billing/fake-checkout/{order}', [FakeCheckoutController::class, 'show'])->name('billing.fake-checkout');
    Route::get('billing/fake-checkout/{order}/{result}', [FakeCheckoutController::class, 'complete'])
        ->whereIn('result', ['paid', 'declined'])
        ->name('billing.fake-checkout.complete');
});
