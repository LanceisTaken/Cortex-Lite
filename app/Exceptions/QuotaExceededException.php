<?php

namespace App\Exceptions;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $type,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct("Free-tier quota exceeded for {$type}.");
    }
}
