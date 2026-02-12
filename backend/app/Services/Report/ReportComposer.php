<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Composer\ReportComposeContext;
use App\Services\Report\Composer\ReportPayloadAssembler;
use App\Services\Report\Composer\ReportPersistence;

class ReportComposer
{
    public function __construct(
        private readonly ReportPayloadAssembler $assembler,
        private readonly ReportPersistence $persistence,
    ) {
    }

    public function compose(Attempt $attempt, array $ctx = [], ?Result $result = null): array
    {
        $composeContext = ReportComposeContext::fromAttempt($attempt, $result, $ctx);
        $payload = $this->assembler->assemble($composeContext);

        if (
            ($payload['ok'] ?? false) === true
            && $composeContext->persist
            && is_array($payload['report'] ?? null)
        ) {
            $attemptId = (string) ($payload['attempt_id'] ?? $composeContext->attemptId);
            $this->persistence->persist($attemptId, $payload['report']);
        }

        return $payload;
    }
}
