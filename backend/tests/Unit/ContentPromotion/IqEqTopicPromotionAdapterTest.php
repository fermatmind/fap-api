<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\ContentReleaseSnapshot;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\ContentPromotion\Adapters\IqEqTopicPromotionAdapter;
use App\Services\ContentPromotion\IqEqTopicPackage;
use App\Services\ContentPromotion\PromotionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class IqEqTopicPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Unit\ContentPromotion\Concerns\SeedsIqEqTopicPrerequisites;

    private array $receiptFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->receiptFiles as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function test_atomic_bilingual_publish_full_http_qa_and_recovery_preserve_boundaries(): void
    {
        $this->seedTopics();
        $before = $this->publicState();
        $adapter = app(IqEqTopicPromotionAdapter::class);
        $context = $this->context();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $draft = $adapter->draftImport($context);
        self::assertSame($before, $this->publicState());
        self::assertSame(2, $draft['written_count']);
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $published = $adapter->publish($context);
        self::assertSame(2, $published['published_count']);
        $this->previous($context, 'cms_publication_receipt', $published);
        self::assertSame(2, $adapter->liveQa($context)['readback_count']);
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame($before, $this->publicState());
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame($before, $this->publicState());
        $this->expectExceptionMessage('iq_eq_topic_failed_sha_terminal');
        $adapter->preflight($context);
    }

    public function test_sql_failure_keeps_business_rows_and_retains_failed_sha_admission(): void
    {
        $this->seedTopics();
        $before = $this->publicState();
        $adapter = app(IqEqTopicPromotionAdapter::class);
        $context = $this->context();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->previous($context, 'cms_draft_import_receipt', $adapter->draftImport($context));
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'update "topic_profiles"')) {
                $armed = false;
                throw new RuntimeException('topic_sql_failure');
            }
        });
        try {
            $adapter->publish($context);
            self::fail('SQL failure must fail');
        } catch (RuntimeException $e) {
            self::assertSame('topic_sql_failure', $e->getMessage());
        }
        self::assertSame($before, $this->publicState());
        self::assertSame(1, ContentReleaseSnapshot::query()->where('pack_id', 'iq-eq-topic')->count());
        $this->expectExceptionMessage('iq_eq_topic_failed_sha_terminal');
        $adapter->preflight($context);
    }

    public function test_new_faq_draft_is_private_recovery_replays_and_corrective_sha_can_publish(): void
    {
        $this->seedTopics();
        TopicProfileSection::query()->where('section_key', 'faq')->delete();
        $adapter = app(IqEqTopicPromotionAdapter::class);
        $context = $this->context();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $draft = $adapter->draftImport($context);
        self::assertSame(2, TopicProfileSection::query()->where('section_key', 'faq')->where('is_enabled', false)->count());
        $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale=en')->assertOk()->assertJsonCount(0, 'answer_surface_v1.faq_blocks');
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $published = $adapter->publish($context);
        $adapter->rollback($context, $published['rollback_reference']);
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame(2, TopicProfileSection::query()->where('section_key', 'faq')->where('is_enabled', false)->count());
        $corrective = new PromotionContext($context->packageDirectory, $context->packageSha256, $context->lane, $context->subscope,
            str_repeat('f', 40), $context->executorReleaseSha256, $context->releasePolicySha256, '13', 1, $context->workflowSignature, 2, str_repeat('f', 64));
        $this->previous($corrective, 'content_promotion_preflight_receipt', $adapter->preflight($corrective));
        $this->previous($corrective, 'cms_draft_import_receipt', $adapter->draftImport($corrective));
        self::assertSame(2, $adapter->publish($corrective)['published_count']);
        $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale=en')->assertOk()->assertJsonCount(7, 'answer_surface_v1.faq_blocks');
        $this->expectExceptionMessage('iq_eq_topic_failed_sha_terminal');
        $adapter->preflight($context);
    }

    public function test_recovery_preserves_operator_withdrawal_and_unowned_jsonld_change(): void
    {
        $this->seedTopics();
        $adapter = app(IqEqTopicPromotionAdapter::class);
        $context = $this->context();
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->previous($context, 'cms_draft_import_receipt', $adapter->draftImport($context));
        $published = $adapter->publish($context);
        $profile = TopicProfile::query()->withoutGlobalScopes()->where('locale', 'en')->firstOrFail();
        $profile->forceFill(['is_public' => false, 'status' => 'draft'])->saveQuietly();
        $seo = $profile->seoMeta()->firstOrFail();
        $payload = $seo->jsonld_overrides_json;
        $payload['url'] = 'https://fermatmind.com/en/topics/iq-eq';
        $payload['image'] = 'https://example.invalid/new-image';
        $seo->forceFill(['jsonld_overrides_json' => $payload, 'og_image_url' => 'https://example.invalid/new-image'])->saveQuietly();
        $adapter->rollback($context, $published['rollback_reference']);
        $profile->refresh();
        self::assertFalse($profile->is_public);
        self::assertSame('draft', $profile->status);
        $seo->refresh();
        self::assertSame($payload, $seo->jsonld_overrides_json);
        self::assertSame('https://example.invalid/new-image', $seo->og_image_url);
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertFalse($profile->fresh()->is_public);
    }

    public function test_unpublished_dependency_rejects_topic_before_any_draft_write(): void
    {
        $this->seedTopics();
        $before = $this->publicState();
        \App\Models\Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('slug', 'what-is-iq-and-how-it-is-measured')->update(['is_public' => false]);
        try {
            app(IqEqTopicPromotionAdapter::class)->preflight($this->context());
            self::fail('A missing published dependency must refuse Topic publication.');
        } catch (\DomainException $exception) {
            self::assertSame('iq_eq_topic_required_article_unpublished', $exception->getMessage());
        }
        self::assertSame($before, $this->publicState());
        self::assertSame(0, \App\Models\TopicProfileRevision::query()->count());
    }

    private function seedTopics(): void
    {
        $this->seedArticles();
        $this->seedIqEntry();
        foreach (app(IqEqTopicPackage::class)->read(base_path(), IqEqTopicPackage::SHA256) as $row) {
            $profile = TopicProfile::query()->withoutGlobalScopes()->create([...$row['identity'], 'topic_code' => 'iq-eq', 'title' => 'Old topic', 'subtitle' => 'Old subtitle', 'excerpt' => 'Old excerpt', 'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'published_at' => now()->subMinute()]);
            TopicProfileSection::query()->create(['profile_id' => $profile->id, 'section_key' => 'overview', 'render_variant' => 'rich_text', 'body_md' => 'Old overview', 'body_html' => 'Old HTML', 'is_enabled' => true, 'sort_order' => 10]);
            TopicProfileSection::query()->create(['profile_id' => $profile->id, 'section_key' => 'faq', 'render_variant' => 'faq', 'payload_json' => ['items' => [['question' => 'Old question', 'answer' => 'Old answer']]], 'is_enabled' => true, 'sort_order' => 20]);
            TopicProfileSection::query()->create(['profile_id' => $profile->id, 'section_key' => 'why_it_matters', 'render_variant' => 'callout', 'body_md' => 'Protected callout', 'is_enabled' => true, 'sort_order' => 30]);
            foreach ($row['snapshot']['entry_excerpt_overrides'] as $entry) {
                TopicProfileEntry::query()->create(['profile_id' => $profile->id, ...array_intersect_key($entry, array_flip(['entry_type', 'group_key', 'target_key'])), 'excerpt_override' => 'Old entry', 'is_enabled' => true, 'sort_order' => 10]);
            }
            TopicProfileSeoMeta::query()->create(['profile_id' => $profile->id, 'seo_title' => 'Old SEO', 'seo_description' => 'Old description', 'canonical_url' => 'https://fermatmind.com/'.($row['identity']['locale'] === 'en' ? 'en' : 'zh').'/topics/iq-eq', 'robots' => 'noindex,follow', 'jsonld_overrides_json' => ['url' => 'https://fermatmind.com/']]);
        }
    }

    private function publicState(): array
    {
        $result = [];
        foreach (['topic_profiles', 'topic_profile_sections', 'topic_profile_entries', 'topic_profile_seo_meta'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                $values = (array) $row;
                unset($values['created_at'],$values['updated_at']);

                return $values;
            })->all();
        }

        return $result;
    }

    private function previous(PromotionContext $context, string $kind, array $result): void
    {
        $file = tempnam(sys_get_temp_dir(), 'iq-topic-test-receipt-');
        $this->receiptFiles[] = $file;
        $receipt = [
            'phase' => match ($kind) {
                'content_promotion_preflight_receipt' => 'preflight',
                'cms_draft_import_receipt' => 'draft-import',
                'cms_publication_receipt' => 'publish',
            },
            'adapter' => 'iq_eq_topic_v1', 'source_repository' => 'fermatmind/fap-api',
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
            'executor_release_sha256' => $context->executorReleaseSha256, 'idempotency_key' => $context->idempotencyKey,
            'receipt_kind' => $kind, 'result' => 'SUCCEEDED', 'lane' => $context->lane, 'subscope' => $context->subscope,
            'package_sha256' => $context->packageSha256, 'source_commit' => $context->sourceCommit,
            'release_policy_sha256' => $context->releasePolicySha256, 'expected_count' => 2,
            'target_state_sha256' => $result['target_state_sha256'], 'rollback_reference' => $result['rollback_reference'],
        ];
        $receipt['receipt_content_sha256'] = hash('sha256', \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($receipt));
        file_put_contents($file, json_encode($receipt, JSON_THROW_ON_ERROR));
        config(['content_promotion.execution.previous_receipt' => $file]);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(IqEqTopicPackage::PACKAGE), IqEqTopicPackage::SHA256, 'W3', IqEqTopicPromotionAdapter::SUBSCOPE, str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12', 1, str_repeat('d', 64), 2, str_repeat('e', 64));
    }
}
