<?php

declare(strict_types=1);

namespace App\Services\Share\Adapters;

use App\Models\Attempt;
use App\Services\Legacy\LegacyShareService;
use App\Services\Share\Contracts\ShareFlowAdapter;
use App\Support\OrgContext;

final class LegacyShareFlowAdapter implements ShareFlowAdapter
{
    public function __construct(private readonly LegacyShareService $legacyShareService) {}

    public function resolveAttemptForAuth(string $attemptId, OrgContext $ctx): Attempt
    {
        return $this->legacyShareService->resolveAttemptForAuth($attemptId, $ctx);
    }

    public function getOrCreateShare(string $attemptId, OrgContext $ctx): array
    {
        return $this->legacyShareService->getOrCreateShare($attemptId, $ctx);
    }

    public function getShareView(string $shareId): array
    {
        return $this->legacyShareService->getShareView($shareId);
    }
}
