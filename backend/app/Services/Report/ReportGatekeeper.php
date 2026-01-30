<?php

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\GenericReportBuilder;
use App\Services\Commerce\EntitlementManager;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportGatekeeper
{
    private const DEFAULT_VIEW_POLICY = [
        'free_sections' => ['intro', 'score'],
        'blur_others' => true,
        'teaser_percent' => 0.3,
        'upgrade_sku' => null,
    ];

    public function __construct(
        private ScaleRegistry $registry,
        private EntitlementManager $entitlements,
        private ReportComposer $reportComposer,
        private GenericReportBuilder $genericReportBuilder,
        private EventRecorder $eventRecorder,
    ) {
    }

    public function resolve(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId
    ): array {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return $this->badRequest('ATTEMPT_REQUIRED', 'attempt_id is required.');
        }

        if (!Schema::hasTable('attempts')) {
            return $this->tableMissing('attempts');
        }
        if (!Schema::hasTable('results')) {
            return $this->tableMissing('results');
        }

        $attempt = Attempt::where('id', $attemptId)->where('org_id', $orgId)->first();
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
        $benefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($benefitCode === '') {
            $benefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        $hasAccess = $benefitCode !== ''
            ? $this->entitlements->hasFullAccess($orgId, $userId, $anonId, $attemptId, $benefitCode)
            : false;

        if ($hasAccess) {
            if (!Schema::hasTable('report_snapshots')) {
                return $this->tableMissing('report_snapshots');
            }

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

            $report = $this->decodeReportJson($snapshotRow->report_json ?? null);

            return $this->responsePayload(false, 'full', $viewPolicy, $report);
        }

        $report = $this->buildReport($scaleCode, $attempt, $result);
        if (!is_array($report)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        $teaser = $this->applyTeaser($report, $viewPolicy);

        return $this->responsePayload(true, 'free', $viewPolicy, $teaser);
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

    private function buildReport(string $scaleCode, Attempt $attempt, Result $result): ?array
    {
        if ($scaleCode === 'MBTI') {
            $composed = $this->reportComposer->compose((string) $attempt->id, []);
            if (!($composed['ok'] ?? false)) {
                return null;
            }
            $report = $composed['report'] ?? null;
            return is_array($report) ? $report : null;
        }

        $report = $this->genericReportBuilder->build($attempt, $result);
        return is_array($report) ? $report : null;
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

    private function responsePayload(bool $locked, string $accessLevel, array $viewPolicy, array $report): array
    {
        return [
            'ok' => true,
            'locked' => $locked,
            'access_level' => $accessLevel,
            'upgrade_sku' => $viewPolicy['upgrade_sku'] ?? null,
            'view_policy' => $viewPolicy,
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

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
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
