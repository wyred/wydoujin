<?php

use App\Http\Controllers\Api\FacetController;
use App\Http\Controllers\Api\MangakaController;
use App\Http\Controllers\Api\SeriesController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\WorkController;
use App\Http\Middleware\EnsureApiToken;
use Illuminate\Support\Facades\Route;

// Stateless machine API for LLM-assisted organizing. Versioned, token-gated, no
// session/CSRF (the api group), so the web RequirePassword gate never applies.
// LLM向けの機械API（/api/v1、トークン認証、ステートレス）。
Route::prefix('v1')->middleware(EnsureApiToken::class)->group(function (): void {
    // Read / discovery
    Route::get('/works', [WorkController::class, 'index']);
    Route::get('/works/{work}', [WorkController::class, 'show']);
    Route::get('/mangaka', [MangakaController::class, 'index']);
    Route::get('/mangaka/{mangaka}', [MangakaController::class, 'show']);
    Route::get('/series/{series}', [SeriesController::class, 'show']);
    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/facets', [FacetController::class, 'index']);
});
