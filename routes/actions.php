<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\GiftVouchers\Http\Controllers\ActionsController;
use NineteenNinetyFour\GiftVouchers\Http\Controllers\VoucherDocumentController;

// At /!/gift-vouchers/...

// Adding vouchers to the cart, and taking them out.
Route::middleware('throttle:gift-vouchers-cart')->group(function () {
    Route::post('cart/add', [ActionsController::class, 'add'])->name('cart.add');
    Route::post('cart/remove', [ActionsController::class, 'remove'])->name('cart.remove');
});

// Anything that takes a code is limited harder, so codes can't be guessed.
Route::middleware('throttle:gift-vouchers-codes')->group(function () {
    Route::post('cart/redeem', [ActionsController::class, 'redeem'])->name('cart.redeem');
    Route::post('check', [ActionsController::class, 'check'])->name('check');
});

// The voucher to print, or as a PDF. The links are signed: they show the
// code, so they're only in the voucher's email and the Control Panel.
Route::middleware('signed')->group(function () {
    Route::get('vouchers/{voucher}/print', [VoucherDocumentController::class, 'print'])->name('print');
    Route::get('vouchers/{voucher}/pdf', [VoucherDocumentController::class, 'pdf'])->name('pdf');
});
