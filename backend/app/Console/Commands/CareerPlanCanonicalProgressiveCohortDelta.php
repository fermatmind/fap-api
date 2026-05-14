<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerProgressiveCohortDeltaPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalProgressiveCohortDelta extends Command
{
    protected $signature = 'career:plan-canonical-progressive-cohort-delta
        {--current-closeout= : Required accepted current cohort closeout JSON artifact}
        {--current-slugs= : Optional accepted current public slug artifact; defaults to closeout total_slugs_path}
        {--target-selection= : Required target cohort selection/readiness JSON artifact}
        {--target= : Required target public total}
        {--locales=en,zh : Comma-separated locale list}
        {--json : Emit JSON output}
        {--output= : Optional output path for progressive cohort delta plan JSON}';

    protected $description = 'Plan read-only progressive Career cohort target deltas such as 80 to 300, 300 to 800, and 800 to 2786.';

    public function handle(): int
    {
        try {
            $currentCloseoutPath = $this->requiredOption('current-closeout');
            $targetSelectionPath = $this->requiredOption('target-selection');
            $target = $this->positiveIntOption('target');
            $currentCloseout = $this->readJson($currentCloseoutPath, 'current_closeout');
            $currentSlugsPath = $this->pathOption('current-slugs')
                ?? $this->pathFromCloseout($currentCloseout, 'total_slugs_path');

            $result = app(CareerProgressiveCohortDeltaPlanner::class)->plan(
                currentCloseout: $currentCloseout,
                currentPublicSlugs: $this->readSlugList($currentSlugsPath, 'current_slug'),
                targetSelection: $this->readJson($targetSelectionPath, 'target_selection'),
                targetPublicTotal: $target,
                locales: $this->localesOption(),
            )->toArray();

            return $this->finish($result, ($result['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerProgressiveCohortDeltaPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
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

    private function positiveIntOption(string $name): int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
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

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException($kind.'_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($kind.'_artifact_shape_invalid');
        }

        return $decoded;
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
            try {
                $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new RuntimeException($kind.'_artifact_json_invalid');
            }

            $slugs = is_array($decoded) && array_is_list($decoded)
                ? $decoded
                : (is_array($decoded) ? ($decoded['slugs'] ?? $decoded['current_public_slugs'] ?? null) : null);
            if (! is_array($slugs) || ! array_is_list($slugs)) {
                throw new RuntimeException($kind.'_artifact_shape_invalid');
            }

            return array_values(array_map(static fn (mixed $slug): string => (string) $slug, $slugs));
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\\R/', $contents) ?: [],
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $output = $this->option('output');
        if (is_string($output) && trim($output) !== '') {
            File::put(trim($output), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->line('status='.($payload['status'] ?? 'unknown'));
            $this->line('current_public_total='.(string) ($payload['current_public_total'] ?? 0));
            $this->line('target_public_total='.(string) ($payload['target_public_total'] ?? 0));
            $this->line('delta_slug_count='.(string) ($payload['delta_slug_count'] ?? 0));
            $this->line('expected_delta_locale_rows='.(string) ($payload['expected_delta_locale_rows'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'progressive_cohort_delta_error';
        $key = trim($key, '_');

        return $key === '' ? 'progressive_cohort_delta_error' : $key;
    }
}
