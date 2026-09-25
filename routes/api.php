<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest.api', 'throttle:5,1'])->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/register', RegisterController::class)->name('register');
    Route::post('/forgot-password', ForgotPasswordController::class)->name('password.email');
    Route::post('/reset-password', ResetPasswordController::class)->name('password.update');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/user', [AuthController::class, 'user'])->name('user');
    Route::get('/products/suggestions', [ProductController::class, 'suggestions'])->name('products.suggestions');
    Route::apiResource('products', ProductController::class)->whereNumber('product');
});
