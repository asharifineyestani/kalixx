<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
        'message' => 'API is running',
    ]);
});