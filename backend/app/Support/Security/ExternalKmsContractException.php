<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

final class ExternalKmsContractException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly bool $retryable,
        private readonly string $category,
        private readonly string $operation
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function operation(): string
    {
        return $this->operation;
    }
}
