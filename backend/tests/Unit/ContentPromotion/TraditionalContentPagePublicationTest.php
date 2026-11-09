<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
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
            str_repeat($commit, 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 27, str_repeat('e', 64));
    }

    private function row(): array
    {
        return app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256)['candidates'][0];
    }

    private function source(string $environment = 'production'): ContentPage
    {
        $about = null;
        foreach (app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256)['candidates'] as $row) {
            $row = TraditionalContentPagePublication::forEnvironment($row, $environment);
            $copy = $row['source_snapshot'];
            $headings = $copy['headings'];
            unset($copy['headings']);
            $slug = $row['identity']['slug'];
            $structuredType = ['methodology' => 'methodology', 'source-review-policy' => 'trust'][$slug] ?? null;
            $policy = $structuredType !== null || in_array($slug, ['privacy', 'terms', 'method-boundaries', 'science', 'policies', 'common-misconceptions', 'data-privacy', 'item-design-notes', 'reliability-validity'], true);
            $path = $structuredType !== null ? '/zh/personality/big-five/'.$slug : (str_starts_with($slug, 'help-') ? '/help/'.substr($slug, 5) : '/'.$slug);
            $source = ContentPage::query()->withoutGlobalScopes()->create($copy + [
                'org_id' => 0, 'slug' => $row['identity']['slug'], 'locale' => 'zh-CN', 'path' => $path, 'canonical_path' => $path, 'kind' => str_starts_with($slug, 'help-') ? 'help' : ($policy ? 'policy' : 'company'),
                'support_contact' => $row['protected_fields']['support_contact'] ?? null,
                'schema_enabled' => $row['protected_fields']['schema_enabled'] ?? false,
                'page_type' => $structuredType ?? (str_starts_with($slug, 'help-') ? 'support_static' : ($policy ? ($slug === 'method-boundaries' ? 'boundary' : $slug) : 'company')), 'template' => str_starts_with($slug, 'help-') ? 'help' : ($policy ? 'policy' : 'company'), 'animation_profile' => 'none',
                'headings_json' => $headings, 'content_html' => '', 'review_state' => 'approved',
                'source_updated_at' => '2026-04-19', 'effective_at' => $slug === 'science' ? null : '2026-04-19', 'policy_version' => 'original-policy-v1',
                'status' => 'published', 'is_public' => true, 'is_indexable' => true,
                'publish_allowed' => ! in_array($slug, ['privacy', 'terms', 'help-contact'], true),
                'claim_gate_status' => in_array($slug, ['privacy', 'terms', 'help-contact'], true) ? 'not_reviewed' : 'passed',
                'operator_approval_required' => true, 'operator_approved_at' => (in_array($slug, ContentPage::SCIENCE_CONTROLLED_SLUGS, true) || $structuredType === 'methodology') ? now() : null,
            ])->refresh();
            if ($source->slug === 'about') {
                $about = $source;
            }
        }

        return $about;
    }

    public function test_unchanged_published_package_retains_caches_and_does_not_call_revalidation(): void
    {
        $this->source();
        $context = $this->context();
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'];
        $native = app(TraditionalContentPagePublication::class);
        DB::transaction(function () use ($native, $rows, $context): void {
            foreach ($rows as $row) {
                $native->publish($row, $native->read($row, true), $context);
            }
        });
        $before = ContentPage::query()->withoutGlobalScopes()->orderBy('id')->get()->map->getRawOriginal()->all();
        $revisions = CmsTranslationRevision::query()->withoutGlobalScopes()->orderBy('id')->get()->map->getRawOriginal()->all();
        $key = 'content_page:v1:0:about:zh-TW';
        app(PublicContent53State::class)->projection($rows[0]);
        $cached = Cache::get($key);
        $this->assertIsArray($cached);
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.invalid/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        $directory = sys_get_temp_dir().'/traditional-unchanged-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $previous = '';
        try {
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $path = $directory.'/'.$phase.'.json';
                $result = app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, $phase, $path);
                $this->assertSame('SUCCEEDED', $result['receipt']['result']);
                $this->assertSame(27, $result['receipt']['readback_count']);
                $this->assertSame(0, $result['receipt']['written_count']);
                $previous = $path;
            }
            $this->assertSame($cached, Cache::get($key));
            Http::assertNothingSent();
            $this->assertSame($before, ContentPage::query()->withoutGlobalScopes()->orderBy('id')->get()->map->getRawOriginal()->all());
            $this->assertSame($revisions, CmsTranslationRevision::query()->withoutGlobalScopes()->orderBy('id')->get()->map->getRawOriginal()->all());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_staging_publishes_all_twenty_seven_exact_environment_copies_and_preserves_sources(): void
    {
        config(['content_promotion.public_content_53_environment' => 'staging']);
        $this->source('staging');
        $sources = ContentPage::query()->withoutGlobalScopes()->orderBy('id')->get()->map->getRawOriginal()->all();
        $context = $this->context();
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256, 'staging')['candidates'];
        $states = app(PublicContent53State::class);
        $before = $states->read($context->packageSha256);
        $states->assertBaseline($rows);
        $published = DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $before));
        $this->assertSame(27, $published['created_count']);
        $states->assertPublished($rows, $published['state']);
        foreach ($rows as $row) {
            $page = ContentPage::query()->withoutGlobalScopes()->where($row['identity'])->firstOrFail();
            $this->assertSame($row['snapshot']['content_md'], $page->content_md);
            $this->assertSame($row['snapshot']['headings'], $page->headings_json);
            $slug = $row['identity']['slug'];
            $path = '/zh-TW/'.match ($slug) {
                'methodology', 'source-review-policy' => 'personality/big-five/'.$slug,
                default => str_starts_with($slug, 'help-') ? 'help/'.substr($slug, 5) : $slug,
            };
            $this->assertSame($path, $page->path);
            $this->assertSame($path, $page->canonical_path);
        }
        $this->assertSame($sources, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_foundation_and_policy_staging_source_differences_cannot_use_production_copy(): void
    {
        $this->source('staging');
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true);
        foreach (['foundation', 'policies'] as $slug) {
            $row = array_values(array_filter($package['candidates'], static fn ($row) => $row['identity']['slug'] === $slug))[0];
            $native = app(TraditionalContentPagePublication::class);
            $saved = $native->read($row, false);
            try {
                $native->assertBaseline($row, $saved);
                $this->fail('Production source cannot authorize a different staging body');
            } catch (DomainException $error) {
                $this->assertStringStartsWith('traditional_page_source_copy_', $error->getMessage());
            }
            $native->assertBaseline(TraditionalContentPagePublication::forEnvironment($row, 'staging'), $saved);
        }
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-TW')->count());
    }

    public function test_review_binding_cannot_allow_hidden_or_reordered_help_faq(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages-review.json')), true);
        $id = 'TRADITIONAL-help-payment-refund';
        $index = array_search($id, array_column($package['candidates'], 'page_id'), true);
        foreach (['hidden', 'reordered', 'marker'] as $change) {
            $copy = $package;
            $snapshot = $copy['candidates'][$index]['snapshot'];
            $expected = 'traditional_page_format_invalid';
            if ($change === 'hidden') {
                $snapshot['faq_items'][0]['answer'] = '這段答案沒有出現在正文。';
            } elseif ($change === 'reordered') {
                $snapshot['faq_items'] = array_reverse($snapshot['faq_items']);
                $expected = 'traditional_page_structure_changed';
            } else {
                $snapshot['content_md'] = str_replace('<!-- help-service-visible-faq:end -->', '', $snapshot['content_md']);
            }
            $copy['candidates'][$index]['snapshot'] = $snapshot;
            $bytes = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $review = $proof;
            $review['candidate_sha256'] = hash('sha256', $bytes);
            $review['pages'][$id]['target_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($snapshot));
            try {
                TraditionalContentPagePublication::package($bytes, json_encode($review, JSON_THROW_ON_ERROR), str_repeat('a', 64));
                $this->fail('Expected visible FAQ rejection: '.$change);
            } catch (DomainException $error) {
                $this->assertSame($expected, $error->getMessage());
            }
        }
    }

    public function test_structured_policy_heading_identity_stays_bound_after_review_rehash(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages-review.json')), true);
        $id = 'TRADITIONAL-methodology';
        $index = array_search($id, array_column($package['candidates'], 'page_id'), true);
        $snapshot = $package['candidates'][$index]['staging_snapshot'];
        $snapshot['headings'][0]['key'] = 'renamed';
        $package['candidates'][$index]['staging_snapshot'] = $snapshot;
        $bytes = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $proof['candidate_sha256'] = hash('sha256', $bytes);
        $proof['pages'][$id]['staging_target_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($snapshot));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('traditional_page_structure_changed');
        TraditionalContentPagePublication::package($bytes, json_encode($proof, JSON_THROW_ON_ERROR), str_repeat('a', 64));
    }

    public function test_contact_binds_each_environment_to_its_reviewed_source_and_target(): void
    {
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256)['candidates'];
        $row = array_values(array_filter($rows, static fn ($row) => $row['identity']['slug'] === 'help-contact'))[0];
        $production = TraditionalContentPagePublication::forEnvironment($row, 'production');
        $staging = TraditionalContentPagePublication::forEnvironment($row, 'staging');
        $this->assertNotSame($production['source_snapshot'], $staging['source_snapshot']);
        $this->assertNotSame($production['snapshot'], $staging['snapshot']);
        $this->assertSame($row['staging_source_snapshot'], $staging['source_snapshot']);
        $this->assertSame($row['staging_snapshot'], $staging['snapshot']);
        $this->assertSame($production['identity'], $staging['identity']);
        $this->assertSame($production['protected_fields'], $staging['protected_fields']);
        config(['content_promotion.public_content_53_environment' => 'staging']);
        $selected = app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256, 'staging')['candidates'];
        $selected = array_values(array_filter($selected, static fn ($row) => $row['identity']['slug'] === 'help-contact'))[0];
        $this->assertSame($staging, $selected, 'State, writer and promotion adapter must consume the same selected pair');
    }

    public function test_contact_rejects_an_unreviewed_staging_source_even_after_package_rehash(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages-review.json')), true);
        $index = array_search('TRADITIONAL-help-contact', array_column($package['candidates'], 'page_id'), true);
        $package['candidates'][$index]['staging_source_snapshot']['summary'] = 'Changed staging authority';
        $bytes = json_encode($package, JSON_THROW_ON_ERROR);
        $proof['candidate_sha256'] = hash('sha256', $bytes);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('traditional_page_review_binding_invalid');
        TraditionalContentPagePublication::package($bytes, json_encode($proof), str_repeat('a', 64));
    }

    public function test_contact_preserves_native_contact_and_nested_path_without_changing_the_source(): void
    {
        $this->source();
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256)['candidates'];
        $row = array_values(array_filter($rows, static fn ($row) => $row['identity']['slug'] === 'help-contact'))[0];
        $native = app(TraditionalContentPagePublication::class);
        $before = $native->read($row, false);
        $changed = $before;
        $changed['source']['support_contact'] = 'wrong@example.invalid';
        try {
            $native->assertBaseline($row, $changed);
            $this->fail('Changed contact authority must not be copied');
        } catch (DomainException $error) {
            $this->assertSame('traditional_page_source_support_contact_help-contact', $error->getMessage());
        }
        DB::transaction(fn () => $native->publish($row, $native->read($row, true), $this->context()));
        $after = $native->read($row, false);
        $native->assertBaseline($row, $after);
        $this->assertSame('/zh-TW/help/contact', $after['values']['path']);
        $this->assertSame($row['protected_fields']['support_contact'], $after['values']['support_contact']);
        $this->assertSame($before['source'], $after['source']);
        $this->assertSame($before['source_revisions'], $after['source_revisions']);
    }

    public function test_staging_contact_publication_cannot_use_the_production_source_snapshot(): void
    {
        config(['content_promotion.public_content_53_environment' => 'staging']);
        $this->source('staging');
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->context()->packageSha256, 'staging')['candidates'];
        $row = array_values(array_filter($rows, static fn ($row) => $row['identity']['slug'] === 'help-contact'))[0];
        $original = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true);
        $production = array_values(array_filter($original['candidates'], static fn ($row) => $row['identity']['slug'] === 'help-contact'))[0];
        $native = app(TraditionalContentPagePublication::class);
        $before = $native->read($row, false);
        try {
            $native->assertBaseline($production, $before);
            $this->fail('A different environment source is not an approved fallback');
        } catch (DomainException $error) {
            $this->assertSame('traditional_page_source_copy_summary_help-contact', $error->getMessage());
        }
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        $context = $this->context();
        app(\App\Services\ContentPromotion\Adapters\PublicContent53PromotionAdapter::class)->preflight($context);
        $states = app(PublicContent53State::class);
        $beforeState = $states->read($context->packageSha256);
        $published = DB::transaction(fn () => app(PublicContent53Writer::class)->publish($context, $beforeState));
        $states->assertPublished($rows, $published['state']);
        Http::assertNothingSent();
        $after = $native->read($row, false);
        $target = ContentPage::query()->withoutGlobalScopes()->where('slug', 'help-contact')->where('locale', 'zh-TW')->firstOrFail();
        $this->assertSame(array_intersect_key($row['snapshot'], array_flip(TraditionalContentPagePublication::FIELDS)), $native->copy($target));
        $this->assertSame($before['source'], $after['source']);
        $this->assertSame($before['source_revisions'], $after['source_revisions']);
    }

    public function test_publication_failure_codes_are_fixed_and_never_disclose_raw_errors(): void
    {
        $this->assertSame('public_content_53_traditional_page_source_drift_or_hold',
            TraditionalContentPagePublication::failureCode(new DomainException('traditional_page_source_drift_or_hold')));
        $this->assertSame('public_content_53_prepared_payload_incomplete',
            TraditionalContentPagePublication::failureCode(new \App\Services\Cms\CmsTranslationWorkflowException('private message', ['prepared payload incomplete'])));
        $storage = new \PDOException('private SQL and content');
        $storage->errorInfo = ['23000', 1062, 'private SQL and content'];
        $this->assertSame('public_content_53_storage_duplicate_identity', TraditionalContentPagePublication::failureCode($storage));
        foreach ([new DomainException('traditional_page_private_payload'), new \RuntimeException('private URL and secret'),
            new \App\Services\Cms\CmsTranslationWorkflowException('private message', ['private blocker'])] as $unknown) {
            $this->assertSame('public_content_53_publication_failed', TraditionalContentPagePublication::failureCode($unknown));
        }
        $this->assertStringContainsString('TraditionalContentPagePublication::failureCode($error)', file_get_contents(base_path('scripts/deploy/run_public_content_53_publish.php')));
    }

    public function test_source_diagnostics_identify_the_failed_condition_without_disclosing_values(): void
    {
        $this->source();
        $row = $this->row();
        $native = app(TraditionalContentPagePublication::class);
        $original = $native->read($row, false);
        foreach ([
            ['source_locale', 'en', 'identity'],
            ['status', 'draft', 'readiness'],
            ['review_state', 'draft', 'review'],
            ['legal_review_required', 1, 'hold'],
            ['content_html', '<p>private body</p>', 'html'],
            ['source_version_hash', null, 'version'],
            ['summary', 'private changed text', 'copy_summary'],
        ] as [$field, $value, $condition]) {
            $saved = $original;
            $saved['source'][$field] = $value;
            try {
                $native->assertBaseline($row, $saved);
                $this->fail('Changed source must be rejected');
            } catch (DomainException $error) {
                $this->assertSame('public_content_53_traditional_page_source_'.$condition.'_about', $native::failureCode($error));
            }
        }
        foreach (['traditional_page_source_copy_password_about', 'traditional_page_source_identity_private-id'] as $unknown) {
            $this->assertSame('public_content_53_publication_failed', $native::failureCode(new DomainException($unknown)));
        }
    }

    public function test_prepared_identity_accepts_published_roots_but_never_translations_or_other_locales(): void
    {
        $source = $this->source();
        $adapter = app(\App\Services\Cms\ContentPageTranslationAdapter::class);
        $values = $source->getRawOriginal();
        $this->assertTrue($adapter->isPreparedTraditionalSourceIdentity($source));
        $source->setRawAttributes(array_replace($values, ['translation_status' => CmsTranslationRevision::STATUS_PUBLISHED]), true);
        $this->assertTrue($adapter->isPreparedTraditionalSourceIdentity($source));
        $this->assertFalse($adapter->isSource($source), 'Generic translation entry keeps its existing source-only contract');
        foreach ([['org_id' => 1], ['locale' => 'en', 'source_locale' => 'en'], ['source_locale' => 'en'],
            ['source_content_id' => 7], ['translation_status' => 'machine_draft'], ['translation_status' => 'human_review'],
            ['translation_status' => 'approved'], ['translation_status' => 'archived'], ['translation_status' => null]] as $invalid) {
            $source->setRawAttributes(array_replace($values, $invalid), true);
            $this->assertFalse($adapter->isPreparedTraditionalSourceIdentity($source));
        }
    }

    public function test_four_phase_service_preserves_sixteen_published_pages_while_creating_nine_faq_pages(): void
    {
        $this->source();
        // Actual staging Science lineage: a published root, not a translation.
        DB::table('content_pages')->where('org_id', 0)->where('slug', 'science')->where('locale', 'zh-CN')
            ->update(['translation_status' => CmsTranslationRevision::STATUS_PUBLISHED]);
        $sourcesBefore = ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->orderBy('id')->get()->map->getRawOriginal()->all();
        $context = $this->context();
        $rows = app(PublicContent53Package::class)->read(base_path(), $context->packageSha256)['candidates'];
        $native = app(TraditionalContentPagePublication::class);
        DB::transaction(function () use ($rows, $native, $context): void {
            foreach (array_slice($rows, 0, 25) as $row) {
                $native->publish($row, $native->read($row, true), $context);
            }
        });
        $preserved = ContentPage::query()->where('locale', 'zh-TW')->orderBy('id')->get()->map->getRawOriginal()->all();
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['ok' => true,
            'revalidated_paths' => $request->data()['cache_signal']['paths'], 'rejected_paths' => [], 'invalidated_tags' => []]));
        $directory = sys_get_temp_dir().'/traditional-twenty-five-service-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $previous = '';
        try {
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $path = $directory.'/'.$phase.'.json';
                $result = app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, $phase, $path);
                $this->assertSame('SUCCEEDED', $result['receipt']['result']);
                $this->assertSame(27, $result['receipt']['readback_count']);
                if ($phase === 'publish') {
                    $this->assertSame(2, $result['receipt']['created_count']);
                    $this->assertSame(25, $result['receipt']['unchanged_count']);
                }
                $previous = $path;
            }
            Http::assertSentCount(22); // Real writes still complete the existing signed cache closeout.
            Http::assertSent(fn ($request) => $request->data()['cache_signal']['paths'] === ['/zh-TW/help/contact']);
            $this->assertSame($preserved, ContentPage::query()->where('locale', 'zh-TW')->orderBy('id')->limit(25)->get()->map->getRawOriginal()->all());
            $this->assertSame($sourcesBefore, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->orderBy('id')->get()->map->getRawOriginal()->all());
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
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
        $this->assertSame(27, $published['created_count']);
        $this->assertSame(27, $published['written_count']);
        $this->assertSame($sourceBefore, $source->fresh()->getRawOriginal());
        $this->assertSame($allSourcesBefore, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->orderBy('id')->get()->map->getRawOriginal()->all());
        foreach ($rows as $row) {
            $slug = $row['identity']['slug'];
            Cache::forget('content_page:v1:0:'.$slug.':zh-TW');
            $variant = $this->getJson('/api/v0.5/content-pages/'.$slug.'?locale=zh-TW')->assertOk()->json('page');
            $structuredType = ['methodology' => 'methodology', 'source-review-policy' => 'trust'][$slug] ?? null;
            $path = '/zh-TW/'.match ($slug) {
                'methodology', 'source-review-policy' => 'personality/big-five/'.$slug,
                default => str_starts_with($slug, 'help-') ? 'help/'.substr($slug, 5) : $slug,
            };
            $this->assertSame($path, $variant['path']);
            if ($structuredType !== null) {
                $this->assertSame($path, $variant['canonical_path']);
                $this->assertSame($structuredType, $variant['page_type']);
                $this->assertSame($row['snapshot']['headings'], $variant['headings']);
                $this->assertFalse($variant['schema_enabled']);
            }
            $this->assertSame($row['snapshot']['content_md'], $variant['content_md']);
            $this->assertSame($slug === 'science' ? null : '2026-04-19', $variant['effective_at']);
            $this->assertSame('original-policy-v1', $variant['policy_version']);
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
        $this->assertSame(27, $again['written_count']);
        $this->assertSame(0, $again['created_count']);
        foreach ($history as $old) {
            $this->assertSame($old, CmsTranslationRevision::query()->withoutGlobalScopes()->findOrFail($old['id'])->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public function test_existing_about_is_preserved_while_other_variants_publish(): void
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
        $this->assertSame(26, $after['created_count']);
        $this->assertSame(26, $after['written_count']);
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
                $candidate['candidates'][3]['identity']['slug'] = 'support';
                $candidate['candidates'][3]['page_id'] = 'TRADITIONAL-support';
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

    public function test_reviewed_science_targets_still_reject_changed_paths_queries_and_heading_shape(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages-review.json')), true, 64, JSON_THROW_ON_ERROR);
        $index = array_search('TRADITIONAL-science', array_column($package['candidates'], 'page_id'), true);
        $this->assertIsInt($index);
        foreach (['path', 'query', 'headings'] as $mode) {
            $candidate = $package;
            if ($mode === 'headings') {
                array_pop($candidate['candidates'][$index]['snapshot']['headings']);
            } else {
                $candidate['candidates'][$index]['snapshot']['content_md'] = str_replace('/method-boundaries',
                    $mode === 'path' ? '/method-boundary' : '/method-boundaries?unexpected=1', $candidate['candidates'][$index]['snapshot']['content_md']);
            }
            $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $review = $proof;
            $review['candidate_sha256'] = hash('sha256', $bytes);
            $review['pages']['TRADITIONAL-science']['target_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($candidate['candidates'][$index]['snapshot']));
            try {
                TraditionalContentPagePublication::package($bytes, json_encode($review, JSON_THROW_ON_ERROR), str_repeat('a', 64));
                $this->fail('Expected source structure or target rejection');
            } catch (DomainException $error) {
                $this->assertSame($mode === 'headings' ? 'traditional_page_structure_changed' : 'traditional_page_protected_tokens_changed', $error->getMessage());
            }
        }
    }

    public function test_markdown_links_preserve_full_destinations_and_reject_unsupported_syntax(): void
    {
        $method = new \ReflectionMethod(TraditionalContentPagePublication::class, 'markdownLinkTargets');
        $this->assertSame(['/zh/pricing', 'mailto:support@fermatmind.com', '/关于?地区=中国#说明'], $method->invoke(null,
            '[價格](/zh/pricing)、[郵件](mailto:support@fermatmind.com)、[說明](/关于?地区=中国#说明)'));
        foreach (['[說明](javascript:alert)', '![圖片](/image.png)', '[說明](/broken', '[說明](//example.com)', '[說明](/bad\\path)'] as $text) {
            try {
                $method->invoke(null, $text);
                $this->fail('Expected format rejection');
            } catch (DomainException $error) {
                $this->assertSame('traditional_page_format_invalid', $error->getMessage());
            }
        }
    }

    public function test_rehashed_review_cannot_authorize_changed_markdown_destinations(): void
    {
        $package = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages.json')), true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/traditional-pages-review.json')), true, 64, JSON_THROW_ON_ERROR);
        $index = array_search('TRADITIONAL-terms', array_column($package['candidates'], 'page_id'), true);
        foreach (['valid', 'query', 'mail'] as $mode) {
            $candidate = $package;
            $links = '[說明](/关于?地区=中国#说明) [郵件](mailto:support@fermatmind.com)';
            $candidate['candidates'][$index]['source_snapshot']['content_md'] .= "\n\n".$links;
            $target = match ($mode) {
                'query' => str_replace('地区=中国', '地区=中國', $links),
                'mail' => str_replace('support@', 'wrong@', $links),
                default => $links,
            };
            $candidate['candidates'][$index]['snapshot']['content_md'] .= "\n\n".$target;
            $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $review = $proof;
            $review['candidate_sha256'] = hash('sha256', $bytes);
            foreach (['source_snapshot' => 'source_sha256', 'snapshot' => 'target_sha256'] as $field => $key) {
                $review['pages']['TRADITIONAL-terms'][$key] = hash('sha256', PromotionContextFactory::canonicalJson($candidate['candidates'][$index][$field]));
            }
            try {
                $result = TraditionalContentPagePublication::package($bytes, json_encode($review, JSON_THROW_ON_ERROR), str_repeat('a', 64));
                $this->assertSame('valid', $mode);
                $this->assertCount(27, $result['candidates']);
            } catch (DomainException $error) {
                $this->assertNotSame('valid', $mode);
                $this->assertSame('traditional_page_link_targets_changed', $error->getMessage());
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
        $this->assertSame(27, ContentPage::query()->withoutGlobalScopes()->count());
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
