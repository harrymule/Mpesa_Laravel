<?php

namespace Harri\LaravelMpesa\Http\Middleware;

use Harri\LaravelMpesa\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;

class EnsureTrustedCallbackRequest
{
    public function handle(Request $request, Closure $next)
    {
        $trustedIps = config('mpesa.security.trusted_ips', []);
        $secret = (string) config('mpesa.security.callback_secret');

        if (is_array($trustedIps) && $trustedIps !== [] && ! in_array($request->ip(), $trustedIps, true)) {
            return ApiErrorResponse::error('Callback IP is not trusted.', 'mpesa_untrusted_callback_ip', 403, [
                'ip' => $request->ip(),
            ]);
        }

        if ($secret !== '') {
            $provided = $request->header('X-Mpesa-Callback-Secret') ?: $request->query('mpesa_secret');

            if (! is_string($provided) || ! hash_equals($secret, $provided)) {
                return ApiErrorResponse::error('Callback secret is invalid.', 'mpesa_untrusted_callback_secret', 403);
            }
        }

        $hmacResult = $this->validateHmacSignature($request);

        if ($hmacResult !== null) {
            return $hmacResult;
        }

        return $next($request);
    }

    protected function validateHmacSignature(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! config('mpesa.security.callback_hmac.enabled', false)) {
            return null;
        }

        $header = (string) config('mpesa.security.callback_hmac.header', 'X-Mpesa-Signature');
        $algorithm = strtolower((string) config('mpesa.security.callback_hmac.algorithm', 'sha256'));
        $encoding = strtolower((string) config('mpesa.security.callback_hmac.encoding', 'hex'));
        $secret = (string) config('mpesa.security.callback_hmac.secret', '');
        $required = (bool) config('mpesa.security.callback_hmac.required', false);
        $provided = $request->header($header);

        if (! in_array($algorithm, hash_hmac_algos(), true)) {
            return ApiErrorResponse::error('Callback HMAC algorithm is not supported.', 'mpesa_callback_hmac_algorithm_invalid', 500);
        }

        if ($secret === '') {
            return ApiErrorResponse::error('Callback HMAC secret is not configured.', 'mpesa_callback_hmac_secret_missing', 500);
        }

        if (! is_string($provided) || trim($provided) === '') {
            if ($required) {
                return ApiErrorResponse::error('Callback signature is missing.', 'mpesa_callback_signature_missing', 403);
            }

            return null;
        }

        $expected = hash_hmac($algorithm, $request->getContent(), $secret, $encoding === 'base64');
        $expected = $encoding === 'base64' ? base64_encode($expected) : $expected;

        if (! hash_equals($expected, trim($provided))) {
            return ApiErrorResponse::error('Callback signature is invalid.', 'mpesa_callback_signature_invalid', 403);
        }

        return null;
    }
}
