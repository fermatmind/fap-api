<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\DTO;

final class WebhookRequestContext
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $payloadMeta
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
        public readonly int $orgId = 0,
        public readonly ?string $userId = null,
        public readonly ?string $anonId = null,
        public readonly bool $signatureOk = true,
        public readonly array $payloadMeta = [],
        public readonly string $rawPayloadSha256 = '',
        public readonly int $rawPayloadBytes = -1,
    ) {
    }
}
