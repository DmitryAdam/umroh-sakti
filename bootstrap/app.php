<?php

use App\Http\Middleware\EnsureNotSuspended;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Penangguhan digigit tiap request, bukan cuma saat login: `remember`
        // memperpanjang sesi berbulan-bulan. Di grup `web` supaya dua grup `auth`
        // di routes/web.php sama-sama tercakup tanpa perlu diingat.
        $middleware->appendToGroup('web', EnsureNotSuspended::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
