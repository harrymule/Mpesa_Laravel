<?php

namespace Harri\LaravelMpesa\Http\Controllers;

use Harri\LaravelMpesa\Services\StkPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StkPushController extends Controller
{
    public function store(Request $request, StkPushService $service): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string'],
            'reference' => ['required', 'string'],
            'callback_url' => ['nullable', 'url'],
            'description' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->initiate(
            amount: $data['amount'],
            phone: $data['phone'],
            reference: $data['reference'],
            callbackUrl: $data['callback_url'] ?? null,
            description: $data['description'] ?? null,
            meta: $data['meta'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'STK push initiated successfully.',
            'tracking_id' => $result['tracking_id'],
            'merchant_request_id' => $result['merchant_request_id'],
            'checkout_request_id' => $result['checkout_request_id'],
            'response' => $result['response'],
        ]);
    }

    public function query(Request $request, StkPushService $service): JsonResponse
    {
        $data = $request->validate([
            'checkout_request_id' => ['required', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->query($data['checkout_request_id'], $data['meta'] ?? []);

        return response()->json([
            'success' => true,
            'message' => 'STK query completed successfully.',
            'merchant_request_id' => $result['merchant_request_id'],
            'checkout_request_id' => $result['checkout_request_id'],
            'response_code' => $result['response']['ResponseCode'] ?? null,
            'response_description' => $result['response']['ResponseDescription'] ?? null,
            'result_code' => $result['response']['ResultCode'] ?? null,
            'result_desc' => $result['response']['ResultDesc'] ?? null,
            'response' => $result['response'],
        ]);
    }

    public function status(string $trackingId, StkPushService $service): JsonResponse
    {
        $result = $service->status($trackingId);

        return response()->json([
            'success' => $result['matched'] ?? false,
            'message' => ($result['matched'] ?? false)
                ? 'STK push status retrieved successfully.'
                : 'STK push tracking record was not found.',
            ...$result,
        ], ($result['matched'] ?? false) ? 200 : 404);
    }

    public function paybillInstructions(string $trackingId, StkPushService $service): JsonResponse
    {
        $result = $service->paybillInstructions($trackingId);

        if (! ($result['matched'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'STK push tracking record was not found.',
                ...$result,
            ], 404);
        }

        if (! ($result['fallback_enabled'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'C2B fallback is not enabled for this package instance.',
                ...$result,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'PayBill fallback instructions retrieved successfully.',
            ...$result,
        ]);
    }

    public function verifyManualPayment(Request $request, StkPushService $service): JsonResponse
    {
        $data = $request->validate([
            'receipt_number' => ['required', 'string'],
            'tracking_id' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string'],
        ]);

        $result = $service->verifyManualPayment(
            receiptNumber: $data['receipt_number'],
            trackingId: $data['tracking_id'] ?? null,
            phone: $data['phone'] ?? null,
            amount: $data['amount'] ?? null,
            reference: $data['reference'] ?? null,
        );

        return response()->json([
            'success' => $result['verified'] ?? false,
            ...$result,
        ], ($result['verified'] ?? false) ? 200 : 422);
    }
}
