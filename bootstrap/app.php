<?php

use App\Http\Middleware\InternalSharedSecretMiddleware;
use App\Http\Middleware\JwtAuthMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pandora.jwt' => JwtAuthMiddleware::class,
            'pandora.internal' => InternalSharedSecretMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // ADR-007 Phase 1: 每分鐘把 pending outbox events POST 給 consumer。
        // publisher_enabled=false 時 service 內部空轉（cron 仍然跑，沒副作用）。
        $schedule->command('identity:dispatch-pending')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
