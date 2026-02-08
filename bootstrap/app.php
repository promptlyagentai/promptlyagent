<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*', headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\ForceHttpsForSignedUrls::class,
        ]);

        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'api/*',
        ]);

        $middleware->alias([
            'trigger.ip.whitelist' => \App\Http\Middleware\CheckTriggerIpWhitelist::class,
            'auth.token.query' => \App\Http\Middleware\AuthenticateWithQueryToken::class,
        ]);

        // Setup middleware removed - admin users must be created via CLI (php artisan make:admin)
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $statusCode = 500;
            $errorCode = 'INTERNAL_ERROR';
            $userMessage = 'An internal error occurred. Please try again or contact support.';

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $statusCode = 422;
                $errorCode = 'VALIDATION_ERROR';
                $userMessage = 'The provided data is invalid.';
            } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $statusCode = 401;
                $errorCode = 'UNAUTHENTICATED';
                $userMessage = 'Authentication required.';
            } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $statusCode = 403;
                $errorCode = 'UNAUTHORIZED';
                $userMessage = 'You do not have permission to perform this action.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $statusCode = 404;
                $errorCode = 'NOT_FOUND';
                $userMessage = 'The requested resource was not found.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                $statusCode = 405;
                $errorCode = 'METHOD_NOT_ALLOWED';
                $userMessage = 'This HTTP method is not allowed for this endpoint.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
                $statusCode = 429;
                $errorCode = 'TOO_MANY_REQUESTS';
                $userMessage = 'Too many requests. Please slow down.';
            }

            if ($statusCode >= 500) {
                \Illuminate\Support\Facades\Log::error('API Exception', [
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'user_id' => $request->user()?->id,
                    'ip' => $request->ip(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $errorCode,
                'message' => $userMessage,
                'timestamp' => now()->toIso8601String(),
            ], $statusCode);
        });
    })->create();
