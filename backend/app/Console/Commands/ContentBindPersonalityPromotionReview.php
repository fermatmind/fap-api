<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentPromotion\PersonalityCmsPromotionAuthority;
use App\Services\ContentPromotion\PersonalityCmsPromotionReviewBinder;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Private review-only gate between exact draft import and publication.
 * It never imports, publishes, changes discoverability, or deploys.
 *
 * @review-surface personality_public_content_asset_revision_review
 */
final class ContentBindPersonalityPromotionReview extends Command
{
    protected $signature = 'content:bind-personality-promotion-review
        {--package= : Allowlisted backend-authority package directory}
        {--expected-package-sha256= : Exact package SHA-256}
        {--lane= : Supported personality lane W2 or W5}
        {--subscope= : Supported personality subscope}
        {--attestation= : Private compact configured-owner attestation JSON path}
        {--actor-admin-user-id= : Authenticated configured owner ID for bind mode}
        {--bind : Bind immutable private review evidence; omitted means preflight only}
        {--json : Emit one redacted JSON object to stdout}';

    protected $description = 'Preflight or bind independent personality promotion review evidence without importing, publishing, indexing, or deployment.';

    public function handle(
        PromotionContextFactory $contexts,
        PersonalityCmsPromotionAuthority $authority,
        PersonalityCmsPromotionReviewBinder $reviews,
    ): int {
        try {
            if (! (bool) $this->option('json')) {
                throw new \DomainException('json_mode_required');
            }
            $context = $contexts->make(
                package: (string) $this->option('package'),
                packageSha256: (string) $this->option('expected-package-sha256'),
                lane: (string) $this->option('lane'),
                subscope: is_string($this->option('subscope')) ? (string) $this->option('subscope') : null,
            );
            $package = $authority->inspect($context);
            $attestation = $this->readObject((string) $this->option('attestation'));
            if (! (bool) $this->option('bind')) {
                $result = $reviews->preflight($context, $package, $attestation);
            } else {
                $actor = filter_var($this->option('actor-admin-user-id'), FILTER_VALIDATE_INT);
                if (! is_int($actor) || $actor <= 0) {
                    throw new \DomainException('actor_admin_user_id_invalid');
                }
                $result = $reviews->bind($context, $package, $attestation, $actor);
            }
            $payload = ['ok' => true, 'result' => $result];
            $exit = self::SUCCESS;
        } catch (Throwable $throwable) {
            $payload = [
                'ok' => false,
                'result' => 'BLOCKED',
                'error_code' => $this->safeErrorCode($throwable),
                'imports' => false,
                'publishes' => false,
                'changes_indexability' => false,
                'deploys' => false,
            ];
            $exit = self::FAILURE;
        }

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exit;
    }

    /** @return array<string,mixed> */
    private function readObject(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            throw new JsonException('attestation_path_invalid');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException('attestation_payload_invalid');
        }

        return $decoded;
    }

    private function safeErrorCode(Throwable $throwable): string
    {
        $candidate = strtolower(trim((string) strtok($throwable->getMessage(), ':')));

        return preg_match('/\A[a-z0-9_]{1,96}\z/', $candidate) === 1 ? $candidate : 'unexpected_error';
    }
}
