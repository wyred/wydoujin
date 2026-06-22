<?php

use App\Http\Controllers\Auth\PasswordLoginController;
use App\Http\Controllers\PageController;
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
