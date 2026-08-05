<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->prefix('api')
    ->group(function (): void {
        Route::prefix('auth')->group(function (): void {

            // Step 1 - identify flow
            Route::post('/identify', [AuthController::class, 'identify']);

            // OTP
            Route::post('/otp/send', [AuthController::class, 'sendCode']);
            Route::post('/otp/verify', [AuthController::class, 'verifyCode']);

            // Register
            Route::post('/register', [AuthController::class, 'register']);

            // Password
            Route::post('/login', [AuthController::class, 'loginWithPassword']);
            Route::post('/password/set', [AuthController::class, 'setPassword']);

            // Google
            Route::post('/google', [GoogleAuthController::class, 'login'])
                ->middleware('throttle:10,1');

            // Protected
            Route::middleware('auth:sanctum')->group(function (): void {
                Route::get('/me', [AuthController::class, 'me']);
                Route::post('/logout', [AuthController::class, 'logout']);
                Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            });
        });
    });
