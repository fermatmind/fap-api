<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Ops\Resources\TopicProfileResource\Support\TopicWorkspace;
use App\Models\AdminUser;
use App\Models\LandingSurface;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileSection;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Bounded native copy edits; publication, eligibility and content identity remain unchanged. */
final class UpdateExistingCmsReaderFields extends Command
{
    protected $signature = 'cms:update-existing-reader-fields
        {--file= : One native topic or category delta package}
        {--sha256= : Exact package bytes SHA-256}
        {--execute : Default is read-only}
        {--admin-user-id= : Active publish-authorized operator}
        {--deployed-sha= : Exact production release}
        {--confirm= : Exact package SHA-256 for execute}';

    protected $description = 'Apply locked native reader field deltas to an existing public topic or test category.';

    public static function state(Model $record, bool $lock = false): array
    {
        $rows = static function ($query) use ($lock): array {
            return ($lock ? $query->lockForUpdate() : $query)->get()->map(fn ($row) => $row->getAttributes())->all();
        };
        if ($record instanceof TopicProfile) {
            return ['attributes' => $record->getAttributes(),
                'sections' => $rows($record->sections()->withoutGlobalScopes()),
                'entries' => $rows($record->entries()->withoutGlobalScopes()),
                'seo' => $rows($record->seoMeta()->withoutGlobalScopes()),
                'revisions' => $rows($record->revisions()->withoutGlobalScopes())];
        }

        return ['attributes' => $record->getAttributes(), 'blocks' => $rows($record->blocks()->withoutGlobalScopes())];
    }

