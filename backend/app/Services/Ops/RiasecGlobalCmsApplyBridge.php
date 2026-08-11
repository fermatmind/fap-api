<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\LandingSurface;
use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/** @review-surface riasec_content_release_review */
final class RiasecGlobalCmsApplyBridge
{
    private const AUTHORIZATION_TTL_SECONDS = 900;

    public const EXPERIMENT_ID = 'FERMATMIND-EN-RIASEC-CMS-EXPERIMENT-01';

    public const SURFACE_KEY = 'test_detail_holland_career_interest_test_riasec';

    public const LOCALE = 'en';

    public const ORG_ID = 0;

    public const BEFORE_SNAPSHOT_SHA256 = 'e995ea8f3881436f3451f37bc8f87f091d68a3e3b7e0f022a1c6ed416eaf43e0';

    public const TARGET_PACKAGE_SHA256 = '064b9e15eb8eae102623306487c4b63635b7500a32925706f14688158734e3f1';

    /**
     * @var list<string>
     */
    private const ALLOWED_CHANGED_PATHS = [
        'description',
        'payload_json.aeo_answer_block',
        'payload_json.claim_risk_notes',
        'payload_json.h1_or_hero_title',
        'payload_json.hero_copy',
        'payload_json.methodology_boundary_note',
        'payload_json.primary_cta_label',
        'payload_json.seo_description',
        'payload_json.seo_title',
        'title',
    ];

    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly RiasecProductBaselineEvidence $baselineEvidence,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(
        string $beforeSnapshotJson,
        string $targetPackageJson,
        string $baselineReceiptJson,
        string $landingAndProductFunnelJson,
        string $attemptResultFunnelJson,
        string $failureCohortsJson,
        int $actorAdminId,
        string $expectedDeployedSha,
        string $expectedReleaseId,
    ): array {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $baselineEvidence = $this->baselineEvidence->validate(
            $baselineReceiptJson,
            $landingAndProductFunnelJson,
            $attemptResultFunnelJson,
            $failureCohortsJson,
            $expectedDeployedSha,
            $expectedReleaseId,
        );
        $this->assertGlobalAuthorityContext();
        $this->assertActor($actorAdminId);
        session()->forget($this->authorizationSessionKey($actorAdminId, 'apply'));
        $this->assertRuntimeIdentity($expectedDeployedSha, $expectedReleaseId);

        $surface = $this->findSurface();
        $current = $this->surfaceSnapshot($surface);

        if ($this->same($current, $package['target_surface'])) {
            return $this->issueAuthorization(
                'apply',
                $actorAdminId,
                $expectedDeployedSha,
                $expectedReleaseId,
                $this->receipt('already_applied', $surface, $package['changed_paths']),
                $baselineEvidence,
            );
        }
        if (! $this->same($current, $package['before_surface'])) {
            throw new RuntimeException('Pre-apply surface drift detected. No write was performed.');
        }

        return $this->issueAuthorization(
            'apply',
            $actorAdminId,
            $expectedDeployedSha,
            $expectedReleaseId,
            $this->receipt('ready_to_apply', $surface, $package['changed_paths']),
            $baselineEvidence,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function preflightRollback(
        string $beforeSnapshotJson,
        string $targetPackageJson,
        int $actorAdminId,
        string $expectedDeployedSha,
        string $expectedReleaseId,
    ): array {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $this->assertGlobalAuthorityContext();
        $this->assertActor($actorAdminId);
        session()->forget($this->authorizationSessionKey($actorAdminId, 'rollback'));
        $this->assertRuntimeIdentity($expectedDeployedSha, $expectedReleaseId);

        $surface = $this->findSurface();
        $current = $this->surfaceSnapshot($surface);

        if ($this->same($current, $package['before_surface'])) {
            return $this->issueAuthorization(
                'rollback',
                $actorAdminId,
                $expectedDeployedSha,
                $expectedReleaseId,
                $this->receipt('already_rolled_back', $surface, $package['changed_paths']),
            );
        }
        if (! $this->same($current, $package['target_surface'])) {
            throw new RuntimeException('Pre-rollback surface drift detected. No write was performed.');
        }

        return $this->issueAuthorization(
            'rollback',
            $actorAdminId,
            $expectedDeployedSha,
            $expectedReleaseId,
            $this->receipt('ready_to_rollback', $surface, $package['changed_paths']),
        );
    }

    /**
     * @param  array<string,mixed>  $requestContext
     * @return array<string,mixed>
     */
    public function apply(
        string $beforeSnapshotJson,
        string $targetPackageJson,
        string $baselineReceiptJson,
        string $landingAndProductFunnelJson,
        string $attemptResultFunnelJson,
        string $failureCohortsJson,
        int $actorAdminId,
        string $expectedDeployedSha,
        string $expectedReleaseId,
        string $preflightFingerprint,
        string $operatorApprovalPhrase,
        array $requestContext = [],
    ): array {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $baselineEvidence = $this->baselineEvidence->validate(
            $baselineReceiptJson,
            $landingAndProductFunnelJson,
            $attemptResultFunnelJson,
            $failureCohortsJson,
            $expectedDeployedSha,
            $expectedReleaseId,
        );
        $this->assertGlobalAuthorityContext();
        $this->assertActor($actorAdminId);
        $authorization = $this->consumeAuthorization(
            'apply',
            $actorAdminId,
            $expectedDeployedSha,
            $expectedReleaseId,
            $preflightFingerprint,
            $operatorApprovalPhrase,
            $baselineEvidence,
        );

        return DB::transaction(function () use ($package, $actorAdminId, $requestContext, $authorization): array {
            $surface = $this->findSurface(lockForUpdate: true);
            $current = $this->surfaceSnapshot($surface);

            if ($this->same($current, $package['target_surface'])) {
                $this->recordAudit('riasec_global_cms_apply_idempotent', $actorAdminId, $requestContext, 'already_applied', $authorization);

                return $this->receipt('already_applied', $surface, $package['changed_paths']);
            }

            if (! $this->same($current, $package['before_surface'])) {
                throw new RuntimeException('Pre-apply surface drift detected. No write was performed.');
            }

            $this->fillSurface($surface, $package['target_surface']);
            $surface->save();
            $surface->refresh();

            if (! $this->same($this->surfaceSnapshot($surface), $package['target_surface'])) {
                throw new RuntimeException('Post-apply readback mismatch. The transaction was rolled back.');
            }

            $this->recordAudit('riasec_global_cms_apply', $actorAdminId, $requestContext, 'applied', $authorization);

            return $this->receipt('applied', $surface, $package['changed_paths']);
        }, 3);
    }

    /**
     * @param  array<string,mixed>  $requestContext
     * @return array<string,mixed>
     */
    public function rollback(
        string $beforeSnapshotJson,
        string $targetPackageJson,
        int $actorAdminId,
        string $expectedDeployedSha,
        string $expectedReleaseId,
        string $preflightFingerprint,
        string $operatorApprovalPhrase,
        array $requestContext = [],
    ): array {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $this->assertGlobalAuthorityContext();
        $this->assertActor($actorAdminId);
        $authorization = $this->consumeAuthorization(
            'rollback',
            $actorAdminId,
            $expectedDeployedSha,
            $expectedReleaseId,
            $preflightFingerprint,
            $operatorApprovalPhrase,
        );

        return DB::transaction(function () use ($package, $actorAdminId, $requestContext, $authorization): array {
            $surface = $this->findSurface(lockForUpdate: true);
            $current = $this->surfaceSnapshot($surface);

            if ($this->same($current, $package['before_surface'])) {
                $this->recordAudit('riasec_global_cms_rollback_idempotent', $actorAdminId, $requestContext, 'already_rolled_back', $authorization);

                return $this->receipt('already_rolled_back', $surface, $package['changed_paths']);
            }

            if (! $this->same($current, $package['target_surface'])) {
                throw new RuntimeException('Rollback source drift detected. No write was performed.');
            }

            $this->fillSurface($surface, $package['before_surface']);
            $surface->save();
            $surface->refresh();

            if (! $this->same($this->surfaceSnapshot($surface), $package['before_surface'])) {
                throw new RuntimeException('Rollback readback mismatch. The transaction was rolled back.');
            }

            $this->recordAudit('riasec_global_cms_rollback', $actorAdminId, $requestContext, 'rolled_back', $authorization);

            return $this->receipt('rolled_back', $surface, $package['changed_paths']);
        }, 3);
    }

    /**
     * @return array{before_surface:array<string,mixed>,target_surface:array<string,mixed>,changed_paths:list<string>}
     */
    private function validatedPackage(string $beforeSnapshotJson, string $targetPackageJson): array
    {
        if (! hash_equals(self::BEFORE_SNAPSHOT_SHA256, hash('sha256', $beforeSnapshotJson))) {
            throw new RuntimeException('Before snapshot SHA-256 mismatch.');
        }

        if (! hash_equals(self::TARGET_PACKAGE_SHA256, hash('sha256', $targetPackageJson))) {
            throw new RuntimeException('Target package SHA-256 mismatch.');
        }

        $before = $this->decodeObject($beforeSnapshotJson, 'before snapshot');
        $target = $this->decodeObject($targetPackageJson, 'target package');
        $beforeSurface = $before['surface'] ?? null;

        if (($before['ok'] ?? null) !== true || ! is_array($beforeSurface)) {
            throw new RuntimeException('Before snapshot schema mismatch.');
        }

        if (
            ($beforeSurface['surface_key'] ?? null) !== self::SURFACE_KEY
            || ($beforeSurface['locale'] ?? null) !== self::LOCALE
            || ($target['org_id'] ?? null) !== self::ORG_ID
            || ($target['locale'] ?? null) !== self::LOCALE
        ) {
            throw new RuntimeException('Package authority identity mismatch.');
        }

        $targetSurface = [
            'surface_key' => self::SURFACE_KEY,
            'locale' => self::LOCALE,
            'title' => $target['title'] ?? null,
            'description' => $target['description'] ?? null,
            'schema_version' => $target['schema_version'] ?? null,
            'payload_json' => $target['payload_json'] ?? null,
            'status' => $target['status'] ?? null,
            'is_public' => $target['is_public'] ?? null,
            'is_indexable' => $target['is_indexable'] ?? null,
            'published_at' => $target['published_at'] ?? null,
            'scheduled_at' => $target['scheduled_at'] ?? null,
            'page_blocks' => $target['page_blocks'] ?? null,
        ];

        if (
            ($targetSurface['status'] ?? null) !== LandingSurface::STATUS_PUBLISHED
            || ($targetSurface['is_public'] ?? null) !== true
            || ($targetSurface['is_indexable'] ?? null) !== true
            || ($beforeSurface['page_blocks'] ?? null) !== []
            || ($targetSurface['page_blocks'] ?? null) !== []
            || ! is_array($beforeSurface['payload_json'] ?? null)
            || ! is_array($targetSurface['payload_json'] ?? null)
        ) {
            throw new RuntimeException('Package guardrail mismatch.');
        }

        $changedPaths = $this->changedPaths($beforeSurface, $targetSurface);
        $unauthorizedPaths = array_values(array_diff($changedPaths, self::ALLOWED_CHANGED_PATHS));

        if ($changedPaths === [] || $unauthorizedPaths !== []) {
            throw new RuntimeException('Package changed-field boundary mismatch.');
        }

        return [
            'before_surface' => $beforeSurface,
            'target_surface' => $targetSurface,
            'changed_paths' => $changedPaths,
        ];
    }

    private function assertGlobalAuthorityContext(): void
    {
        if ($this->orgContext->orgId() !== self::ORG_ID || ! $this->orgContext->isPublicContext()) {
            throw new RuntimeException('The bridge requires the unselected org-0 Ops authority context.');
        }
    }

    private function assertActor(int $actorAdminId): void
    {
        if ($actorAdminId <= 0) {
            throw new RuntimeException('Authenticated owner identity is required.');
        }
    }

    private function assertRuntimeIdentity(string $expectedDeployedSha, string $expectedReleaseId): void
    {
        if (
            preg_match('/^[0-9a-f]{40}$/', $expectedDeployedSha) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $expectedReleaseId) !== 1
        ) {
            throw new RuntimeException('Exact deployed SHA and release identity are required.');
        }

        $actualSha = '';
        $actualReleaseId = '';
        if (app()->environment('testing')) {
            $actualSha = trim((string) config('app.riasec_global_cms_test_runtime_revision', ''));
            $actualReleaseId = trim((string) config('app.riasec_global_cms_test_release_id', ''));
        }
        if ($actualSha === '' && $actualReleaseId === '') {
            $revisionPath = base_path('../REVISION');
            $releaseRoot = realpath(base_path('..'));
            $actualSha = is_file($revisionPath) ? trim((string) file_get_contents($revisionPath)) : '';
            $actualReleaseId = is_string($releaseRoot) ? basename($releaseRoot) : '';
        }

        if (
            ! hash_equals($expectedDeployedSha, $actualSha)
            || ! hash_equals($expectedReleaseId, $actualReleaseId)
        ) {
            throw new RuntimeException('Active backend REVISION or release identity does not match the authorization.');
        }
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function issueAuthorization(
        string $action,
        int $actorAdminId,
        string $deployedSha,
        string $releaseId,
        array $receipt,
        ?array $baselineEvidence = null,
    ): array {
        $issuedAt = now()->getTimestamp();
        $authorization = [
            'action' => $action,
            'actor_admin_id' => $actorAdminId,
            'deployed_sha' => $deployedSha,
            'release_id' => $releaseId,
            'experiment_id' => self::EXPERIMENT_ID,
            'surface_key' => self::SURFACE_KEY,
            'locale' => self::LOCALE,
            'org_id' => self::ORG_ID,
            'before_snapshot_sha256' => self::BEFORE_SNAPSHOT_SHA256,
            'target_package_sha256' => self::TARGET_PACKAGE_SHA256,
            'surface_updated_at' => $receipt['updated_at'] ?? null,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + self::AUTHORIZATION_TTL_SECONDS,
        ];
        if ($baselineEvidence !== null) {
            $authorization['production_baseline'] = $baselineEvidence;
        }
        $fingerprint = hash('sha256', (string) json_encode(
            $this->canonicalize($authorization),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $authorization['preflight_fingerprint'] = $fingerprint;
        session()->put($this->authorizationSessionKey($actorAdminId, $action), $authorization);

        return $receipt + [
            'authorization_action' => $action,
            'deployed_sha' => $deployedSha,
            'release_id' => $releaseId,
            'preflight_fingerprint' => $fingerprint,
            'preflight_expires_at' => gmdate('c', $authorization['expires_at']),
            'operator_approval_phrase' => $this->expectedApprovalPhrase($action, $deployedSha, $releaseId, $fingerprint),
        ] + ($baselineEvidence === null ? [] : ['production_baseline' => $baselineEvidence]);
    }

    /**
     * @return array<string,mixed>
     */
    private function consumeAuthorization(
        string $action,
        int $actorAdminId,
        string $deployedSha,
        string $releaseId,
        string $preflightFingerprint,
        string $operatorApprovalPhrase,
        ?array $baselineEvidence = null,
    ): array {
        $sessionKey = $this->authorizationSessionKey($actorAdminId, $action);
        $authorization = session()->get($sessionKey);
        if (! is_array($authorization)) {
            throw new RuntimeException('A fresh exact preflight is required before this mutation.');
        }

        $storedFingerprint = trim((string) ($authorization['preflight_fingerprint'] ?? ''));
        $unsigned = $authorization;
        unset($unsigned['preflight_fingerprint']);
        $computedFingerprint = hash('sha256', (string) json_encode(
            $this->canonicalize($unsigned),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $now = now()->getTimestamp();
        $expectedPhrase = $this->expectedApprovalPhrase($action, $deployedSha, $releaseId, $storedFingerprint);

        if (
            preg_match('/^[0-9a-f]{64}$/', $preflightFingerprint) !== 1
            || ! hash_equals($computedFingerprint, $storedFingerprint)
            || ! hash_equals($storedFingerprint, $preflightFingerprint)
            || ($authorization['action'] ?? null) !== $action
            || ($authorization['actor_admin_id'] ?? null) !== $actorAdminId
            || ($authorization['deployed_sha'] ?? null) !== $deployedSha
            || ($authorization['release_id'] ?? null) !== $releaseId
            || ($authorization['experiment_id'] ?? null) !== self::EXPERIMENT_ID
            || ($authorization['surface_key'] ?? null) !== self::SURFACE_KEY
            || ($authorization['locale'] ?? null) !== self::LOCALE
            || ($authorization['org_id'] ?? null) !== self::ORG_ID
            || ($authorization['before_snapshot_sha256'] ?? null) !== self::BEFORE_SNAPSHOT_SHA256
            || ($authorization['target_package_sha256'] ?? null) !== self::TARGET_PACKAGE_SHA256
            || ($action === 'apply' && ! $this->same($authorization['production_baseline'] ?? null, $baselineEvidence))
            || ($action === 'rollback' && array_key_exists('production_baseline', $authorization))
            || ! is_int($authorization['issued_at'] ?? null)
            || ! is_int($authorization['expires_at'] ?? null)
            || $authorization['issued_at'] > $now
            || $authorization['expires_at'] < $now
            || $authorization['expires_at'] - $authorization['issued_at'] !== self::AUTHORIZATION_TTL_SECONDS
            || ! hash_equals($expectedPhrase, $operatorApprovalPhrase)
        ) {
            throw new RuntimeException('Fresh exact preflight authorization does not match.');
        }

        $this->assertRuntimeIdentity($deployedSha, $releaseId);
        session()->forget($sessionKey);
        $authorization['operator_approval_phrase_sha256'] = hash('sha256', $operatorApprovalPhrase);

        return $authorization;
    }

    private function authorizationSessionKey(int $actorAdminId, string $action): string
    {
        return 'riasec_global_cms_authorization.'.$actorAdminId.'.'.$action;
    }

    private function expectedApprovalPhrase(
        string $action,
        string $deployedSha,
        string $releaseId,
        string $preflightFingerprint,
    ): string {
        $direction = $action === 'rollback'
            ? 'target SHA256 '.self::TARGET_PACKAGE_SHA256.' to before SHA256 '.self::BEFORE_SNAPSHOT_SHA256
            : 'before SHA256 '.self::BEFORE_SNAPSHOT_SHA256.' to target SHA256 '.self::TARGET_PACKAGE_SHA256;

        return sprintf(
            'I explicitly approve one production CMS %s for experiment %s, deployed SHA %s, release %s, surface %s, locale %s, org 0, %s, preflight %s.',
            $action,
            self::EXPERIMENT_ID,
            $deployedSha,
            $releaseId,
            self::SURFACE_KEY,
            self::LOCALE,
            $direction,
            $preflightFingerprint,
        );
    }

    private function findSurface(bool $lockForUpdate = false): LandingSurface
    {
        $query = LandingSurface::query()
            ->withoutGlobalScopes()
            ->with('blocks')
            ->where('org_id', self::ORG_ID)
            ->where('surface_key', self::SURFACE_KEY)
            ->where('locale', self::LOCALE);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $surface = $query->first();
        if (! $surface instanceof LandingSurface) {
            throw new RuntimeException('Exact RIASEC global landing surface not found.');
        }

        return $surface;
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceSnapshot(LandingSurface $surface): array
    {
        $surface->loadMissing('blocks');

        if ($surface->blocks->isNotEmpty()) {
            throw new RuntimeException('RIASEC page-block state drift detected.');
        }

        return [
            'surface_key' => (string) $surface->surface_key,
            'locale' => (string) $surface->locale,
            'title' => $surface->title,
            'description' => $surface->description,
            'schema_version' => (string) $surface->schema_version,
            'payload_json' => is_array($surface->payload_json) ? $surface->payload_json : [],
            'status' => (string) $surface->status,
            'is_public' => (bool) $surface->is_public,
            'is_indexable' => (bool) $surface->is_indexable,
            'published_at' => $surface->published_at?->toIso8601String(),
            'scheduled_at' => $surface->scheduled_at?->toIso8601String(),
            'page_blocks' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function fillSurface(LandingSurface $surface, array $snapshot): void
    {
        $surface->fill([
            'title' => $snapshot['title'],
            'description' => $snapshot['description'],
            'schema_version' => $snapshot['schema_version'],
            'payload_json' => $snapshot['payload_json'],
            'status' => $snapshot['status'],
            'is_public' => $snapshot['is_public'],
            'is_indexable' => $snapshot['is_indexable'],
            'published_at' => $snapshot['published_at'],
            'scheduled_at' => $snapshot['scheduled_at'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $requestContext
     */
    private function recordAudit(
        string $action,
        int $actorAdminId,
        array $requestContext,
        string $result,
        array $authorization,
    ): void {
        $meta = [
            'experiment_id' => self::EXPERIMENT_ID,
            'before_snapshot_sha256' => self::BEFORE_SNAPSHOT_SHA256,
            'target_package_sha256' => self::TARGET_PACKAGE_SHA256,
            'surface_key' => self::SURFACE_KEY,
            'locale' => self::LOCALE,
            'org_id' => self::ORG_ID,
            'result' => $result,
            'deployed_sha' => $authorization['deployed_sha'],
            'release_id' => $authorization['release_id'],
            'preflight_fingerprint' => $authorization['preflight_fingerprint'],
            'operator_approval_phrase_sha256' => $authorization['operator_approval_phrase_sha256'],
        ];
        if (is_array($authorization['production_baseline'] ?? null)) {
            $meta['production_baseline'] = $authorization['production_baseline'];
        }

        DB::table('audit_logs')->insert([
            'org_id' => self::ORG_ID,
            'actor_admin_id' => $actorAdminId,
            'action' => $action,
            'target_type' => 'landing_surface',
            'target_id' => self::SURFACE_KEY,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ip' => $this->boundedNullable($requestContext['ip'] ?? null, 64),
            'user_agent' => $this->boundedNullable($requestContext['user_agent'] ?? null, 255),
            'request_id' => $this->boundedNullable($requestContext['request_id'] ?? null, 128),
            'reason' => self::EXPERIMENT_ID,
            'result' => $result,
            'created_at' => now(),
        ]);

        OpsAuditLogger::log(strtoupper($action), $meta + ['actor_admin_id' => $actorAdminId]);
    }

    /**
     * @param  list<string>  $changedPaths
     * @return array<string,mixed>
     */
    private function receipt(string $status, LandingSurface $surface, array $changedPaths): array
    {
        return [
            'experiment_id' => self::EXPERIMENT_ID,
            'status' => $status,
            'surface_key' => self::SURFACE_KEY,
            'locale' => self::LOCALE,
            'org_id' => self::ORG_ID,
            'before_snapshot_sha256' => self::BEFORE_SNAPSHOT_SHA256,
            'target_package_sha256' => self::TARGET_PACKAGE_SHA256,
            'changed_paths' => $changedPaths,
            'updated_at' => $surface->updated_at?->toIso8601String(),
            'discoverability_change_triggered' => false,
            'application_deploy_triggered' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Invalid {$label} JSON.");
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("Invalid {$label} JSON object.");
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function changedPaths(mixed $before, mixed $after, string $prefix = ''): array
    {
        if (! is_array($before) || ! is_array($after)) {
            return $before === $after ? [] : [$prefix];
        }

        if (array_is_list($before) || array_is_list($after)) {
            return $this->same($before, $after) ? [] : [$prefix];
        }

        $paths = [];
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        sort($keys);

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (! array_key_exists($key, $before) || ! array_key_exists($key, $after)) {
                $paths[] = $path;

                continue;
            }

            $paths = [...$paths, ...$this->changedPaths($before[$key], $after[$key], $path)];
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->canonicalize($left) === $this->canonicalize($right);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function boundedNullable(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_substr($normalized, 0, $limit);
    }
}
