<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Http\Controllers\ManifestController;
use Hygo\ApiWaypoint\Http\Controllers\ReferenceController;
use Hygo\ApiWaypoint\Http\Controllers\ScenarioController;
use Hygo\ApiWaypoint\Http\Controllers\SchemaController;
use Hygo\ApiWaypoint\Http\Controllers\TokenController;
use Hygo\ApiWaypoint\Http\Middleware\LogWaypointRequest;
use Hygo\ApiWaypoint\Http\Middleware\VerifyWaypointSecret;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('api-waypoint.prefix', '_api-waypoint'))
    ->middleware([VerifyWaypointSecret::class, LogWaypointRequest::class])
    ->name('api-waypoint.')
    ->group(function (): void {
        Route::get('/', SchemaController::class)->name('schema');
        Route::get('/manifest', ManifestController::class)->name('manifest');

        Route::get('/references/{table}/{column}', ReferenceController::class)
            ->where(['table' => '[A-Za-z0-9_]+', 'column' => '[A-Za-z0-9_]+'])
            ->name('references');

        Route::get('/scenarios', [ScenarioController::class, 'index'])->name('scenarios.index');
        Route::post('/scenarios', [ScenarioController::class, 'store'])->name('scenarios.store');
        Route::delete('/scenarios/{cleanup_token}', [ScenarioController::class, 'destroy'])->name('scenarios.destroy');

        Route::post('/tokens', TokenController::class)->name('tokens.store');
    });
