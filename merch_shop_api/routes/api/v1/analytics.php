<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::post('/events', [AnalyticsController::class, 'store'])->name('events.store');
Route::get('/events/{eventId}', [AnalyticsController::class, 'show'])->name('events.show');
Route::delete('/events/{eventId}', [AnalyticsController::class, 'destroy'])->name('events.destroy');
