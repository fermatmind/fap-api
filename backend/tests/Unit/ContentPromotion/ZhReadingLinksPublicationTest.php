<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ZhReadingLinksPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function digest(?string $root = null): string
    {
        $chain = "fermatmind.public_content_zh_reading_links.v1\n";
        foreach (['zh-reading-links.json', 'zh-reading-links-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    private function article(array $identity, array $copy, string $group): Article
    {
        $a = Article::query()->create($identity + $copy + ['status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        $a->forceFill(['translation_group_id' => $group, 'source_locale' => $a->locale])->saveQuietly();
        $revision = ArticleTranslationRevision::query()->create($copy + ['org_id' => 0, 'article_id' => $a->id, 'source_article_id' => $a->id, 'translation_group_id' => $group, 'locale' => $a->locale, 'source_locale' => $a->locale, 'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => $a->source_version_hash, 'translated_from_version_hash' => $a->source_version_hash]);
        $draft = $revision->replicate();
        $draft->forceFill(['revision_number' => 2, 'revision_status' => 'machine_draft', 'content_md' => 'Private operator draft'])->save();
        $a->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $draft->id])->saveQuietly();
        ArticleSeoMeta::query()->create(['article_id' => $a->id, 'org_id' => 0, 'locale' => $a->locale, 'seo_title' => 'Keep SEO', 'seo_description' => 'Keep description', 'is_indexable' => true]);

        return $a;
    }

    public static function environments(): array
    {
        return [['production'], ['staging']];
    }

    #[DataProvider('environments')]
    public function test_native_batch_preserves_drafts_seo_and_english_with_atomic_failure_restore_and_conflict(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->digest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        self::assertCount(5, $rows);
        foreach ($rows as $r) {
            $this->article($r['identity'], PublicContent53Package::before($r), $r['source_identity']['translation_group_id']);
        }
        $en = $this->article(['org_id' => 0, 'slug' => 'mbti-basics', 'locale' => 'en'], ['title' => 'English', 'excerpt' => 'Keep', 'content_md' => 'English control'], 'english-control');
        $enBefore = $en->fresh()->toArray();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($sha);
        $states->assertBaseline($rows);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 5, str_repeat('e', 64));
        $updates = 0;
        $fail = true;
        DB::listen(function ($q) use (&$updates, &$fail): void {
            if ($fail && preg_match('/^update [`"]articles[`"]/i', $q->sql) === 1 && ++$updates === 3) {
                $fail = false;
                throw new DomainException('zh_reading_third_write_failed');
            }
        });
        try {
            DB::transaction(fn () => $writer->publish($context, $before));
            self::fail('Later write failure must roll back the batch');
        } catch (DomainException $e) {
            self::assertSame('zh_reading_third_write_failed', $e->getMessage());
        }
        self::assertFalse($fail);
        self::assertSame($before, $states->read($sha));
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(5, $after['written_count']);
        $states->assertPublished($rows, $after['state']);
        foreach ($rows as $r) {
            $id = $r['page_id'];
            self::assertSame($before[$id]['working'], $after['state'][$id]['working']);
            self::assertSame($before[$id]['relations'], $after['state'][$id]['relations']);
            self::assertSame($before[$id]['values']['is_indexable'], $after['state'][$id]['values']['is_indexable']);
        }
        self::assertSame($enBefore, $en->fresh()->toArray());
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        self::assertSame($before, $states->read($sha));
        $next = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53', str_repeat('f', 40), str_repeat('b', 64), str_repeat('c', 64), '12346', 1, str_repeat('d', 64), 5, str_repeat('e', 64));
        self::assertSame(5, DB::transaction(fn () => $writer->publish($next, $before))['written_count']);
        $stale = $states->read($sha);
        Article::query()->where($rows[0]['identity'])->firstOrFail()->forceFill(['excerpt' => 'Concurrent operator change'])->saveQuietly();
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => $writer->publish($next, $stale));
    }

    public function test_package_rejects_wrong_target_nonbody_changes_and_unreviewed_bytes(): void
    {
        $root = sys_get_temp_dir().'/fm-zh-reading-'.bin2hex(random_bytes(6));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        $source = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/zh-reading-links.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/zh-reading-links-review.json')), true);
        try {
            foreach (['locale', 'target', 'title', 'staging_excerpt', 'field', 'unreviewed'] as $case) {
                $data = $source;
                if ($case === 'locale') {
                    $data['candidates'][0]['identity']['locale'] = 'en';
                } elseif ($case === 'target') {
                    $data['candidates'][0]['identity']['slug'] = 'unselected';
                } elseif ($case === 'title') {
                    $data['candidates'][0]['snapshot']['title'] = 'Changed title';
                } elseif ($case === 'staging_excerpt') {
                    $data['candidates'][0]['staging_snapshot']['excerpt'] = 'Changed staging excerpt';
                } elseif ($case === 'field') {
                    $data['candidates'][0]['snapshot']['is_indexable'] = false;
                }
                $bytes = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/zh-reading-links.json', $bytes);
                $review = $proof;
                $review['candidate_sha256'] = $case === 'unreviewed' ? str_repeat('0', 64) : hash('sha256', $bytes);
                file_put_contents($dir.'/zh-reading-links-review.json', json_encode($review, JSON_THROW_ON_ERROR));
                try {
                    app(PublicContent53Package::class)->read($root, $this->digest($root));
                    self::fail('Out-of-scope package accepted: '.$case);
                } catch (DomainException $e) {
                    self::assertStringStartsWith('public_content_53_zh_reading_',$e->getMessage());
                }
            }
        } finally {
            foreach (glob($dir.'/*') as $f) {
                unlink($f);
            } rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }
}
