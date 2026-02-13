<?php

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\GenericReportBuilder;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\SkuCatalog;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportGatekeeper
{
    private const SNAPSHOT_RETRY_AFTER_SECONDS = 3;

    private const DEFAULT_VIEW_POLICY = [
        'free_sections' => ['intro', 'score'],
        'blur_others' => true,
        'teaser_percent' => 0.3,
        'upgrade_sku' => null,
    ];

    public function __construct(
        private ScaleRegistry $registry,
        private EntitlementManager $entitlements,
        private SkuCatalog $skus,
        private ReportComposer $reportComposer,
        private GenericReportBuilder $genericReportBuilder,
        private EventRecorder $eventRecorder,
    ) {
    }

    public function ensureAccess(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId,
        ?string $role = null
    ): array {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return $this->badRequest('ATTEMPT_REQUIRED', 'attempt_id is required.');
        }

        $attempt = $this->ownedAttemptQuery($orgId, $attemptId, $userId, $anonId, $role)->first();
        if (!$attempt) {
            return $this->notFound('ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if (!$result) {
            return $this->notFound('RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return $this->badRequest('SCALE_REQUIRED', 'scale_code missing on attempt.');
        }

        $registry = $this->registry->getByCode($scaleCode, $orgId);
        if (!$registry) {
            return $this->notFound('SCALE_NOT_FOUND', 'scale not found.');
        }

        $commercial = $this->normalizeCommercial($registry['commercial_json'] ?? null);
        $benefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($benefitCode === '') {
            $benefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        $hasAccess = $benefitCode !== ''
            ? $this->entitlements->hasFullAccess($orgId, $userId, $anonId, $attemptId, $benefitCode)
            : false;

        return [
            'ok' => true,
            'locked' => !$hasAccess,
        ];
    }

    public function resolve(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId,
        ?string $role = null,
        bool $forceSystemAccess = false
    ): array {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return $this->badRequest('ATTEMPT_REQUIRED', 'attempt_id is required.');
        }

        $attempt = $this->ownedAttemptQuery($orgId, $attemptId, $userId, $anonId, $role, $forceSystemAccess)->first();
        if (!$attempt) {
            return $this->notFound('ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if (!$result) {
            return $this->notFound('RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return $this->badRequest('SCALE_REQUIRED', 'scale_code missing on attempt.');
        }

        $registry = $this->registry->getByCode($scaleCode, $orgId);
        if (!$registry) {
            return $this->notFound('SCALE_NOT_FOUND', 'scale not found.');
        }

        $viewPolicy = $this->normalizeViewPolicy($registry['view_policy_json'] ?? null);
        $commercial = $this->normalizeCommercial($registry['commercial_json'] ?? null);
        $paywall = $this->buildPaywall($viewPolicy, $commercial, $scaleCode);
        $viewPolicy = $paywall['view_policy'] ?? $viewPolicy;
        $benefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($benefitCode === '') {
            $benefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        $hasAccess = $benefitCode !== ''
            ? $this->entitlements->hasFullAccess($orgId, $userId, $anonId, $attemptId, $benefitCode)
            : false;

        if ($hasAccess) {
            $snapshotRow = DB::table('report_snapshots')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->first();

            if (!$snapshotRow) {
                $this->eventRecorder->record('report_snapshot_missing', $this->numericUserId($userId), [
                    'scale_code' => $scaleCode,
                    'attempt_id' => $attemptId,
                ], [
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'pack_id' => (string) ($attempt->pack_id ?? ''),
                    'dir_version' => (string) ($attempt->dir_version ?? ''),
                ]);

                return $this->serverError('REPORT_SNAPSHOT_MISSING', 'report snapshot missing.');
            }

            $snapshotStatus = strtolower(trim((string) ($snapshotRow->status ?? 'ready')));
            if ($snapshotStatus === 'pending') {
                return $this->responsePayload(
                    true,
                    'full',
                    $viewPolicy,
                    [],
                    $paywall,
                    [
                        'generating' => true,
                        'snapshot_error' => false,
                        'retry_after_seconds' => self::SNAPSHOT_RETRY_AFTER_SECONDS,
                    ]
                );
            }

            if ($snapshotStatus === 'failed') {
                return $this->responsePayload(
                    true,
                    'full',
                    $viewPolicy,
                    [],
                    $paywall,
                    [
                        'generating' => false,
                        'snapshot_error' => true,
                        'retry_after_seconds' => self::SNAPSHOT_RETRY_AFTER_SECONDS,
                    ]
                );
            }

            $report = $this->decodeReportJson($snapshotRow->report_json ?? null);

            return $this->responsePayload(false, 'full', $viewPolicy, $report, $paywall);
        }

        $built = $this->buildReport($scaleCode, $attempt, $result);
        if (!($built['ok'] ?? false)) {
            return $built;
        }

        $report = $built['report'] ?? [];
        if (!is_array($report)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        $teaser = $this->applyTeaser($report, $viewPolicy);

        return $this->responsePayload(true, 'free', $viewPolicy, $teaser, $paywall);
    }

    private function ownedAttemptQuery(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId,
        ?string $role,
        bool $forceSystemAccess = false
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgId);

        $normalizedRole = $role !== null ? strtolower(trim($role)) : null;
        $user = $userId !== null ? trim($userId) : '';
        $anon = $anonId !== null ? trim($anonId) : '';
        $user = $user !== '' ? $user : null;
        $anon = $anon !== '' ? $anon : null;

        if ($forceSystemAccess === true) {
            return $query;
        }

        if ($normalizedRole !== null && $this->isPrivilegedRole($normalizedRole)) {
            return $query;
        }

        if ($normalizedRole !== null && $this->isMemberLikeRole($normalizedRole)) {
            if ($user === null) {
                return $query->whereRaw('1=0');
            }

            return $query->where('user_id', $user);
        }

        if ($user === null && $anon === null) {
            return $query->whereRaw('1=0');
        }

        return $query->where(function ($q) use ($user, $anon) {
            if ($user !== null) {
                $q->where('user_id', $user);
            }
            if ($anon !== null) {
                if ($user !== null) {
                    $q->orWhere('anon_id', $anon);
                } else {
                    $q->where('anon_id', $anon);
                }
            }
        });
    }

    private function isPrivilegedRole(string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    private function isMemberLikeRole(string $role): bool
    {
        return in_array($role, ['member', 'viewer'], true);
    }

    private function normalizeViewPolicy(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        $raw = is_array($raw) ? $raw : [];

        $policy = array_merge(self::DEFAULT_VIEW_POLICY, $raw);

        $freeSections = $policy['free_sections'] ?? null;
        $policy['free_sections'] = is_array($freeSections) ? array_values($freeSections) : self::DEFAULT_VIEW_POLICY['free_sections'];

        $policy['blur_others'] = (bool) ($policy['blur_others'] ?? true);

        $pct = (float) ($policy['teaser_percent'] ?? self::DEFAULT_VIEW_POLICY['teaser_percent']);
        if ($pct < 0) $pct = 0;
        if ($pct > 1) $pct = 1;
        $policy['teaser_percent'] = $pct;

        $upgradeSku = trim((string) ($policy['upgrade_sku'] ?? ''));
        $policy['upgrade_sku'] = $upgradeSku !== '' ? $upgradeSku : null;

        return $policy;
    }

    private function normalizeCommercial(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        return is_array($raw) ? $raw : [];
    }

    private function buildPaywall(array $viewPolicy, array $commercial, string $scaleCode): array
    {
        $scaleCode = strtoupper(trim($scaleCode));
        $effectiveSku = strtoupper(trim((string) ($viewPolicy['upgrade_sku'] ?? '')));
        if ($effectiveSku === '' || $this->skus->isAnchorSku($effectiveSku, $scaleCode)) {
            $effectiveSku = $this->skus->defaultEffectiveSku($scaleCode) ?? $effectiveSku;
        }

        $viewPolicy['upgrade_sku'] = $effectiveSku !== '' ? $effectiveSku : null;

        $anchorSku = $this->skus->anchorForSku($effectiveSku, $scaleCode);
        if ($anchorSku === null || $anchorSku === '') {
            $anchorSku = $this->skus->defaultAnchorSku($scaleCode);
        }

        $offers = $this->buildOffersFromSkus($this->skus->listActiveSkus($scaleCode));
        if (count($offers) === 0) {
            $offers = $this->normalizeOffers($commercial['offers'] ?? null);
        }

        return [
            'upgrade_sku' => $anchorSku,
            'upgrade_sku_effective' => $effectiveSku !== '' ? $effectiveSku : null,
            'offers' => $offers,
            'view_policy' => $viewPolicy,
        ];
    }

    private function buildOffersFromSkus(array $items): array
    {
        $offers = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['meta_json'] ?? null;
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $meta = is_array($meta) ? $meta : [];

            if (array_key_exists('offer', $meta) && $meta['offer'] === false) {
                continue;
            }

            $grantType = trim((string) ($meta['grant_type'] ?? ''));
            if ($grantType === '') {
                $grantType = strtolower(trim((string) ($item['kind'] ?? '')));
            }

            $grantQty = isset($meta['grant_qty']) ? (int) $meta['grant_qty'] : 1;
            $periodDays = isset($meta['period_days']) ? (int) $meta['period_days'] : null;

            $entitlementId = trim((string) ($meta['entitlement_id'] ?? ''));

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'grant' => [
                    'type' => $grantType !== '' ? $grantType : null,
                    'qty' => $grantQty,
                    'period_days' => $periodDays,
                ],
            ];
        }

        return $offers;
    }

    private function normalizeOffers(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        $offers = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $grant = $this->normalizeGrant($item['grant'] ?? null);
            $entitlementId = trim((string) ($item['entitlement_id'] ?? ''));

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($item['title'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'grant' => $grant,
            ];
        }

        return $offers;
    }

    private function normalizeGrant(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        $raw = is_array($raw) ? $raw : [];

        $type = trim((string) ($raw['type'] ?? ''));
        $qty = isset($raw['qty']) ? (int) $raw['qty'] : null;
        $periodDays = isset($raw['period_days']) ? (int) $raw['period_days'] : null;

        return [
            'type' => $type !== '' ? $type : null,
            'qty' => $qty,
            'period_days' => $periodDays,
        ];
    }

    private function buildReport(string $scaleCode, Attempt $attempt, Result $result): array
    {
        try {
            if ($scaleCode === 'MBTI') {
                $composed = $this->reportComposer->compose($attempt, [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                ], $result);
                if (!($composed['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'error' => (string) ($composed['error'] ?? 'REPORT_FAILED'),
                        'message' => (string) ($composed['message'] ?? 'report generation failed.'),
                        'status' => (int) ($composed['status'] ?? 500),
                    ];
                }

                $report = $composed['report'] ?? null;

                return is_array($report)
                    ? ['ok' => true, 'report' => $report]
                    : [
                        'ok' => false,
                        'error' => 'REPORT_FAILED',
                        'message' => 'report generation failed.',
                        'status' => 500,
                    ];
            }

            $report = $this->genericReportBuilder->build($attempt, $result);

            return is_array($report)
                ? ['ok' => true, 'report' => $report]
                : [
                    'ok' => false,
                    'error' => 'REPORT_FAILED',
                    'message' => 'report generation failed.',
                    'status' => 500,
                ];
        } catch (\Throwable $e) {
            Log::error('[KEY] report_gatekeeper_build_failed', [
                'org_id' => (int) ($attempt->org_id ?? 0),
                'attempt_id' => (string) ($attempt->id ?? ''),
                'scale_code' => $scaleCode,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function applyTeaser(array $report, array $policy): array
    {
        $free = $policy['free_sections'] ?? [];
        $blur = (bool) ($policy['blur_others'] ?? true);
        $pct = (float) ($policy['teaser_percent'] ?? self::DEFAULT_VIEW_POLICY['teaser_percent']);

        if (isset($report['sections']) && is_array($report['sections'])) {
            $report['sections'] = $this->teaseSections($report['sections'], $free, $blur, $pct);
            return $report;
        }

        return $this->teaseSections($report, $free, $blur, $pct);
    }

    private function teaseSections(array $sections, array $freeSections, bool $blurOthers, float $pct): array
    {
        $out = [];
        $freeSet = [];
        foreach ($freeSections as $sec) {
            if (is_string($sec) && $sec !== '') {
                $freeSet[$sec] = true;
            }
        }

        foreach ($sections as $key => $value) {
            if (isset($freeSet[$key])) {
                $out[$key] = $value;
                continue;
            }

            if (!$blurOthers) {
                $out[$key] = null;
                continue;
            }

            $out[$key] = $this->blurValue($value, $pct);
        }

        return $out;
    }

    private function blurValue(mixed $value, float $pct): mixed
    {
        if (is_string($value)) {
            return '[LOCKED]';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $count = count($value);
                $take = $this->teaserCount($count, $pct);
                $slice = array_slice($value, 0, $take);
                $out = [];
                foreach ($slice as $item) {
                    $out[] = $this->blurValue($item, $pct);
                }
                return $out;
            }

            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->blurValue($item, $pct);
            }
            return $out;
        }

        return null;
    }

    private function teaserCount(int $count, float $pct): int
    {
        if ($count <= 0 || $pct <= 0) {
            return 0;
        }

        $take = (int) ceil($count * $pct);
        if ($take < 1) $take = 1;
        if ($take > $count) $take = $count;

        return $take;
    }

    private function decodeReportJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function responsePayload(
        bool $locked,
        string $accessLevel,
        array $viewPolicy,
        array $report,
        array $paywall = [],
        array $meta = []
    ): array
    {
        return [
            'ok' => true,
            'locked' => $locked,
            'access_level' => $accessLevel,
            'upgrade_sku' => $paywall['upgrade_sku'] ?? ($viewPolicy['upgrade_sku'] ?? null),
            'upgrade_sku_effective' => $paywall['upgrade_sku_effective'] ?? ($viewPolicy['upgrade_sku'] ?? null),
            'offers' => $paywall['offers'] ?? [],
            'view_policy' => $viewPolicy,
            'meta' => $meta,
            'report' => $report,
        ];
    }

    private function numericUserId(?string $userId): ?int
    {
        $userId = $userId !== null ? trim($userId) : '';
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function serverError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'status' => 500,
        ];
    }
}
