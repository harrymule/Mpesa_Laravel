<?php

namespace Harri\LaravelMpesa\Exceptions;

use Exception;
use Throwable;

class MpesaRequestException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected array $details = [],
        protected ?string $journey = null,
        protected string $stage = 'request',
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function details(): array
    {
        return $this->details;
    }

    public function journey(): ?string
    {
        return $this->journey;
    }

    public function stage(): string
    {
        return $this->stage;
    }
}
