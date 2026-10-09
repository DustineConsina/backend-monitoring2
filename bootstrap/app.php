<?php

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
            'role' => \App\Http\Middleware\CheckRole::class,
            'tenant.access' => \App\Http\Middleware\RestrictTenantAccess::class,
        ]);
        
        // Add CORS middleware - prepend instead of append to run before auth
        $middleware->prepend(\App\Http\Middleware\CorsMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON for API authentication failures
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'status' => 'error'
                ], 401);
            }
            return null;
        });

        // Catch RouteNotFoundException when trying to access missing login route
        $exceptions->renderable(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                if (str_contains($e->getMessage(), 'login')) {
                    return response()->json([
                        'message' => 'Unauthenticated.',
                        'status' => 'error'
                    ], 401);
                }
                // Return generic error for other missing routes
                return response()->json([
                    'message' => 'Route not found.',
                    'status' => 'error'
                ], 404);
            }
            return null;
        });

        // Catch all other exceptions for API requests
        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                // Log the actual error with full details for debugging
                \Log::error('API Error: ' . get_class($e), [
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'url' => $request->url(),
                    'ip' => $request->ip(),
                    'user_id' => optional($request->user())->id,
                    'stack_trace' => $e->getTraceAsString(),
                ]);
                
                return response()->json([
                    'message' => 'Internal server error.',
                    'status' => 'error',
                    'error' => $e->getMessage()
                ], 500);
            }
            return null;
        });
    })->create();

