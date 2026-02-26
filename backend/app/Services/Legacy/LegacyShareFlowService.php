<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Services\Analytics\EventPayloadLimiter;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportComposer;
use App\Services\Scale\ScaleRegistry;
use App\Services\Share\Adapters\LegacyShareFlowAdapter;
use App\Services\Share\ShareFlowCoreService;
use App\Support\OrgContext;
use Psr\Log\LoggerInterface;

class LegacyShareFlowService
{
    private ShareFlowCoreService $core;

    public function __construct(
        OrgContext $orgContext,
        LegacyShareService $legacyShareService,
        ReportComposer $reportComposer,
        EventPayloadLimiter $eventPayloadLimiter,
        ScaleRegistry $scaleRegistry,
        EntitlementManager $entitlementManager,
        LoggerInterface $logger,
    ) {
        $this->core = new ShareFlowCoreService(
            $orgContext,
            new LegacyShareFlowAdapter($legacyShareService),
            $reportComposer,
            $eventPayloadLimiter,
            $scaleRegistry,
            $entitlementManager,
            $logger,
            'SHARE_LEGACY_EVENT_CREATE_FAILED'
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function getShareLinkForAttempt(string $attemptId, array $input): array
    {
        return $this->core->getShareLinkForAttempt($attemptId, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $requestMeta
     * @return array<string, mixed>
     */
    public function clickAndComposeReport(string $shareId, array $input, array $requestMeta): array
    {
        return $this->core->clickAndComposeReport($shareId, $input, $requestMeta);
    }

    /**
     * @return array<string, mixed>
     */
    public function getShareView(string $shareId): array
    {
        return $this->core->getShareView($shareId);
    }
}
