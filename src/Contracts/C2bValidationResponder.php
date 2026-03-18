<?php

namespace Harri\LaravelMpesa\Contracts;

interface C2bValidationResponder
{
    public function respond(array $payload): array;
}
