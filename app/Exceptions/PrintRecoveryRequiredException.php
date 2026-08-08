<?php

namespace App\Exceptions;

use RuntimeException;

class PrintRecoveryRequiredException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $identityValidated = false
    ) {
        parent::__construct($message);
    }
}
