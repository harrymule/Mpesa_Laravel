<?php

namespace Harri\LaravelMpesa\Support;

use Harri\LaravelMpesa\Contracts\C2bValidationResponder;

class AcceptC2bValidation implements C2bValidationResponder
{
    public function respond(array $payload): array
    {
        return [
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ];
    }
}
