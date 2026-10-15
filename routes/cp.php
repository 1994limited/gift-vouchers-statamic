<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\GiftVouchers\Http\Controllers\CP\VouchersController;

Route::prefix('gift-vouchers')->name('gift-vouchers.')->group(function () {
    Route::get('/', [VouchersController::class, 'index'])->name('index');
    Route::get('export', [VouchersController::class, 'export'])->name('export');
    Route::get('create', [VouchersController::class, 'create'])->name('create');
    Route::post('/', [VouchersController::class, 'store'])->name('store');
    Route::get('{voucher}', [VouchersController::class, 'show'])->name('show');
    Route::patch('{voucher}', [VouchersController::class, 'update'])->name('update');
    Route::post('{voucher}/code', [VouchersController::class, 'code'])->name('code');
    Route::post('{voucher}/adjust', [VouchersController::class, 'adjust'])->name('adjust');
    Route::post('{voucher}/disable', [VouchersController::class, 'disable'])->name('disable');
    Route::post('{voucher}/enable', [VouchersController::class, 'enable'])->name('enable');
    Route::post('{voucher}/send', [VouchersController::class, 'send'])->name('send');
});
