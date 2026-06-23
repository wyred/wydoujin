<?php

use App\Http\Controllers\Auth\PasswordLoginController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\BrowseSearchController;
use App\Http\Controllers\CoverController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MangakaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\SeriesController;
use App\Http\Controllers\SeriesManagementController;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\WorkTagController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BrowseController::class, 'home'])->name('home');

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

Route::get('/mangaka', [MangakaController::class, 'index'])->name('mangaka.index');
Route::get('/mangaka/{mangaka:slug}', [MangakaController::class, 'show'])->name('mangaka.show');

Route::get('/browse', [BrowseSearchController::class, 'index'])->name('browse.index');

Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
Route::get('/maintenance/status', [MaintenanceController::class, 'status'])->name('maintenance.status');
Route::post('/scan', [MaintenanceController::class, 'scan'])->name('scan.store');

Route::get('/series/{series}', [SeriesController::class, 'show'])->name('series.show');
Route::post('/series/group', [SeriesManagementController::class, 'group'])->name('series.group');
Route::post('/series/ungroup', [SeriesManagementController::class, 'ungroup'])->name('series.ungroup');
Route::post('/series/{series}/add', [SeriesManagementController::class, 'add'])->name('series.add');
Route::post('/series/{series}/rename', [SeriesManagementController::class, 'rename'])->name('series.rename');
Route::get('/tags/suggest', [WorkTagController::class, 'suggest'])->name('tags.suggest');

Route::post('/work/{work}/tags/attach', [WorkTagController::class, 'attach'])->name('work.tags.attach');
Route::post('/work/{work}/tags/detach', [WorkTagController::class, 'detach'])->name('work.tags.detach');
Route::post('/work/{work}/tags/reset', [WorkTagController::class, 'reset'])->name('work.tags.reset');

Route::get('/work/{work}', [WorkController::class, 'show'])->name('work.show');

Route::get('/work/{work}/read', [ReaderController::class, 'show'])->name('work.read');
