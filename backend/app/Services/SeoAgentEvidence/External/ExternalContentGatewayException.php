<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

use RuntimeException;

final class ExternalContentGatewayException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $stage,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($reasonCode);
    }
}
