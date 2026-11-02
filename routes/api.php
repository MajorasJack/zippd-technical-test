<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::patch('/orders/{order}/status', [OrderStatusController::class, 'update'])
        ->name('orders.status.update')
        ->can('update', 'order');
});
