<?php

declare(strict_types=1);

namespace Tests\Feature\ContentPages;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Services\ContentPages\ReviewedSciencePageDelivery;
use App\Services\ContentPages\ReviewedSciencePageRevision;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReviewedSciencePageRevisionTest extends TestCase
{
    use RefreshDatabase;

    private string $candidatePath;

    private array $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        $this->candidatePath = tempnam(sys_get_temp_dir(), 'science-revision-');
    }

    protected function tearDown(): void
    {
        @unlink($this->candidatePath);
        parent::tearDown();
    }

    public function test_source_and_translation_preserve_public_copy_until_separate_review_and_publish(): void
    {
        $source = $this->page('zh-CN', false);
        $translation = $this->page('en', true, $source);
        foreach ([$source, $translation] as $page) {
            $this->freeze($page);
            $original = $page->refresh()->getAttributes();
            $this->assertTrue($this->operation($page, 'stage')['ok']);
            $this->assertSame($original, $page->fresh()->getAttributes());
            $this->executePhase($page, 'stage');
            $this->assertSame('Original self-report guidance.', $page->fresh()->content_md);
            $this->assertNotSame($page->fresh()->published_revision_id, $page->fresh()->working_revision_id);
            $this->executePhase($page, 'review');
            $this->assertSame('Original self-report guidance.', $page->fresh()->content_md);
            $this->executePhase($page, 'publish');
            $page->refresh();
            $this->assertSame($this->candidate['content_md'], $page->content_md);
            $this->assertSame($this->candidate['faq_items'], $page->faq_items);
            foreach (['id', 'org_id', 'slug', 'locale', 'path', 'title', 'seo_title', 'seo_description', 'meta_description',
                'canonical_path', 'is_indexable', 'schema_enabled', 'published_at', 'source_content_id', 'translation_group_id'] as $field) {
                $this->assertSame($original[$field] ?? null, $page->getAttributes()[$field] ?? null, $field);
            }
            $this->assertSame($page->working_revision_id, $page->published_revision_id);
            $revisionCount = CmsTranslationRevision::query()->count();
            $again = $this->executePhase($page, 'publish');
            $this->assertFalse($again['writes_committed']);
            $this->assertSame($revisionCount, CmsTranslationRevision::query()->count());
            $this->getJson('/api/v0.5/content-pages/item-design-notes?locale='.$page->locale.'&org_id=0')
                ->assertOk()->assertJsonPath('page.content_md', $this->candidate['content_md'])
                ->assertJsonPath('page.is_indexable', $page->locale === 'en');
        }
    }

    public function test_publish_cannot_reuse_the_old_published_approval_for_a_new_draft(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $this->executePhase($page, 'stage');
        $result = $this->operation($page, 'publish');
        $this->assertFalse($result['ok']);
        $this->assertSame('existing_science_review_required', $result['errors'][0]['code']);
        $this->assertSame('Original self-report guidance.', $page->fresh()->content_md);
    }

    public function test_legacy_published_payload_can_fork_but_history_and_public_copy_remain_unchanged(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $old = $page->workingRevision;
        $legacy = ['title' => $page->title, 'content_md' => $page->content_md, 'content_html' => ''];
        $old->forceFill(['payload_json' => $legacy])->save();
        $before = $page->fresh()->getAttributes();
        $this->executePhase($page, 'stage');
        $draft = $page->fresh()->workingRevision;
        $this->assertNotSame($old->id, $draft->id);
        $this->assertSame($old->id, $page->fresh()->published_revision_id);
        $this->assertSame($legacy, $old->fresh()->payload_json);
        $this->assertSame($before['content_md'], $page->fresh()->content_md);
        $this->assertSame([], app(RowBackedRevisionWorkspace::class)->publishValidationErrors('content_page', $page->fresh(), $draft));
        $this->executePhase($page, 'review');
        $this->executePhase($page, 'publish');
        $this->assertSame($this->candidate['content_md'], $page->fresh()->content_md);
        $this->assertSame($legacy, $old->fresh()->payload_json);
    }

    public function test_legacy_compatibility_cannot_bypass_identity_payload_candidate_or_draft_checks(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $revision = $page->workingRevision;
        $original = $revision->getAttributes();
        $legacy = ['content_md' => $page->content_md];
        foreach (['content_type' => 'support_article', 'content_id' => 9999, 'org_id' => 999,
            'locale' => 'en', 'translation_group_id' => 'foreign-group'] as $field => $value) {
            $revision->forceFill(['payload_json' => $legacy, $field => $value])->save();
            $result = $this->operation($page->fresh(), 'stage');
            $this->assertSame('existing_science_revision_identity_invalid:'.$field, $result['errors'][0]['code']);
            $revision->setRawAttributes($original)->save();
        }
        $revision->forceFill(['payload_json' => 'not-an-object'])->save();
        $this->assertSame('existing_science_revision_payload_invalid', $this->operation($page->fresh(), 'stage')['errors'][0]['code']);
        $revision->forceFill(['payload_json' => $legacy])->save();
        $page->forceFill(['seo_title' => ''])->save();
        $this->assertSame('existing_science_candidate_payload_invalid', $this->operation($page->fresh(), 'stage')['errors'][0]['code']);
        $page->forceFill(['seo_title' => 'Item design'])->save();
        $this->executePhase($page, 'stage');
        $page->fresh()->workingRevision->forceFill(['payload_json' => $legacy])->save();
        foreach (['stage', 'review', 'publish'] as $phase) {
            $this->assertSame('existing_science_working_payload_invalid', $this->operation($page->fresh(), $phase)['errors'][0]['code']);
        }
        $this->assertSame('Original self-report guidance.', $page->fresh()->content_md);
        $this->assertSame(2, CmsTranslationRevision::query()->count());
    }

    public function test_binding_observation_is_readonly_and_distinguishes_topology_status_and_missing_parent(): void
    {
        $source = $this->page('zh-CN', false);
        $target = $this->page('en', true, $source);
        $source->forceFill(['translation_status' => 'published'])->saveQuietly();
        $before = [$source->fresh()->getAttributes(), $target->fresh()->getAttributes()];
        $history = CmsTranslationRevision::query()->get()->map->getAttributes()->all();
        $delivery = app(ReviewedSciencePageDelivery::class);
        $facts = $delivery->bindingDiagnostics();
        $this->assertSame('after_failure_recovery', $facts['capture']);
        $this->assertSame('source', $facts['pages']['zh-CN']['topology']);
        $this->assertSame('published', $facts['pages']['zh-CN']['translation_status']);
        $this->assertTrue($facts['pages']['en']['source_topology_valid']);
        $this->assertFalse($facts['pages']['en']['source_status_is_source']);
        $this->assertTrue($facts['pages']['en']['source_group_matches']);
        $this->assertSame($before, [$source->fresh()->getAttributes(), $target->fresh()->getAttributes()]);
        $this->assertSame($history, CmsTranslationRevision::query()->get()->map->getAttributes()->all());
        $source->forceFill(['translation_status' => 'source', 'source_locale' => 'en'])->saveQuietly();
        $facts = $delivery->bindingDiagnostics();
        $this->assertFalse($facts['pages']['en']['source_topology_valid']);
        $this->assertTrue($facts['pages']['en']['source_status_is_source']);
        $source->forceFill(['source_locale' => 'zh-CN'])->saveQuietly();
        $target->forceFill(['source_content_id' => null])->saveQuietly();
        $facts = $delivery->bindingDiagnostics();
        $this->assertSame('unlinked_translation', $facts['pages']['en']['topology']);
        $this->assertFalse($facts['pages']['en']['source_id_present']);
        $this->assertSame('not_evaluated', $facts['pages']['en']['source_group_matches']);
        foreach ($facts['pages'] as $row) {
            foreach ($row as $value) {
                $this->assertTrue(is_bool($value) || in_array($value, ['not_evaluated', 'source', 'published', 'unlinked_translation'], true));
            }
        }
    }

    public function test_state_changes_after_preflight_and_non_owner_mutations_are_refused(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $plan = $this->operation($page, 'stage');
        $page->forceFill(['summary' => 'Concurrent legitimate editorial update'])->save();
        $result = $this->operation($page, 'stage', true, $plan['state_sha256']);
        $this->assertSame('existing_science_preflight_state_changed', $result['errors'][0]['code']);
        $fresh = $this->operation($page, 'stage');
        $result = $this->operation($page, 'stage', true, $fresh['state_sha256'], 2);
        $this->assertSame('existing_science_owner_or_state_lock_missing', $result['errors'][0]['code']);
    }

    public function test_unrelated_working_revision_is_not_overwritten(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $workspace = app(RowBackedRevisionWorkspace::class);
        $payload = $workspace->adapter('content_page')->snapshotPayload($page);
        $payload['body_md'] = 'Another task draft';
        $workspace->saveWorkingDraft('content_page', $page, $payload, CmsTranslationRevision::STATUS_DRAFT);
        $result = $this->operation($page, 'stage');
        $this->assertSame('existing_science_foreign_working_draft', $result['errors'][0]['code']);
        $this->assertSame('Another task draft', $page->fresh()->workingRevision->payload_json['body_md']);
    }

    public function test_source_version_changes_block_an_already_staged_translation(): void
    {
        $source = $this->page('zh-CN', false);
        $page = $this->page('en', true, $source);
        $this->freeze($page);
        $this->executePhase($page, 'stage');
        $source->forceFill(['content_md' => 'Changed source instructions'])->save();
        $result = $this->operation($page, 'review');
        $this->assertSame('existing_science_translation_source_changed', $result['errors'][0]['code']);
    }

    public function test_unknown_fields_and_candidate_drift_cannot_change_visibility_or_identity(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $this->candidate['is_indexable'] = true;
        $this->writeCandidate();
        $result = $this->operation($page, 'stage');
        $this->assertSame('existing_science_candidate_fields_invalid', $result['errors'][0]['code']);
        $this->assertFalse($page->fresh()->is_indexable);
        $this->assertFalse(app(ReviewedSciencePageRevision::class)->run('zh-TW', ['item-design-notes'], 'stage',
            $this->candidatePath, hash_file('sha256', $this->candidatePath), false, '', 1)['ok']);
        $this->assertFalse(app(ReviewedSciencePageRevision::class)->run('zh-CN', ['terms'], 'stage',
            $this->candidatePath, hash_file('sha256', $this->candidatePath), false, '', 1)['ok']);
    }

    public function test_original_cohort_command_does_not_accept_a_single_science_key(): void
    {
        $code = Artisan::call('content-pages:publish-controlled', ['--scope' => 'science-zh', '--locale' => 'zh-CN',
            '--keys' => 'item-design-notes', '--dry-run' => true, '--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertContains('exact_scope_required', array_column($result['errors'], 'code'));
    }

    public function test_failed_native_publication_rolls_back_public_content_and_pointers(): void
    {
        $page = $this->page('zh-CN', false);
        $this->freeze($page);
        $this->executePhase($page, 'stage');
        $this->executePhase($page, 'review');
        $before = $page->fresh()->getAttributes();
        $body = $this->candidate['content_md'];
        $injected = false;
        DB::listen(static function ($event) use ($body, &$injected): void {
            if (! $injected && str_contains($event->sql, 'update "content_pages"') && in_array($body, $event->bindings, true)) {
                $injected = true;
                throw new \DomainException('synthetic_native_publication_failure');
            }
        });
        $plan = $this->operation($page, 'publish');
        $this->assertTrue($plan['ok']);
        $result = $this->operation($page, 'publish', true, $plan['state_sha256']);
        $this->assertTrue($injected);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['writes_committed']);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame(CmsTranslationRevision::STATUS_APPROVED, $page->fresh()->workingRevision->revision_status);
    }

    public function test_pair_publication_and_restore_preserve_the_original_public_rows(): void
    {
        [$source, $translation] = $this->frozenPair();
        $before = [$source->getAttributes(), $translation->getAttributes()];
        $service = app(ReviewedSciencePageDelivery::class);
        $this->assertSame(2, $service->publish($this->binding())['published_count']);
        $this->assertSame($source->fresh()->source_version_hash, $translation->fresh()->translated_from_version_hash);
        $this->assertSame('restored', $service->recover($this->binding())['recovery_status']);
        $this->assertSame($before, [$source->fresh()->getAttributes(), $translation->fresh()->getAttributes()]);
        $this->assertSame('not_required', $service->recover($this->binding())['recovery_status']);
        $this->assertGreaterThan(2, CmsTranslationRevision::query()->count());
        $next = [...$this->binding(), 'workflow_run_id' => '124'];
        $this->assertSame(2, $service->publish($next)['published_count']);
        $this->assertSame($source->fresh()->source_version_hash, $translation->fresh()->translated_from_version_hash);
        $this->assertSame('restored', $service->recover($next)['recovery_status']);
        $this->assertSame($before, [$source->fresh()->getAttributes(), $translation->fresh()->getAttributes()]);
    }

    public function test_pair_second_language_failure_rolls_back_both_languages(): void
    {
        [$source, $translation] = $this->frozenPair();
        $before = [$source->getAttributes(), $translation->getAttributes()];
        $package = json_decode(file_get_contents(base_path(ReviewedSciencePageDelivery::PACKAGE)), true);
        $body = collect($package['candidates'])->firstWhere('identity.locale', 'en')['content_md'];
        $injected = false;
        DB::listen(static function ($event) use ($body, &$injected): void {
            if (! $injected && str_contains($event->sql, 'update "content_pages"') && in_array($body, $event->bindings, true)) {
                $injected = true;
                throw new \DomainException('synthetic_second_language_failure');
            }
        });
        try {
            app(ReviewedSciencePageDelivery::class)->publish($this->binding());
            $this->fail('Expected pair failure');
        } catch (\DomainException $error) {
            $this->assertSame('synthetic_second_language_failure', $error->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertSame($before, [$source->fresh()->getAttributes(), $translation->fresh()->getAttributes()]);
        $this->assertSame('not_required', app(ReviewedSciencePageDelivery::class)->recover($this->binding())['recovery_status']);
    }

    public function test_legacy_source_status_is_preserved_until_native_publication_and_requires_fork_lineage(): void
    {
        $page = $this->page('zh-CN', false);
        $page->forceFill(['translation_status' => 'published'])->saveQuietly();
        $this->freeze($page);
        $old = $page->workingRevision;
        $old->forceFill(['payload_json' => ['content_md' => $page->content_md]])->save();
        $history = $old->fresh()->getAttributes();
        $this->executePhase($page, 'stage');
        $draft = $page->fresh()->workingRevision;
        $draft->forceFill(['supersedes_revision_id' => null])->save();
        $this->assertSame('existing_science_legacy_source_lineage_invalid', $this->operation($page->fresh(), 'review')['errors'][0]['code']);
        $draft->forceFill(['supersedes_revision_id' => $old->id])->save();
        $this->assertSame('published', $page->fresh()->translation_status);
        $this->executePhase($page->fresh(), 'review');
        $this->assertSame('published', $page->fresh()->translation_status);
        $this->executePhase($page->fresh(), 'publish');
        $this->assertSame('source', $page->fresh()->translation_status);
        $this->assertSame($history, $old->fresh()->getAttributes());
    }

    public function test_fixed_pair_uses_native_initial_link_and_restores_unlinked_legacy_state(): void
    {
        [$source, $target] = $this->legacyPair();
        $before = [$source->getAttributes(), $target->getAttributes()];
        $history = CmsTranslationRevision::query()->get()->map->getAttributes()->all();
        $service = app(ReviewedSciencePageDelivery::class);
        foreach ([$this->binding(), [...$this->binding(), 'workflow_run_id' => '124']] as $binding) {
            $this->assertSame(2, $service->publish($binding)['published_count']);
            $this->assertSame('source', $source->fresh()->translation_status);
            $this->assertSame($source->id, $target->fresh()->source_content_id);
            $this->assertSame($source->fresh()->source_version_hash, $target->fresh()->translated_from_version_hash);
            $this->assertDatabaseHas('audit_logs', ['action' => 'content_page_source_linked', 'target_id' => (string) $target->id]);
            foreach ($history as $attributes) {
                $this->assertSame($attributes, CmsTranslationRevision::query()->findOrFail($attributes['id'])->getAttributes());
            }
            $this->assertSame('restored', $service->recover($binding)['recovery_status']);
            $this->assertSame($before, [$source->fresh()->getAttributes(), $target->fresh()->getAttributes()]);
        }
    }

    #[DataProvider('legacyLinkRefusals')]
    public function test_native_initial_link_refusal_rolls_back_source_publication(string $case, string $code): void
    {
        [$source, $target] = $this->legacyPair();
        if ($case === 'group') {
            $target->forceFill(['translation_group_id' => 'foreign-group'])->saveQuietly();
            $target->workingRevision->forceFill(['translation_group_id' => 'foreign-group'])->save();
        } elseif ($case === 'provenance') {
            $target->forceFill(['translated_from_version_hash' => str_repeat('b', 64)])->saveQuietly();
            $target->workingRevision->forceFill(['translated_from_version_hash' => str_repeat('b', 64)])->save();
        } elseif ($case === 'draft') {
            $workspace = app(RowBackedRevisionWorkspace::class);
            $payload = $workspace->adapter('content_page')->snapshotPayload($target);
            $payload['body_md'] = 'Foreign private work';
            $workspace->saveWorkingDraft('content_page', $target, $payload, CmsTranslationRevision::STATUS_DRAFT);
            $target->refresh()->forceFill(['translation_status' => 'published'])->saveQuietly();
        } else {
            ContentPage::query()->create(['org_id' => 0, 'slug' => 'other-page', 'locale' => 'en', 'path' => '/other-page',
                'title' => 'Other page', 'translation_group_id' => $source->translation_group_id]);
        }
        $before = [$source->fresh()->getAttributes(), $target->fresh()->getAttributes()];
        $history = CmsTranslationRevision::query()->get()->map->getAttributes()->all();
        $auditCount = DB::table('audit_logs')->count();
        try {
            app(ReviewedSciencePageDelivery::class)->publish($this->binding());
            $this->fail('Expected native linkage refusal');
        } catch (\DomainException $error) {
            $this->assertSame('science_initial_link_refused:'.$code, $error->getMessage());
        }
        $this->assertSame($before, [$source->fresh()->getAttributes(), $target->fresh()->getAttributes()]);
        $this->assertSame($history, CmsTranslationRevision::query()->get()->map->getAttributes()->all());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public static function legacyLinkRefusals(): array
    {
        return [['group', 'group_pair_ambiguous'], ['provenance', 'target_link_or_provenance_drift'],
            ['draft', 'target_revision_pointer_drift'], ['third_member', 'group_pair_ambiguous']];
    }

    public function test_pair_recovery_refuses_a_later_editorial_change(): void
    {
        [$source] = $this->frozenPair();
        $service = app(ReviewedSciencePageDelivery::class);
        $service->publish($this->binding());
        $source->refresh()->forceFill(['summary' => 'Later editorial change'])->save();
        $this->expectExceptionMessage('science_delivery_restore_foreign_state');
        $service->recover($this->binding());
    }

    private function binding(): array
    {
        return ['source_commit' => str_repeat('a', 40), 'workflow_run_id' => '123', 'workflow_run_attempt' => 1,
            'environment' => 'production', 'package_sha256' => ReviewedSciencePageDelivery::SHA];
    }

    private function legacyPair(): array
    {
        [$source, $target] = $this->frozenPair();
        $source->forceFill(['translation_status' => 'published'])->saveQuietly();
        $target->forceFill(['source_content_id' => null, 'translated_from_version_hash' => null])->saveQuietly();
        $target->workingRevision->forceFill(['source_content_id' => null, 'translated_from_version_hash' => null])->save();
        foreach ([$source, $target] as $page) {
            $page->workingRevision->forceFill(['payload_json' => ['title' => $page->title, 'content_md' => $page->content_md]])->save();
        }

        return [$source->fresh(), $target->fresh()];
    }

    private function frozenPair(): array
    {
        $replacements = json_decode(<<<'JSON'
{
  "en": {
    "replace": [
      [
        "The process can be understood as identifying a construct to observe, then designing a set of items covering its different aspects. Self-report items collect users' descriptions of themselves in understandable situations; they do not read minds.\n",
        "The process can be understood as identifying a construct to observe, then designing a set of items covering its different aspects. Items collect users' self-descriptions in understandable situations; they do not read minds.\n"
      ],
      [
        "For self-report items about preferences, interests or traits, there is no ideal or universally correct answer. Describe your usual behaviour, rather than your ideal self or others' expectations.\n\nPerformance tasks work differently. In [FermatMind’s current IQ reasoning exercise](/en/tests/iq-test-intelligence-quotient-assessment), answers are scored as correct or incorrect. Solve those questions independently and follow the task instructions; advice about describing your usual state does not apply to solving a reasoning problem.\n",
        "There is no need to seek a ‘correct answer’. Answer based on your usual state, rather than your ideal self or others' expectations.\n"
      ],
      [
        "For self-report items about preferences, interests or traits, describe your usual state. Answering as your ideal self may make the result resemble a desired image rather than currently observable tendencies. For reasoning tasks, solve the problem instead of choosing a response that describes your personality.\n",
        "Answer based on your usual state. Answering as your ideal self may make the result resemble a desired image rather than currently observable tendencies.\n"
      ]
    ],
    "faq": "Answer based on your usual state. Answering as your ideal self may make the result resemble a desired image rather than currently observable tendencies."
  },
  "zh-CN": {
    "replace": [
      [
        "这个过程可以理解为：先确定想观察的构念，再设计一组题项来覆盖它的不同侧面。自陈题项不是为了读心，而是为了收集用户对可理解情境的自我描述。\n",
        "这个过程可以理解为：先确定想观察的构念，再设计一组题项来覆盖它的不同侧面。题项不是为了读心，而是为了收集用户对可理解情境的自我描述。\n"
      ],
      [
        "回答偏好、兴趣或特质类自陈题目时，没有理想或普遍正确的答案。应按自己通常的状态回答，而不是按理想中的自己或别人期待的样子回答。\n\n表现任务的作答方式不同。例如，[本站当前的IQ推理练习](/zh/tests/iq-test-intelligence-quotient-assessment)会区分答案正确与否。应独立解题并遵循题目要求；“描述平时状态”的自陈作答建议不适用于解推理题。\n",
        "用户作答时，不需要寻找“正确答案”。更合适的方式，是按自己通常的状态回答，而不是按理想中的自己或别人期待的样子回答。\n"
      ],
      [
        "对于偏好、兴趣或特质类自陈题目，建议按通常状态回答。按理想状态回答可能让结果更像目标形象，而不是当前可观察倾向。推理任务则应解答题目，而不是选择一个描述自己性格的选项。\n",
        "建议按通常状态回答。按理想状态回答可能让结果更像目标形象，而不是当前可观察倾向。\n"
      ]
    ],
    "faq": "建议按通常状态回答。按理想状态回答可能让结果更像目标形象，而不是当前可观察倾向。"
  }
}
JSON, true, flags: JSON_THROW_ON_ERROR);
        $source = $this->page('zh-CN', false);
        $translation = $this->page('en', true, $source);
        $package = json_decode(file_get_contents(base_path(ReviewedSciencePageDelivery::PACKAGE)), true);
        foreach ([$source, $translation] as $page) {
            $candidate = collect($package['candidates'])->firstWhere('identity.locale', $page->locale);
            $body = $candidate['content_md'];
            foreach ($replacements[$page->locale]['replace'] as [$new, $old]) {
                $body = str_replace($new, $old, $body);
            }
            $faq = $candidate['faq_items'];
            $faq[1]['answer'] = $replacements[$page->locale]['faq'];
            $page->forceFill(['content_md' => $body, 'faq_items' => $faq])->save();
            $workspace = app(RowBackedRevisionWorkspace::class);
            $page->workingRevision->forceFill(['payload_json' => $workspace->adapter('content_page')->snapshotPayload($page),
                'source_version_hash' => $page->source_version_hash])->save();
            $this->assertSame($candidate['before_body_sha256'], hash('sha256', $body));
            $this->assertSame($candidate['before_faq_sha256'], hash('sha256', PromotionContextFactory::canonicalJson($faq)));
        }
        $translation->forceFill(['translated_from_version_hash' => $source->fresh()->source_version_hash])->save();

        return [$source->fresh(), $translation->fresh()];
    }

    private function operation(ContentPage $page, string $phase, bool $execute = false, string $state = '', int $actor = 1): array
    {
        return app(ReviewedSciencePageRevision::class)->run($page->locale, ['item-design-notes'], $phase,
            $this->candidatePath, hash_file('sha256', $this->candidatePath), $execute, $state, $actor);
    }

    private function executePhase(ContentPage $page, string $phase): array
    {
        $plan = $this->operation($page, $phase);
        $this->assertTrue($plan['ok'], json_encode($plan));
        $result = $this->operation($page, $phase, true, $plan['state_sha256']);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function freeze(ContentPage $page): void
    {
        $this->candidate = ['identity' => ['org_id' => 0, 'slug' => 'item-design-notes', 'locale' => $page->locale],
            'before_body_sha256' => hash('sha256', $page->content_md),
            'before_faq_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($page->faq_items)),
            'content_md' => 'Self-report guidance differs from scored reasoning tasks.',
            'faq_items' => [['question' => 'How should I answer?', 'answer' => 'Use self-report or solve the task as instructed.']],
            'preserve_fields' => ['is_indexable', 'published_at']];
        $this->writeCandidate();
    }

    private function writeCandidate(): void
    {
        file_put_contents($this->candidatePath, json_encode(['candidates' => [$this->candidate]], JSON_THROW_ON_ERROR));
    }

    private function page(string $locale, bool $indexable, ?ContentPage $source = null): ContentPage
    {
        $page = ContentPage::query()->create(['org_id' => 0, 'slug' => 'item-design-notes', 'path' => '/item-design-notes',
            'locale' => $locale, 'title' => 'Item design', 'kind' => 'policy', 'page_type' => 'methodology',
            'template' => 'policy', 'animation_profile' => 'editorial', 'status' => 'published', 'is_public' => true,
            'is_indexable' => $indexable, 'review_state' => 'approved', 'published_at' => now()->subYear(),
            'content_md' => 'Original self-report guidance.', 'content_html' => '', 'headings_json' => [],
            'faq_items' => [['question' => 'How should I answer?', 'answer' => 'Describe yourself.']],
            'seo_title' => 'Item design', 'meta_description' => 'How item design works.', 'seo_description' => 'How item design works.',
            'canonical_path' => '/item-design-notes', 'schema_enabled' => false, 'publish_allowed' => true,
            'operator_approval_required' => true, 'operator_approved_at' => now()->subYear(), 'claim_gate_status' => 'passed',
            'forbidden_claims' => [], 'legal_review_required' => false, 'science_review_required' => false,
            'translation_group_id' => 'science-pair', 'source_locale' => $source?->locale ?? $locale,
            'source_content_id' => $source?->id, 'translated_from_version_hash' => $source?->source_version_hash,
            'translation_status' => $source ? 'published' : 'source']);
        app(RowBackedRevisionWorkspace::class)->ensureInitialRevision('content_page', $page, 1);

        return $page->refresh();
    }
}
