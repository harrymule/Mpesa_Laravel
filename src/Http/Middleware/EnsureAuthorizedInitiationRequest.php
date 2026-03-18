<?php

namespace Harri\LaravelMpesa\Http\Middleware;

use Harri\LaravelMpesa\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;

class EnsureAuthorizedInitiationRequest
{
    public function handle(Request $request, Closure $next)
    {
        $token = (string) config('mpesa.security.initiation_token');

        if ($token === '') {
            return $next($request);
        }

        $provided = $request->bearerToken() ?: $request->header('X-Mpesa-Token');

        if (! is_string($provided) || ! hash_equals($token, $provided)) {
            return ApiErrorResponse::error('Unauthorized initiation request.', 'mpesa_unauthorized', 401);
        }

        return $next($request);
    }
}
