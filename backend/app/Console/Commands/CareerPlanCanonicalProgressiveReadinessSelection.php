<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerProgressiveReadinessSelector;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalProgressiveReadinessSelection extends Command
{
    protected $signature = 'career:plan-canonical-progressive-readiness-selection
        {--source-plan= : Required 2786 public-resolution/source plan JSON artifact}
        {--closeout= : Required accepted current cohort closeout JSON artifact}
        {--current-total= : Required current public total}
        {--target-total= : Required target public total: 300, 800, or 2786}
        {--locales=en,zh : Comma-separated locales}
        {--entity-context= : Optional production-derived entity context JSON; if supplied, selected delta slugs must have occupation_exists=true}
        {--cn-proxy-public-owner-plan= : Optional reviewed CN proxy public-owner plan JSON for final 2786 partition accounting}
        {--software-manual-hold-decision= : Optional reviewed software-developers manual-hold decision JSON for final 2786 partition accounting}
        {--exclude-slugs= : Optional newline, comma, or JSON slug artifact to exclude}
        {--strict : Fail on source-plan validation issues}
        {--json : Emit JSON output}
        {--output= : Optional output path for progressive readiness selection JSON}';

    protected $description = 'Plan read-only Career progressive readiness selections for 300, 800, and 2786 cohorts.';

    public function handle(): int
    {
        try {
            $sourcePlanPath = $this->requiredOption('source-plan');
            $closeoutPath = $this->requiredOption('closeout');
            $sourcePlanValidation = CareerPublicResolutionPlanResolver::fromPath($sourcePlanPath, 2786);
            $sourcePlan = $sourcePlanValidation->plan;
            if ($sourcePlan === null) {
                throw new RuntimeException('source_plan_invalid');
            }
            if ((bool) $this->option('strict') && $sourcePlanValidation->issues !== []) {
                throw new RuntimeException('source_plan_validation_issues');
            }

            $closeout = $this->readJson($closeoutPath, 'closeout');
            $baselinePath = $this->pathFromCloseout($closeout, 'total_slugs_path');
            $excludePath = $this->pathOption('exclude-slugs');
            $entityContextPath = $this->pathOption('entity-context');
            $cnProxyPublicOwnerPlanPath = $this->pathOption('cn-proxy-public-owner-plan');
            $softwareManualHoldDecisionPath = $this->pathOption('software-manual-hold-decision');
            $cnProxyPublicOwnerPlan = $cnProxyPublicOwnerPlanPath === null
                ? null
                : [
                    ...$this->readJson($cnProxyPublicOwnerPlanPath, 'cn_proxy_public_owner_plan'),
                    'source_path' => $cnProxyPublicOwnerPlanPath,
                ];
            $softwareManualHoldDecision = $softwareManualHoldDecisionPath === null
                ? null
                : [
                    ...$this->readJson($softwareManualHoldDecisionPath, 'software_manual_hold_decision'),
                    'source_path' => $softwareManualHoldDecisionPath,
                ];
            $payload = app(CareerProgressiveReadinessSelector::class)->select(
                sourcePlan: $sourcePlan,
                currentCloseout: $closeout,
                currentPublicSlugs: $this->readSlugList($baselinePath, 'baseline_slug'),
                currentPublicTotal: $this->intOption('current-total'),
                targetPublicTotal: $this->intOption('target-total'),
                locales: $this->localesOption(),
                excludeSlugs: $excludePath === null ? [] : $this->readSlugList($excludePath, 'exclude_slug'),
                occupationExistingSlugs: $entityContextPath === null ? null : $this->readOccupationExistingSlugs($entityContextPath),
                cnProxyPublicOwnerPlan: $cnProxyPublicOwnerPlan,
                softwareManualHoldDecision: $softwareManualHoldDecision,
                strict: (bool) $this->option('strict'),
            )->toArray();

            $payload['source_plan']['validation_issue_count'] = count($sourcePlanValidation->issues);
            if ($entityContextPath !== null) {
                $payload['entity_context']['source_path'] = $entityContextPath;
            }

            return $this->finish($payload, ($payload['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerProgressiveReadinessSelector::SCHEMA_VERSION,
                'status' => 'blocked',
                'readiness_pass' => false,
                'read_only' => true,
                'writes_database' => false,
                'apply_allowed' => false,
                'rollout_allowed' => false,
                'selected_count' => 0,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                    'severity' => 'high',
                    'evidence' => [],
                ]],
                'sidecars' => [],
                'next_required_action' => 'FIX_PROGRESSIVE_READINESS_SELECTION_INPUTS',
            ], self::FAILURE);
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $value;
    }

    private function pathOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value === '' ? null : $value;
    }

    private function intOption(string $name): int
    {
        $raw = trim((string) ($this->option($name) ?? ''));
        if ($raw === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function localesOption(): array
    {
        $raw = trim((string) ($this->option('locales') ?? 'en,zh'));
        $locales = array_values(array_filter(array_map(
            static fn (string $locale): string => strtolower(trim($locale)),
            explode(',', $raw),
        )));
        if ($locales === []) {
            throw new RuntimeException('locales_missing');
        }

        return $locales;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, string $kind): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($kind.'_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException($kind.'_artifact_unreadable');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($kind.'_artifact_shape_invalid');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pathFromCloseout(array $payload, string $key): string
    {
        $path = $payload[$key] ?? null;
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException($key.'_missing');
        }

        return trim($path);
    }

    /**
     * @return list<string>
     */
    private function readSlugList(string $path, string $kind): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($kind.'_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException($kind.'_artifact_unreadable');
        }

        $trimmed = trim($contents);
        if ($trimmed === '') {
            throw new RuntimeException($kind.'_artifact_empty');
        }

        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            $slugs = is_array($decoded) && array_is_list($decoded)
                ? $decoded
                : (is_array($decoded) ? ($decoded['slugs'] ?? $decoded['selected_slugs'] ?? $decoded['total_slugs'] ?? []) : []);
        } else {
            $slugs = preg_split('/[\r\n,]+/', $trimmed) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $slug): string => trim((string) $slug),
            is_array($slugs) ? $slugs : [],
        ), static fn (string $slug): bool => $slug !== ''));
    }

    /**
     * @return list<string>
     */
    private function readOccupationExistingSlugs(string $path): array
    {
        $payload = $this->readJson($path, 'entity_context');
        $rows = $payload['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('entity_context_rows_missing');
        }

        $slugs = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('entity_context_row_invalid_at_'.$index);
            }
            if (($row['occupation_exists'] ?? false) !== true) {
                continue;
            }

            $slug = $row['canonical_slug'] ?? $row['slug'] ?? null;
            if (! is_string($slug) || trim($slug) === '') {
                throw new RuntimeException('entity_context_slug_missing_at_'.$index);
            }

            $slug = strtolower(trim($slug));
            if (! isset($seen[$slug])) {
                $seen[$slug] = true;
                $slugs[] = $slug;
            }
        }

        if ($slugs === []) {
            throw new RuntimeException('entity_context_occupation_exists_slugs_missing');
        }

        return $slugs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed_to_encode_json_payload');

            return self::FAILURE;
        }

        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            File::put($outputPath, $encoded.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
            $this->line('selected_count='.(string) ($payload['selected_count'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'progressive_readiness_selection_error';
        $key = trim($key, '_');

        return $key === '' ? 'progressive_readiness_selection_error' : $key;
    }
}
