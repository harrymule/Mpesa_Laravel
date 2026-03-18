<?php

namespace Harri\LaravelMpesa\Http\Controllers;

use Harri\LaravelMpesa\Contracts\C2bValidationResponder;
use Harri\LaravelMpesa\Events\AccountBalanceResultReceived;
use Harri\LaravelMpesa\Events\B2bResultReceived;
use Harri\LaravelMpesa\Events\B2cResultReceived;
use Harri\LaravelMpesa\Events\B2cTimeoutReceived;
use Harri\LaravelMpesa\Events\C2bConfirmationReceived;
use Harri\LaravelMpesa\Events\C2bValidationReceived;
use Harri\LaravelMpesa\Events\ReversalResultReceived;
use Harri\LaravelMpesa\Events\StkCallbackReceived;
use Harri\LaravelMpesa\Events\TimeoutCallbackReceived;
use Harri\LaravelMpesa\Events\TransactionStatusResultReceived;
use Harri\LaravelMpesa\Services\MpesaCallbackProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    public function stk(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('stk', $payload);
        event(new StkCallbackReceived($payload));
        $processor->processStk($payload);

        return $this->accepted('Accepted');
    }

    public function genericTimeout(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('timeout', $payload);
        event(new TimeoutCallbackReceived($payload));
        $processor->processTimeoutAny($payload);

        return $this->accepted('Timeout accepted');
    }

    public function confirmation(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('confirmation', $payload);
        event(new C2bConfirmationReceived($payload));
        $processor->processC2bConfirmation($payload);

        return $this->accepted('Confirmation accepted');
    }

    public function validation(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('validation', $payload);
        event(new C2bValidationReceived($payload));

        $response = $this->validationResponder()->respond($payload);
        $resultCode = $response['ResultCode'] ?? 0;
        $resultDesc = (string) ($response['ResultDesc'] ?? ((string) $resultCode === '0' ? 'Accepted' : 'Rejected'));

        $processor->processC2bValidation($payload, (string) $resultCode === '0' ? 'validated' : 'rejected');

        return response()->json([
            'ResultCode' => $resultCode,
            'ResultDesc' => $resultDesc,
        ]);
    }

    public function result(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('b2c.result', $payload);
        event(new B2cResultReceived($payload));
        $processor->processResult('b2c', $payload);

        return $this->accepted('Result accepted');
    }

    public function timeout(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('b2c.timeout', $payload);
        event(new B2cTimeoutReceived($payload));
        $processor->processTimeout('b2c', $payload);

        return $this->accepted('Timeout accepted');
    }

    public function b2bResult(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('b2b.result', $payload);
        event(new B2bResultReceived($payload));
        $processor->processResult('b2b', $payload);

        return $this->accepted('B2B result accepted');
    }

    public function reversalResult(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('reversal.result', $payload);
        event(new ReversalResultReceived($payload));
        $processor->processResult('reversal', $payload);

        return $this->accepted('Reversal result accepted');
    }

    public function accountBalanceResult(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('account-balance.result', $payload);
        event(new AccountBalanceResultReceived($payload));
        $processor->processResult('account_balance', $payload);

        return $this->accepted('Account balance result accepted');
    }

    public function transactionStatusResult(Request $request, MpesaCallbackProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $this->log('transaction-status.result', $payload);
        event(new TransactionStatusResultReceived($payload));
        $processor->processResult('transaction_status', $payload);

        return $this->accepted('Transaction status result accepted');
    }

    protected function accepted(string $description): JsonResponse
    {
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => $description,
        ]);
    }

    protected function validationResponder(): C2bValidationResponder
    {
        $class = config('mpesa.c2b.validation_responder', \Harri\LaravelMpesa\Support\AcceptC2bValidation::class);

        return app($class);
    }

    protected function log(string $type, array $payload): void
    {
        Log::channel(config('mpesa.log_channel'))
            ->info("mpesa.{$type}.callback", $payload);
    }
}
