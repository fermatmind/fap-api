<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\LandingSurface;
use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class RiasecGlobalCmsApplyBridge
{
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
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(string $beforeSnapshotJson, string $targetPackageJson): array
    {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $this->assertGlobalAuthorityContext();

        $surface = $this->findSurface();
        $current = $this->surfaceSnapshot($surface);

        if ($this->same($current, $package['target_surface'])) {
            $status = 'already_applied';
        } elseif ($this->same($current, $package['before_surface'])) {
            $status = 'ready_to_apply';
        } else {
            throw new RuntimeException('Pre-apply surface drift detected. No write was performed.');
        }

        return $this->receipt($status, $surface, $package['changed_paths']);
    }

    /**
     * @param  array<string,mixed>  $requestContext
     * @return array<string,mixed>
     */
    public function apply(
        string $beforeSnapshotJson,
        string $targetPackageJson,
        int $actorAdminId,
        array $requestContext = [],
    ): array {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $this->assertGlobalAuthorityContext();
        $this->assertActor($actorAdminId);

        return DB::transaction(function () use ($package, $actorAdminId, $requestContext): array {
            $surface = $this->findSurface(lockForUpdate: true);
            $current = $this->surfaceSnapshot($surface);

            if ($this->same($current, $package['target_surface'])) {
                $this->recordAudit('riasec_global_cms_apply_idempotent', $actorAdminId, $requestContext, 'already_applied');

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

            $this->recordAudit('riasec_global_cms_apply', $actorAdminId, $requestContext, 'applied');

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
        array $requestContext = [],
    ): array {
        $package = $this->validatedPackage($beforeSnapshotJson, $targetPackageJson);
        $this->assertGlobalAuthorityContext();
        $this->assertActor($actorAdminId);

        return DB::transaction(function () use ($package, $actorAdminId, $requestContext): array {
            $surface = $this->findSurface(lockForUpdate: true);
            $current = $this->surfaceSnapshot($surface);

            if ($this->same($current, $package['before_surface'])) {
                $this->recordAudit('riasec_global_cms_rollback_idempotent', $actorAdminId, $requestContext, 'already_rolled_back');

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

            $this->recordAudit('riasec_global_cms_rollback', $actorAdminId, $requestContext, 'rolled_back');

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
    ): void {
        $meta = [
            'experiment_id' => self::EXPERIMENT_ID,
            'before_snapshot_sha256' => self::BEFORE_SNAPSHOT_SHA256,
            'target_package_sha256' => self::TARGET_PACKAGE_SHA256,
            'surface_key' => self::SURFACE_KEY,
            'locale' => self::LOCALE,
            'org_id' => self::ORG_ID,
            'result' => $result,
        ];

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
