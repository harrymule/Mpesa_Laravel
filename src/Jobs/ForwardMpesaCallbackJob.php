<?php

namespace Harri\LaravelMpesa\Jobs;

use Harri\LaravelMpesa\Events\CallbackForwardingFailed;
use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Support\MpesaLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ForwardMpesaCallbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $callbackUrl,
        public array $payload,
        public ?int $paymentId = null,
        public ?int $transactionId = null,
        public ?string $journey = null,
    ) {
    }

    public function handle(): void
    {
        $this->logInfo('mpesa.callback.forwarding_started');

        try {
            $response = Http::retry(3, 1000)->post($this->callbackUrl, $this->payload);
        } catch (Throwable $exception) {
            $this->updateDeliveryState(false, 'exception');
            $this->emitFailure(null, $exception->getMessage());

            throw $exception;
        }

        $this->updateDeliveryState($response->successful(), (string) $response->status());

        if ($response->successful()) {
            $this->logInfo('mpesa.callback.forwarded', [
                'status_code' => $response->status(),
            ]);

            return;
        }

        $this->emitFailure($response->status(), 'Callback forwarding failed with non-success response.');
    }

    public function failed(Throwable $exception): void
    {
        $this->emitFailure(null, $exception->getMessage());
    }

    protected function updateDeliveryState(bool $success, ?string $statusCode): void
    {
        if ($this->paymentId && Schema::hasTable('mpesa_payments')) {
            Payment::query()->whereKey($this->paymentId)->update([
                'callback_success' => $success,
                'callback_attempts' => DB::raw('callback_attempts + 1'),
                'last_callback_code' => $statusCode,
                'last_callback_attempt' => now(),
            ]);
        }

        if ($this->transactionId && Schema::hasTable('mpesa_transactions')) {
            MpesaTransaction::query()->whereKey($this->transactionId)->update([
                'callback_success' => $success,
                'callback_attempts' => DB::raw('callback_attempts + 1'),
                'last_callback_code' => $statusCode,
                'last_callback_attempt' => now(),
            ]);
        }
    }

    protected function emitFailure(?int $statusCode, string $message): void
    {
        $context = [
            'callback_url' => $this->callbackUrl,
            'payment_id' => $this->paymentId,
            'transaction_id' => $this->transactionId,
            'status_code' => $statusCode,
            'message' => $message,
        ];

        Log::channel(MpesaLog::channel($this->journey ?? 'forwarding'))->error('mpesa.callback.forwarding_failed', $context);

        Event::dispatch(new CallbackForwardingFailed(
            callbackUrl: $this->callbackUrl,
            payload: $this->payload,
            paymentId: $this->paymentId,
            transactionId: $this->transactionId,
            statusCode: $statusCode,
            message: $message,
        ));
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::channel(MpesaLog::channel($this->journey ?? 'forwarding'))->info($message, array_merge([
            'callback_url' => $this->callbackUrl,
            'payment_id' => $this->paymentId,
            'transaction_id' => $this->transactionId,
        ], $context));
    }
}
