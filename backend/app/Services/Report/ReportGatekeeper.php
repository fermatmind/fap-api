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
    use ReportGatekeeperTeaserTrait;

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
        private BigFiveReportComposer $bigFiveReportComposer,
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
        bool $forceSystemAccess = false,
        bool $forceRefresh = false
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

        $hasFullAccess = $benefitCode !== ''
            ? $this->entitlements->hasFullAccess($orgId, $userId, $anonId, $attemptId, $benefitCode)
            : false;

        $modulesOffered = $this->collectModulesFromOffers((array) ($paywall['offers'] ?? []));
        if ($modulesOffered === []) {
            $modulesOffered = ReportAccess::allDefaultModulesOffered($scaleCode);
        }

        $modulesAllowed = $this->entitlements->getAllowedModulesForAttempt($orgId, $attemptId);
        if ($scaleCode === ReportAccess::SCALE_BIG5_OCEAN) {
            $modulesAllowed = array_values(array_filter(
                $modulesAllowed,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'big5_')
            ));
        }
        if ($modulesAllowed === []) {
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked($scaleCode);
        }

        $freeModule = ReportAccess::freeModuleForScale($scaleCode);
        $fullModule = ReportAccess::fullModuleForScale($scaleCode);

        if ($hasFullAccess) {
            $modulesAllowed = ReportAccess::normalizeModules(array_merge(
                $modulesAllowed,
                $modulesOffered,
                [$fullModule, $freeModule]
            ));
        }
        if (!in_array($freeModule, $modulesAllowed, true)) {
            $modulesAllowed[] = $freeModule;
            $modulesAllowed = ReportAccess::normalizeModules($modulesAllowed);
        }

        $modulesPreview = ReportAccess::normalizeModules($modulesOffered);
        $hasPaidModuleAccess = count(array_diff($modulesAllowed, [$freeModule])) > 0;

        $scoreContract = $this->extractScoreContract($result);
        $normsPayload = is_array($scoreContract['norms'] ?? null) ? $scoreContract['norms'] : [];
        $qualityPayload = is_array($scoreContract['quality'] ?? null) ? $scoreContract['quality'] : [];

        $variant = $hasPaidModuleAccess ? ReportAccess::VARIANT_FULL : ReportAccess::VARIANT_FREE;
        $reportAccessLevel = $variant === ReportAccess::VARIANT_FREE
            ? ReportAccess::REPORT_ACCESS_FREE
            : ReportAccess::REPORT_ACCESS_FULL;
        $locked = $variant === ReportAccess::VARIANT_FREE;

        $shouldUseSnapshot = $hasFullAccess && $this->modulesCoverOffered($modulesAllowed, $modulesOffered);
        if ($shouldUseSnapshot && !$forceRefresh) {
            $snapshotRow = DB::table('report_snapshots')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->first();

            if ($snapshotRow) {
                $snapshotStatus = strtolower(trim((string) ($snapshotRow->status ?? 'ready')));
                if ($snapshotStatus === 'pending') {
                    return $this->responsePayload(
                        false,
                        $reportAccessLevel,
                        $variant,
                        $viewPolicy,
                        [],
                        $paywall,
                        [
                            'generating' => true,
                            'snapshot_error' => false,
                            'retry_after_seconds' => self::SNAPSHOT_RETRY_AFTER_SECONDS,
                        ],
                        $modulesAllowed,
                        $modulesOffered,
                        $modulesPreview,
                        $normsPayload,
                        $qualityPayload
                    );
                }

                if ($snapshotStatus === 'failed') {
                    return $this->responsePayload(
                        false,
                        $reportAccessLevel,
                        $variant,
                        $viewPolicy,
                        [],
                        $paywall,
                        [
                            'generating' => false,
                            'snapshot_error' => true,
                            'retry_after_seconds' => self::SNAPSHOT_RETRY_AFTER_SECONDS,
                        ],
                        $modulesAllowed,
                        $modulesOffered,
                        $modulesPreview,
                        $normsPayload,
                        $qualityPayload
                    );
                }

                $report = $this->decodeReportJson($snapshotRow->report_full_json ?? null);
                if ($report === []) {
                    $report = $this->decodeReportJson($snapshotRow->report_json ?? null);
                }

                return $this->responsePayload(
                    $locked,
                    $reportAccessLevel,
                    $variant,
                    $viewPolicy,
                    $report,
                    $paywall,
                    [],
                    $modulesAllowed,
                    $modulesOffered,
                    $modulesPreview,
                    $normsPayload,
                    $qualityPayload
                );
            }
        }

        $built = $this->buildReportVariant(
            $scaleCode,
            $attempt,
            $result,
            $variant,
            $modulesAllowed,
            $modulesPreview
        );
        if (!($built['ok'] ?? false)) {
            return $built;
        }

        $report = $built['report'] ?? [];
        if (!is_array($report)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        // Non-MBTI reports are still built by GenericReportBuilder. Re-apply teaser
        // masking when locked to avoid exposing full payload to unpaid users.
        if ($locked && !in_array(strtoupper($scaleCode), ['MBTI', 'BIG5_OCEAN'], true)) {
            $report = $this->applyTeaser($report, $viewPolicy);
        }

        if ($shouldUseSnapshot && $variant === ReportAccess::VARIANT_FULL) {
            $reportFreeBuilt = $this->buildReportVariant(
                $scaleCode,
                $attempt,
                $result,
                ReportAccess::VARIANT_FREE,
                ReportAccess::defaultModulesAllowedForLocked($scaleCode),
                $modulesPreview
            );
            $reportFree = is_array($reportFreeBuilt['report'] ?? null) ? $reportFreeBuilt['report'] : [];
            $this->upsertSnapshotVariants($orgId, $attempt, $result, $reportFree, $report);
        }

        return $this->responsePayload(
            $locked,
            $reportAccessLevel,
            $variant,
            $viewPolicy,
            $report,
            $paywall,
            [],
            $modulesAllowed,
            $modulesOffered,
            $modulesPreview,
            $normsPayload,
            $qualityPayload
        );
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
            $modulesIncluded = $this->normalizeModulesIncluded(
                $item['modules_included'] ?? ($meta['modules_included'] ?? null)
            );

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'modules_included' => $modulesIncluded,
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
            $modulesIncluded = $this->normalizeModulesIncluded($item['modules_included'] ?? null);

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($item['title'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'modules_included' => $modulesIncluded,
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

    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
    }

    private function collectModulesFromOffers(array $offers): array
    {
        $modules = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $modules = array_merge(
                $modules,
                $this->normalizeModulesIncluded($offer['modules_included'] ?? null)
            );
        }

        return ReportAccess::normalizeModules($modules);
    }

    private function modulesCoverOffered(array $modulesAllowed, array $modulesOffered): bool
    {
        if ($modulesOffered === []) {
            return true;
        }

        $allowed = array_fill_keys(ReportAccess::normalizeModules($modulesAllowed), true);
        foreach (ReportAccess::normalizeModules($modulesOffered) as $module) {
            if (!isset($allowed[$module])) {
                return false;
            }
        }

        return true;
    }

    private function upsertSnapshotVariants(
        int $orgId,
        Attempt $attempt,
        Result $result,
        array $reportFree,
        array $reportFull
    ): void {
        try {
            $reportFullJson = json_encode($reportFull, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reportFreeJson = json_encode($reportFree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($reportFullJson === false || $reportFreeJson === false) {
                return;
            }

            $attemptId = (string) ($attempt->id ?? '');
            if ($attemptId === '') {
                return;
            }

            $now = now();
            $row = [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'order_no' => null,
                'scale_code' => strtoupper((string) ($attempt->scale_code ?? $result->scale_code ?? 'MBTI')),
                'pack_id' => (string) ($attempt->pack_id ?? $result->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? $result->dir_version ?? ''),
                'scoring_spec_version' => $attempt->scoring_spec_version ?? $result->scoring_spec_version ?? null,
                'report_engine_version' => 'v1.2',
                'snapshot_version' => 'v1',
                'report_json' => $reportFullJson,
                'report_free_json' => $reportFreeJson,
                'report_full_json' => $reportFullJson,
                'status' => 'ready',
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            DB::table('report_snapshots')->insertOrIgnore($row);

            $updates = $row;
            unset($updates['created_at']);
            DB::table('report_snapshots')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->update($updates);
        } catch (\Throwable $e) {
            Log::warning('[REPORT] snapshot_variant_upsert_failed', [
                'org_id' => $orgId,
                'attempt_id' => (string) ($attempt->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildReportVariant(
        string $scaleCode,
        Attempt $attempt,
        Result $result,
        string $variant,
        array $modulesAllowed = [],
        array $modulesPreview = []
    ): array
    {
        try {
            if ($scaleCode === 'MBTI') {
                $composed = $this->reportComposer->composeVariant($attempt, $variant, [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                    'variant' => $variant,
                    'report_access_level' => $variant === ReportAccess::VARIANT_FREE
                        ? ReportAccess::REPORT_ACCESS_FREE
                        : ReportAccess::REPORT_ACCESS_FULL,
                    'modules_allowed' => $modulesAllowed,
                    'modules_preview' => $modulesPreview,
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

            if ($scaleCode === 'BIG5_OCEAN') {
                $composed = $this->bigFiveReportComposer->composeVariant($attempt, $result, $variant, [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                    'variant' => $variant,
                    'report_access_level' => $variant === ReportAccess::VARIANT_FREE
                        ? ReportAccess::REPORT_ACCESS_FREE
                        : ReportAccess::REPORT_ACCESS_FULL,
                    'modules_allowed' => $modulesAllowed,
                    'modules_preview' => $modulesPreview,
                ]);
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
        string $variant,
        array $viewPolicy,
        array $report,
        array $paywall = [],
        array $meta = [],
        array $modulesAllowed = [],
        array $modulesOffered = [],
        array $modulesPreview = [],
        array $norms = [],
        array $quality = []
    ): array
    {
        return [
            'ok' => true,
            'locked' => $locked,
            'access_level' => ReportAccess::normalizeReportAccessLevel($accessLevel),
            'variant' => ReportAccess::normalizeVariant($variant),
            'upgrade_sku' => $paywall['upgrade_sku'] ?? ($viewPolicy['upgrade_sku'] ?? null),
            'upgrade_sku_effective' => $paywall['upgrade_sku_effective'] ?? ($viewPolicy['upgrade_sku'] ?? null),
            'offers' => $paywall['offers'] ?? [],
            'modules_allowed' => ReportAccess::normalizeModules($modulesAllowed),
            'modules_offered' => ReportAccess::normalizeModules($modulesOffered),
            'modules_preview' => ReportAccess::normalizeModules($modulesPreview),
            'view_policy' => $viewPolicy,
            'meta' => $meta,
            'norms' => $norms,
            'quality' => $quality,
            'report' => $report,
        ];
    }

    /**
     * @return array{norms:array<string,mixed>,quality:array<string,mixed>}
     */
    private function extractScoreContract(Result $result): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload['breakdown_json']['score_result'] ?? null,
            $payload['axis_scores_json']['score_result'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $norms = is_array($candidate['norms'] ?? null) ? $candidate['norms'] : [];
            $quality = is_array($candidate['quality'] ?? null) ? $candidate['quality'] : [];
            if ($norms === [] && $quality === []) {
                continue;
            }

            return [
                'norms' => $norms,
                'quality' => $quality,
            ];
        }

        return [
            'norms' => [],
            'quality' => [],
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
