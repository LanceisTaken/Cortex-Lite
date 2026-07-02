<?php

namespace App\Exceptions;

use RuntimeException;

class SteamApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Steam API request failed.',
        private readonly int $statusCode = 502,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function badGateway(string $message = 'Steam API request failed.'): self
    {
        return new self($message, 502);
    }

    public static function serviceUnavailable(
        string $message = 'Steam API is temporarily unavailable.',
        ?\Throwable $previous = null,
    ): self {
        return new self($message, 503, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
