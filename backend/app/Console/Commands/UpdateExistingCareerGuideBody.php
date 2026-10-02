<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\AdminUser;
use App\Models\CareerGuide;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Updates existing public guide copy; creates no editorial approval or publication transition. */
final class UpdateExistingCareerGuideBody extends Command
{
    protected $signature = 'career-guides:update-existing-body
        {--file= : Complete native before/candidate package}
        {--sha256= : Exact package bytes SHA-256}
        {--execute : Apply only the locked body update; default is read-only}
        {--deployed-sha= : Exact active release SHA required for production execute}
        {--admin-user-id= : Authorized active operator for execute}
        {--confirm= : Exact package SHA-256 for execute}';

    protected $description = 'Update one existing public CareerGuide body with row, native snapshot and history locks, revision, audit and readback.';

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    public static function state(CareerGuide $guide): array
    {
        $guide->unsetRelations();

        return [
            'attributes_sha256' => self::digest($guide->getAttributes()),
            'native_snapshot' => CareerGuideWorkspace::snapshotPayload($guide),
            'revisions' => $guide->revisions()->get()->map(static fn ($revision): array => [
                'id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'snapshot_sha256' => self::digest($revision->snapshot_json),
                'note' => $revision->note,
                'created_at' => $revision->created_at?->toISOString(),
            ])->all(),
        ];
    }

    public function handle(AuditLogger $logger): int
    {
        $guard = auth((string) config('admin.guard', 'admin'));
        $originalActor = $guard->user();
        $execute = (bool) $this->option('execute');
        try {
            $file = (string) $this->option('file');
            $sha = (string) $this->option('sha256');
            if (! is_file($file) || is_link($file) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
                throw new RuntimeException('package_required');
            }
            $bytes = file_get_contents($file);
            if ($bytes === false || ! hash_equals($sha, hash('sha256', $bytes))) {
                throw new RuntimeException('package_bytes_drift');
            }
            $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (($package['schema'] ?? null) !== 'career_guide_existing_body_update.v1') {
                throw new RuntimeException('package_schema_invalid');
            }
            $body = data_get($package, 'candidate.body_md');
            if (! is_string($body) || trim($body) === ''
                || ! hash_equals(hash('sha256', $body), (string) data_get($package, 'candidate.body_md_sha256'))) {
                throw new RuntimeException('candidate_body_invalid');
            }
            $actor = null;
            if ($execute) {
                if (! hash_equals($sha, (string) $this->option('confirm')) || ! SchemaBaseline::hasTable('audit_logs')) {
                    throw new RuntimeException('execute_confirmation_or_audit_missing');
                }
                if (app()->environment('production')) {
                    $releaseFile = dirname(base_path()).'/REVISION';
                    $releaseSha = (string) $this->option('deployed-sha');
                    if (preg_match('/\A[a-f0-9]{40}\z/', $releaseSha) !== 1 || ! is_file($releaseFile)
                        || ! hash_equals($releaseSha, trim((string) file_get_contents($releaseFile)))) {
                        throw new RuntimeException('production_release_drift');
                    }
                }
                $actor = AdminUser::query()->find((int) $this->option('admin-user-id'));
                if (! $actor instanceof AdminUser || (int) $actor->is_active !== 1
                    || $actor->locked_until?->isFuture()
                    || ! ($actor->hasPermission(PermissionNames::ADMIN_OWNER)
                        || $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH))) {
                    throw new RuntimeException('operator_publish_permission_required');
                }
            }
            $operation = function () use ($package, $body, $execute, $actor, $logger, $sha): array {
                $identity = (array) ($package['identity'] ?? []);
                $query = CareerGuide::withoutGlobalScopes()->whereKey((int) ($identity['id'] ?? 0));
                $guide = ($execute ? $query->lockForUpdate() : $query)->first();
                if (! $guide instanceof CareerGuide || (int) $guide->org_id !== 0
                    || ! in_array($guide->locale, CareerGuide::SUPPORTED_LOCALES, true)
                    || $guide->status !== CareerGuide::STATUS_PUBLISHED || ! $guide->is_public
                    || filled($guide->body_html)) {
                    throw new RuntimeException('existing_public_markdown_guide_required');
                }
                foreach (['id', 'org_id', 'guide_code', 'slug', 'locale'] as $field) {
                    if ($guide->getAttribute($field) !== ($identity[$field] ?? null)) {
                        throw new RuntimeException('identity_drift');
                    }
                }
                $before = self::state($guide);
                $expected = $before['native_snapshot'];
                $expected['guide']['body_md'] = $body;
                if (self::digest($expected) !== self::digest(data_get($package, 'candidate.native_snapshot'))) {
                    throw new RuntimeException('body_only_scope_required');
                }
                if (! hash_equals($before['attributes_sha256'], (string) data_get($package, 'before.attributes_sha256'))
                    || ! hash_equals(self::digest($before['native_snapshot']), (string) data_get($package, 'before.native_snapshot_sha256'))
                    || ! hash_equals(self::digest($before['revisions']), (string) data_get($package, 'before.revision_history_sha256'))) {
                    throw new RuntimeException('before_state_drift');
                }
                if (! $execute || $guide->body_md === $body) {
                    return ['guide_id' => $guide->id, 'changed' => false, 'readonly' => ! $execute];
                }
                $attributes = $guide->getAttributes();
                $guide->body_md = $body;
                $guide->save(); // Preserve the native after-commit PublicAuthorityChanged event.
                CareerGuideWorkspace::createRevision($guide, 'Delegated CLI body update package '.$sha);
                $guide->refresh();
                $after = self::state($guide);
                $afterAttributes = $guide->getAttributes();
                foreach (['body_md', 'updated_at'] as $field) {
                    unset($attributes[$field], $afterAttributes[$field]);
                }
                if ($attributes !== $afterAttributes || self::digest($after['native_snapshot']) !== self::digest($expected)
                    || count($after['revisions']) !== count($before['revisions']) + 1
                    || array_slice($after['revisions'], 1) !== $before['revisions']) {
                    throw new RuntimeException('native_readback_failed');
                }
                $logger->log(Request::create('/console/career-guides/update-existing-body', 'POST'),
                    'career_guide_body_update', 'career_guide', (string) $guide->id,
                    ['package_sha256' => $sha, 'before_snapshot_sha256' => self::digest($before['native_snapshot']),
                        'actor' => 'delegated_cli', 'authorized_operator_id' => (int) $actor->id,
                        'after_snapshot_sha256' => self::digest($after['native_snapshot']), 'editorial_attestation_created' => false],
                    'Operator-authorized existing guide copy update');

                return ['guide_id' => $guide->id, 'changed' => true, 'readonly' => false,
                    'new_revision_id' => $after['revisions'][0]['id'], 'readback_passed' => true];
            };
            $result = $execute ? DB::transaction($operation) : $operation();
            $this->line(json_encode(['ok' => true, ...$result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException && preg_match('/\A[a-z_]+\z/', $exception->getMessage())
                ? $exception->getMessage() : 'native_update_failed';
            $this->line(json_encode(['ok' => false, 'readonly' => ! $execute, 'error' => $error], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        } finally {
            if ($execute) {
                $guard->forgetUser();
                if ($originalActor !== null) {
                    $guard->setUser($originalActor);
                }
            }
        }
    }
}