    public function handle(AuditLogger $logger): int
    {
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
            $kind = $package['kind'] ?? null;
            if (($package['schema'] ?? null) !== 'existing_cms_reader_fields.v1' || ! in_array($kind, ['topic', 'tests_category'], true)) {
                throw new RuntimeException('package_schema_invalid');
            }
            if ($execute) {
                if (! hash_equals($sha, (string) $this->option('confirm')) || ! SchemaBaseline::hasTable('audit_logs')) {
                    throw new RuntimeException('execute_confirmation_or_audit_missing');
                }
                if (app()->environment('production')) {
                    $release = dirname(base_path()).'/REVISION';
                    $expected = (string) $this->option('deployed-sha');
                    if (preg_match('/\A[a-f0-9]{40}\z/', $expected) !== 1 || ! is_file($release)
                        || ! hash_equals($expected, trim((string) file_get_contents($release)))) {
                        throw new RuntimeException('production_release_drift');
                    }
                }
                $actor = AdminUser::query()->find((int) $this->option('admin-user-id'));
                if (! $actor instanceof AdminUser || (int) $actor->is_active !== 1 || $actor->locked_until?->isFuture()
                    || ! ($actor->hasPermission(PermissionNames::ADMIN_OWNER) || $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH))) {
                    throw new RuntimeException('operator_publish_permission_required');
                }
            }
            $operation = function () use ($package, $kind, $sha, $execute, $logger): array {
                $class = $kind === 'topic' ? TopicProfile::class : LandingSurface::class;
                $query = $class::withoutGlobalScopes()->whereKey((int) data_get($package, 'identity.id'));
                $record = ($execute ? $query->lockForUpdate() : $query)->first();
                if (! $record || (int) $record->org_id !== 0 || $record->status !== 'published' || ! $record->is_public
                    || ! in_array($record->locale, ['zh-CN', 'en'], true)
                    || ($kind === 'tests_category' && $record->surface_key !== 'tests_category_personality')) {
                    throw new RuntimeException('existing_public_native_record_required');
                }
                $keys = $kind === 'topic' ? ['id', 'org_id', 'slug', 'locale', 'topic_code'] : ['id', 'org_id', 'surface_key', 'locale'];
                foreach ($keys as $key) {
                    if ($record->getAttribute($key) !== data_get($package, 'identity.'.$key)) {
                        throw new RuntimeException('identity_drift');
                    }
                }
                $before = self::state($record, $execute);
                if (! hash_equals(Hash::hash($before), (string) ($package['before_sha256'] ?? ''))) {
                    throw new RuntimeException('before_state_drift');
                }
                $deltas = $package['operations'] ?? null;
                if (! is_array($deltas) || ! array_is_list($deltas) || $deltas === [] || count($deltas) > 30) {
                    throw new RuntimeException('bounded_operations_required');
                }
                $expected = $before;
                $writes = [];
                $seen = [];
                $payload = $record instanceof LandingSurface ? $record->payload_json : null;
                foreach ($deltas as $delta) {
                    if ($record instanceof TopicProfile) {
                        $entity = $delta['entity'] ?? '';
                        $field = $delta['field'] ?? '';
                        $id = (int) ($delta['id'] ?? 0);
                        $allowed = ['profile' => ['subtitle', 'excerpt'], 'section' => ['body_md'], 'entry' => ['title_override', 'excerpt_override']];
                        if (! in_array($field, $allowed[$entity] ?? [], true)) {
                            throw new RuntimeException('reader_field_scope_required');
                        }
                        $bucket = ['profile' => 'attributes', 'section' => 'sections', 'entry' => 'entries'][$entity];
                        $index = $entity === 'profile' ? null : array_search($id, array_column($before[$bucket], 'id'), true);
                        if (($entity === 'profile' && $id !== (int) $record->id) || $index === false) {
                            throw new RuntimeException('native_child_identity_invalid');
                        }
                        $old = $entity === 'profile' ? $before[$bucket][$field] : $before[$bucket][$index][$field];
                        $new = $delta['after'] ?? null;
                        if (! array_key_exists('native_before', $delta) || $old !== $delta['native_before'] || (! is_string($new) && $new !== null)) {
                            throw new RuntimeException('native_field_drift');
                        }
                        if ($entity === 'section' && filled($before[$bucket][$index]['body_html'] ?? null)) {
                            throw new RuntimeException('html_shadow_requires_separate_edit');
                        }
                        if ($old === $new) {
                            throw new RuntimeException('reader_change_required');
                        }
                        $key = $entity.':'.$id.':'.$field;
                        if ($entity === 'profile') {
                            $expected[$bucket][$field] = $new;
                        } else {
                            $expected[$bucket][$index][$field] = $new;
                        }
                        $writes[] = [$entity, $id, $field, $new];
                    } else {
                        $pointer = $delta['pointer'] ?? '';
                        if (! is_string($pointer) || preg_match('#\A/payload_json/(?:featured|allTests|differences|resources)/(?:kicker|items/[0-9]+/(?:durationLabel|questionsLabel|scientificBasis|href|primaryActions/[0-9]+/href))\z#', $pointer) !== 1) {
                            throw new RuntimeException('category_reader_pointer_required');
                        }
                        $parts = explode('/', substr($pointer, strlen('/payload_json/')));
                        $cursor = &$payload;
                        foreach ($parts as $part) {
                            if (! is_array($cursor) || ! array_key_exists($part, $cursor)) {
                                throw new RuntimeException('existing_payload_field_required');
                            }
                            $cursor = &$cursor[$part];
                        }
                        if (! array_key_exists('current', $delta) || $cursor !== $delta['current'] || ! is_string($delta['proposed'] ?? null)) {
                            throw new RuntimeException('native_field_drift');
                        }
                        $new = $delta['proposed'];
                        if ($cursor === $new) {
                            throw new RuntimeException('reader_change_required');
                        }
                        // These two query values are existing BigFiveFormCatalog identities.
                        $plainTestPath = preg_match('#\A/(?:zh|en)/tests/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9-]+)?\z#', $new) === 1;
                        $bigFiveFormPath = preg_match('#\A/(?:zh|en)/tests/big-five-personality-test-ocean-model/take\?form=big5_(?:90|120)\z#', $new) === 1;
                        if (end($parts) === 'href' && ! $plainTestPath && ! $bigFiveFormPath) {
                            throw new RuntimeException('public_test_path_required');
                        }
                        $cursor = $new;
                        unset($cursor);
                        $key = $pointer;
                    }
                    if (isset($seen[$key])) {
                        throw new RuntimeException('duplicate_operation');
                    }
                    $seen[$key] = true;
                }
                if (! $execute) {
                    return ['readonly' => true, 'id' => $record->id, 'kind' => $kind, 'operations' => count($deltas)];
                }
                if ($record instanceof TopicProfile) {
                    foreach ($writes as [$entity, $id, $field, $new]) {
                        $target = match ($entity) {
                            'profile' => $record,
                            'section' => TopicProfileSection::withoutGlobalScopes()->findOrFail($id),
                            'entry' => TopicProfileEntry::withoutGlobalScopes()->findOrFail($id),
                        };
                        $target->setAttribute($field, $new);
                        $target->save();
                    }
                    $record->unsetRelations();
                    TopicWorkspace::createRevision($record, 'Delegated CLI native reader fields package '.$sha);
                } else {
                    $record->payload_json = $payload;
                    $record->save();
                }
                $record->refresh();
                $after = self::state($record, true);
                if ($record instanceof TopicProfile) {
                    if (count($after['revisions']) !== count($before['revisions']) + 1 || array_slice($after['revisions'], 1) !== $before['revisions']) {
                        throw new RuntimeException('native_history_readback_failed');
                    }
                    unset($expected['revisions'], $after['revisions']);
                    foreach (['sections', 'entries'] as $bucket) {
                        foreach ($expected[$bucket] as &$row) {
                            unset($row['updated_at']);
                        }
                        unset($row);
                        foreach ($after[$bucket] as &$row) {
                            unset($row['updated_at']);
                        }
                        unset($row);
                    }
                } else {
                    if (Hash::hash($record->payload_json) !== Hash::hash($payload)) {
                        throw new RuntimeException('native_payload_readback_failed');
                    }
                    unset($expected['attributes']['payload_json'], $after['attributes']['payload_json']);
                }
                unset($expected['attributes']['updated_at'], $after['attributes']['updated_at']);
                if (Hash::hash($expected) !== Hash::hash($after)) {
                    throw new RuntimeException('protected_state_readback_failed');
                }
                $logger->log(Request::create('/console/cms/update-existing-reader-fields', 'POST'), 'cms_native_reader_fields_updated',
                    $kind, (string) $record->id, ['package_sha256' => $sha, 'before_native_state' => $before,
                        'after_sha256' => Hash::hash(self::state($record)), 'actor' => 'delegated_cli',
                        'authorized_operator_id' => (int) $this->option('admin-user-id'), 'editorial_attestation_created' => false],
                    'Operator-authorized existing native reader fields update');

                return ['readonly' => false, 'id' => $record->id, 'kind' => $kind, 'readback_passed' => true];
            };
            $this->line(json_encode(['ok' => true, ...($execute ? DB::transaction($operation) : $operation())], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException && preg_match('/\A[a-z_]+\z/', $exception->getMessage()) ? $exception->getMessage() : 'native_reader_update_failed';
            $this->line(json_encode(['ok' => false, 'readonly' => ! $execute, 'error' => $error], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
