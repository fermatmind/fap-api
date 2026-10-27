<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Services\Cms\MbtiEnglishVariantSectionEnrichmentService;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PublicContent53MbtiResultCopy;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PublicContent53MbtiResultCopyTest extends TestCase
{
    use RefreshDatabase;

    public static function environments(): array
    {
        return [['staging'], ['production']];
    }

    public function test_exact_package_and_native_factory_share_only_the_two_selected_fields(): void
    {
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
        $this->assertCount(1, $rows);
        $factory = new MbtiEnglishVariantSectionEnrichmentService;
        $built = $factory->build('INTJ-T', 'Architect', 'growth.motivators');
        $this->assertSame($rows[0]['snapshot'], ['body_md' => $built['body_md'], 'teaser' => $built['payload_json']['teaser']]);
        $this->assertTrue($built['payload_json']['is_premium']);
        $this->assertSame('premium_teaser', $built['render_variant']);
        $this->assertStringNotContainsString('energized by you', $built['body_md']);
        $this->assertStringContainsString('INTJ-A', $factory->build('INTJ-A', 'Architect', 'growth.motivators')['body_md']);
    }

    #[DataProvider('environments')]
    public function test_native_publish_idempotence_and_restore_preserve_other_copy_drafts_and_parents(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
        $before = $states->read($this->digest());
        $draft = PersonalityProfileVariantRevision::first()->getRawOriginal();
        $states->assertBaseline($rows);
        $writer = app(PublicContent53Writer::class);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before));
        $this->assertSame(1, $published['written_count']);
        $this->assertSame($rows[0]['snapshot'], $states->projection($rows[0]));
        $this->assertSame($before[$rows[0]['page_id']]['controls']['profile'], $published['state'][$rows[0]['page_id']]['controls']['profile']);
        $this->assertSame($before[$rows[0]['page_id']]['controls']['variants'], $published['state'][$rows[0]['page_id']]['controls']['variants']);
        $this->assertSame($draft, PersonalityProfileVariantRevision::find($draft['id'])->getRawOriginal());
        $this->assertSame(2, PersonalityProfileVariantRevision::count());
        $replayed = DB::transaction(fn () => $writer->publish($this->context(), $published['state']));
        $this->assertSame(0, $replayed['written_count']);
        $this->assertSame(2, PersonalityProfileVariantRevision::count());
        DB::transaction(fn () => $writer->restore($this->context(), $before, $published['state']));
        $this->assertSame($rows[0]['before'], $states->projection($rows[0]));
        $this->assertSame(3, PersonalityProfileVariantRevision::count());
        $restored = $states->read($this->digest());
        $this->assertSame($before[$rows[0]['page_id']]['controls']['sections'], $restored[$rows[0]['page_id']]['controls']['sections']);
        $this->assertSame($draft, PersonalityProfileVariantRevision::find($draft['id'])->getRawOriginal());
        $this->assertDatabaseCount('attempts', 0);
    }

    public function test_stale_native_state_cannot_overwrite_an_operator_change(): void
    {
        $this->seedTargets();
        $before = app(PublicContent53State::class)->read($this->digest());
        DB::table('personality_profile_variant_sections')->where('section_key', 'overview')->update(['body_md' => 'Concurrent operator copy.']);
        $current = DB::table('personality_profile_variant_sections')->orderBy('id')->get()->all();
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            $this->fail('A changed native CAS boundary must reject publication.');
        } catch (DomainException $error) {
            $this->assertSame('public_content_53_native_prestate_drift', $error->getMessage());
        }
        $this->assertEquals($current, DB::table('personality_profile_variant_sections')->orderBy('id')->get()->all());
        $this->assertSame(1, PersonalityProfileVariantRevision::count());
    }

    public function test_revision_failure_rolls_back_both_copy_fields(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $dispatcher = PersonalityProfileVariantRevision::getEventDispatcher();
        PersonalityProfileVariantRevision::setEventDispatcher(clone $dispatcher);
        PersonalityProfileVariantRevision::creating(static function (): void {
            throw new RuntimeException('Injected native revision failure');
        });
        try {
            DB::transaction(fn () => app(PublicContent53Writer::class)->publish($this->context(), $before));
            $this->fail('The injected native revision failure must escape.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected native revision failure', $error->getMessage());
        } finally {
            PersonalityProfileVariantRevision::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $states->read($this->digest()));
    }

    public function test_disabled_section_is_a_hold_and_rollback_cannot_clobber_new_content(): void
    {
        $this->seedTargets();
        $states = app(PublicContent53State::class);
        $before = $states->read($this->digest());
        $writer = app(PublicContent53Writer::class);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before));
        DB::table('personality_profile_variant_sections')->where('id', $before['MBTI-EN-INTJ-T-MOTIVATORS']['values']['id'])->update(['body_md' => 'A newer operator edit.']);
        try {
            DB::transaction(fn () => $writer->restore($this->context(), $before, $published['state']));
            $this->fail('Rollback must preserve a newer operator edit.');
        } catch (DomainException $error) {
            $this->assertSame('public_content_53_rollback_concurrent_state', $error->getMessage());
        }
        DB::table('personality_profile_variant_sections')->where('section_key', 'growth.motivators')->update(['is_enabled' => false]);
        $this->expectExceptionMessage('mbti_result_copy_section_hold');
        $states->read($this->digest());
    }

    public function test_foreign_scope_and_extra_payload_fields_fail_before_native_writes(): void
    {
        $file = base_path(PublicContent53Package::PACKAGE.'/mbti-result-copy.json');
        $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        foreach (['org', 'locale', 'type', 'extra'] as $change) {
            $changed = $data;
            match ($change) {
                'org' => $changed['candidates'][0]['identity']['org_id'] = 17,
                'locale' => $changed['candidates'][0]['identity']['locale'] = 'zh-CN',
                'type' => $changed['candidates'][0]['identity']['slug'] = 'intj-a',
                'extra' => $changed['candidates'][0]['snapshot']['is_premium'] = false,
            };
            $bytes = json_encode($changed, JSON_THROW_ON_ERROR);
            $review = ['schema' => 'fermatmind.mbti_english_result_copy.review.v1', 'candidate_sha256' => hash('sha256', $bytes), 'verdict' => 'PASS', 'pages' => ['MBTI-EN-INTJ-T-MOTIVATORS' => 'PASS']];
            try {
                PublicContent53MbtiResultCopy::package($bytes, json_encode($review, JSON_THROW_ON_ERROR));
                $this->fail('Unsupported scope must not become a native candidate.');
            } catch (DomainException $error) {
                $this->assertStringStartsWith('mbti_result_copy_', $error->getMessage());
            }
        }
    }

    private function digest(): string
    {
        $chain = "fermatmind.mbti_english_result_copy.v1\n";
        foreach (['mbti-result-copy.json', 'mbti-result-copy-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', base_path(PublicContent53Package::PACKAGE.'/'.$name))."\n";
        }

        return hash('sha256', $chain);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(PublicContent53Package::PACKAGE), $this->digest(), 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 1, str_repeat('e', 64));
    }

    private function seedTargets(): void
    {
        $rows = app(PublicContent53Package::class)->read(base_path(), $this->digest())['candidates'];
        $profile = PersonalityProfile::create(['org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => 'INTJ', 'canonical_type_code' => 'INTJ',
            'slug' => 'intj', 'locale' => 'en', 'title' => 'INTJ', 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2]);
        foreach (['A', 'T'] as $letter) {
            $variant = PersonalityProfileVariant::create(['org_id' => 0, 'personality_profile_id' => $profile->id, 'canonical_type_code' => 'INTJ',
                'variant_code' => $letter, 'runtime_type_code' => 'INTJ-'.$letter, 'is_published' => true]);
            foreach (['overview', 'growth.motivators'] as $key) {
                $copy = $rows[0]['before']['body_md'];
                PersonalityProfileVariantSection::create(['org_id' => 0, 'personality_profile_variant_id' => $variant->id, 'section_key' => $key,
                    'render_variant' => $key === 'growth.motivators' ? 'premium_teaser' : 'rich_text', 'body_md' => $key === 'growth.motivators' && $letter === 'T' ? $copy : 'Keep '.$letter.' '.$key,
                    'body_html' => '<p>Keep existing HTML.</p>', 'payload_json' => ['teaser' => $copy, 'is_premium' => true, 'future' => ['keep' => [4, 1, 7]]], 'sort_order' => 120, 'is_enabled' => true]);
            }
            if ($letter === 'T') {
                PersonalityProfileVariantRevision::create(['personality_profile_variant_id' => $variant->id, 'revision_no' => 1,
                    'snapshot_json' => ['draft' => 'Keep this existing separate draft.'], 'note' => 'Operator draft', 'created_at' => now()]);
            }
        }
    }
}
