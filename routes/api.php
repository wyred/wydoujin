<?php

use App\Http\Controllers\Api\WorkController;
use App\Http\Middleware\EnsureApiToken;
use Illuminate\Support\Facades\Route;

// Stateless machine API for LLM-assisted organizing. Versioned, token-gated, no
// session/CSRF (the api group), so the web RequirePassword gate never applies.
// LLM向けの機械API（/api/v1、トークン認証、ステートレス）。
Route::prefix('v1')->middleware(EnsureApiToken::class)->group(function (): void {
    Route::get('/works', [WorkController::class, 'index']);
});
