<?php

namespace Harri\LaravelMpesa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallbackForwardingFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $callbackUrl,
        public array $payload,
        public ?int $paymentId = null,
        public ?int $transactionId = null,
        public ?int $statusCode = null,
        public ?string $message = null,
    ) {
    }
}
