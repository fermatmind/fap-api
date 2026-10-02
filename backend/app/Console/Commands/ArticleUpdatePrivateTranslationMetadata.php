<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticleUpdatePrivateTranslationMetadata extends Command
{
    private const ARTICLE_FIELDS = ['category_id', 'cover_image_url', 'cover_image_alt', 'cover_image_width', 'cover_image_height', 'cover_image_variants'];

    private const SEO_FIELDS = ['canonical_url', 'robots', 'og_image_url'];

    protected $signature = 'articles:update-private-translation-metadata
        {--file= : Exact private translation metadata package}
        {--sha256= : Exact package digest}
        {--dry-run : Read only}
        {--execute : Apply metadata only}
        {--restore-audit-id= : Restore an unchanged update by its exact audit}
        {--confirm= : Exact confirmation}
        {--json : Metadata-only summary}';

    protected $description = 'Fill reviewed media and English taxonomy on private translation drafts without changing content, revisions or publication.';

    public function handle(AuditLogger $logger): int
    {
        $after = null;
        try {
            $execute = (bool) $this->option('execute');
            if ($execute === (bool) $this->option('dry-run')) {
                throw new RuntimeException('exactly_one_mode_required');
            }
            $p = $this->package();
            $original = $p;
            $restore = (int) $this->option('restore-audit-id');
            if ($this->option('restore-audit-id') !== null && $restore < 1) {
                throw new RuntimeException('restore_audit_invalid');
            }
            $recovery = null;
            if ($restore) {
                $audit = AuditLog::withoutGlobalScopes()->where('org_id', 0)->findOrFail($restore);
                $recovery = $audit->meta_json;
                if ($audit->action !== 'article_private_translation_metadata_updated' || $audit->result !== 'success'
                    || $audit->target_type !== 'article_translation' || (string) $audit->target_id !== (string) $p['target_id']
                    || ($recovery['package_sha256'] ?? null) !== $this->option('sha256')) {
                    throw new RuntimeException('restore_audit_mismatch');
                }
                $p['target_snapshot_hash'] = $recovery['target_snapshot_hash_after'];
                $p['target_tags_sha256'] = $recovery['target_tags_sha256_after'];
            }
            $this->snapshot($p, false);
            if ($execute) {
                if ($this->option('confirm') !== ($restore ? 'Restore' : 'Update').' private Article '.$p['target_id'].' metadata with package '.$this->option('sha256').($restore ? ' and audit '.$restore : '').'.') {
                    throw new RuntimeException('confirmation_mismatch');
                }
                $after = DB::transaction(function () use ($p, $original, $restore, $recovery, $logger): array {
                    [$s, $t, $seo, $dependencies] = $this->snapshot($p, true);
                    $before = ['article' => $t->only([...self::ARTICLE_FIELDS, 'updated_at']), 'seo' => $seo->only([...self::SEO_FIELDS, 'updated_at']),
                        'tag_map' => DB::table('article_tag_map')->where('article_id', $t->id)->orderBy('tag_id')->get()->map(fn ($r): array => (array) $r)->all()];
                    $row = $t->getAttributes();
                    $seoRow = $seo->getAttributes();
                    $history = ArticleSourceTargetSnapshot::sourceRevisions($t);
                    if ($restore) {
                        $t->timestamps = false;
                        $seo->timestamps = false;
                        $t->forceFill($recovery['before_metadata']['article'])->saveQuietly();
                        $seo->forceFill($recovery['before_metadata']['seo'])->saveQuietly();
                        DB::table('article_tag_map')->where('article_id', $t->id)->delete();
                        if ($recovery['before_metadata']['tag_map'] !== []) {
                            DB::table('article_tag_map')->insert($recovery['before_metadata']['tag_map']);
                        }
                    } else {
                        $variants = $t->cover_image_variants ?? [];
                        foreach ($dependencies['variants'] as $v) {
                            $variants[$v['variant_key']] = ['url' => $v['url'], 'width' => (int) $v['width'], 'height' => (int) $v['height']];
                        }
                        $t->forceFill(['category_id' => $p['category_id'], 'cover_image_url' => $dependencies['hero']['url'],
                            'cover_image_alt' => $p['cover_image_alt'], 'cover_image_width' => $dependencies['hero']['width'],
                            'cover_image_height' => $dependencies['hero']['height'], 'cover_image_variants' => $variants])->saveQuietly();
                        $seo->forceFill(['canonical_url' => 'https://fermatmind.com/en/articles/'.$t->slug,
                            'robots' => 'noindex,nofollow', 'og_image_url' => $dependencies['og']['url']])->saveQuietly();
                        $t->tags()->sync(array_fill_keys($p['tag_ids'], ['org_id' => 0, 'created_at' => now()]));
                    }
                    $t->refresh();
                    $seo->refresh();
                    foreach ([[$row, $t->getAttributes(), self::ARTICLE_FIELDS], [$seoRow, $seo->getAttributes(), self::SEO_FIELDS]] as [$old, $new, $fields]) {
                        foreach ([...$fields, 'updated_at'] as $key) {
                            unset($old[$key], $new[$key]);
                        }
                        if ($old !== $new) {
                            throw new RuntimeException('protected_row_changed');
                        }
                    }
                    if ($history !== ArticleSourceTargetSnapshot::sourceRevisions($t)
                        || ! hash_equals($p['source_snapshot_hash'], ArticleForkPrivateTranslationLinks::sourceHash($s->fresh()))) {
                        throw new RuntimeException('source_or_revision_changed');
                    }
                    if (! $restore && ((int) $t->category_id !== $p['category_id'] || $t->cover_image_alt !== $p['cover_image_alt']
                        || $t->cover_image_url !== $dependencies['hero']['url']
                        || (int) $t->cover_image_width !== (int) $dependencies['hero']['width']
                        || (int) $t->cover_image_height !== (int) $dependencies['hero']['height']
                        || self::digest($t->cover_image_variants) !== self::digest($variants)
                        || $seo->canonical_url !== 'https://fermatmind.com/en/articles/'.$t->slug || $seo->robots !== 'noindex,nofollow'
                        || $seo->og_image_url !== $dependencies['og']['url']
                        || $t->tags()->orderBy('article_tags.id')->pluck('article_tags.id')->map(fn ($id): int => (int) $id)->all() !== $p['tag_ids'])) {
                        throw new RuntimeException('metadata_readback_failed');
                    }
                    if ($restore && (! hash_equals($original['target_snapshot_hash'], ArticleForkPrivateTranslationLinks::targetHash($s))
                        || ! hash_equals($original['target_tags_sha256'], ArticleSourceTargetSnapshot::tagsHash($t)))) {
                        throw new RuntimeException('restore_readback_failed');
                    }
                    $action = $restore ? 'article_private_translation_metadata_restored' : 'article_private_translation_metadata_updated';
                    $last = (int) AuditLog::withoutGlobalScopes()->max('id');
                    $logger->log(Request::create('/ops/article-translation/private-metadata', 'POST'), $action,
                        'article_translation', (string) $t->id, ['package_sha256' => $this->option('sha256'),
                            'source_id' => $s->id, 'working_revision_id' => $t->working_revision_id,
                            'source_snapshot_hash' => $p['source_snapshot_hash'], 'before_metadata' => $before,
                            'target_snapshot_hash_after' => ArticleForkPrivateTranslationLinks::targetHash($s),
                            'target_tags_sha256_after' => ArticleSourceTargetSnapshot::tagsHash($t),
                            'restore_audit_id' => $restore ?: null, 'human_review_completed' => false, 'public_state_changed' => false], reason: $action);
                    $audit = AuditLog::withoutGlobalScopes()->where('id', '>', $last)->where('action', $action)
                        ->where('target_id', (string) $t->id)->latest('id')->first();
                    if (! $audit instanceof AuditLog || $audit->result !== 'success'
                        || self::digest($audit->meta_json['before_metadata'] ?? []) !== self::digest($before)) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['target_id' => $t->id, 'working_revision_id' => $t->working_revision_id, 'audit_id' => $audit->id];
                });
            }
            $result = ['ok' => true, 'mode' => $execute ? 'execute' : 'dry_run', 'after' => $after];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'errors' => [$e instanceof RuntimeException ? $e->getMessage() : 'snapshot_or_transaction_failed'], 'after' => null];
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function package(): array
    {
        $file = (string) $this->option('file');
        if (! is_file($file) || filesize($file) > 32768) {
            throw new RuntimeException('package_unavailable');
        }
        $bytes = file_get_contents($file);
        if (! hash_equals(hash('sha256', $bytes), (string) $this->option('sha256'))) {
            throw new RuntimeException('package_digest_mismatch');
        }
        $p = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        $keys = array_keys($p);
        sort($keys);
        $expected = ['schema', 'source_id', 'target_id', 'working_revision_id', 'source_snapshot_hash', 'target_snapshot_hash',
            'target_tags_sha256', 'dependencies_sha256', 'category_id', 'tag_ids', 'media_asset_id', 'cover_image_alt'];
        sort($expected);
        if ($keys !== $expected || $p['schema'] !== 'fermat_private_translation_metadata_v1') {
            throw new RuntimeException('package_schema_invalid');
        }
        foreach (['source_id', 'target_id', 'working_revision_id', 'category_id', 'media_asset_id'] as $key) {
            if (! is_int($p[$key]) || $p[$key] < 1) {
                throw new RuntimeException('identity_invalid');
            }
        }
        foreach (['source_snapshot_hash', 'target_snapshot_hash', 'target_tags_sha256', 'dependencies_sha256'] as $key) {
            if (! is_string($p[$key]) || ! preg_match('/^[a-f0-9]{64}$/', $p[$key])) {
                throw new RuntimeException('hash_invalid');
            }
        }
        if (! is_array($p['tag_ids']) || ! array_is_list($p['tag_ids']) || count($p['tag_ids']) < 1 || count($p['tag_ids']) > 8
            || array_filter($p['tag_ids'], fn ($id): bool => ! is_int($id) || $id < 1) !== []) {
            throw new RuntimeException('tags_invalid');
        }
        $tags = $p['tag_ids'];
        sort($tags);
        if ($tags !== $p['tag_ids'] || count(array_unique($tags)) !== count($tags)
            || ! is_string($p['cover_image_alt']) || trim($p['cover_image_alt']) !== $p['cover_image_alt']
            || mb_strlen($p['cover_image_alt']) < 10 || mb_strlen($p['cover_image_alt']) > 300
            || preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $p['cover_image_alt'])) {
            throw new RuntimeException('english_metadata_invalid');
        }

        return $p;
    }

    private function snapshot(array $p, bool $lock): array
    {
        $query = Article::withoutGlobalScopes()->where('org_id', 0)->whereIn('id', [$p['source_id'], $p['target_id']])->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $s = $rows->get($p['source_id']);
        $t = $rows->get($p['target_id']);
        if (! $s instanceof Article || ! $t instanceof Article || $s->locale !== 'zh-CN'
            || $s->translation_status !== Article::TRANSLATION_STATUS_SOURCE
            || $t->locale !== 'en' || $t->status !== 'draft' || $t->is_public || $t->is_indexable
            || $t->sitemap_eligible || $t->llms_eligible || $t->published_revision_id !== null || $t->published_at !== null
            || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $t->slug)
            || ! in_array($t->translation_status, [Article::TRANSLATION_STATUS_MACHINE_DRAFT, Article::TRANSLATION_STATUS_HUMAN_REVIEW], true)
            || (int) $t->working_revision_id !== $p['working_revision_id']) {
            throw new RuntimeException('private_translation_required');
        }
        if (! hash_equals($p['source_snapshot_hash'], ArticleForkPrivateTranslationLinks::sourceHash($s, $lock))
            || ! hash_equals($p['target_snapshot_hash'], ArticleForkPrivateTranslationLinks::targetHash($s, $lock))
            || ! hash_equals($p['target_tags_sha256'], ArticleSourceTargetSnapshot::tagsHash($t, $lock))) {
            throw new RuntimeException('snapshot_drift');
        }
        $w = $t->workingRevision;
        if ($w === null || (int) $w->org_id !== 0 || (int) $w->source_article_id !== (int) $s->id
            || ! in_array($w->revision_status, ['machine_draft', 'human_review'], true) || $w->reviewed_by !== null || $w->reviewed_at !== null
            || $w->approved_at !== null || $w->published_at !== null
            || ! $this->hasPrivateDraftProvenance($t, $lock)) {
            throw new RuntimeException('unreviewed_ai_draft_required');
        }
        $q = ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $t->id);
        $seoRows = ($lock ? $q->lockForUpdate() : $q)->get();
        $seo = $seoRows->first();
        if ($seoRows->count() !== 1 || (int) $seo->org_id !== 0 || $seo->locale !== 'en' || $seo->is_indexable) {
            throw new RuntimeException('private_seo_required');
        }
        $deps = self::dependencies($p, $lock);
        if (! hash_equals($p['dependencies_sha256'], self::digest($deps))) {
            throw new RuntimeException('metadata_dependency_drift');
        }

        return [$s, $t, $seo, $deps];
    }

    private function hasPrivateDraftProvenance(Article $target, bool $lock): bool
    {
        $revision = $target->workingRevision;
        if (($revision->authority_metadata_json['draft_origin'] ?? null) === 'operator_supplied_ai_draft') {
            return true;
        }
        // The native content-package updater stages an unreviewed revision and
        // records its exact body in the import table rather than AI metadata.
        if ($revision->revision_status !== 'human_review' || $revision->authority_metadata_json !== null) {
            return false;
        }
        $query = ArticleEditorialPackageImport::withoutGlobalScopes()
            ->where('org_id', $target->org_id)->where('article_id', $target->id)
            ->where('locale', $target->locale)->where('slug', $target->slug)
            ->where('status', ArticleEditorialPackageImport::STATUS_IMPORTED)
            // Match the native updater's bodyHash normalization; snapshot locks
            // above still bind the approved revision's exact original bytes.
            ->where('body_hash', hash('sha256', preg_replace("/\r\n?/", "\n", trim((string) $revision->content_md)) ?: trim((string) $revision->content_md)));

        return ($lock ? $query->lockForUpdate() : $query)->first() !== null;
    }

    public static function dependencies(array $p, bool $lock = false): array
    {
        $q = ArticleCategory::withoutGlobalScopes()->where('org_id', 0)->whereKey($p['category_id']);
        $category = ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
        $q = ArticleTag::withoutGlobalScopes()->where('org_id', 0)->whereIn('id', $p['tag_ids'])->orderBy('id');
        $tags = ($lock ? $q->lockForUpdate() : $q)->get();
        if ($tags->count() !== count($p['tag_ids'])) {
            throw new RuntimeException('tags_missing');
        }
        foreach ([$category, ...$tags->all()] as $r) {
            if (! $r->is_active || trim((string) $r->name) === '' || preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $r->name)) {
                throw new RuntimeException('active_english_taxonomy_required');
            }
        }
        $q = MediaAsset::withoutGlobalScopes()->where('org_id', 0)->whereKey($p['media_asset_id']);
        $asset = ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
        if (! $asset->is_public) {
            throw new RuntimeException('public_media_required');
        }
        $q = MediaVariant::where('media_asset_id', $asset->id)->whereIn('variant_key', ['hero', 'card', 'thumbnail', 'og', 'preload'])->orderBy('variant_key');
        $variants = ($lock ? $q->lockForUpdate() : $q)->get();
        if ($variants->count() !== 5 || $variants->pluck('variant_key')->unique()->count() !== 5) {
            throw new RuntimeException('media_variants_missing');
        }
        foreach ($variants as $v) {
            if ($v->sync_status !== 'synced' || $v->cdn_status !== 'verified' || $v->verified_at === null
                || $v->width < 1 || $v->height < 1 || PublicMediaUrlGuard::sanitizeNullableUrl($v->url) !== $v->url) {
                throw new RuntimeException('verified_media_required');
            }
        }

        return ['category' => $category->getAttributes(), 'tags' => $tags->map(fn ($r): array => $r->getAttributes())->all(),
            'asset' => $asset->getAttributes(), 'variants' => $variants->map(fn ($r): array => $r->getAttributes())->all(),
            'hero' => $variants->firstWhere('variant_key', 'hero')->getAttributes(),
            'og' => $variants->firstWhere('variant_key', 'og')->getAttributes()];
    }

    public static function digest(array $value): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }
            foreach ($a as &$v) {
                if (is_array($v)) {
                    $v = $sort($v);
                }
            }

            return $a;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
