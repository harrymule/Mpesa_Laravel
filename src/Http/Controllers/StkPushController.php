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
}
