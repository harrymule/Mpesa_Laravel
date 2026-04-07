<?php

namespace Harri\LaravelMpesa\Http\Responses;

use Harri\LaravelMpesa\Exceptions\MpesaRequestException;
use Harri\LaravelMpesa\Support\MpesaErrorCatalog;
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
        $details = $exception->details();

        if ($details === []) {
            $decoded = json_decode($exception->getMessage(), true);
            $details = is_array($decoded) ? $decoded : [];
        }

        $fallbackMessage = $details['errorMessage'] ?? ($exception->getMessage() !== '' ? $exception->getMessage() : 'M-Pesa request failed.');
        $journey = $exception->journey();
        $stage = $exception->stage();
        $catalogEntry = MpesaErrorCatalog::record($status, $details, (string) $fallbackMessage, $journey, $stage);

        return self::error(
            $catalogEntry?->title ?: (string) $fallbackMessage,
            $catalogEntry?->error_key ?: 'mpesa_request_failed',
            $status,
            array_filter([
                ...$details,
                'journey' => $journey,
                'error_stage' => $stage,
                'mpesa_error_code' => $catalogEntry?->code,
                'possible_cause' => $catalogEntry?->possible_cause,
                'mitigation' => $catalogEntry?->mitigation,
                'known_error' => $catalogEntry?->is_known,
            ], fn ($value) => $value !== null)
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
