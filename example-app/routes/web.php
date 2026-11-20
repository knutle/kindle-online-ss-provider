<?php

use App\Http\Controllers\DashboardImageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'weather-dashboard')->name('home');
Route::get('/dashboard.png', DashboardImageController::class)->name('dashboard.image');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
