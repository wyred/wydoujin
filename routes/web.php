<?php

use App\Http\Controllers\Auth\PasswordLoginController;
use App\Http\Controllers\CoverController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReadingProgressController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/login', [PasswordLoginController::class, 'show'])->name('login');
Route::post('/login', [PasswordLoginController::class, 'store']);

Route::get('/work/{work}/page/{n}', [PageController::class, 'show'])
    ->whereNumber('n')
    ->name('work.page');

Route::get('/covers/{hash}.webp', [CoverController::class, 'show'])
    ->where('hash', '[0-9a-f]{64}')
    ->name('cover');

Route::post('/work/{work}/progress', [ReadingProgressController::class, 'update'])
    ->name('work.progress');
