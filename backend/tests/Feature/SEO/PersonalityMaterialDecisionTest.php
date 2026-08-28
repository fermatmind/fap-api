<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\ContentMaterialDecision;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\Result;
use App\Services\Cms\PersonalityMaterialDecisionService;
use App\Services\Cms\PersonalityReviewAttestationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class PersonalityMaterialDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_mbti_public_revision_and_review_are_bound_while_review_time_is_non_material(): void
    {
        CarbonImmutable::setTestNow('2026-08-28T04:00:00Z');
        [$profile, $seo] = $this->profile('en', 'intj');
        $firstRevision = $this->revision($profile, 1, str_repeat('a', 64));
        [$firstReview, $firstIdentity] = $this->review(1, str_repeat('a', 64));
        DB::transaction(fn () => app(PersonalityMaterialDecisionService::class)->recordMbti(
            ['kind' => 'mbti_profile', 'model' => $profile, 'seo' => $seo],
            $firstRevision,
            $firstReview,
            $firstIdentity,
            str_repeat('a', 64),
            now(),
        ));
        $initial = ContentMaterialDecision::query()->sole();

        CarbonImmutable::setTestNow('2026-08-30T04:00:00Z');
        $secondRevision = $this->revision($profile, 2, str_repeat('b', 64));
        [$secondReview, $secondIdentity] = $this->review(2, str_repeat('b', 64));
        DB::transaction(fn () => app(PersonalityMaterialDecisionService::class)->recordMbti(
            ['kind' => 'mbti_profile', 'model' => $profile->fresh(), 'seo' => $seo->fresh()],
            $secondRevision,
            $secondReview,
            $secondIdentity,
            str_repeat('b', 64),
            now(),
        ));
        $unchanged = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('unchanged_republish', $unchanged->decision_code);
        self::assertFalse((bool) $unchanged->material_changed);
        self::assertSame($initial->material_fingerprint, $unchanged->material_fingerprint);
        self::assertSame($initial->material_changed_at?->toISOString(), $unchanged->material_changed_at?->toISOString());
        self::assertSame((string) $secondRevision->id, $unchanged->authority_revision);
        self::assertStringStartsWith('review_attestation_target:', (string) $unchanged->evidence_ref);

        $seo->forceFill(['seo_title' => 'Changed public SEO title'])->saveQuietly();
        $thirdRevision = $this->revision($profile, 3, str_repeat('c', 64));
        [$thirdReview, $thirdIdentity] = $this->review(3, str_repeat('c', 64));
        DB::transaction(fn () => app(PersonalityMaterialDecisionService::class)->recordMbti(
            ['kind' => 'mbti_profile', 'model' => $profile->fresh(), 'seo' => $seo->fresh()],
            $thirdRevision,
            $thirdReview,
            $thirdIdentity,
            str_repeat('c', 64),
            now(),
        ));
        $changed = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertTrue((bool) $changed->material_changed);
        self::assertSame('material_change', $changed->decision_code);
        self::assertNotSame($initial->material_fingerprint, $changed->material_fingerprint);
        self::assertSame('2026-08-30T04:00:00.000000Z', $changed->material_changed_at?->toISOString());
    }

    public function test_private_result_cannot_enter_personality_material_decision_or_storage(): void
    {
        [$profile, $seo] = $this->profile('zh-CN', 'infp');
        $revision = $this->revision($profile, 1, str_repeat('d', 64));
        [$review, $identity] = $this->review(4, str_repeat('d', 64));
        $private = new Result;
        $private->forceFill(['id' => 99]);

        try {
            DB::transaction(fn () => app(PersonalityMaterialDecisionService::class)->recordMbti(
                ['kind' => 'mbti_profile', 'model' => $private, 'seo' => $seo],
                $revision,
                $review,
                $identity,
                str_repeat('d', 64),
                now(),
            ));
            self::fail('Private result authority must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unsupported MBTI public material authority.', $exception->getMessage());
        }
        self::assertSame(0, ContentMaterialDecision::query()->count());
    }

    public function test_backend_locale_is_preserved_while_zh_public_identity_uses_the_existing_route_segment(): void
    {
        [$profile, $seo] = $this->profile('zh-CN', 'enfp');
        $revision = $this->revision($profile, 1, str_repeat('e', 64));
        [$review, $identity] = $this->review(5, str_repeat('e', 64));
        DB::transaction(fn () => app(PersonalityMaterialDecisionService::class)->recordMbti(
            ['kind' => 'mbti_profile', 'model' => $profile, 'seo' => $seo],
            $revision,
            $review,
            $identity,
            str_repeat('e', 64),
            now(),
        ));

        $decision = ContentMaterialDecision::query()->sole();
        self::assertSame('zh-CN', $decision->locale);
        self::assertSame('/zh/personality/enfp', $decision->public_identity);
    }

    /** @return array{PersonalityProfile,PersonalityProfileSeoMeta} */
    private function profile(string $locale, string $slug): array
    {
        $profile = PersonalityProfile::unguarded(fn (): PersonalityProfile => PersonalityProfile::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => strtoupper($slug),
            'canonical_type_code' => strtoupper($slug), 'slug' => $slug, 'locale' => $locale,
            'title' => strtoupper($slug).' profile', 'hero_summary_md' => 'Stable public summary.',
            'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            'published_at' => now(), 'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]));
        PersonalityProfileSection::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'profile_id' => $profile->id, 'section_key' => 'strengths',
            'title' => 'Strengths', 'render_variant' => 'rich_text', 'body_md' => 'Public section.',
            'payload_json' => [], 'sort_order' => 1, 'is_enabled' => true,
        ]);
        $seo = PersonalityProfileSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'profile_id' => $profile->id, 'seo_title' => 'Stable SEO title',
            'seo_description' => 'Stable SEO description', 'canonical_url' => '/'.$locale.'/personality/'.$slug,
            'robots' => 'index,follow', 'jsonld_overrides_json' => [],
        ]);

        return [$profile, $seo];
    }

    private function revision(PersonalityProfile $profile, int $number, string $sourceHash): PersonalityProfileRevision
    {
        return PersonalityProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => $number,
            'snapshot_json' => ['public_package' => ['source_hash' => $sourceHash]],
            'note' => 'reviewed-public-revision-'.$number,
            'created_by_admin_user_id' => 1,
            'created_at' => now(),
        ]);
    }

    /** @return array{\App\Models\ReviewAttestation,string} */
    private function review(int $number, string $sha256): array
    {
        $target = ['identity' => 'test-mbti-public-revision-'.$number, 'sha256' => $sha256];
        $review = app(PersonalityReviewAttestationService::class)->bindOrCreateApproved(
            null,
            'mbti_approval_batch',
            'test_exact_public_revision',
            'test-mbti-'.$number,
            [$target],
            1,
            str_repeat(dechex(($number % 15) + 1), 64),
        );

        return [$review, 'mbti_approval_batch:'.$target['identity']];
    }
}
