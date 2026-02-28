<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

final class ExternalKmsContractException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
