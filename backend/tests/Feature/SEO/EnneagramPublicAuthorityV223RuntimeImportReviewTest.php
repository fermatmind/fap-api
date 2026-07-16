<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV205RevisionWorkspaceWriter;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV206RevisionPromoter;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV223ReviewEvidenceBinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class EnneagramPublicAuthorityV223RuntimeImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_DEPLOY_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_exact_candidate_import_and_private_review_bind_are_atomic_and_publicly_invisible(): void
    {
        $this->seedPublishedEstate();
        $publicBefore = $this->publicFingerprint();
        $workspacePlan = $this->workspace()->preflight($this->releaseReport());
        $workspace = $this->workspace()->write(
            $this->releaseReport(),
            (string) $workspacePlan['package_sha256'],
            (string) $workspacePlan['preflight_fingerprint'],
        );

        $this->assertSame(116, $workspace['revision_created_count']);
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
            ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW)
            ->count());
        $this->assertSame($publicBefore, $this->publicFingerprint());

        $register = $this->reviewRegister();
        $registerSha = $this->fingerprint($register);
        $plan = $this->binder()->preflight($this->releaseReport(), $register, $registerSha);
        $bound = $this->binder()->bind(
            $this->releaseReport(),
            $register,
            $registerSha,
            (string) $plan['package_sha256'],
            (string) $plan['preflight_fingerprint'],
        );

        $this->assertTrue($plan['ok']);
        $this->assertSame('PASS_EXACT_HUMAN_REVIEW_EVIDENCE_PREFLIGHT', $plan['status']);
        $this->assertSame(116, $plan['approved_count']);
        $this->assertSame(0, $plan['rejected_count']);
        $this->assertFalse($plan['writes_committed']);
        $this->assertArrayNotHasKey('targets', $plan);
        $this->assertSame('PASS_EXACT_HUMAN_REVIEW_EVIDENCE_BIND', $bound['status']);
        $this->assertSame(116, $bound['review_evidence_created_count']);
        $this->assertSame(116, $bound['workflow_transition_count']);
        $this->assertSame(0, $bound['public_reviewer_name_write_count']);
        $this->assertTrue($bound['public_fingerprint_unchanged']);
        $this->assertSame(116, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
            ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED)
            ->count());
        $this->assertSame($publicBefore, $this->publicFingerprint());

        PersonalityPublicContentAsset::query()->each(function (PersonalityPublicContentAsset $asset): void {
            $this->assertStringNotContainsString('Private Human Reviewer', json_encode(
                $asset->getAttributes(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
        });
        $targets = $this->promotionTargets();
        $promotionPlan = $this->promoter()->preflight($targets);
        $this->assertSame(116, $promotionPlan['target_count']);
        $hubRevision = PersonalityPublicContentAssetRevision::query()
            ->where('authority_asset_key', 'enneagram:hub:enneagram:en')
            ->firstOrFail();
        $expectedHub = $hubRevision->snapshot_json;
        $promoted = $this->promoter()->promote($targets, (string) $promotionPlan['preflight_fingerprint']);
        $this->assertSame(116, $promoted['promoted_count']);

        $public = $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale=en');
        $public->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.title', $expectedHub['title'])
            ->assertJsonPath('personality_public_content_asset_v1.summary', $expectedHub['summary'])
            ->assertJsonPath('personality_public_content_asset_v1.media.hero', null)
            ->assertJsonPath('personality_public_content_asset_v1.media.inline', [])
            ->assertJsonPath('personality_public_content_asset_v1.media.og', null)
            ->assertJsonPath('personality_public_content_asset_v1.schema', [])
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.eligible', true)
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null)
            ->assertJsonPath(
                'personality_public_content_asset_v2.editorial_authority.review_state',
                EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED,
            )
            ->assertJsonPath('personality_public_content_asset_v2.schema_eligible', true)
            ->assertJsonCount(count($expectedHub['internal_links_json']), 'personality_public_content_asset_v1.internal_links');
        $this->assertStringNotContainsString('Private Human Reviewer', (string) $public->getContent());
    }

    public function test_missing_rejected_duplicate_and_hash_drift_reviews_all_fail_with_zero_writes(): void
    {
        $this->seedImportedWorkspace();
        $valid = $this->reviewRegister();
        $cases = [];

        $missing = $valid;
        array_pop($missing['reviews']);
        $cases['missing'] = $missing;

        $rejected = $valid;
        $rejected['reviews'][0]['decision'] = 'rejected';
        $cases['rejected'] = $rejected;

        $duplicate = $valid;
        $duplicate['reviews'][115] = $duplicate['reviews'][0];
        $cases['duplicate'] = $duplicate;

        $hashDrift = $valid;
        $hashDrift['reviews'][0]['asset_sha256'] = str_repeat('0', 64);
        $cases['hash_drift'] = $hashDrift;

        $missingTimestamp = $valid;
        $missingTimestamp['reviews'][0]['reviewed_at'] = '';
        $cases['missing_timestamp'] = $missingTimestamp;

        foreach ($cases as $label => $register) {
            try {
                $this->binder()->preflight($this->releaseReport(), $register, $this->fingerprint($register));
                $this->fail('Expected fail-closed human-review preflight for '.$label.'.');
            } catch (RuntimeException) {
                $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count(), $label);
                $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
                    ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW)
                    ->count(), $label);
            }
        }
    }

    public function test_mid_bind_failure_rolls_back_all_private_evidence_and_workflow_transitions(): void
    {
        $this->seedImportedWorkspace();
        $register = $this->reviewRegister();
        $registerSha = $this->fingerprint($register);
        $plan = $this->binder()->preflight($this->releaseReport(), $register, $registerSha);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_enneagram_review_evidence_bind
BEFORE INSERT ON personality_public_content_asset_revision_reviews
WHEN NEW.authority_asset_key = 'enneagram:wing:8w9:zh-CN'
BEGIN
    SELECT RAISE(ABORT, 'forced review evidence bind failure');
END
SQL);

        try {
            $this->binder()->bind(
                $this->releaseReport(),
                $register,
                $registerSha,
                (string) $plan['package_sha256'],
                (string) $plan['preflight_fingerprint'],
            );
            $this->fail('Expected forced private review-evidence bind failure.');
        } catch (Throwable $throwable) {
            $this->assertStringContainsString('forced review evidence bind failure', $throwable->getMessage());
        }

        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
            ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW)
            ->count());
    }

    public function test_console_binder_preflight_is_redacted_and_unapproved_bind_writes_nothing(): void
    {
        $this->seedImportedWorkspace();
        $register = $this->reviewRegister();
        $path = storage_path('framework/testing/enneagram-authority-v2-private-review-register.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode($register, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $registerSha = (string) hash_file('sha256', $path);
        $plan = $this->binder()->preflight($this->releaseReport(), $register, $registerSha);

        $this->artisan('personality:enneagram-authority-v2-review-evidence-binder', [
            '--review-register' => $path,
            '--preflight' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"status": "PASS_EXACT_HUMAN_REVIEW_EVIDENCE_PREFLIGHT"')
            ->assertSuccessful();

        $this->artisan('personality:enneagram-authority-v2-review-evidence-binder', [
            '--review-register' => $path,
            '--bind' => true,
            '--confirm-package-sha256' => (string) $plan['package_sha256'],
            '--confirm-review-register-sha256' => $registerSha,
            '--confirm-preflight-fingerprint' => (string) $plan['preflight_fingerprint'],
            '--confirm-writer-deploy-sha' => self::TEST_DEPLOY_SHA,
            '--operator-approved' => 'not-authorized',
            '--allow-testing' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"status": "FAIL_CLOSED"')
            ->assertFailed();

        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
            ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW)
            ->count());
        @unlink($path);
    }

    private function workspace(): EnneagramPublicAuthorityV205RevisionWorkspaceWriter
    {
        return app(EnneagramPublicAuthorityV205RevisionWorkspaceWriter::class);
    }

    private function binder(): EnneagramPublicAuthorityV223ReviewEvidenceBinder
    {
        return app(EnneagramPublicAuthorityV223ReviewEvidenceBinder::class);
    }

    private function promoter(): EnneagramPublicAuthorityV206RevisionPromoter
    {
        return app(EnneagramPublicAuthorityV206RevisionPromoter::class);
    }

    private function seedImportedWorkspace(): void
    {
        $this->seedPublishedEstate();
        $plan = $this->workspace()->preflight($this->releaseReport());
        $this->workspace()->write(
            $this->releaseReport(),
            (string) $plan['package_sha256'],
            (string) $plan['preflight_fingerprint'],
        );
    }

    private function seedPublishedEstate(): void
    {
        foreach ($this->scorecard()['rows'] as $index => $row) {
            $path = (string) $row['path'];
            $slug = (string) preg_replace('#^/(?:en|zh)/personality/#', '', $path);
            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                'entity_type' => (string) $row['entity_type'],
                'entity_key' => (string) $row['code'],
                'slug' => $slug,
                'locale' => (string) $row['locale'],
                'title' => 'Published Enneagram authority '.($index + 1),
                'summary' => 'Existing backend-authoritative public content.',
                'content_sections_json' => [['key' => 'existing', 'body_md' => 'Existing public content.']],
                'seo_json' => ['title' => 'Published Enneagram authority '.($index + 1)],
                'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                'canonical_json' => ['path' => $path, 'url' => (string) $row['canonical']],
                'hreflang_json' => $row['hreflang'],
                'faq_json' => [],
                'media_json' => ['hero' => null, 'inline' => [], 'og' => null],
                'schema_json' => [],
                'method_boundary_json' => ['is_diagnostic' => false],
                'evidence_notes_json' => [],
                'authority_json' => ['source' => 'test-fixture', 'reviewer' => null],
                'internal_links_json' => [],
                'is_public' => true,
                'index_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                'review_state' => 'published_no_llms',
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'source_package' => 'enneagram-90-cms-v1',
                'source_hash' => hash('sha256', (string) $row['identity_key'].'|'.$row['locale']),
                'published_at' => '2026-07-01 00:00:00',
            ]);
        }
        $this->assertSame(116, PersonalityPublicContentAsset::query()->count());
    }

    /** @return array<string, mixed> */
    private function releaseReport(): array
    {
        return $this->loadJsonFixture('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/release-gate-report.json');
    }

    /** @return array<string, mixed> */
    private function scorecard(): array
    {
        return $this->loadJsonFixture('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json');
    }

    /** @return array<string, mixed> */
    private function reviewRegister(): array
    {
        $packageSha256 = (string) $this->releaseReport()['package_sha256'];
        $reviews = [];
        foreach ($this->releaseReport()['asset_records'] as $index => $record) {
            $reviews[] = [
                'asset_key' => (string) $record['asset_key'],
                'asset_sha256' => (string) $record['asset_sha256'],
                'reviewer_name' => 'Private Human Reviewer '.($index + 1),
                'reviewed_at' => '2026-07-15T12:00:00Z',
                'decision' => PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED,
                'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
            ];
        }

        return [
            'schema_version' => 'enneagram_public_authority_v2_private_review_register.v1',
            'package_sha256' => $packageSha256,
            'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
            'reviews' => $reviews,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function promotionTargets(): array
    {
        return PersonalityPublicContentAsset::query()
            ->orderBy('id')
            ->get()
            ->map(function (PersonalityPublicContentAsset $asset): array {
                $revision = PersonalityPublicContentAssetRevision::query()->findOrFail((int) $asset->working_revision_id);

                return [
                    'asset_id' => (int) $asset->id,
                    'asset_key' => (string) $revision->authority_asset_key,
                    'expected_current_published_revision_id' => null,
                    'expected_working_revision_id' => (int) $revision->id,
                    'expected_package_sha256' => (string) $revision->authority_package_sha256,
                    'expected_source_hash' => (string) $revision->source_hash,
                    'expected_public_fingerprint_before' => (string) $revision->public_runtime_fingerprint_before,
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function loadJsonFixture(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function publicFingerprint(): string
    {
        $rows = DB::table('personality_public_content_assets')
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $attributes = (array) $row;
                unset($attributes['working_revision_id']);

                return $attributes;
            })
            ->all();

        return $this->fingerprint($rows);
    }

    private function fingerprint(mixed $value): string
    {
        $value = $this->normalizeForHash($value);

        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $child): mixed => $this->normalizeForHash($child), $value);
    }
}
