<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ApiProblemException extends HttpException
{
    /** @var array<string, mixed> */
    private array $details;

    public function __construct(
        int $statusCode,
        private readonly string $errorCode,
        string $message,
        array $details = [],
        ?Throwable $previous = null,
        array $headers = [],
        int $code = 0,
    ) {
        parent::__construct($statusCode, $message, $previous, $headers, $code);

        $this->details = $details;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
