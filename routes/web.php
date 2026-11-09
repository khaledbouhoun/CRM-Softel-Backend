<?php

use Illuminate\Support\Facades\Route;

// This application is an API only; point browsers at the API root.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/api/v1/health'),
]));
