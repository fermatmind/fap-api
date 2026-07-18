<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeManifest;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeReadback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
        {--backend-deployed-sha= : Exact deployed backend Git SHA observed by this readback}
        {--frontend-deployed-sha= : Exact deployed frontend Git SHA observed by this readback}
        {--frontend-revision-url= : Read-only frontend revision endpoint}
        {--review-register= : Optional private register; required for production post-readback reviewer-leak checks}
        {--attestation= : Optional compact solo-owner attestation; alternative to --review-register for post-readback}
        {--require-fresh-api-cache : Require X-Fermat-Public-Read-Cache=fresh for every API read}
        {--output= : Optional JSON artifact path}
        {--allow-testing : Skip deployed revision probes only in APP_ENV=testing}
        {--json : Emit the complete redacted JSON result}';

    protected $description = 'Read-only Enneagram Authority V2 API/HTML pre/post readback for one batch or all 116 pages.';

    public function handle(
        EnneagramPublicAuthorityV224RuntimeReadback $readback,
        EnneagramPublicAuthorityV224RuntimeManifest $manifest,
    ): int {
        try {
            $phase = $this->requiredOption('phase');
            $releaseReport = $this->jsonFile((string) $this->option('source'));
            $sensitiveValues = $this->privateReviewerNames($phase, $releaseReport, $manifest);
            $testingOverride = app()->environment('testing') && (bool) $this->option('allow-testing');
            $apiBaseUrl = $manifest->publicRuntimeOrigin(
                $this->requiredHttpsOrigin('api-base-url'),
                '--api-base-url',
                $testingOverride ? null : (string) config('app.url', ''),
            );
            $frontendBaseUrl = $manifest->publicRuntimeOrigin(
                $this->requiredHttpsOrigin('frontend-base-url'),
                '--frontend-base-url',
                $testingOverride ? null : (string) config('app.frontend_url', ''),
            );
            $backendDeployedSha = $this->requiredOption('backend-deployed-sha');
            $frontendDeployedSha = $this->requiredOption('frontend-deployed-sha');
            $this->assertDeployedRevisions(
                $backendDeployedSha,
                $frontendDeployedSha,
                $frontendBaseUrl,
            );
            $result = $readback->run(
                $phase,
                trim((string) $this->option('batch')),
                $releaseReport,
                $apiBaseUrl,
                $frontendBaseUrl,
                $backendDeployedSha,
                $frontendDeployedSha,
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
        $value = $this->requiredOption($name);
        $parts = parse_url($value);
        if (! filter_var($value, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || str_contains($value, '?')
            || str_contains($value, '#')) {
            throw new RuntimeException('--'.$name.' must be an exact HTTPS origin without credentials, path, query, or fragment.');
        }

        return rtrim($value, '/');
    }

    private function assertDeployedRevisions(
        string $backendSha,
        string $frontendSha,
        string $frontendBaseUrl,
    ): void {
        foreach ([$backendSha, $frontendSha] as $sha) {
            if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
                throw new RuntimeException('Backend/frontend deployed SHAs must be exact lowercase 40-character Git SHAs.');
            }
        }
        $testingOverride = app()->environment('testing') && (bool) $this->option('allow-testing');
        $revisionUrl = trim((string) $this->option('frontend-revision-url'));
        if ($revisionUrl !== '') {
            $this->assertUrlUsesOrigin($revisionUrl, $frontendBaseUrl);
        }
        if ($testingOverride) {
            return;
        }
        $revisionPath = base_path('../REVISION');
        if (! File::isFile($revisionPath) || trim(File::get($revisionPath)) !== $backendSha) {
            throw new RuntimeException('Deployed backend REVISION does not match the exact readback SHA.');
        }
        if ($revisionUrl === '') {
            throw new RuntimeException('--frontend-revision-url is required outside the testing override.');
        }
        $response = Http::acceptJson()->withoutRedirecting()->timeout(15)->get($revisionUrl);
        $observed = trim((string) ($response->json('revision') ?? $response->header('X-Revision') ?? $response->body()));
        if (! $response->successful() || $observed !== $frontendSha) {
            throw new RuntimeException('Deployed frontend revision endpoint does not match the exact readback SHA.');
        }
    }

    private function assertUrlUsesOrigin(string $url, string $expectedOrigin): void
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ! hash_equals($this->canonicalHttpsOrigin($expectedOrigin), $this->canonicalHttpsOrigin($url))) {
            throw new RuntimeException('--frontend-revision-url must use the exact --frontend-base-url origin without credentials, query, or fragment.');
        }
    }

    private function canonicalHttpsOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '') {
            throw new RuntimeException('HTTPS origin is invalid.');
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
    }

    /** @return list<string> */
    private function privateReviewerNames(
        string $phase,
        array $releaseReport,
        EnneagramPublicAuthorityV224RuntimeManifest $manifest,
    ): array {
        $reviewRegister = trim((string) $this->option('review-register'));
        $attestation = trim((string) $this->option('attestation'));
        if ($reviewRegister !== '' && $attestation !== '') {
            throw new RuntimeException('--review-register and --attestation are mutually exclusive.');
        }
        $path = $reviewRegister !== '' ? $reviewRegister : $attestation;
        if ($phase === 'post' && $path === '') {
            throw new RuntimeException('Exactly one of --review-register or --attestation is required for post-readback.');
        }
        if ($path === '') {
            return [];
        }
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Runtime readback JSON input not found.');
        }
        $raw = File::get($resolved);
        $register = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($register)) {
            throw new RuntimeException('Runtime readback JSON input must be an object.');
        }

        return $manifest->approvedPrivateReviewerNames(
            $releaseReport,
            $register,
            hash('sha256', $raw),
        );
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
