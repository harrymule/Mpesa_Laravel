<?php

namespace Harri\LaravelMpesa\Http\Responses;

use Harri\LaravelMpesa\Exceptions\MpesaRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ApiErrorResponse
{
    public static function validation(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'error' => 'validation_error',
            'status' => 422,
            'errors' => $exception->errors(),
        ], 422);
    }

    public static function fromMpesaException(MpesaRequestException $exception): JsonResponse
    {
        $status = is_int($exception->getCode()) && $exception->getCode() >= 400 && $exception->getCode() <= 599 ? $exception->getCode() : 422;
        $details = json_decode($exception->getMessage(), true);

        return self::error(
            is_array($details) && isset($details['errorMessage']) ? (string) $details['errorMessage'] : ($exception->getMessage() !== '' ? $exception->getMessage() : 'M-Pesa request failed.'),
            'mpesa_request_failed',
            $status,
            is_array($details) ? $details : []
        );
    }

    public static function error(string $message, string $error, int $status, array $context = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'error' => $error,
            'status' => $status,
            'details' => $context === [] ? null : $context,
        ], fn ($value) => $value !== null), $status);
    }
}
