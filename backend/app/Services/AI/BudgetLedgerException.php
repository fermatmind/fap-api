<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;
use Throwable;

final class BudgetLedgerException extends RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message !== '' ? $message : $errorCode, $code, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
