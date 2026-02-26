<?php

declare(strict_types=1);

namespace App\Services\Share\Adapters;

use App\Models\Attempt;
use App\Services\Share\Contracts\ShareFlowAdapter;
use App\Services\V0_3\ShareService;
use App\Support\OrgContext;

final class V03ShareFlowAdapter implements ShareFlowAdapter
{
    public function __construct(private readonly ShareService $shareService) {}

    public function resolveAttemptForAuth(string $attemptId, OrgContext $ctx): Attempt
    {
        return $this->shareService->resolveAttemptForAuth($attemptId, $ctx);
    }

    public function getOrCreateShare(string $attemptId, OrgContext $ctx): array
    {
        return $this->shareService->getOrCreateShare($attemptId, $ctx);
    }

    public function getShareView(string $shareId): array
    {
        return $this->shareService->getShareView($shareId);
    }
}
