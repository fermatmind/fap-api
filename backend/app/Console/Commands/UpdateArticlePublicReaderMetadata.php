<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\PublicAuthorityChanged;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ScaleRegistry;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Existing public reader metadata only; does not approve, publish or modify a text revision. */
final class UpdateArticlePublicReaderMetadata extends Command
{
    protected $signature = 'articles:update-public-reader-metadata
        {--file= : Locked native article/SEO metadata package}
        {--sha256= : Exact package bytes SHA-256}
        {--execute : Default is strictly read-only}
        {--admin-user-id= : Active operator with publish permission}
        {--deployed-sha= : Exact active production revision}
        {--confirm= : Exact package bytes SHA-256 for execute}';

    protected $description = 'Merge bounded FAQ, cover alt and test entry metadata while preserving content, history, pointers and eligibility.';

    /** @return array<string,mixed> */
    public static function state(Article $article, bool $lock = false): array
    {
        $query = ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $article->id)->orderBy('id');
        $seo = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($seo->count() !== 1 || (int) $seo[0]->org_id !== 0 || $seo[0]->locale !== $article->locale) {
            throw new RuntimeException('existing_native_seo_row_required');
        }
        $article->setRelation('seoMeta', $seo[0]);

        return ['article' => $article->getAttributes(), 'seo' => $seo[0]->getAttributes(),
            'history' => ArticleSourceTargetSnapshot::sourceRevisions($article, [], $lock)];
    }

    public function handle(AuditLogger $logger): int
    {
        $execute = (bool) $this->option('execute');
        $guard = auth((string) config('admin.guard', 'admin'));
        $oldActor = $guard->user();
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
            $p = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (($p['schema'] ?? null) !== 'article_public_reader_metadata.v1') {
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
            $operation = function () use ($p, $sha, $execute, $logger): array {
                $query = Article::withoutGlobalScopes()->publiclyReadable()->whereKey((int) data_get($p, 'identity.id'))->whereNull('deleted_at');
                $article = ($execute ? $query->lockForUpdate() : $query)->first();
                if (! $article instanceof Article || (int) $article->org_id !== 0 || (int) $article->id === 15
                    || $article->status !== 'published' || ! $article->is_public || ! $article->published_revision_id) {
                    throw new RuntimeException('ordinary_existing_public_article_required');
                }
                foreach (['id', 'org_id', 'slug', 'locale', 'published_revision_id', 'working_revision_id'] as $key) {
                    if ($article->getAttribute($key) !== data_get($p, 'identity.'.$key)) {
                        throw new RuntimeException('identity_or_pointer_drift');
                    }
                }
                $before = self::state($article, $execute);
                if (! hash_equals(Hash::hash($before), (string) ($p['before_sha256'] ?? ''))) {
                    throw new RuntimeException('before_state_drift');
                }
                $schema = data_get($p, 'candidate.schema_json');
                if ($schema !== null && ! is_array($schema)) {
                    throw new RuntimeException('candidate_schema_invalid');
                }
                $oldSchema = $article->seoMeta->schema_json;
                $oldTop = is_array($oldSchema) ? $oldSchema : [];
                $newTop = is_array($schema) ? $schema : [];
                $oldMetadata = array_replace_recursive((array) data_get($article->cover_image_variants, 'editorial_package_v1', []),
                    (array) ($oldTop['editorial_package_v1'] ?? []));
                $newMetadata = array_replace_recursive((array) data_get($article->cover_image_variants, 'editorial_package_v1', []),
                    (array) ($newTop['editorial_package_v1'] ?? []));
                unset($oldTop['editorial_package_v1'], $newTop['editorial_package_v1']);
                $protectedOld = $oldMetadata;
                $protectedNew = $newMetadata;
                foreach (['answer_surface_policy', 'answer_surface_visibility', 'answer_surface_v1'] as $field) {
                    unset($protectedOld[$field], $protectedNew[$field]);
                }
                if (Hash::hash($oldTop) !== Hash::hash($newTop) || Hash::hash($protectedOld) !== Hash::hash($protectedNew)) {
                    throw new RuntimeException('protected_metadata_changed');
                }
                $oldAnswer = (array) ($oldMetadata['answer_surface_v1'] ?? []);
                $newAnswer = (array) ($newMetadata['answer_surface_v1'] ?? []);
                $items = data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items', $newAnswer['faq_items'] ?? []);
                unset($oldAnswer['faq_items'], $newAnswer['faq_items']);
                if (Hash::hash($oldAnswer) !== Hash::hash($newAnswer) || ! is_array($items) || ! array_is_list($items) || count($items) > 6) {
                    throw new RuntimeException('ordinary_faq_scope_invalid');
                }
                if (($oldMetadata['answer_surface_visibility'] ?? null) === 'disabled'
                    && ($newMetadata['answer_surface_visibility'] ?? null) !== 'disabled') {
                    throw new RuntimeException('disabled_surface_hold_preserved');
                }
                foreach ($items as $item) {
                    if (! is_array($item) || array_diff(array_keys($item), ['key', 'id', 'question', 'answer', 'q', 'a']) !== []
                        || trim((string) ($item['question'] ?? $item['q'] ?? '')) === ''
                        || trim((string) ($item['answer'] ?? $item['a'] ?? '')) === '') {
                        throw new RuntimeException('visible_faq_item_invalid');
                    }
                }
                $fields = (array) data_get($p, 'candidate.article_fields', []);
                if (array_diff(array_keys($fields), ['cover_image_alt', 'related_test_slug']) !== []) {
                    throw new RuntimeException('article_metadata_scope_invalid');
                }
                foreach ($fields as $key => $value) {
                    if (! is_string($value) || trim($value) === '' || ($key === 'related_test_slug' && preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $value) !== 1)) {
                        throw new RuntimeException('article_metadata_value_invalid');
                    }
                    if ($key === 'related_test_slug' && ! ScaleRegistry::withoutGlobalScopes()->where('org_id', 0)
                        ->where('is_active', true)->where('is_public', true)->where('primary_slug', $value)->exists()) {
                        throw new RuntimeException('canonical_public_test_required');
                    }
                }
                if (! $execute) {
                    return ['article_id' => $article->id, 'readonly' => true, 'faq_count' => count($items)];
                }
                $seo = $article->seoMeta;
                $seo->schema_json = $schema;
                $seo->save();
                if ($fields !== []) {
                    // Match the existing metadata updater: text provenance is immutable here.
                    $article->forceFill($fields)->saveQuietly();
                }
                $article->refresh();
                $after = self::state($article, true);
                $expected = $before;
                foreach ($fields as $key => $value) {
                    $expected['article'][$key] = $value;
                }
                // Compare decoded JSON separately: database serialization is driver-specific.
                foreach (['updated_at'] as $key) {
                    unset($expected['article'][$key], $after['article'][$key], $expected['seo'][$key], $after['seo'][$key]);
                }
                unset($expected['seo']['schema_json'], $after['seo']['schema_json']);
                if (Hash::hash($expected) !== Hash::hash($after) || Hash::hash($article->seoMeta->schema_json) !== Hash::hash($schema)) {
                    throw new RuntimeException('metadata_readback_failed');
                }
                $logger->log(Request::create('/console/articles/update-public-reader-metadata', 'POST'),
                    'article_public_reader_metadata_updated', 'article', (string) $article->id,
                    ['package_sha256' => $sha, 'before_sha256' => Hash::hash($before),
                        'before_schema_json' => $oldSchema, 'before_article_fields' => array_intersect_key($before['article'], $fields),
                        'actor' => 'delegated_cli', 'authorized_operator_id' => (int) $this->option('admin-user-id'),
                        'editorial_attestation_created' => false], 'Operator-authorized native reader metadata update');
                event(new PublicAuthorityChanged('article', (string) $article->id, (string) $article->locale,
                    Hash::hash(self::state($article)), 'authority_revision'));

                return ['article_id' => $article->id, 'readonly' => false, 'readback_passed' => true, 'faq_count' => count($items)];
            };
            $this->line(json_encode(['ok' => true, ...($execute ? DB::transaction($operation) : $operation())], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException && preg_match('/\A[a-z_]+\z/', $exception->getMessage()) ? $exception->getMessage() : 'native_metadata_update_failed';
            $this->line(json_encode(['ok' => false, 'readonly' => ! $execute, 'error' => $error], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        } finally {
            if ($execute) {
                $guard->forgetUser();
                if ($oldActor !== null) {
                    $guard->setUser($oldActor);
                }
            }
        }
    }
}
