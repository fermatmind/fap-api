<?php

namespace App\Services\Report;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Resolvers\AccessResolver;
use App\Services\Report\Resolvers\CrisisPolicyResolver;
use App\Services\Report\Resolvers\OfferResolver;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRolloutGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportGatekeeper
{
    use ReportGatekeeperTeaserTrait;

    private const SNAPSHOT_RETRY_AFTER_SECONDS = 3;

    public function __construct(
        private ScaleRegistry $registry,
        private ReportSnapshotStore $snapshotStore,
        private ReportComposerRegistry $composerRegistry,
        private AccessResolver $accessResolver,
        private OfferResolver $offerResolver,
        private CrisisPolicyResolver $crisisPolicyResolver,
    ) {}

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
        if (! $attempt) {
            return $this->notFound('ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::withoutGlobalScopes()->where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if (! $result) {
            return $this->notFound('RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return $this->badRequest('SCALE_REQUIRED', 'scale_code missing on attempt.');
        }

        $registry = $this->registry->getByCode($scaleCode, $orgId);
        if (! $registry) {
            return $this->notFound('SCALE_NOT_FOUND', 'scale not found.');
        }

        $commercial = $this->offerResolver->normalizeCommercial($registry['commercial_json'] ?? null);
        $paywallMode = ScaleRolloutGate::paywallMode($registry);
        $forceFreeOnly = in_array($paywallMode, [ScaleRolloutGate::PAYWALL_FREE_ONLY, ScaleRolloutGate::PAYWALL_OFF], true);
        $accessState = $this->accessResolver->resolveAccess(
            $orgId,
            $userId,
            $anonId,
            $attemptId,
            $commercial,
            $forceFreeOnly
        );
        $hasAccess = (bool) ($accessState['has_full_access'] ?? false);

        return [
            'ok' => true,
            'locked' => ! $hasAccess,
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
        if (! $attempt) {
            return $this->notFound('ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::withoutGlobalScopes()->where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if (! $result) {
            return $this->notFound('RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return $this->badRequest('SCALE_REQUIRED', 'scale_code missing on attempt.');
        }

        $registry = $this->registry->getByCode($scaleCode, $orgId);
        if (! $registry) {
            return $this->notFound('SCALE_NOT_FOUND', 'scale not found.');
        }

        $viewPolicy = $this->offerResolver->normalizeViewPolicy($registry['view_policy_json'] ?? null);
        $commercial = $this->offerResolver->normalizeCommercial($registry['commercial_json'] ?? null);
        $paywallMode = ScaleRolloutGate::paywallMode($registry);
        $forceFreeOnly = in_array($paywallMode, [ScaleRolloutGate::PAYWALL_FREE_ONLY, ScaleRolloutGate::PAYWALL_OFF], true);
        $paywall = $this->offerResolver->buildPaywall($viewPolicy, $commercial, $scaleCode, $orgId);
        $viewPolicy = $paywall['view_policy'] ?? $viewPolicy;

        $accessState = $this->accessResolver->resolveAccess(
            $orgId,
            $userId,
            $anonId,
            $attemptId,
            $commercial,
            $forceFreeOnly
        );
        $hasFullAccess = (bool) ($accessState['has_full_access'] ?? false);

        $modulesOffered = $this->offerResolver->collectModulesFromOffers((array) ($paywall['offers'] ?? []));
        if ($modulesOffered === []) {
            $modulesOffered = ReportAccess::allDefaultModulesOffered($scaleCode);
        }

        $modulesState = $this->accessResolver->resolveModules(
            $scaleCode,
            $orgId,
            $attemptId,
            $hasFullAccess,
            $forceFreeOnly,
            $modulesOffered
        );
        $modulesAllowed = (array) ($modulesState['modules_allowed'] ?? []);
        $modulesPreview = (array) ($modulesState['modules_preview'] ?? []);
        $hasPaidModuleAccess = (bool) ($modulesState['has_paid_module_access'] ?? false);

        $scoreContract = $this->extractScoreContract($result);
        $normsPayload = is_array($scoreContract['norms'] ?? null) ? $scoreContract['norms'] : [];
        $qualityPayload = is_array($scoreContract['quality'] ?? null) ? $scoreContract['quality'] : [];
        $crisisState = $this->crisisPolicyResolver->apply(
            $scaleCode,
            $qualityPayload,
            $paywall,
            $modulesAllowed,
            $modulesOffered,
            $modulesPreview,
            $hasFullAccess,
            $hasPaidModuleAccess
        );
        $paywall = (array) ($crisisState['paywall'] ?? $paywall);
        $modulesAllowed = (array) ($crisisState['modules_allowed'] ?? $modulesAllowed);
        $modulesOffered = (array) ($crisisState['modules_offered'] ?? $modulesOffered);
        $modulesPreview = (array) ($crisisState['modules_preview'] ?? $modulesPreview);
        $hasFullAccess = (bool) ($crisisState['has_full_access'] ?? $hasFullAccess);
        $hasPaidModuleAccess = (bool) ($crisisState['has_paid_module_access'] ?? $hasPaidModuleAccess);

        $variant = $hasPaidModuleAccess ? ReportAccess::VARIANT_FULL : ReportAccess::VARIANT_FREE;
        $reportAccessLevel = $variant === ReportAccess::VARIANT_FREE
            ? ReportAccess::REPORT_ACCESS_FREE
            : ReportAccess::REPORT_ACCESS_FULL;
        $locked = $variant === ReportAccess::VARIANT_FREE;

        $shouldUseSnapshot = $hasFullAccess && $this->offerResolver->modulesCoverOffered($modulesAllowed, $modulesOffered);
        $snapshotStrictMode = $this->strictSnapshotModeEnabled();
        $shouldReadFromSnapshot = $snapshotStrictMode || $shouldUseSnapshot;
        if ($shouldReadFromSnapshot && ($snapshotStrictMode || ! $forceRefresh)) {
            $snapshotRow = DB::table('report_snapshots')
                ->where('org_id', $orgId)
                ->where('attempt_id', $attemptId)
                ->first();

            if ($snapshotStrictMode && ($snapshotRow === null || $forceRefresh)) {
                $this->enqueueSnapshotBuild($orgId, $attempt, $result);

                return $this->responsePayload(
                    $locked,
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

            if ($snapshotRow) {
                $snapshotStatus = strtolower(trim((string) ($snapshotRow->status ?? 'ready')));
                if ($snapshotStatus === 'pending') {
                    return $this->responsePayload(
                        $locked,
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
                        $locked,
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

                $report = $this->snapshotReportForVariant($snapshotRow, $variant);
                if ($report !== []) {
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

                if ($snapshotStrictMode) {
                    $this->enqueueSnapshotBuild($orgId, $attempt, $result);

                    return $this->responsePayload(
                        $locked,
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
            }
        }

        if ($snapshotStrictMode) {
            return $this->responsePayload(
                $locked,
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

        $built = $this->buildReportVariant(
            $scaleCode,
            $attempt,
            $result,
            $variant,
            $modulesAllowed,
            $modulesPreview
        );
        if (! ($built['ok'] ?? false)) {
            return $built;
        }

        $report = $built['report'] ?? [];
        if (! is_array($report)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        // Non-MBTI reports are still built by GenericReportBuilder. Re-apply teaser
        // masking when locked to avoid exposing full payload to unpaid users.
        if ($locked && ! in_array(strtoupper($scaleCode), ['MBTI', 'BIG5_OCEAN', 'CLINICAL_COMBO_68', 'SDS_20', 'EQ_60'], true)) {
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
        $query = Attempt::withoutGlobalScopes()
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
    ): array {
        try {
            $composed = $this->composerRegistry->composeVariant(
                $scaleCode,
                $attempt,
                $result,
                $variant,
                [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                    'variant' => $variant,
                    'report_access_level' => $variant === ReportAccess::VARIANT_FREE
                        ? ReportAccess::REPORT_ACCESS_FREE
                        : ReportAccess::REPORT_ACCESS_FULL,
                    'modules_allowed' => $modulesAllowed,
                    'modules_preview' => $modulesPreview,
                ]
            );
            if (! ($composed['ok'] ?? false)) {
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

    private function snapshotReportForVariant(object $snapshotRow, string $variant): array
    {
        if (ReportAccess::normalizeVariant($variant) === ReportAccess::VARIANT_FREE) {
            return $this->decodeReportJson($snapshotRow->report_free_json ?? null);
        }

        $report = $this->decodeReportJson($snapshotRow->report_full_json ?? null);
        if ($report !== []) {
            return $report;
        }

        return $this->decodeReportJson($snapshotRow->report_json ?? null);
    }

    private function strictSnapshotModeEnabled(): bool
    {
        return (bool) config('fap.features.report_snapshot_strict_v2', false)
            || (bool) config('fap.features.submit_async_v2', false);
    }

    private function enqueueSnapshotBuild(int $orgId, Attempt $attempt, Result $result): void
    {
        $attemptId = trim((string) ($attempt->id ?? ''));
        if ($attemptId === '') {
            return;
        }

        try {
            $this->snapshotStore->seedPendingSnapshot($orgId, $attemptId, null, [
                'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? $result->scale_code ?? ''))),
                'scale_code_v2' => strtoupper(trim((string) ($attempt->scale_code_v2 ?? $result->scale_code_v2 ?? ''))),
                'scale_uid' => trim((string) ($attempt->scale_uid ?? $result->scale_uid ?? '')),
                'pack_id' => (string) ($attempt->pack_id ?? $result->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? $result->dir_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? $result->scoring_spec_version ?? ''),
            ]);

            GenerateReportSnapshotJob::dispatch(
                $orgId,
                $attemptId,
                'report_api',
                null
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('[REPORT] snapshot_queue_failed', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);
        }
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
    ): array {
        $generating = (bool) ($meta['generating'] ?? false);
        $snapshotError = (bool) ($meta['snapshot_error'] ?? false);
        $retryAfterSeconds = isset($meta['retry_after_seconds']) ? (int) $meta['retry_after_seconds'] : null;

        return [
            'ok' => true,
            'generating' => $generating,
            'snapshot_error' => $snapshotError,
            'retry_after_seconds' => $retryAfterSeconds,
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
            if (! is_array($candidate)) {
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
