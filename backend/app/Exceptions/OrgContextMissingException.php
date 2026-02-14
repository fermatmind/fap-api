<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrgContextMissingException extends HttpException
{
    /**
     * @var array<string, mixed>
     */
    private array $details;

    public function __construct(?string $modelClass = null)
    {
        $this->details = $modelClass !== null ? ['model' => $modelClass] : [];

        parent::__construct(404, 'org context required.');
    }

    public function errorCode(): string
    {
        return 'ORG_CONTEXT_MISSING';
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
