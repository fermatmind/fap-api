<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerProgressiveLiveVerificationScalingPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerPlanCanonicalProgressiveLiveVerification extends Command
{
    protected $signature = 'career:plan-canonical-progressive-live-verification
        {--target-public-total= : Required target total: 300, 800, or 2786}
        {--slugs= : Required newline, comma, or JSON slug artifact}
        {--locales=en,zh : Comma-separated locales}
        {--base-url=https://www.fermatmind.com : Public base URL for later read-only verification}
        {--chunk-size=100 : Number of slugs per chunk}
        {--resume-from-chunk=1 : First chunk number to plan for execution}
        {--request-rate-per-second=1 : Guarded request rate, max 1}
        {--timeout-seconds=20 : Guarded timeout, max 20}
        {--retries=1 : Guarded retry count, max 1}
        {--output-dir=/tmp : Directory for later chunk and merged artifacts}
        {--partial= : Optional partial progress JSON artifact}
        {--json : Emit JSON output}
        {--output= : Optional output path for scaling plan JSON}';

    protected $description = 'Plan chunked, rate-limited read-only Career progressive live verification without executing HTTP requests.';

    public function handle(): int
    {
        try {
            $partialPath = $this->pathOption('partial');
            $payload = app(CareerProgressiveLiveVerificationScalingPlanner::class)->plan(
                targetPublicTotal: $this->intOption('target-public-total'),
                slugs: $this->readSlugs($this->requiredOption('slugs')),
                locales: $this->csvOption('locales', 'en,zh'),
                baseUrl: $this->stringOption('base-url', 'https://www.fermatmind.com'),
                chunkSize: $this->intOption('chunk-size'),
                resumeFromChunk: $this->intOption('resume-from-chunk'),
                requestRatePerSecond: $this->floatOption('request-rate-per-second'),
                timeoutSeconds: $this->intOption('timeout-seconds'),
                retries: $this->intOption('retries'),
                outputDir: $this->stringOption('output-dir', '/tmp'),
                partial: $partialPath === null ? null : $this->readJson($partialPath, 'partial'),
            )->toArray();

            return $this->finish($payload, ($payload['status'] ?? null) === 'planned' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema_version' => CareerProgressiveLiveVerificationScalingPlanner::SCHEMA_VERSION,
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'apply_allowed' => false,
                'rollout_allowed' => false,
                'live_crawl_executed' => false,
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
                'sidecars' => [],
                'next_required_action' => 'FIX_PROGRESSIVE_LIVE_VERIFICATION_PLAN_INPUTS',
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

    private function stringOption(string $name, string $default): string
    {
        $value = trim((string) ($this->option($name) ?? $default));

        return $value === '' ? $default : $value;
    }

    private function intOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);
        if (! is_int($value)) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    private function floatOption(string $name): float
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_FLOAT);
        if (! is_float($value)) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name, string $default): array
    {
        $raw = trim((string) ($this->option($name) ?? $default));
        $values = $raw === '' ? [] : array_map(static fn (string $value): string => trim($value), explode(',', $raw));

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return list<string>
     */
    private function readSlugs(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('slug_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('slug_artifact_unreadable');
        }

        $trimmed = trim($contents);
        if ($trimmed === '') {
            throw new RuntimeException('slug_artifact_empty');
        }

        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            $values = is_array($decoded) && array_is_list($decoded)
                ? $decoded
                : (is_array($decoded) ? ($decoded['slugs'] ?? $decoded['combined_slugs'] ?? $decoded['total_slugs'] ?? []) : []);
        } else {
            $values = preg_split('/[\r\n,]+/', $trimmed) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($values) ? $values : [],
        ), static fn (string $value): bool => $value !== ''));
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
            $this->line('target_public_total='.(string) ($payload['target_public_total'] ?? 0));
            $this->line('chunk_count='.(string) ($payload['chunk_count'] ?? 0));
        }

        return $exitCode;
    }

    private function reasonKey(string $message): string
    {
        $key = strtolower(trim($message));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? 'career_progressive_live_verification_error';
        $key = trim($key, '_');

        return $key === '' ? 'career_progressive_live_verification_error' : $key;
    }
}
