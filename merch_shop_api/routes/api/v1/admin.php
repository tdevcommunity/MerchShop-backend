<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\V1\DashboardController::class, 'index'])->name('dashboard');

    Route::get('/search', [\App\Http\Controllers\Api\V1\SearchController::class, 'index'])->name('search');

    Route::get('/audit', [\App\Http\Controllers\Api\V1\AuditController::class, 'index'])->name('audit');

    Route::get('/notifications', [\App\Http\Controllers\Api\V1\NotificationsController::class, 'index'])->name('notifications');
    Route::post('/notifications/read', [\App\Http\Controllers\Api\V1\NotificationsController::class, 'markRead'])->name('notifications.read');

    Route::get('/users', [\App\Http\Controllers\Api\V1\UsersController::class, 'index'])->name('users');

    Route::post('/login', function () {
        return response()->json(['message' => 'Admin login endpoint']);
    })->name('login');

    Route::post('/logout', function () {
        return response()->json(['message' => 'Admin logout endpoint']);
    })->name('logout');

    Route::get('/me', function () {
        return response()->json(['message' => 'Admin me endpoint']);
    })->name('me');

    Route::prefix('categories')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\CategoryController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\V1\CategoryController::class, 'store']);
        Route::put('/{uuid}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'update']);
        Route::delete('/{uuid}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'destroy']);
    });

    Route::prefix('products')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\ProductController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
        Route::put('/{uuid}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update']);
        Route::delete('/{uuid}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy']);
    });

    Route::prefix('orders')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\OrderController::class, 'index']);
        Route::get('/{uuid}', [\App\Http\Controllers\Api\V1\OrderController::class, 'show']);
        Route::post('/{uuid}/status', [\App\Http\Controllers\Api\V1\OrderController::class, 'updateStatus']);
    });

    Route::get('/payments', function () { return response()->json(['data'=>['message'=>'Admin payments endpoint']]); })->name('payments');

    Route::prefix('inventory')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\InventoryController::class, 'index']);
        Route::put('/{uuid}', [\App\Http\Controllers\Api\V1\InventoryController::class, 'update']);
    });

    Route::prefix('pickups')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\PickupsController::class, 'index']);
        Route::post('/{id}/validate', [\App\Http\Controllers\Api\V1\PickupsController::class, 'validate']);
    });
});
