<?php

use App\Http\Controllers\DashboardImageController;
use App\Services\CalendarEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request, CalendarEvents $calendar) {
    $overview = $calendar->overview($request->string('calendar')->toString());

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
