<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeReadback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2RuntimeReadback extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-runtime-readback
        {--phase= : Required readback phase: pre or post}
        {--batch=all : canary-00, readback-01..09, or all}
        {--source=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/release-gate-report.json : Exact 116-page release report}
        {--api-base-url= : Public backend API origin}
        {--frontend-base-url= : Public frontend origin}
        {--review-register= : Optional private register; required for production post-readback reviewer-leak checks}
        {--require-fresh-api-cache : Require X-Fermat-Public-Read-Cache=fresh for every API read}
        {--output= : Optional JSON artifact path}
        {--json : Emit the complete redacted JSON result}';

    protected $description = 'Read-only Enneagram Authority V2 API/HTML pre/post readback for one batch or all 116 pages.';

    public function handle(EnneagramPublicAuthorityV224RuntimeReadback $readback): int
    {
        try {
            $phase = $this->requiredOption('phase');
            $sensitiveValues = $this->privateReviewerNames($phase);
            $result = $readback->run(
                $phase,
                trim((string) $this->option('batch')),
                $this->jsonFile((string) $this->option('source')),
                $this->requiredHttpsOrigin('api-base-url'),
                $this->requiredHttpsOrigin('frontend-base-url'),
                (bool) $this->option('require-fresh-api-cache'),
                $sensitiveValues,
            );
            $this->writeOptionalOutput($result);
        } catch (Throwable $throwable) {
            $result = [
                'artifact' => EnneagramPublicAuthorityV224RuntimeReadback::ARTIFACT,
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'writes_committed' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->emit($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function jsonFile(string $path): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Runtime readback JSON input not found.');
        }
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Runtime readback JSON input must be an object.');
        }

        return $decoded;
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $value;
    }

    private function requiredHttpsOrigin(string $name): string
    {
        $value = rtrim($this->requiredOption($name), '/');
        if (! filter_var($value, FILTER_VALIDATE_URL) || ! str_starts_with($value, 'https://')) {
            throw new RuntimeException('--'.$name.' must be an HTTPS URL.');
        }

        return $value;
    }

    /** @return list<string> */
    private function privateReviewerNames(string $phase): array
    {
        $path = trim((string) $this->option('review-register'));
        if ($phase === 'post' && ! app()->environment('testing') && $path === '') {
            throw new RuntimeException('--review-register is required for production post-readback.');
        }
        if ($path === '') {
            return [];
        }
        $register = $this->jsonFile($path);
        $names = [];
        foreach (is_array($register['reviews'] ?? null) ? $register['reviews'] : [] as $review) {
            $name = is_array($review) ? trim((string) ($review['reviewer_name'] ?? '')) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<string,mixed> $result */
    private function writeOptionalOutput(array $result): void
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return;
        }
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, $this->encode($result)."\n");
    }

    /** @param array<string,mixed> $result */
    private function emit(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return;
        }
        foreach (['ok', 'status', 'phase', 'batch', 'target_count', 'api_read_count', 'html_read_count', 'public_projection_fingerprint', 'stable_identity_discoverability_fingerprint'] as $field) {
            if (array_key_exists($field, $result)) {
                $this->line($field.'='.(is_bool($result[$field]) ? ($result[$field] ? '1' : '0') : (string) $result[$field]));
            }
        }
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
