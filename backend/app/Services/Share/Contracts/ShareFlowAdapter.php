<?php

declare(strict_types=1);

namespace App\Services\Share\Contracts;

use App\Models\Attempt;
use App\Support\OrgContext;

interface ShareFlowAdapter
{
    public function resolveAttemptForAuth(string $attemptId, OrgContext $ctx): Attempt;

    /**
     * @return array<string, mixed>
     */
    public function getOrCreateShare(string $attemptId, OrgContext $ctx): array;

    /**
     * @return array<string, mixed>
     */
    public function getShareView(string $shareId): array;
}
