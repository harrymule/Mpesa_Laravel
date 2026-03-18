<?php

namespace Harri\LaravelMpesa;

use Harri\LaravelMpesa\Exceptions\MpesaRequestException;
use Harri\LaravelMpesa\Http\Middleware\EnsureAuthorizedInitiationRequest;
use Harri\LaravelMpesa\Http\Middleware\EnsureTrustedCallbackRequest;
use Harri\LaravelMpesa\Http\Responses\ApiErrorResponse;
use Harri\LaravelMpesa\Services\MpesaCallbackProcessor;
use Harri\LaravelMpesa\Services\StkPushService;
use Harri\LaravelMpesa\Services\TransactionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Throwable;

class MpesaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mpesa.php', 'mpesa');

        $this->app->singleton(MpesaClient::class, fn () => new MpesaClient(config('mpesa')));
        $this->app->singleton(StkPushService::class, fn ($app) => new StkPushService($app->make(MpesaClient::class)));
        $this->app->singleton(TransactionService::class, fn ($app) => new TransactionService($app->make(MpesaClient::class)));
        $this->app->singleton(MpesaCallbackProcessor::class, fn () => new MpesaCallbackProcessor());
        $this->app->singleton('mpesa', fn ($app) => $app->make(MpesaClient::class));

        $this->registerExceptionRendering();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/mpesa.php' => config_path('mpesa.php'),
        ], 'mpesa-config');

        if (config('mpesa.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        $this->registerRateLimiter();

        $router = $this->app['router'];
        $router->aliasMiddleware('mpesa.initiation.auth', EnsureAuthorizedInitiationRequest::class);
        $router->aliasMiddleware('mpesa.callback.auth', EnsureTrustedCallbackRequest::class);

        if (config('mpesa.load_routes', true)) {
            $prefix = (string) config('mpesa.route_prefix', 'mpesa');

            if (config('mpesa.load_initiation_routes', true)) {
                Route::prefix($prefix)
                    ->middleware(config('mpesa.initiation_route_middleware', config('mpesa.route_middleware', ['api'])))
                    ->group(__DIR__ . '/../routes/initiation.php');
            }

            if (config('mpesa.load_callback_routes', true)) {
                Route::prefix($prefix)
                    ->middleware(config('mpesa.callback_route_middleware', config('mpesa.route_middleware', ['api'])))
                    ->group(__DIR__ . '/../routes/callbacks.php');
            }
        }
    }

    protected function registerRateLimiter(): void
    {
        $limiterName = (string) config('mpesa.rate_limit.name', 'mpesa.initiation');

        RateLimiter::for($limiterName, function (Request $request) {
            if (! config('mpesa.rate_limit.enabled', true)) {
                return Limit::none();
            }

            $maxAttempts = max(1, (int) config('mpesa.rate_limit.max_attempts', 60));
            $decaySeconds = max(1, (int) config('mpesa.rate_limit.decay_seconds', 60));

            return Limit::perSecond($maxAttempts, $decaySeconds)
                ->by($request->ip() ?: 'mpesa-initiation')
                ->response(function ($request, array $headers) {
                    return ApiErrorResponse::error(
                        'Too many M-Pesa initiation requests. Please retry shortly.',
                        'mpesa_rate_limited',
                        429,
                        ['headers' => $headers],
                    )->withHeaders($headers);
                });
        });
    }

    protected function registerExceptionRendering(): void
    {
        $this->app->afterResolving('Illuminate\\Contracts\\Debug\\ExceptionHandler', function ($handler) {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(function (ValidationException $exception, Request $request) {
                if ($this->isMpesaRequest($request)) {
                    return ApiErrorResponse::validation($exception);
                }
            });

            $handler->renderable(function (MpesaRequestException $exception, Request $request) {
                if ($this->isMpesaRequest($request)) {
                    return ApiErrorResponse::fromMpesaException($exception);
                }
            });

            $handler->renderable(function (ConnectionException $exception, Request $request) {
                if ($this->isMpesaRequest($request)) {
                    return ApiErrorResponse::error(
                        'Unable to reach Safaricom Daraja.',
                        'mpesa_connection_failed',
                        503,
                    );
                }
            });

            $handler->renderable(function (HttpResponseException $exception, Request $request) {
                if ($this->isMpesaRequest($request)) {
                    return $exception->getResponse();
                }
            });

            $handler->renderable(function (Throwable $exception, Request $request) {
                if ($this->isMpesaRequest($request) && $request->expectsJson()) {
                    return ApiErrorResponse::error(
                        (bool) config('app.debug', false) ? $exception->getMessage() : 'Unexpected M-Pesa package error.',
                        'mpesa_internal_error',
                        500,
                    );
                }
            });
        });
    }

    protected function isMpesaRequest(Request $request): bool
    {
        $prefix = trim((string) config('mpesa.route_prefix', 'mpesa'), '/');

        return $request->is($prefix) || $request->is($prefix . '/*');
    }
}
