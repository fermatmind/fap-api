<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Ops\SeoIntel;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionSelector;
use App\Services\SeoIntel\Ledger\SeoLedgerSnapshotReadService;
use App\Services\SeoIntel\OpsDashboard\ContentLifecycleReadService;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeoIntelDashboardController
{
    public function __construct(
        private readonly SeoDashboardApiReadService $readService,
        private readonly SeoLedgerSnapshotReadService $ledgerReadService,
        private readonly SeoWeeklyDecisionSelector $weeklyDecisionSelector,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->respond($this->readService->overview());
    }

    public function urlTruth(): JsonResponse
    {
        return $this->respond($this->readService->urlTruth());
    }

    public function issues(Request $request): JsonResponse
    {
        return $this->respond($this->readService->issues($this->limit($request)));
    }

    public function trends(Request $request): JsonResponse
    {
        return $this->respond($this->readService->trends($this->limit($request)));
    }

    public function pagePerformance(Request $request): JsonResponse
    {
        return $this->respond($this->readService->pagePerformance($this->limit($request)));
    }

    public function opportunityQueue(Request $request): JsonResponse
    {
        return $this->respond($this->readService->opportunityQueue($this->limit($request)));
    }

    public function productionCloseout(): JsonResponse
    {
        return $this->respond($this->readService->productionCloseout());
    }

    public function contentLifecycle(Request $request, ContentLifecycleReadService $readService): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $locale = $request->query('locale');

        return $this->respond($readService->read(
            $page,
            $this->limit($request),
            is_string($locale) ? $locale : null,
        ));
    }

    public function technicalHealth(): JsonResponse
    {
        return $this->respond($this->readService->technicalHealth());
    }

    public function conversionFunnel(Request $request): JsonResponse
    {
        return $this->respond($this->readService->conversionFunnel(
            $this->trustedOrgId($request),
            $this->safeFilters($request),
            $this->limit($request),
        ));
    }

    public function experimentLedger(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $snapshot = $this->ledgerReadService->snapshot($page, $this->limit($request));

        return response()->json([
            'ok' => true,
            'data' => $snapshot,
            'meta' => [
                'contract_version' => SeoLedgerSnapshotReadService::CONTRACT_VERSION,
                'read_only' => true,
                'authority' => 'fap-api seo change ledger',
            ],
        ]);
    }

    public function weeklyDecisions(): JsonResponse
    {
        $snapshot = $this->weeklyDecisionSelector->snapshot();

        return response()->json([
            'ok' => true,
            'data' => $snapshot,
            'meta' => [
                'contract_version' => SeoWeeklyDecisionSelector::CONTRACT_VERSION,
                'read_only' => true,
                'authority' => 'fap-api seo decision cards',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'contract_version' => 'seo-dash-api-01.v1',
                'read_only' => true,
                'authority' => 'fap-api seo_intel read model',
            ],
        ]);
    }

    private function limit(Request $request, int $default = 25, int $maximum = 100): int
    {
        $raw = $request->query('limit', $default);
        $limit = is_numeric($raw) ? (int) $raw : $default;

        return max(1, min($limit, $maximum));
    }

    /**
     * @return array<string,mixed>
     */
    private function safeFilters(Request $request): array
    {
        return $request->only([
            'group_by',
            'window_days',
            'url',
            'lang',
            'page_type',
            'source_url',
            'source_article',
            'target_test',
            'scale_id',
            'form_id',
        ]);
    }

    private function trustedOrgId(Request $request): int
    {
        if ($request->attributes->get('org_context_trusted') !== true) {
            abort(403, 'Trusted organization context is required.');
        }

        $orgId = $request->attributes->get('fm_org_id');
        if (! is_int($orgId) && ! is_string($orgId) && ! is_numeric($orgId)) {
            abort(403, 'Trusted organization context is required.');
        }

        $normalized = trim((string) $orgId);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            abort(403, 'Trusted organization context is required.');
        }

        return max(0, (int) $normalized);
    }
}
