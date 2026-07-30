<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\FlyerThumbController;
use App\Http\Controllers\PackageSearchController;
use App\Http\Controllers\PipelineController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PackageSearchController::class, 'index'])->name('search');
Route::get('/paket/{package}', [PackageSearchController::class, 'show'])->name('package.show');
Route::post('/paket/{package}/feedback', [PackageSearchController::class, 'feedback'])->name('package.feedback');
Route::delete('/paket/{package}', [PackageSearchController::class, 'destroy'])->name('package.destroy');
Route::get('/akun', [AccountController::class, 'index'])->name('accounts');
Route::post('/akun', [AccountController::class, 'store'])->name('accounts.store');
Route::post('/akun/scrap', [AccountController::class, 'fetchAll'])->name('accounts.fetch_all');
Route::post('/akun/{account}/fetch', [AccountController::class, 'fetch'])->name('accounts.fetch');
Route::delete('/akun/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
Route::post('/pipeline', [PipelineController::class, 'start'])->name('pipeline.start');
Route::get('/pipeline/status', [PipelineController::class, 'status'])->name('pipeline.status');
Route::get('/flyer/{media}/{index}.jpg', FlyerThumbController::class)
    ->whereNumber(['media', 'index'])
    ->name('flyer');
