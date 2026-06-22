<?php

use App\Http\Controllers\Auth\PasswordLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/login', [PasswordLoginController::class, 'show'])->name('login');
Route::post('/login', [PasswordLoginController::class, 'store']);
