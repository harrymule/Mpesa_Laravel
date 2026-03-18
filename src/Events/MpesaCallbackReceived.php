<?php

namespace Harri\LaravelMpesa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MpesaCallbackReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public array $payload)
    {
    }
}
