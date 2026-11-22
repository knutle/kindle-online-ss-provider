<?php

use App\Http\Controllers\DashboardImageController;
use App\Services\CalendarEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn (Request $request, CalendarEvents $calendar) => Inertia::render('weather-dashboard', [
    'events' => $calendar->upcoming($request->string('calendar')->toString()),
]))->name('home');
Route::get('/dashboard.png', DashboardImageController::class)->name('dashboard.image');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
