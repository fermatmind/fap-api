<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Services\Cms\MbtiEnglishVariantSectionEnrichmentService;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PublicContent53MbtiResultCopy;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PublicContent53MbtiFourLeafCopyTest extends TestCase
{
    use RefreshDatabase;

    public static function environments(): array
    {
        return [['staging'], ['production']];
    }

    public function test_factory_matches_three_owned_fields_and_keeps_other_types_and_motivators(): void
    {
        $row = $this->row();
        $factory = new MbtiEnglishVariantSectionEnrichmentService;
        $this->assertSame($row['snapshot']['trait_overview'], $factory->build('INTJ-T', 'Architect', 'trait_overview')['body_md']);
        $this->assertSame($row['snapshot']['career_advantage'], $factory->build('INTJ-T', 'Architect', 'career.advantages')['payload_json']['items'][1]['body']);
        $this->assertSame($row['snapshot']['growth_weakness'], $factory->build('INTJ-T', 'Architect', 'growth.weaknesses')['payload_json']['items'][0]['body']);
        $this->assertNotContains('faq', $factory->sectionKeys());
        foreach ($factory->supportedRuntimeTypeCodes() as $type) {
            if ($type === 'INTJ-T') {
                continue;
            }
            $this->assertStringContainsString($type, $factory->build($type, 'Personality', 'trait_overview')['body_md']);
            $this->assertStringStartsWith('Check whether ', $factory->build($type, 'Personality', 'career.advantages')['payload_json']['items'][1]['body']);
        }
        $legacy = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/mbti-result-copy.json')), true, 64, JSON_THROW_ON_ERROR)['candidates'][0];
        $this->assertSame($legacy['snapshot']['body_md'], $factory->build('INTJ-T', 'Architect', 'growth.motivators')['body_md']);
    }

    #[DataProvider('environments')]
    public function test_publish_replay_restore_and_new_owner_republish_keep_native_controls_and_drafts(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $row = $this->row();
        $before = $states->read($this->digest());
        $draft = PersonalityProfileVariantRevision::first()->getRawOriginal();
        $states->assertBaseline([$row]);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before));
        $this->assertSame(1, $published['written_count']);
        $this->assertSame($row['snapshot'], $states->projection($row));
        $this->assertSame(0, DB::transaction(fn () => $writer->publish($this->context(), $published['state']))['written_count']);
        $this->assertSame(2, PersonalityProfileVariantRevision::count());
        DB::transaction(fn () => $writer->restore($this->context(), $before, $published['state']));
        $this->assertSame($row['before'], $states->projection($row));
        $restored = $states->read($this->digest());
        $this->assertSame($before[$row['page_id']]['controls']['sections'], $restored[$row['page_id']]['controls']['sections']);
        $next = DB::transaction(fn () => $writer->publish($this->context('f'), $restored));
        $this->assertSame($row['snapshot'], $states->projection($row));
        $this->assertSame(1, $next['written_count']);
        $this->assertSame(4, PersonalityProfileVariantRevision::count());
        $this->assertSame($draft, PersonalityProfileVariantRevision::findOrFail($draft['id'])->getRawOriginal());
        foreach (['profile', 'variants'] as $key) {
            $this->assertSame($before[$row['page_id']]['controls'][$key], $next['state'][$row['page_id']]['controls'][$key]);
        }
        $this->assertDatabaseCount('attempts', 0);
    }

    public function test_revision_failure_rolls_back_all_four_leaves(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $dispatcher = PersonalityProfileVariantRevision::getEventDispatcher();
        PersonalityProfileVariantRevision::setEventDispatcher(clone $dispatcher);
        PersonalityProfileVariantRevision::creating(static function (): void {
            throw new RuntimeException('Injected four leaf revision failure');
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            $this->fail('Must roll back all leaves.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected four leaf revision failure', $error->getMessage());
        } finally {
            PersonalityProfileVariantRevision::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $states->read($this->digest()));
    }

    public static function concurrentFields(): array
    {
        return [['trait_overview', 'body_md'], ['career.advantages', 'body_html'], ['growth.motivators', 'body_md'], ['faq', 'payload_json']];
    }

    #[DataProvider('concurrentFields')]
    public function test_stale_prestate_cannot_overwrite_target_or_unowned_operator_changes(string $section, string $field): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        DB::table('personality_profile_variant_sections')->where('section_key', $section)->update([$field => $field === 'payload_json' ? '{"items":[],"operator":"new"}' : 'Concurrent operator text']);
        $changed = DB::table('personality_profile_variant_sections')->orderBy('id')->get()->all();
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            $this->fail('Must reject stale state.');
        } catch (DomainException $error) {
            $this->assertContains($error->getMessage(), ['public_content_53_native_prestate_drift', 'mbti_result_copy_faq_identity_drift']);
        }
        $this->assertEquals($changed, DB::table('personality_profile_variant_sections')->orderBy('id')->get()->all());
        $this->assertSame(1, PersonalityProfileVariantRevision::count());
    }

    public function test_restore_preserves_newer_writer(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($this->digest());
        $after = DB::transaction(fn () => $writer->publish($this->context(), $before));
        DB::table('personality_profile_variant_sections')->where('section_key', 'trait_overview')->update(['body_md' => 'Newer writer']);
        $this->expectExceptionMessage('public_content_53_rollback_concurrent_state');
        DB::transaction(fn () => $writer->restore($this->context(), $before, $after['state']));
    }

    public function test_faq_identity_is_more_than_its_array_position(): void
    {
        $this->seedTargets();
        $variant = PersonalityProfileVariant::where('runtime_type_code', 'INTJ-T')->firstOrFail();
        $section = PersonalityProfileVariantSection::where('personality_profile_variant_id', $variant->id)->where('section_key', 'faq')->firstOrFail();
        $payload = $section->payload_json;
        $payload['items'][5]['intent'] = 'different-question';
        $section->update(['payload_json' => $payload]);
        $this->expectExceptionMessage('mbti_result_copy_faq_identity_drift');
        app(PublicContent53State::class)->read($this->digest());
    }

    public function test_package_rejects_foreign_identity_and_extra_or_missing_fields(): void
    {
        $data = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/mbti-four-leaf-copy.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach (['org', 'locale', 'type', 'section', 'faq', 'extra', 'missing'] as $change) {
            $changed = $data;
            match ($change) {
                'org' => $changed['candidates'][0]['identity']['org_id'] = 7,
                'locale' => $changed['candidates'][0]['identity']['locale'] = 'zh-CN',
                'type' => $changed['candidates'][0]['identity']['slug'] = 'intj-a',
                'section' => $changed['candidates'][0]['source_identity']['section_keys'][] = 'growth.motivators',
                'faq' => $changed['candidates'][0]['source_identity']['faq_question'] = 'Another question?',
                'extra' => $changed['candidates'][0]['snapshot']['entitlement'] = 'unlocked',
                'missing' => $changed['candidates'][0]['snapshot']['stress_answer'] = '',
            };
            $bytes = json_encode($changed, JSON_THROW_ON_ERROR);
            $review = ['schema' => 'fermatmind.mbti_english_four_leaf_copy.review.v1', 'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS', 'pages' => [PublicContent53MbtiResultCopy::FOUR_LEAF_PAGE => 'PASS']];
            try {
                PublicContent53MbtiResultCopy::fourLeafPackage($bytes, json_encode($review, JSON_THROW_ON_ERROR));
                $this->fail('Must reject widened scope.');
            } catch (DomainException $error) {
                $this->assertStringStartsWith('mbti_result_copy_', $error->getMessage());
            }
        }
    }

    public function test_existing_four_phase_service_and_canonical_json_rollback(): void
    {
        $this->seedTargets();
        config(['ops.content_release_observability.hmac_revalidation_url' => 'https://staging.fermatmind.com/api/content-release/revalidate', 'ops.content_release_observability.hmac_revalidation_secret' => 'test-only-secret']);
        Http::preventStrayRequests();
        $directory = sys_get_temp_dir().'/mbti-four-leaf-phases-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $previous = '';
        $reference = '';
        try {
            foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
                config(['content_promotion.execution.previous_receipt' => $previous]);
                $path = $directory.'/'.$phase.'.json';
                $result = app(ExactPackagePromotionService::class)->execute($this->context(), $phase, $path);
                $this->assertSame('SUCCEEDED', $result['receipt']['result']);
                $this->assertSame(1, $result['receipt']['readback_count']);
                if ($phase === 'publish') {
                    $reference = $result['receipt']['rollback_reference'];
                } $previous = $path;
            }
            $snapshot = \App\Models\ContentReleaseSnapshot::findOrFail((int) substr($reference, strlen('content-release-snapshot:')));
            $snapshot->meta_json = json_decode(PromotionContextFactory::canonicalJson($snapshot->meta_json), true, 512, JSON_THROW_ON_ERROR);
            $snapshot->save();
            app(\App\Services\ContentPromotion\Adapters\PublicContent53PromotionAdapter::class)->rollback($this->context(), $reference);
            $this->assertSame($this->row()['before'], $states->projection($this->row()));
            $this->assertSame($before[$this->row()['page_id']]['controls']['sections'], $states->read($this->digest())[$this->row()['page_id']]['controls']['sections']);
            Http::assertNothingSent();
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }

    private function row(): array
    {
        return app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'][0];
    }

    private function digest(): string
    {
        $chain = "fermatmind.mbti_english_four_leaf_copy.v1\n";
        foreach (['mbti-four-leaf-copy.json', 'mbti-four-leaf-copy-review.json'] as $file) {
            $chain .= $file."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$file))."\n";
        }

        return hash('sha256', $chain);
    }

    private function context(string $source = 'a'): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->digest(), 'W3', 'PUBLIC-CONTENT-53', str_repeat($source, 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 1, str_repeat('e', 64));
    }

    private function seedTargets(): void
    {
        $row = $this->row();
        $profile = PersonalityProfile::create(['org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => 'INTJ', 'canonical_type_code' => 'INTJ', 'slug' => 'intj', 'locale' => 'en', 'title' => 'INTJ', 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2]);
        foreach (['A', 'T'] as $letter) {
            $variant = PersonalityProfileVariant::create(['org_id' => 0, 'personality_profile_id' => $profile->id, 'canonical_type_code' => 'INTJ', 'variant_code' => $letter, 'runtime_type_code' => 'INTJ-'.$letter, 'is_published' => true]);
            foreach (['trait_overview', 'career.advantages', 'growth.weaknesses', 'faq', 'growth.motivators'] as $key) {
                $payload = ['future' => ['keep' => [4, 1, 7]], 'items' => array_fill(0, 7, ['body' => 'Keep body', 'question' => 'Keep question', 'intent' => 'keep', 'answer' => 'Keep answer'])];
                $payload['items'][1]['body'] = $row['before']['career_advantage'];
                $payload['items'][0]['body'] = $row['before']['growth_weakness'];
                $payload['items'][5] = ['question' => 'What stresses INTJ-T?', 'intent' => 'stress', 'answer' => $row['before']['stress_answer'], 'claim_boundary' => 'Pattern description only.'];
                PersonalityProfileVariantSection::create(['org_id' => 0, 'personality_profile_variant_id' => $variant->id, 'section_key' => $key, 'render_variant' => match ($key) {
                    'trait_overview' => 'trait_dimension_grid','faq' => 'faq','growth.motivators' => 'premium_teaser',default => 'bullets'
                }, 'body_md' => $letter === 'T' && $key === 'trait_overview' ? $row['before']['trait_overview'] : 'Keep '.$letter.' '.$key, 'body_html' => '<p>Keep native HTML.</p>', 'payload_json' => $payload, 'sort_order' => 100, 'is_enabled' => true]);
            }
            if ($letter === 'T') {
                PersonalityProfileVariantRevision::create(['personality_profile_variant_id' => $variant->id, 'revision_no' => 1, 'snapshot_json' => ['draft' => 'Keep separate operator draft.'], 'note' => 'Operator draft', 'created_at' => now()]);
            }
        }
    }
}
