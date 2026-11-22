<?php

use App\Http\Controllers\DashboardImageController;
use App\Services\CalendarEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request, CalendarEvents $calendar) {
    $calendarInput = $request->input('calendar');
    $overview = $calendar->overview(
        is_string($calendarInput) || is_array($calendarInput) ? $calendarInput : null,
    );

    return Inertia::render('weather-dashboard', [
        'events' => $overview['events'],
        'calendar' => $overview['configured'] ? ['name' => $overview['name']] : null,
    ]);
})->name('home');
Route::get('/dashboard.png', DashboardImageController::class)->name('dashboard.image');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
