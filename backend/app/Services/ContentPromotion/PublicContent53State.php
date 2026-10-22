<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Http\Controllers\API\V0_5\Cms\CareerGuideController;
use App\Http\Controllers\API\V0_5\Cms\ContentPageController;
use App\Http\Controllers\API\V0_5\Cms\LandingSurfaceController;
use App\Http\Controllers\API\V0_5\Cms\TopicController;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Services\Cms\ArticleBodyHeadingGuard;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Private rollback state and the real public consumer projection. */
final class PublicContent53State
{
    public function __construct(private readonly PublicContent53Package $package) {}

    public function read(string $sha, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('public_content_53_lock_requires_transaction');
        }
        $state = [];
        foreach ($this->package->read(base_path(), $sha)['candidates'] as $row) {
            $table = ['article' => 'articles', 'page' => 'content_pages', 'surface' => 'landing_surfaces', 'profile' => 'topic_profiles', 'guide' => 'career_guides'][$row['kind']];
            $where = $row['identity'];
            if ($row['kind'] === 'surface') {
                $where['surface_key'] = $where['slug'];
                unset($where['slug']);
            }
            $records = $this->query($table, $where, $lock);
            if (count($records) !== 1 || $records[0]['status'] !== 'published' || ! (bool) $records[0]['is_public']
                || ($records[0]['deleted_at'] ?? null) !== null) {
                throw new DomainException('public_content_53_identity_or_hold');
            }
            $values = $records[0];
            foreach (['published_at', 'scheduled_at'] as $field) {
                if (($values[$field] ?? null) !== null && strtotime($values[$field]) > time()) {
                    throw new DomainException('public_content_53_future_publication');
                }
            }
            $saved = ['table' => $table, 'values' => $values, 'relations' => []];
            if ($row['kind'] === 'guide') {
                if (! in_array($values['lifecycle_state'] ?? null, [null, 'active'], true)) {
                    throw new DomainException('public_content_53_guide_lifecycle_hold');
                }
                $saved['revisions'] = $this->query('career_guide_revisions', ['career_guide_id' => $values['id']], $lock);
                $saved['controls'] = $this->guideControls($values, $lock);
                $other = $this->query('career_guides', array_replace($where, ['locale' => $where['locale'] === 'en' ? 'zh-CN' : 'en']), $lock);
                if (count($other) !== 1) {
                    throw new DomainException('public_content_53_guide_counterpart_missing');
                }
                $saved['counterpart'] = ['values' => $other[0], 'controls' => $this->guideControls($other[0], $lock),
                    'revisions' => $this->query('career_guide_revisions', ['career_guide_id' => $other[0]['id']], $lock)];
            } elseif ($row['kind'] === 'article') {
                $published = $this->query('article_translation_revisions', ['id' => $values['published_revision_id'] ?? 0], $lock);
                $working = $this->query('article_translation_revisions', ['id' => $values['working_revision_id'] ?? 0], $lock);
                if (count($published) !== 1 || count($working) !== 1) {
                    throw new DomainException('public_content_53_article_revision_missing');
                }
                $article = new Article;
                $article->setRawAttributes($values, true);
                $revision = new ArticleTranslationRevision;
                $revision->setRawAttributes($published[0], true);
                if (! $revision->isPubliclyReadableForArticle($article)) {
                    throw new DomainException('public_content_53_article_revision_hold');
                }
                $saved['published'] = $published[0];
                $saved['working'] = $working[0];
                // Source rows and divergent drafts enter the CAS boundary too.
                $sourceId = $values['source_article_id'] ?? $values['translated_from_article_id'] ?? $values['id'];
                $saved['source'] = $this->query('articles', ['id' => $sourceId], $lock);
                $saved['relations']['article_seo_meta'] = $this->query('article_seo_meta', ['article_id' => $values['id']], $lock);
            } elseif ($row['kind'] === 'page') {
                $page = new ContentPage;
                $page->setRawAttributes($values, true);
                if (! $page->passesPublicReadinessGate()) {
                    throw new DomainException('public_content_53_page_hold');
                }
                $saved['published'] = $this->query('cms_translation_revisions', ['id' => $values['published_revision_id'] ?? 0], $lock);
                $saved['working'] = $this->query('cms_translation_revisions', ['id' => $values['working_revision_id'] ?? 0], $lock);
                $saved['source'] = $values['source_content_id'] ? $this->query('content_pages', ['id' => $values['source_content_id']], $lock) : [];
            } elseif ($row['kind'] === 'profile') {
                foreach (['topic_profile_sections', 'topic_profile_entries', 'topic_profile_seo_meta'] as $related) {
                    $saved['relations'][$related] = $this->query($related, ['profile_id' => $values['id']], $lock);
                }
            } else {
                $saved['relations']['page_blocks'] = $this->query('page_blocks', ['landing_surface_id' => $values['id']], $lock);
            }
            if (isset($row['source_snapshot'])) {
                $sourceWhere = array_replace($row['identity'], ['locale' => 'zh-CN']);
                if ($row['kind'] === 'surface') {
                    $sourceWhere['surface_key'] = $sourceWhere['slug'];
                    unset($sourceWhere['slug']);
                }
                $source = $this->query($table, $sourceWhere, $lock);
                if (count($source) !== 1 || $source[0]['status'] !== 'published' || ! (bool) $source[0]['is_public']
                    || ($source[0]['deleted_at'] ?? null) !== null
                    || array_filter(['published_at', 'scheduled_at'], static fn ($field): bool => ($source[0][$field] ?? null) !== null && strtotime($source[0][$field]) > time())) {
                    throw new DomainException('public_content_53_translation_source_missing');
                }
                $sourcePage = $row['kind'] === 'surface' ? new LandingSurface : new ContentPage;
                $sourcePage->setRawAttributes($source[0], true);
                if ($sourcePage instanceof ContentPage && ! $sourcePage->passesPublicReadinessGate()) {
                    throw new DomainException('public_content_53_translation_source_hold');
                }
                $saved['translation_source'] = $source[0];
                $sourceProjection = array_intersect_key($sourcePage->toArray(), PublicContent53Package::source($row));
                if ($this->hash($sourceProjection) !== $this->hash(PublicContent53Package::source($row))) {
                    throw new DomainException('public_content_53_translation_source_drift');
                }
            }
            $state[$row['page_id']] = $saved;
        }

