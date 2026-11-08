<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use App\Services\ContentPromotion\TraditionalContentPagePublication;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TraditionalContentPagePublicationTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $commit = 'a'): PromotionContext
    {
        $chain = TraditionalContentPagePublication::SCHEMA."\n";
        foreach (['traditional-pages.json', 'traditional-pages-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), hash('sha256', $chain), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat($commit, 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 4, str_repeat('e', 64));
    }

    private function row(): array
    {
        return app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256)['candidates'][0];
    }

    private function source(): ContentPage
    {
        $about = null;
        foreach (app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256)['candidates'] as $row) {
            $copy = $row['source_snapshot'];
            $headings = $copy['headings'];
            unset($copy['headings']);
            $source = ContentPage::query()->withoutGlobalScopes()->create($copy + [
                'org_id' => 0, 'slug' => $row['identity']['slug'], 'locale' => 'zh-CN', 'path' => '/'.$row['identity']['slug'], 'kind' => 'company',
                'page_type' => 'company', 'template' => 'company', 'animation_profile' => 'none',
                'headings_json' => $headings, 'content_html' => '', 'review_state' => 'approved',
                'source_updated_at' => '2026-04-19',
                'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            ])->refresh();
            if ($source->slug === 'about') {
                $about = $source;
            }
        }

        return $about;
    }

    public function test_native_transaction_publishes_full_copy_and_preserves_originals_and_history(): void
    {
        Http::preventStrayRequests();
        $source = $this->source();
        $sourceBefore = $source->getRawOriginal();
        $allSourcesBefore = ContentPage::query()->withoutGlobalScopes()->orderBy('id')->get()->map->getRawOriginal()->all();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $context = $this->context();
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'];
        $before = $states->read($context->packageSha256);
        $states->assertBaseline($rows);
        $published = DB::transaction(fn () => $writer->publish($context, $before));
        $this->assertSame(4, $published['created_count']);
        $this->assertSame(4, $published['written_count']);
        $this->assertSame($sourceBefore, $source->fresh()->getRawOriginal());
        $this->assertSame($allSourcesBefore, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->orderBy('id')->get()->map->getRawOriginal()->all());
        foreach ($rows as $row) {
            $slug = $row['identity']['slug'];
            Cache::forget('content_page:v1:0:'.$slug.':zh-TW');
            $variant = $this->getJson('/api/v0.5/content-pages/'.$slug.'?locale=zh-TW')->assertOk()->json('page');
            $this->assertSame('/zh-TW/'.$slug, $variant['path']);
            $this->assertSame($row['snapshot']['content_md'], $variant['content_md']);
        }
        Cache::forget('content_page:v1:0:about:zh-TW');
        $states->assertPublished($rows, $published['state']);
        $page = $this->getJson('/api/v0.5/content-pages/about?locale=zh-TW')->assertOk()->json('page');
        $this->assertSame('zh-TW', $page['locale']);
        $this->assertSame($rows[0]['snapshot']['content_md'], $page['content_md']);
        $this->assertSame($rows[0]['snapshot']['headings'], $page['headings']);
        $this->assertFalse($page['is_indexable']);
        $this->assertSame('2026-04-19', $page['updated_at']);
        $this->assertSame(0, DB::transaction(fn () => $writer->publish($context, $published['state']))['written_count']);
        $history = CmsTranslationRevision::query()->withoutGlobalScopes()->get()->map->getRawOriginal()->all();
        DB::transaction(fn () => $writer->restore($context, $before, $published['state']));
        Cache::forget('content_page:v1:0:about:zh-TW');
        $this->getJson('/api/v0.5/content-pages/about?locale=zh-TW')->assertNotFound();
        $this->assertSame($history, CmsTranslationRevision::query()->withoutGlobalScopes()->get()->map->getRawOriginal()->all());
        $states->assertBaseline($rows);
        $restored = $states->read($context->packageSha256);
        $again = DB::transaction(fn () => $writer->publish($this->context('f'), $restored));
        $this->assertSame(4, $again['written_count']);
        $this->assertSame(0, $again['created_count']);
        foreach ($history as $old) {
            $this->assertSame($old, CmsTranslationRevision::query()->withoutGlobalScopes()->findOrFail($old['id'])->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public function test_existing_about_is_preserved_while_three_new_variants_publish(): void
    {
        $this->source();
        $native = app(TraditionalContentPagePublication::class);
        $row = $this->row();
        DB::transaction(fn () => $native->publish($row, $native->read($row, true), $this->context()));
        $about = ContentPage::query()->withoutGlobalScopes()->where('slug', 'about')->where('locale', 'zh-TW')->firstOrFail();
        $beforeAbout = $about->getRawOriginal();
        $beforeRevisions = CmsTranslationRevision::query()->withoutGlobalScopes()->where('content_id', $about->id)->orderBy('id')->get()->map->getRawOriginal()->all();
        $context = $this->context('f');
        $before = app(PublicContent53State::class)->read($context->packageSha256);
        $after = DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $before));
        $this->assertSame(3, $after['created_count']);
        $this->assertSame(3, $after['written_count']);
        $this->assertSame($beforeAbout, $about->fresh()->getRawOriginal());
        $this->assertSame($beforeRevisions, CmsTranslationRevision::query()->withoutGlobalScopes()->where('content_id', $about->id)->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_duplicate_and_unselected_identities_are_rejected(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages-review.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach (['duplicate', 'unselected'] as $mode) {
            $candidate = $package;
            if ($mode === 'duplicate') {
                $candidate['candidates'][3] = $candidate['candidates'][0];
            } else {
                $candidate['candidates'][3]['identity']['slug'] = 'privacy';
                $candidate['candidates'][3]['page_id'] = 'TRADITIONAL-privacy';
            }
            $bytes = json_encode($candidate, JSON_THROW_ON_ERROR);
            $proof['candidate_sha256'] = hash('sha256', $bytes);
            try {
                TraditionalContentPagePublication::package($bytes, json_encode($proof, JSON_THROW_ON_ERROR), str_repeat('a', 64));
                $this->fail('Expected selected identity rejection');
            } catch (DomainException $error) {
                $this->assertSame('traditional_page_identity_or_fields_invalid', $error->getMessage());
            }
        }
    }

    public function test_source_changes_after_preflight_are_rejected_without_target_writes(): void
    {
        $source = $this->source();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->context()->packageSha256);
        $source->forceFill(['summary' => 'Changed source.'])->save();
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            $this->fail('Expected source conflict');
        } catch (DomainException $error) {
            $this->assertSame('public_content_53_native_prestate_drift', $error->getMessage());
        }
        $this->assertSame(4, ContentPage::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, CmsTranslationRevision::query()->withoutGlobalScopes()->count());
    }

    public function test_legacy_native_version_key_is_preserved_with_independent_payload_binding(): void
    {
        $source = $this->source();
        $legacy = str_repeat('f', 64);
        DB::table('content_pages')->where('id', $source->id)->update(['source_version_hash' => $legacy]);
        $source = $source->fresh();
        $beforeSource = $source->getRawOriginal();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->context()->packageSha256);
        $states->assertBaseline([$this->row()]);
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
        $this->assertSame($beforeSource, $source->fresh()->getRawOriginal());
        $this->assertSame($legacy, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-TW')->value('translated_from_version_hash'));
    }

    public function test_sql_failure_after_native_write_rolls_back_entire_publication(): void
    {
        $this->source();
        $before = app(PublicContent53State::class)->read($this->context()->packageSha256);
        try {
            DB::transaction(function () use ($before): void {
                app(PublicContent53Writer::class)->publish($this->context(), $before);
                DB::statement('insert into traditional_page_injected_missing_table (id) values (1)');
            });
            $this->fail('Expected injected SQL failure');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('traditional_page_injected_missing_table', $error->getMessage());
        }
        $this->assertSame($before, app(PublicContent53State::class)->read($this->context()->packageSha256));
        $this->assertSame(0, CmsTranslationRevision::query()->withoutGlobalScopes()->count());
    }

    public function test_existing_unrelated_target_is_never_overwritten(): void
    {
        $source = $this->source();
        $copy = $source->replicate();
        $copy->forceFill(['locale' => 'zh-TW', 'title' => 'Operator draft', 'status' => 'draft', 'is_public' => false])->save();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('traditional_page_target_not_owned');
        app(PublicContent53State::class)->assertBaseline([$this->row()]);
    }

    public function test_concurrent_target_change_blocks_rollback_without_overwriting_it(): void
    {
        $this->source();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->context()->packageSha256);
        $after = DB::transaction(fn () => $writer->publish($this->context(), $before));
        $target = ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-TW')->firstOrFail();
        $target->forceFill(['summary' => 'Operator changed the live row.'])->save();
        try {
            DB::transaction(fn () => $writer->restore($this->context(), $before, $after['state']));
            $this->fail('Expected rollback conflict');
        } catch (DomainException $error) {
            $this->assertSame('public_content_53_rollback_concurrent_state', $error->getMessage());
        }
        $this->assertSame('Operator changed the live row.', $target->fresh()->summary);
    }
}
