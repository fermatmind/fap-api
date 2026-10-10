<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Native prestate for the exact six existing EQ public locale pages. */
final class EqExistingPublicPageState
{
    public function __construct(private readonly EqExistingPublicPagePackage $package) {}

    public function read(string $packageSha256, bool $lock = false): array
    {
        $rows = $this->package->read(base_path(), $packageSha256)['candidates'];
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('eq_existing_state_lock_requires_transaction');
        }
        $state = ['registries' => [], 'articles' => [], 'guides' => []];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $records = $this->query($table, ['org_id' => 0, 'code' => 'EQ_60'], $lock, $table !== 'scales_registry');
            if (count($records) !== 1 || ($records[0]['primary_slug'] ?? null) !== 'eq-test-emotional-intelligence-assessment'
                || ! (bool) ($records[0]['is_public'] ?? false) || ! (bool) ($records[0]['is_active'] ?? false)) {
                throw new DomainException('eq_existing_state_registry_identity_or_hold');
            }
            $content = $records[0]['content_i18n_json'] ?? null;
            $content = is_string($content) ? json_decode($content, true, 64, JSON_THROW_ON_ERROR) : $content;
            if (! is_array($content) || ! is_array($content['en'] ?? null) || ! is_array($content['zh'] ?? null)) {
                throw new DomainException('eq_existing_state_registry_locale_invalid');
            }
            $state['registries'][] = ['table' => $table, 'values' => $records[0], 'content' => $content];
        }
        $state['scale_slugs'] = $this->query('scale_slugs', ['org_id' => 0, 'scale_code' => 'EQ_60'], $lock);
        foreach ($rows as $row) {
            $identity = $row['identity'];
            $key = $row['page_id'].':'.$identity['locale'];
            if ($row['page_id'] === 'EQ-02') {
                $article = $this->query('articles', $identity, $lock);
                if (count($article) !== 1) {
                    throw new DomainException('eq_existing_state_article_ambiguous');
                }
                $values = $article[0];
                $this->assertPublic($values);
                if (($values['deleted_at'] ?? null) !== null) {
                    throw new DomainException('eq_existing_state_article_deleted');
                }
                $published = $this->query('article_translation_revisions', ['id' => $values['published_revision_id'] ?? 0], $lock);
                $working = $this->query('article_translation_revisions', ['id' => $values['working_revision_id'] ?? 0], $lock);
                foreach ([$published, $working] as $revisions) {
                    if (count($revisions) !== 1 || (int) $revisions[0]['article_id'] !== (int) $values['id']
                        || (int) $revisions[0]['org_id'] !== 0 || $revisions[0]['locale'] !== $identity['locale']
                        || $revisions[0]['translation_group_id'] !== $values['translation_group_id']) {
                        throw new DomainException('eq_existing_state_article_revision_identity');
                    }
                }
                $model = new Article;
                $model->setRawAttributes($values, true);
                $revision = new ArticleTranslationRevision;
                $revision->setRawAttributes($published[0], true);
                if (! $revision->isPubliclyReadableForArticle($model)
                    || (($published[0]['published_at'] ?? null) !== null && strtotime($published[0]['published_at']) > time())) {
                    throw new DomainException('eq_existing_state_article_revision_not_public');
                }
                $state['articles'][$key] = ['identity' => $identity, 'values' => $values, 'published' => $published[0], 'working' => $working[0],
                    'seo' => $this->query('article_seo_meta', ['article_id' => $values['id']], $lock)];
            } elseif ($row['page_id'] === 'SH-02') {
                $guides = $this->query('career_guides', $identity, $lock);
                if (count($guides) !== 1) {
                    throw new DomainException('eq_existing_state_guide_ambiguous');
                }
                $this->assertPublic($guides[0]);
                $id = $guides[0]['id'];
                $maps = [];
                foreach (['career_guide_job_map', 'career_guide_article_map', 'career_guide_personality_map'] as $table) {
                    $maps[$table] = $this->query($table, ['career_guide_id' => $id], $lock, false);
                }
                $state['guides'][$key] = ['identity' => $identity, 'values' => $guides[0],
                    'seo' => $this->query('career_guide_seo_meta', ['career_guide_id' => $id], $lock),
                    'revisions' => $this->query('career_guide_revisions', ['career_guide_id' => $id], $lock), 'maps' => $maps];
            }
        }

        return $state;
    }

    public function hash(array $state): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($state));
    }

    private function assertPublic(array $values): void
    {
        if (($values['status'] ?? null) !== CareerGuide::STATUS_PUBLISHED || ! (bool) ($values['is_public'] ?? false)
            || ! in_array($values['lifecycle_state'] ?? null, [null, 'active'], true)
            || (($values['scheduled_at'] ?? null) !== null && strtotime($values['scheduled_at']) > time())
            || (($values['published_at'] ?? null) !== null && strtotime($values['published_at']) > time())) {
            throw new DomainException('eq_existing_state_target_not_public');
        }
    }

    private function query(string $table, array $where, bool $lock, bool $hasId = true): array
    {
        $query = DB::table($table)->where($where);
        if ($hasId) {
            $query->orderBy('id');
        }
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn (object $row): array => (array) $row)->all();
        if (! $hasId) {
            usort($rows, static fn (array $a, array $b): int => strcmp(PromotionContextFactory::canonicalJson($a), PromotionContextFactory::canonicalJson($b)));
        }

        return $rows;
    }
}
