<?php

namespace Harri\LaravelMpesa\Http\Controllers\Api;

use Harri\LaravelMpesa\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MpesaTransactionController extends Controller
{
    public function registerC2bUrls(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'ShortCode' => ['nullable', 'string'],
            'ResponseType' => ['nullable', 'string'],
            'ConfirmationURL' => ['nullable', 'url'],
            'ValidationURL' => ['nullable', 'url'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->registerC2bUrls($this->withoutMeta($data), $data['meta'] ?? []);

        return $this->respond('C2B URLs registered successfully.', $result);
    }

    public function simulateC2b(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'ShortCode' => ['nullable', 'string'],
            'CommandID' => ['nullable', 'string'],
            'Amount' => ['required', 'numeric', 'min:1'],
            'Msisdn' => ['required', 'string'],
            'BillRefNumber' => ['required', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->simulateC2b($this->withoutMeta($data), $data['meta'] ?? []);

        return $this->respond('C2B simulation submitted successfully.', $result);
    }

    public function b2c(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'InitiatorName' => ['nullable', 'string'],
            'CommandID' => ['nullable', 'string'],
            'Amount' => ['required', 'numeric', 'min:1'],
            'PartyA' => ['nullable', 'string'],
            'PartyB' => ['required', 'string'],
            'Remarks' => ['nullable', 'string'],
            'QueueTimeOutURL' => ['nullable', 'url'],
            'ResultURL' => ['nullable', 'url'],
            'Occasion' => ['nullable', 'string'],
            'callback_url' => ['nullable', 'url'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->b2c($this->withoutSystemFields($data), $data['callback_url'] ?? null, $data['meta'] ?? []);

        return $this->respond('B2C request submitted successfully.', $result);
    }

    public function b2b(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'Initiator' => ['nullable', 'string'],
            'CommandID' => ['nullable', 'string'],
            'SenderIdentifierType' => ['nullable', 'string'],
            'RecieverIdentifierType' => ['nullable', 'string'],
            'Amount' => ['required', 'numeric', 'min:1'],
            'PartyA' => ['nullable', 'string'],
            'PartyB' => ['required', 'string'],
            'AccountReference' => ['required', 'string'],
            'Remarks' => ['nullable', 'string'],
            'QueueTimeOutURL' => ['nullable', 'url'],
            'ResultURL' => ['nullable', 'url'],
            'callback_url' => ['nullable', 'url'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->b2b($this->withoutSystemFields($data), $data['callback_url'] ?? null, $data['meta'] ?? []);

        return $this->respond('B2B request submitted successfully.', $result);
    }

    public function reversal(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'Initiator' => ['nullable', 'string'],
            'CommandID' => ['nullable', 'string'],
            'TransactionID' => ['required', 'string'],
            'Amount' => ['required', 'numeric', 'min:1'],
            'ReceiverParty' => ['nullable', 'string'],
            'RecieverIdentifierType' => ['nullable', 'string'],
            'QueueTimeOutURL' => ['nullable', 'url'],
            'ResultURL' => ['nullable', 'url'],
            'Remarks' => ['nullable', 'string'],
            'Occasion' => ['nullable', 'string'],
            'callback_url' => ['nullable', 'url'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->reversal($this->withoutSystemFields($data), $data['callback_url'] ?? null, $data['meta'] ?? []);

        return $this->respond('Reversal request submitted successfully.', $result);
    }

    public function accountBalance(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'Initiator' => ['nullable', 'string'],
            'CommandID' => ['nullable', 'string'],
            'PartyA' => ['nullable', 'string'],
            'IdentifierType' => ['nullable', 'string'],
            'Remarks' => ['nullable', 'string'],
            'QueueTimeOutURL' => ['nullable', 'url'],
            'ResultURL' => ['nullable', 'url'],
            'callback_url' => ['nullable', 'url'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->accountBalance($this->withoutSystemFields($data), $data['callback_url'] ?? null, $data['meta'] ?? []);

        return $this->respond('Account balance request submitted successfully.', $result);
    }

    public function transactionStatus(Request $request, TransactionService $service): JsonResponse
    {
        $data = $request->validate([
            'Initiator' => ['nullable', 'string'],
            'CommandID' => ['nullable', 'string'],
            'TransactionID' => ['required', 'string'],
            'PartyA' => ['nullable', 'string'],
            'IdentifierType' => ['nullable', 'string'],
            'QueueTimeOutURL' => ['nullable', 'url'],
            'ResultURL' => ['nullable', 'url'],
            'Remarks' => ['nullable', 'string'],
            'Occasion' => ['nullable', 'string'],
            'OriginalConversationID' => ['nullable', 'string'],
            'callback_url' => ['nullable', 'url'],
            'meta' => ['nullable', 'array'],
        ]);

        $result = $service->transactionStatus($this->withoutSystemFields($data), $data['callback_url'] ?? null, $data['meta'] ?? []);

        return $this->respond('Transaction status request submitted successfully.', $result);
    }

    protected function respond(string $message, array $result): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'transaction_id' => $result['transaction']->id ?? null,
            'merchant_request_id' => $result['response']['MerchantRequestID'] ?? null,
            'checkout_request_id' => $result['response']['CheckoutRequestID'] ?? null,
            'conversation_id' => $result['response']['ConversationID'] ?? null,
            'originator_conversation_id' => $result['response']['OriginatorConversationID'] ?? null,
            'response' => $result['response'],
        ]);
    }

    protected function withoutMeta(array $data): array
    {
        unset($data['meta']);

        return $data;
    }

    protected function withoutSystemFields(array $data): array
    {
        unset($data['callback_url'], $data['meta']);

        return $data;
    }
}
