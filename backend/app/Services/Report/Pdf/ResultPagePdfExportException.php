<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use RuntimeException;
use Throwable;

final class ResultPagePdfExportException extends RuntimeException
{
    public function __construct(
        private readonly string $traceId,
        private readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
