<?php

use App\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');

/*
| Public catalogue. Everyone can browse and listen; only the download
| route is gated, and the gate itself lives in DownloadController.
*/
Route::livewire('sounds', 'pages::sounds')->name('sounds.index');

Route::get('sounds/{sound}/download', DownloadController::class)
    ->middleware('auth')
    ->name('sounds.download');

Route::livewire('sounds/{sound}', 'pages::sounds.show')->name('sounds.show');

/*
| Contributors. The component itself checks canUpload() in mount(),
| so the role check lives next to the thing it protects.
*/
Route::livewire('upload', 'pages::upload')
    ->middleware('auth')
    ->name('upload');

Route::livewire('moderate', 'pages::moderate')
    ->middleware('auth')
    ->name('moderate');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
