<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Cms\ContentPageTranslationAdapter;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PublicContent53PricingRefundTest extends TestCase
{
    use RefreshDatabase;

    private string $selection = 'pricing-refund';

    public static function environments(): array
    {
        return [['staging', 'pricing-refund'], ['production', 'pricing-refund'], ['staging', 'support-contact'], ['production', 'support-contact']];
    }

    #[DataProvider('environments')]
    public function test_native_publication_replay_restore_and_new_owner_preserve_drafts_and_controls(string $environment, string $selection): void
    {
        $this->selection = $selection;
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedPages();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $rows = $this->rows();
        $before = $states->read($this->digest());
        $immutable = CmsTranslationRevision::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $states->assertBaseline($rows);
        $after = DB::transaction(fn () => $writer->publish($this->context(), $before));
        self::assertSame(count($rows), $after['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $after['state']);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->context(), $after['state']))['written_count']);
        foreach ($immutable as $revision) {
            self::assertSame($revision, CmsTranslationRevision::findOrFail($revision['id'])->getRawOriginal());
        }
        foreach (ContentPage::query()->where('locale', 'en')->whereIn('slug', ['terms', 'policies', 'help-payment-refund', 'help-faq', 'help-contact'])->get() as $page) {
            self::assertSame(ContentPage::findOrFail($page->source_content_id)->source_version_hash, $page->translated_from_version_hash);
        }
        DB::transaction(fn () => $writer->restore($this->context(), $before, $after['state']));
        Cache::flush();
        self::assertSame($before, $states->read($this->digest()));
        $again = DB::transaction(fn () => $writer->publish($this->context('f'), $before));
        self::assertSame(count($rows), $again['written_count']);
        Cache::flush();
        $states->assertPublished($rows, $again['state']);
        self::assertDatabaseCount('orders', 0);
    }

    public function test_revision_failure_is_atomic_and_concurrent_native_draft_drift_is_rejected(): void
    {
        $this->seedPages();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $count = CmsTranslationRevision::count();
        $dispatcher = CmsTranslationRevision::getEventDispatcher();
        CmsTranslationRevision::setEventDispatcher(clone $dispatcher);
        $creates = 0;
        CmsTranslationRevision::creating(static function () use (&$creates): void {
            if (++$creates === 3) {
                throw new RuntimeException('Injected pricing policy revision failure');
            }
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            self::fail('Expected revision failure');
        } catch (RuntimeException $e) {
            self::assertSame('Injected pricing policy revision failure', $e->getMessage());
        } finally {
            CmsTranslationRevision::setEventDispatcher($dispatcher);
        }
        self::assertSame($count, CmsTranslationRevision::count());
        self::assertSame($before, $states->read($this->digest()));
        CmsTranslationRevision::query()->where('revision_status', 'draft')->update(['payload_json' => json_encode(['title' => 'Concurrent operator edit'])]);
        $this->expectException(DomainException::class);
        DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
    }

    public function test_exact_page_scope_and_noncopy_writes_are_rejected_even_with_a_fresh_review_hash(): void
    {
        $original = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/pricing-refund.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach (['missing', 'duplicate', 'locale', 'org', 'slug', 'title', 'control'] as $mutation) {
            $package = $original;
            match ($mutation) {
                'missing' => array_pop($package['candidates']),
                'duplicate' => $package['candidates'][1] = $package['candidates'][0],
                'locale' => $package['candidates'][0]['identity']['locale'] = 'zh-TW',
                'org' => $package['candidates'][0]['identity']['org_id'] = 1,
                'slug' => $package['candidates'][0]['identity']['slug'] = 'privacy',
                'title' => $package['candidates'][0]['snapshot']['title'] = 'Changed protected title',
                'control' => $package['candidates'][0]['snapshot']['is_indexable'] = false,
            };
            $bytes = json_encode($package, JSON_THROW_ON_ERROR);
            $review = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/pricing-refund-review.json')), true, 64, JSON_THROW_ON_ERROR);
            $review['candidate_sha256'] = hash('sha256', $bytes);
            try {
                PublicContent53Package::pricingRefundPackage($bytes, json_encode($review, JSON_THROW_ON_ERROR), $this->digest());
                self::fail('Accepted '.$mutation);
            } catch (DomainException $e) {
                self::assertStringStartsWith('public_content_53_pricing_refund_', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('public_content_53_pricing_refund_review_invalid');
        PublicContent53Package::pricingRefundPackage(json_encode($original, JSON_THROW_ON_ERROR), file_get_contents(base_path(PublicContent53Package::PACKAGE.'/pricing-refund-review.json')), $this->digest());
    }

    public function test_support_contact_rejects_noncontact_changes_and_invalid_mailboxes(): void
    {
        $original = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/support-contact.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach (['missing', 'duplicate', 'locale', 'org', 'title', 'control', 'email', 'body', 'faq'] as $mutation) {
            $package = $original;
            match ($mutation) {
                'missing' => array_pop($package['candidates']),
                'duplicate' => $package['candidates'][1] = $package['candidates'][0],
                'locale' => $package['candidates'][0]['identity']['locale'] = 'zh-TW',
                'org' => $package['candidates'][0]['identity']['org_id'] = 1,
                'title' => $package['candidates'][0]['snapshot']['title'] = 'Changed title',
                'control' => $package['candidates'][0]['snapshot']['is_indexable'] = true,
                'email' => $package['candidates'][0]['snapshot']['support_contact'] = 'invalid',
                'body' => $package['candidates'][0]['snapshot']['content_md'] .= ' Changed refund terms',
                'faq' => $package['candidates'][0]['snapshot']['faq_items'] = [['question' => 'New question', 'answer' => 'New answer']],
            };
            $bytes = json_encode($package, JSON_THROW_ON_ERROR);
            $review = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/support-contact-review.json')), true, 64, JSON_THROW_ON_ERROR);
            $review['candidate_sha256'] = hash('sha256', $bytes);
            try {
                PublicContent53Package::supportContactPackage($bytes, json_encode($review, JSON_THROW_ON_ERROR), str_repeat('a', 64));
                self::fail('Accepted '.$mutation);
            } catch (DomainException $e) {
                self::assertStringStartsWith('public_content_53_support_contact_', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('public_content_53_support_contact_review_invalid');
        PublicContent53Package::supportContactPackage(json_encode($original, JSON_THROW_ON_ERROR), file_get_contents(base_path(PublicContent53Package::PACKAGE.'/support-contact-review.json')), str_repeat('a', 64));
    }

    private function rows(): array
    {
        return app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
    }

    private function digest(): string
    {
        $chain = ($this->selection === 'support-contact' ? 'fermatmind.public_support_contact.v1' : 'fermatmind.public_content_pricing_refund.v1')."\n";
        foreach ([$this->selection.'.json', $this->selection.'-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return hash('sha256', $chain);
    }

    private function context(string $owner = 'a'): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->digest(), 'W3', 'PUBLIC-CONTENT-53', str_repeat($owner, 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), count($this->rows()), str_repeat('e', 64));
    }

    private function seedPages(): void
    {
        $sources = [];
        foreach ($this->rows() as $row) {
            $locale = $row['identity']['locale'];
            $slug = $row['identity']['slug'];
            $copy = PublicContent53Package::before($row);
            $page = ContentPage::query()->create($row['identity'] + $copy + [
                'status' => 'published', 'is_public' => true, 'is_indexable' => $slug !== 'help-payment-refund',
                'schema_enabled' => $slug === 'help-payment-refund', 'faq_schema_eligible' => true, 'schema_eligibility_reviewed_at' => now(),
                'legal_review_required' => false, 'science_review_required' => false,
                'path' => '/'.($locale === 'en' ? 'en' : 'zh').'/'.(str_starts_with($slug, 'help-') ? 'help/'.substr($slug, 5) : $slug),
                'kind' => str_starts_with($slug, 'help-') ? 'help' : 'policy', 'content_html' => '',
                'seo_title' => $copy['title'], 'seo_description' => 'Existing reviewed public policy metadata.',
                'translation_group_id' => 'pricing-policy-'.$slug, 'source_locale' => 'zh-CN',
                'source_content_id' => $locale === 'en' ? $sources[$slug] : null,
                'review_state' => 'approved', 'publish_allowed' => true, 'operator_approval_required' => false,
                'claim_gate_status' => 'passed', 'published_at' => now()->subDay(),
            ]);
            if ($locale === 'zh-CN') {
                $sources[$slug] = $page->id;
            }
            $page->forceFill([
                'translation_group_id' => 'pricing-policy-'.$slug, 'source_locale' => 'zh-CN',
                'source_content_id' => $locale === 'en' ? $sources[$slug] : null,
            ])->saveQuietly();
            $revision = CmsTranslationRevision::query()->create([
                'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $page->id,
                'translation_group_id' => $page->translation_group_id, 'source_content_id' => $page->source_content_id,
                'locale' => $locale, 'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'published',
                'source_version_hash' => $page->freshSourceVersionHash(), 'payload_json' => app(ContentPageTranslationAdapter::class)->snapshotPayload($page),
            ]);
            $page->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $revision->id, 'source_version_hash' => $page->freshSourceVersionHash()])->saveQuietly();
        }
        $page = ContentPage::query()->where('slug', 'terms')->where('locale', 'en')->firstOrFail();
        $draft = CmsTranslationRevision::query()->create([
            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $page->id,
            'translation_group_id' => $page->translation_group_id, 'source_content_id' => $page->source_content_id,
            'locale' => 'en', 'source_locale' => 'zh-CN', 'revision_number' => 2, 'revision_status' => 'draft',
            'source_version_hash' => $page->source_version_hash, 'payload_json' => ['title' => 'Unpublished operator draft'],
        ]);
        $page->forceFill(['working_revision_id' => $draft->id])->saveQuietly();
    }
}
