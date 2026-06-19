<?php

use Cosmos\LaravelMonitor\Http\Controllers\CacheTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\DatabaseTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\DiagnosticsController;
use Cosmos\LaravelMonitor\Http\Controllers\ExceptionTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\HealthController;
use Cosmos\LaravelMonitor\Http\Controllers\LogTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\MetricsController;
use Cosmos\LaravelMonitor\Http\Controllers\NotificationController;
use Cosmos\LaravelMonitor\Http\Controllers\PerformanceController;
use Cosmos\LaravelMonitor\Http\Controllers\QueueTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\RequestTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\ScheduleTelemetryController;
use Cosmos\LaravelMonitor\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);
Route::get('metrics/summary', [MetricsController::class, 'summary']);
Route::get('metrics/timeseries', [MetricsController::class, 'timeseries']);
Route::get('requests', [RequestTelemetryController::class, 'index']);
Route::get('queues', [QueueTelemetryController::class, 'index']);
Route::get('queues/{queue}/jobs', [QueueTelemetryController::class, 'jobs']);
Route::get('logs', [LogTelemetryController::class, 'index']);
Route::get('exceptions', [ExceptionTelemetryController::class, 'index']);
Route::put('exceptions/{hash}/status', [ExceptionTelemetryController::class, 'updateStatus']);
Route::get('schedules', [ScheduleTelemetryController::class, 'index']);
Route::get('database/latency', [DatabaseTelemetryController::class, 'latency']);
Route::get('performance', [PerformanceController::class, 'index']);
Route::get('cache', [CacheTelemetryController::class, 'index']);
Route::get('settings', [SettingsController::class, 'index']);
Route::put('settings', [SettingsController::class, 'update']);
Route::post('notifications/test', [NotificationController::class, 'test']);
Route::post('diagnostics/logs/test', [DiagnosticsController::class, 'logTest']);
Route::post('diagnostics/exceptions/test', [DiagnosticsController::class, 'exceptionTest']);
Route::post('diagnostics/database/test-query', [DiagnosticsController::class, 'databaseTestQuery']);
