<?php

use Cofa\PerformanceTracking\Http\Controllers\ClientReportController;
use Cofa\PerformanceTracking\Http\Controllers\DashboardController;
use Cofa\PerformanceTracking\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

$config = config('performance-tracking');

Route::group([
    'prefix' => $config['path'],
    'domain' => $config['domain'] ?? null,
    'as' => 'performance-tracking.',
], function () use ($config) {
    // Public: the browser script and the endpoint clients report to.
    Route::get('client.js', [DashboardController::class, 'clientScript'])->name('client-script');
    Route::post('api/client', ClientReportController::class)
        ->middleware($config['client_middleware'] ?? [])
        ->name('client-report');

    Route::middleware(array_merge($config['middleware'] ?? ['web'], [Authorize::class]))->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
        Route::get('requests/{id}', [DashboardController::class, 'show'])->whereUuid('id')->name('show');
    });
});
