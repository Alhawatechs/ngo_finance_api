<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\SetOfficeContext::class,
        ]);

        $middleware->alias([
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API routes must return JSON (no web `route("login")`); avoids 500 when session/export runs without auth
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*');
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });

        $exceptions->report(function (\Throwable $e) {
            $request = request();
            if ($request && $request->is('api/*')) {
                $logPath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'debug.log';
                $dir = dirname($logPath);
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                @file_put_contents($logPath, json_encode([
                    'location' => 'bootstrap:exception',
                    'message' => 'API exception reported',
                    'data' => [
                        'route' => $request->path(),
                        'method' => $request->method(),
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                    ],
                    'timestamp' => (int)(microtime(true) * 1000),
                    'hypothesisId' => 'H4',
                ]) . "\n", FILE_APPEND | LOCK_EX);
            }
        });
    })->create();