        return $state;
    }

    public function projection(array $row): array
    {
        $paths = ['article' => 'articles', 'page' => 'content-pages', 'surface' => 'landing-surfaces', 'profile' => 'topics', 'guide' => 'career-guides'];
        $controllers = ['article' => ArticleController::class, 'page' => ContentPageController::class,
            'surface' => LandingSurfaceController::class, 'profile' => TopicController::class, 'guide' => CareerGuideController::class];
        $request = Request::create('/api/v0.5/'.$paths[$row['kind']].'/'.$row['identity']['slug'], 'GET', ['org_id' => 0, 'locale' => $row['identity']['locale']]);
        $response = app($controllers[$row['kind']])->show($request, $row['identity']['slug']);
        $data = $response->getData(true);
        $value = $data[$row['kind']] ?? null;
        if ($response->getStatusCode() !== 200 || ($data['ok'] ?? null) !== true || ! is_array($value)
            || ($value['locale'] ?? null) !== $row['identity']['locale']) {
            throw new DomainException('public_content_53_public_consumer_unavailable');
        }
        $projection = array_intersect_key($value, $row['snapshot']);
        if ($row['kind'] === 'surface' && isset($row['snapshot']['block_copy'])) {
            $projection['block_copy'] = $this->blockCopy($value['page_blocks'], array_keys($row['snapshot']['block_copy']));
        }
        if (in_array($row['kind'], ['article', 'guide'], true) && isset($row['snapshot']['seo_text'])) {
            $projection['seo_text'] = array_intersect_key(($row['kind'] === 'guide' ? $data['seo_meta'] : $value['seo_meta']) ?? [], $row['snapshot']['seo_text']);
        }
        if ($row['kind'] === 'profile') {
            $projection['sections'] = $data['sections'];
            $projection['entry_groups'] = $data['entry_groups'];
            $projection['seo_text'] = ['title' => $data['seo_meta']['seo_title'], 'description' => $data['seo_meta']['seo_description']];
        }

        return $projection;
    }

    public function assertBaseline(array $rows): void
    {
        foreach ($rows as $row) {
            if (isset($row['source_snapshot'])) {
                $source = $row;
                $source['identity']['locale'] = 'zh-CN';
                if ($this->hash($this->projection($source)) !== $this->hash(PublicContent53Package::source($row))) {
                    throw new DomainException('public_content_53_translation_public_source_drift');
                }
            }
            $projection = $this->projection($row);
            // A staging environment may already contain the accepted candidate.
            if ($this->hash($projection) !== $this->hash(PublicContent53Package::before($row)) && $this->hash($projection) !== $this->hash($row['snapshot'])) {
                throw new DomainException('public_content_53_reviewed_prestate_drift');
            }
        }
    }

    public function assertLockedBaseline(array $rows, array $state): void
    {
        foreach ($rows as $row) {
            $saved = $state[$row['page_id']];
            $beforeCopy = PublicContent53Package::before($row);
            $candidateCopy = $row['snapshot'];
            $nativeBefore = PublicContent53Package::nativeBlocks($row, false);
            if ($nativeBefore !== null) {
                $native = $this->blockCopy($saved['relations']['page_blocks'], array_keys($nativeBefore));
                if ($this->hash($native) !== $this->hash($nativeBefore)
                    && $this->hash($native) !== $this->hash(PublicContent53Package::nativeBlocks($row, true))) {
                    throw new DomainException('public_content_53_locked_copy_drift');
                }
                // The API derives empty family items; its projection is checked
                // separately. Compare raw compatibility copy against its own baseline.
                unset($beforeCopy['block_copy'], $candidateCopy['block_copy']);
            }
            if ($row['kind'] === 'profile') {
                $projection = $this->projection($row); // Topic readers have no cache.
            } else {
                $values = $row['kind'] === 'article' ? $saved['published'] : $saved['values'];
                $projection = [];
                foreach ($row['snapshot'] as $field => $_) {
                    if ($nativeBefore !== null && $field === 'block_copy') {
                        continue;
                    }
                    if ($row['kind'] === 'surface' && $field === 'block_copy') {
                        $projection[$field] = $this->blockCopy($saved['relations']['page_blocks'], array_keys($row['snapshot']['block_copy']));

                        continue;
                    }
                    if ($row['kind'] === 'guide' && $field === 'seo_text') {
                        $seoRows = $saved['controls']['seo'];
                        if (count($seoRows) !== 1) {
                            throw new DomainException('public_content_53_copy_seo_missing');
                        }
                        $projection[$field] = array_intersect_key($seoRows[0], $row['snapshot']['seo_text']);

                        continue;
                    }
                    if ($row['kind'] === 'article' && $field === 'seo_text') {
                        $seoRows = $saved['relations']['article_seo_meta'];
                        if (count($seoRows) !== 1) {
                            throw new DomainException('public_content_53_copy_seo_missing');
                        }
                        $projection[$field] = array_intersect_key(array_replace($seoRows[0], [
                            'seo_title' => $values['seo_title'], 'seo_description' => $values['seo_description'],
                        ]), $row['snapshot']['seo_text']);

                        continue;
                    }
                    $value = $values[$field] ?? null;
                    if ($row['kind'] === 'article' && $field === 'content_md') {
                        // Match the public reader's legacy heading/newline projection.
                        // The full raw revision remains in the locked CAS/rollback state.
                        $value = app(ArticleBodyHeadingGuard::class)->downgradeMarkdownH1ToH2((string) $value);
                    }
                    if (in_array($field, ['faq_items', 'payload_json'], true)) {
                        $value = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : ($value ?? []);
                    }
                    $projection[$field] = $value;
                }
            }
            if ($this->hash($projection) !== $this->hash($beforeCopy) && $this->hash($projection) !== $this->hash($candidateCopy)) {
                throw new DomainException('public_content_53_locked_copy_drift');
            }
            foreach ((PublicContent53Package::environment() === 'staging' ? $row['staging_source_identity'] : $row['source_identity']) as $field => $expected) {
                // Revision numbers are environment-local; the current revision
                // and all source bindings are locked in the private state CAS.
                if (in_array($field, ['id', 'published_revision_id', 'source_article_id'], true)) {
                    continue;
                }
                if (($saved['values'][$field] ?? null) !== $expected) {
                    throw new DomainException('public_content_53_locked_identity_drift');
                }
            }
        }
    }

    public function assertPublished(array $rows, array $state): void
    {
        foreach ($rows as $row) {
            if ($this->hash($this->projection($row)) !== $this->hash($row['snapshot'])) {
                throw new DomainException('public_content_53_public_readback_drift');
            }
            $native = PublicContent53Package::nativeBlocks($row, true);
            if ($native !== null && $this->hash($this->blockCopy($state[$row['page_id']]['relations']['page_blocks'], array_keys($native))) !== $this->hash($native)) {
                throw new DomainException('public_content_53_native_block_readback_drift');
            }
        }
    }

    public function hash(array $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }

    private function blockCopy(array $blocks, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $matches = array_values(array_filter($blocks, static fn (array $block): bool => $block['block_key'] === $key));
            if (count($matches) !== 1 || ! (bool) $matches[0]['is_enabled']) {
                throw new DomainException('public_content_53_copy_block_missing');
            }
            $payload = $matches[0]['payload_json'];
            $result[$key] = is_string($payload) ? json_decode($payload, true, 64, JSON_THROW_ON_ERROR) : $payload;
        }

        return $result;
    }

    private function guideControls(array $values, bool $lock): array
    {
        $result = ['seo' => $this->query('career_guide_seo_meta', ['career_guide_id' => $values['id']], $lock)];
        foreach (['career_guide_job_map', 'career_guide_article_map', 'career_guide_personality_map'] as $table) {
            $result[$table] = $this->query($table, ['career_guide_id' => $values['id']], $lock, false);
        }

        return $result;
    }

    private function query(string $table, array $where, bool $lock, bool $hasId = true): array
    {
        $query = DB::table($table)->where($where);
        if ($hasId) {
            $query->orderBy('id');
        }
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn (object $record): array => (array) $record)->all();
        if (! $hasId) {
            usort($rows, static fn (array $a, array $b): int => strcmp(PromotionContextFactory::canonicalJson($a), PromotionContextFactory::canonicalJson($b)));
        }

        return $rows;
    }
}
