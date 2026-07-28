<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Verify-only command for content-release revalidation secret presence and
 * configuration consistency. Never outputs secret values, HMAC challenge
 * results, nonces, or signatures. No HTTP requests, no mutations.
 */
final class ContentReleaseSecretVerify extends Command
{
    protected $signature = 'content-release:secret-verify
        {--json : Emit machine-readable JSON artifact}
        {--cache-only-resume-preflight : Also verify promotion state for cache-only resume readiness}
        {--package-sha256= : Expected authority package SHA-256 for resume preflight}
        {--target-count=116 : Expected promotion target count}';

    protected $description = 'Verify content-release revalidation secret presence and configuration without exposing secret values.';

    private const CHALLENGE = 'fermatmind-content-release-revalidation-secret-equality-v1';

    private const ARTIFACT = 'CONTENT-RELEASE-SECRET-VERIFY-V1';

    public function handle(): int
    {
        $result = $this->verify();

        if ((bool) $this->option('cache-only-resume-preflight')) {
            $result = array_merge($result, $this->cacheOnlyResumePreflight($result));
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            foreach ($result as $key => $value) {
                $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
            }
        }

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed> $secretResult @return array<string,mixed> */
    private function cacheOnlyResumePreflight(array $secretResult): array
    {
        $packageSha256 = trim((string) $this->option('package-sha256'));
        $targetCount = (int) $this->option('target-count');
        if ($targetCount < 1 || $targetCount > 10000) {
            $targetCount = 116;
        }

        $secretOk = ($secretResult['secret_present'] ?? false) === true
            && ($secretResult['secret_length_ok'] ?? false) === true
            && ($secretResult['revalidation_url_present'] ?? false) === true;

        // Verify promotion state: count published revisions for the package
        $promotedCount = 0;
        $workingImportCount = 0;
        $reviewBindCount = 0;
        $promotionOk = false;
        $packageMatch = false;

        if ($packageSha256 !== '' && preg_match('/^[0-9a-f]{64}$/', $packageSha256) === 1) {
            $promotedCount = (int) \App\Models\PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->whereNotNull('published_revision_id')
                ->join('personality_public_content_asset_revisions', 'personality_public_content_assets.published_revision_id', '=', 'personality_public_content_asset_revisions.id')
                ->where('personality_public_content_asset_revisions.authority_package_sha256', $packageSha256)
                ->count();

            $workingImportCount = (int) \App\Models\PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', $packageSha256)
                ->count();

            $reviewBindCount = (int) \App\Models\PersonalityPublicContentAssetRevisionReview::query()
                ->where('authority_package_sha256', $packageSha256)
                ->count();

            $promotionOk = $promotedCount === $targetCount;
            $packageMatch = $workingImportCount === $targetCount && $reviewBindCount === $targetCount;
        }

        $resumeReady = $secretOk && $promotionOk && $packageMatch;

        return [
            'resume_phase' => 'cache_only_resume_preflight',
            'cache_only_resume_ready' => $resumeReady,
            'resume_status' => $resumeReady ? 'PASS_CACHE_ONLY_RESUME_READY' : 'FAIL_RESUME_NOT_READY',
            'secret_configured' => $secretOk,
            'promotion_committed_count' => $promotedCount,
            'promotion_target_count' => $targetCount,
            'promotion_ok' => $promotionOk,
            'working_import_committed_count' => $workingImportCount,
            'review_bind_committed_count' => $reviewBindCount,
            'package_match' => $packageMatch,
            'package_sha256' => $packageSha256 !== '' ? $packageSha256 : null,
            'import_allowed' => false,
            'review_bind_allowed' => false,
            'promotion_allowed' => false,
            'rollback_allowed' => false,
            'automatic_rollback' => false,
            'revalidation_allowed' => true,
            'post_readback_allowed' => true,
            'writes_committed' => false,
            'http_requests' => 0,
            'no_mutation' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function verify(): array
    {
        // Backend deployed SHA from REVISION file
        $revisionPath = base_path('REVISION');
        $backendSha = File::isFile($revisionPath) ? trim(File::get($revisionPath)) : '';
        $backendShaValid = preg_match('/^[0-9a-f]{40}$/', $backendSha) === 1;

        // Config source SHA-256 (detect drift from committed config/ops.php)
        $configPath = config_path('ops.php');
        $configSourceSha = File::isFile($configPath) ? hash('sha256', File::get($configPath)) : '';

        // Secret presence (boolean only, never output the value)
        $secret = (string) config('ops.content_release_observability.hmac_revalidation_secret', '');
        $secretPresent = $secret !== '';
        $secretLengthOk = strlen($secret) >= 24;

        // Revalidation URL presence
        $url = trim((string) config('ops.content_release_observability.hmac_revalidation_url', ''));
        $urlPresent = $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://');

        // Cached config presence check (Laravel bootstrap/cache/config.php)
        $cachedConfigPath = base_path('bootstrap/cache/config.php');
        $cachedConfigPresent = File::isFile($cachedConfigPath);

        // Config fingerprint for exact-state binding
        $configFingerprint = hash('sha256', json_encode([
            'hmac_revalidation_secret_present' => $secretPresent,
            'hmac_revalidation_secret_length_ok' => $secretLengthOk,
            'hmac_revalidation_url_present' => $urlPresent,
            'config_source_sha256' => $configSourceSha,
            'cached_config_present' => $cachedConfigPresent,
            'backend_sha' => $backendSha,
            'backend_sha_valid' => $backendShaValid,
        ], JSON_THROW_ON_ERROR));

        $secretsOk = $secretPresent && $secretLengthOk && $urlPresent;

        return [
            'schema_version' => 'content_release_secret_verify.v1',
            'artifact' => self::ARTIFACT,
            'ok' => $secretsOk && $backendShaValid,
            'status' => $secretsOk ? 'PASS_SECRET_CONFIGURED' : 'FAIL_SECRET_MISSING_OR_INCOMPLETE',
            'backend_sha' => $backendSha,
            'backend_sha_valid' => $backendShaValid,
            'config_source_sha256' => $configSourceSha,
            'secret_present' => $secretPresent,
            'secret_length_ok' => $secretLengthOk,
            'revalidation_url_present' => $urlPresent,
            'cached_config_present' => $cachedConfigPresent,
            'config_fingerprint' => $configFingerprint,
            'challenge' => self::CHALLENGE,
            'secret_output' => false,
            'secret_value_output' => false,
            'hmac_output' => false,
            'nonce_output' => false,
            'signature_output' => false,
            'writes_committed' => false,
            'http_requests' => 0,
            'no_mutation' => true,
            'production_execution' => app()->environment('production'),
        ];
    }
}
