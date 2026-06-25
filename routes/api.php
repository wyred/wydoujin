<?php

use App\Http\Controllers\Api\BulkTagController;
use App\Http\Controllers\Api\FacetController;
use App\Http\Controllers\Api\MangakaController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\SeriesController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\WorkController;
use App\Http\Controllers\Api\WorkTagController;
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

    // Per-work tags
    Route::post('/works/{work}/tags', [WorkTagController::class, 'attach']);
    Route::put('/works/{work}/tags', [WorkTagController::class, 'replace']);
    Route::delete('/works/{work}/tags', [WorkTagController::class, 'detach']);
    Route::post('/works/{work}/tags/reset', [WorkTagController::class, 'reset']);

    // Bulk tags
    Route::post('/tags/attach', [BulkTagController::class, 'attach']);
    Route::post('/tags/detach', [BulkTagController::class, 'detach']);

    // Global tags
    Route::patch('/tags/{tag}', [TagController::class, 'rename']);
    Route::post('/tags/{tag}/merge', [TagController::class, 'merge']);

    // Series
    Route::post('/series', [SeriesController::class, 'store']);
    Route::delete('/series/works', [SeriesController::class, 'ungroup']);
    Route::post('/series/{series}/works', [SeriesController::class, 'addWorks']);
    Route::patch('/series/{series}', [SeriesController::class, 'rename']);

    // Maintenance
    Route::post('/scan', [ScanController::class, 'store']);
    Route::get('/scan', [ScanController::class, 'show']);
    Route::post('/works/{work}/rescan', [WorkController::class, 'rescan']);
});
