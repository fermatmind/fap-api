<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Dedicated review-only entry point. It cannot execute any Career, CMS, SEO,
 * indexability, discoverability, or search-submission transition.
 *
 * @review-surface career_trust_manifest
 * @review-surface career_occupation_truth_metric_review
 * @review-surface career_editorial_patch
 * @review-surface career_occupation_directory_review
 * @review-surface career_salary_asset_review
 * @review-surface career_ai_impact_asset_review
 * @review-surface career_import_publish_readiness
 * @review-surface seo_agent_draft_review
 * @review-surface seo_canary_approval
 * @review-surface search_submission_queue_approval
 * @review-surface seo_claim_risk_review
 * @review-surface content_package_approval
 */
final class CareerSeoReviewAttestationCommand extends Command
{
    protected $signature = 'review:career-seo-attestation
        {--surface= : Registered Career/SEO review surface}
        {--attestation= : Private compact attestation JSON path}
        {--targets= : Private authoritative target JSON path}
        {--expected-package-sha256= : Exact optional package SHA-256}
        {--actor-admin-user-id= : Authenticated admin user ID for bind mode}
        {--bind : Bind immutable review evidence; omitted means read-only preflight}
        {--json : Emit safe machine-readable JSON}';

    protected $description = 'Preflight or bind Career/SEO review evidence without publishing, importing, indexing, or submitting search URLs.';

    public function handle(CareerSeoReviewAttestationService $reviews): int
    {
        try {
            $surface = trim((string) $this->option('surface'));
            $attestation = $this->jsonObject((string) $this->option('attestation'), 'attestation');
            $targets = $this->targetList((string) $this->option('targets'));
            $expectedPackageSha256 = $this->nullableString($this->option('expected-package-sha256'));
            $bind = (bool) $this->option('bind');

            if (! $bind) {
                return $this->finish([
                    ...$reviews->preflight($attestation, $surface, $targets, $expectedPackageSha256),
                    'surface_id' => $surface,
                    'bind_requested' => false,
                    'review_evidence_bound' => false,
                    'safety_boundaries' => $reviews->safetyBoundaries(),
                ]);
            }

            $actorAdminUserId = filter_var($this->option('actor-admin-user-id'), FILTER_VALIDATE_INT);
            if (! is_int($actorAdminUserId) || $actorAdminUserId <= 0) {
                throw new \RuntimeException('--actor-admin-user-id must be a positive integer in bind mode.');
            }

            $bound = $reviews->bindReview(
                $attestation,
                $surface,
                $targets,
                $actorAdminUserId,
                $expectedPackageSha256,
            );

            return $this->finish([
                'status' => 'PASS_SOLO_OWNER_REVIEW_EVIDENCE_BOUND',
                'surface_id' => $surface,
                'scope_type' => (string) $bound->scope_type,
                'scope_identity' => (string) $bound->scope_identity,
                'decision' => (string) $bound->decision,
                'target_count' => (int) $bound->target_count,
                'target_set_sha256' => (string) $bound->target_set_sha256,
                'package_sha256' => $bound->package_sha256,
                'evidence_sha256' => (string) $bound->evidence_sha256,
                'bind_requested' => true,
                'review_evidence_bound' => true,
                'safety_boundaries' => $reviews->safetyBoundaries(),
            ]);
        } catch (Throwable $throwable) {
            return $this->finish([
                'status' => 'BLOCKED_CAREER_SEO_REVIEW_ATTESTATION',
                'error' => $throwable->getMessage(),
                'bind_requested' => (bool) $this->option('bind'),
                'review_evidence_bound' => false,
                'publishes' => false,
                'imports' => false,
                'changes_indexability' => false,
                'submits_search_urls' => false,
            ], self::FAILURE);
        }
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $path, string $label): array
    {
        $decoded = $this->readJson($path, $label);
        if (array_is_list($decoded)) {
            throw new \RuntimeException($label.' JSON root must be an object.');
        }

        return $decoded;
    }

    /** @return list<array{identity:string,sha256:string}> */
    private function targetList(string $path): array
    {
        $decoded = $this->readJson($path, 'targets');
        $targets = $decoded['targets'] ?? $decoded;
        if (! is_array($targets) || ! array_is_list($targets)) {
            throw new \RuntimeException('targets JSON must be a list or an object containing a targets list.');
        }

        return $targets;
    }

    /** @return array<mixed> */
    private function readJson(string $path, string $label): array
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException($label.' JSON path is missing or unreadable.');
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \RuntimeException($label.' JSON is invalid.');
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException($label.' JSON root must be an object or list.');
        }

        return $decoded;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach (['status', 'surface_id', 'decision', 'target_count', 'review_evidence_bound'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $this->line($key.'='.$this->stringValue($payload[$key]));
                }
            }
        }

        return $exitCode;
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
