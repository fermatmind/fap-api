<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AdminUser;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Services\Cms\PersonalityPublicReadModelCache;
use App\Services\Cms\PersonalityReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PersonalityMbti64CmsInternalLinkPromoteCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DEPLOY_SHA = '1111111111111111111111111111111111111111';

    private const GRAPH_SHA = '9cb25be500cd75b1f62ed9b1c6c7f1886338248b6012d104d16971519059636c';

    private const COHORT_SHA = 'ed99662788d106d371bc14ca676df57edf860bb50bb2e62fa3e551f2bd4c7f23';

    private const CHECKPOINT112_INVENTORY_SHA = 'e18dd567a2826678f16fd06cd1de976a7831dd6ab505b75e213abf51ae257908';

    private const CHECKPOINT112_SECTION_SHA = 'b595b38be3fc50c9ee69cda1749644197207af13c6b90e5c1fd480370bff813d';

    private string $revisionPath;

    private ?string $previousRevision = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        Cache::setDefaultDriver('array');
        $this->revisionPath = base_path('../REVISION');
        $this->previousRevision = File::isFile($this->revisionPath)
            ? (string) File::get($this->revisionPath)
            : null;
        File::put($this->revisionPath, self::DEPLOY_SHA.PHP_EOL);
        AdminUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'test-password',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->previousRevision === null) {
            File::delete($this->revisionPath);
        } else {
            File::put($this->revisionPath, $this->previousRevision);
        }

        parent::tearDown();
    }

    public function test_dry_run_binds_exact_revision_and_rollback_inventory_without_writes(): void
    {
        $fixture = $this->seedRevisionCohort();

        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge(['--dry-run' => true], $fixture['options'])
        );
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame(32, $payload['row_count']);
        $this->assertSame(64, $payload['edge_count']);
        $this->assertSame($fixture['revision_identity_sha256'], $payload['revision_identity_sha256']);
        $this->assertSame($fixture['rollback_markers_sha256'], $payload['rollback_markers_sha256']);
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, ReviewAttestation::query()->count());
    }

    public function test_review_binding_is_separate_exact_and_idempotent(): void
    {
        $reviewed = $this->bindReview();

        $this->assertSame(0, $reviewed['exit']);
        $this->assertSame('bound_exact_32_target_review', $reviewed['payload']['action']);
        $this->assertSame(1, ReviewAttestation::query()->count());
        $this->assertSame(32, ReviewAttestationTargetEvidence::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());

        $second = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge([
                '--bind-review' => true,
                '--review-write-authorized' => true,
                '--attestation' => $reviewed['attestation_path'],
                '--actor-admin-user-id' => 1,
            ], $reviewed['options'])
        );
        $payload = $this->jsonOutput();

        $this->assertSame(0, $second);
        $this->assertSame('skipped_existing_exact_review', $payload['action']);
        $this->assertSame(1, ReviewAttestation::query()->count());
        $this->assertSame(32, ReviewAttestationTargetEvidence::query()->count());
    }

    public function test_unreviewed_or_stale_review_fails_before_section_writes(): void
    {
        $fixture = $this->seedRevisionCohort();
        $options = array_merge($fixture['options'], [
            '--expected-review-evidence-sha256' => str_repeat('a', 64),
            '--expected-promotion-authorization-sha256' => str_repeat('b', 64),
        ]);

        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $options)
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('review evidence is missing or stale', Artisan::output());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
    }

    public function test_new_latest_revision_invalidates_bound_review_and_promotion_package(): void
    {
        $reviewed = $this->bindReview();
        $row = $reviewed['fixture']['rows'][0];
        $latest = PersonalityProfileVariantRevision::query()->findOrFail($row['revision_id']);
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $row['target_id'],
            'revision_no' => 2,
            'snapshot_json' => $latest->snapshot_json,
            'note' => 'stale review fixture',
            'created_at' => now(),
        ]);

        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $reviewed['options'], [
                '--expected-promotion-authorization-sha256' => str_repeat('b', 64),
            ])
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'live revision identity SHA256 does not match',
            Artisan::output()
        );
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
    }

    public function test_promotion_creates_exact_sections_invalidates_cache_and_is_idempotent(): void
    {
        $reviewed = $this->bindReview();
        $plan = $this->dryRun($reviewed['options']);
        $options = array_merge($reviewed['options'], [
            '--expected-promotion-authorization-sha256' => $plan['promotion_authorization_sha256'],
        ]);
        $cache = app(PersonalityPublicReadModelCache::class);
        $this->assertSame('0', $cache->versionToken('INTJ-A', 'en', 0, 'mbti'));

        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $options)
        );
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exit);
        $this->assertSame('promoted_exact_32_sections', $payload['action']);
        $this->assertSame(32, $payload['created_section_count']);
        $this->assertSame(32, $payload['cache_closeout']['invalidated_count']);
        $this->assertSame(32, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertNotSame('0', $cache->versionToken('INTJ-A', 'en', 0, 'mbti'));

        $response = $this->getJson('/api/v0.5/personality/INTJ-A?locale=en');
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'internal_links')
            ->assertJsonPath('internal_links.0.key', 'variant_at_pair')
            ->assertJsonPath('internal_links.0.label', 'Compare INTJ-A and INTJ-T')
            ->assertJsonPath('internal_links.1.key', 'variant_to_comparison')
            ->assertJsonPath('internal_links.1.label', 'INTJ-A vs INTJ-T');

        $second = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $options)
        );
        $secondPayload = $this->jsonOutput();

        $this->assertSame(0, $second);
        $this->assertSame('skipped_existing_exact_promotion', $secondPayload['action']);
        $this->assertSame($payload['promotion_receipt_sha256'], $secondPayload['promotion_receipt_sha256']);
        $this->assertSame(32, PersonalityProfileVariantSection::query()->count());
    }

    public function test_partial_or_drifted_live_sections_fail_closed(): void
    {
        $reviewed = $this->bindReview();
        $plan = $this->dryRun($reviewed['options']);
        $options = array_merge($reviewed['options'], [
            '--expected-promotion-authorization-sha256' => $plan['promotion_authorization_sha256'],
        ]);
        $row = $reviewed['fixture']['rows'][0];
        PersonalityProfileVariantSection::query()->create($this->sectionAttributes($row));

        $partialExit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $options)
        );
        $this->assertSame(1, $partialExit);
        $this->assertStringContainsString('partial target-section state', Artisan::output());
        $this->assertSame(1, PersonalityProfileVariantSection::query()->count());

        $section = PersonalityProfileVariantSection::query()->firstOrFail();
        $section->forceFill(['sort_order' => 982])->save();
        $this->assertSame(1, PersonalityProfileVariantSection::query()->count());

        PersonalityProfileVariantSection::query()->delete();
        foreach ($reviewed['fixture']['rows'] as $fixtureRow) {
            PersonalityProfileVariantSection::query()->create(
                $this->sectionAttributes($fixtureRow)
            );
        }
        PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', $row['target_id'])
            ->update(['sort_order' => 982]);
        $driftedExit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $options)
        );

        $this->assertSame(1, $driftedExit);
        $this->assertStringContainsString('missing, extra or drifted', Artisan::output());
        $this->assertSame(32, PersonalityProfileVariantSection::query()->count());
    }

    public function test_cache_failure_reports_retryable_partial_without_rolling_back_sections(): void
    {
        $reviewed = $this->bindReview();
        $plan = $this->dryRun($reviewed['options']);
        Cache::shouldReceive('forever')->times(32)->andThrow(new RuntimeException('cache unavailable'));

        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $reviewed['options'], [
                '--expected-promotion-authorization-sha256' => $plan['promotion_authorization_sha256'],
            ])
        );
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('partial_cache_closeout', $payload['status']);
        $this->assertSame(32, $payload['created_section_count']);
        $this->assertSame(0, $payload['cache_closeout']['invalidated_count']);
        $this->assertCount(32, $payload['cache_closeout']['failed_runtime_types']);
        $this->assertSame(32, PersonalityProfileVariantSection::query()->count());
    }

    #[DataProvider('wrongBoundaryProvider')]
    public function test_wrong_row_or_edge_boundary_fails_before_writes(
        int $rows,
        int $edges,
    ): void {
        $fixture = $this->seedRevisionCohort();
        $options = $fixture['options'];
        $options['--expected-rows'] = $rows;
        $options['--expected-edges'] = $edges;

        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge(['--dry-run' => true], $options)
        );

        $this->assertSame(1, $exit);
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, ReviewAttestation::query()->count());
    }

    /**
     * @return array<string,array{int,int}>
     */
    public static function wrongBoundaryProvider(): array
    {
        return [
            '33 rows' => [33, 64],
            '63 edges' => [32, 63],
            '65 edges' => [32, 65],
        ];
    }

    public function test_rollback_requires_exact_receipt_and_deletes_only_exact_sections(): void
    {
        $promoted = $this->promoteReviewedCohort();
        $wrong = array_merge(
            $this->rollbackFlags(),
            $promoted['options'],
            [
                '--expected-promotion-receipt-sha256' => str_repeat('c', 64),
                '--expected-rollback-authorization-sha256' => $promoted['payload']['rollback_authorization_sha256'],
            ]
        );
        $wrongExit = Artisan::call('personality:mbti64-cms-internal-link-promote', $wrong);
        $this->assertSame(1, $wrongExit);
        $this->assertSame(32, PersonalityProfileVariantSection::query()->count());

        $exact = array_merge(
            $this->rollbackFlags(),
            $promoted['options'],
            [
                '--expected-promotion-receipt-sha256' => $promoted['payload']['promotion_receipt_sha256'],
                '--expected-rollback-authorization-sha256' => $promoted['payload']['rollback_authorization_sha256'],
            ]
        );
        $exit = Artisan::call('personality:mbti64-cms-internal-link-promote', $exact);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exit);
        $this->assertSame('rolled_back_exact_32_sections', $payload['action']);
        $this->assertSame(32, $payload['deleted_section_count']);
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
    }

    #[DataProvider('unsafeMutationProvider')]
    public function test_unsafe_copy_role_or_route_fails_before_writes(string $mutation): void
    {
        $fixture = $this->seedRevisionCohort($mutation);
        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge(['--dry-run' => true], $fixture['options'])
        );

        $this->assertSame(1, $exit, $mutation);
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unsafeMutationProvider(): array
    {
        return [
            'copy' => ['copy'],
            'role' => ['role'],
            'route' => ['route'],
            'wrong safe target' => ['safe_target'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bindReview(): array
    {
        $fixture = $this->seedRevisionCohort();
        $targets = app(PersonalityReviewAttestationService::class)->targets(
            'mbti_approval_batch',
            $fixture['review_targets']
        );
        $attestation = app(ReviewAttestationFactory::class)->make(
            scopeType: 'per02_mbti_en64_internal_link_revision_set',
            scopeIdentity: $fixture['revision_identity_sha256'],
            decision: 'approved_all',
            targets: $targets,
            packageSha256: $fixture['revision_identity_sha256'],
            adminUserId: 1,
        );
        $path = sys_get_temp_dir().'/per02-en64-review-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($attestation, JSON_THROW_ON_ERROR));
        $options = array_merge($fixture['options'], [
            '--expected-review-evidence-sha256' => $attestation['evidence_sha256'],
        ]);
        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge([
                '--bind-review' => true,
                '--review-write-authorized' => true,
                '--attestation' => $path,
                '--actor-admin-user-id' => 1,
            ], $options)
        );

        return [
            'exit' => $exit,
            'payload' => $this->jsonOutput(),
            'attestation_path' => $path,
            'options' => $options,
            'fixture' => $fixture,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promoteReviewedCohort(): array
    {
        $reviewed = $this->bindReview();
        $plan = $this->dryRun($reviewed['options']);
        $options = array_merge($reviewed['options'], [
            '--expected-promotion-authorization-sha256' => $plan['promotion_authorization_sha256'],
        ]);
        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge($this->writeFlags(), $options)
        );

        return [
            'exit' => $exit,
            'payload' => $this->jsonOutput(),
            'options' => $options,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function dryRun(array $options): array
    {
        $exit = Artisan::call(
            'personality:mbti64-cms-internal-link-promote',
            array_merge(['--dry-run' => true], $options)
        );
        $this->assertSame(0, $exit);

        return $this->jsonOutput();
    }

    /**
     * @return array<string,mixed>
     */
    private function seedRevisionCohort(?string $mutation = null): array
    {
        $rows = [];
        foreach (PersonalityProfile::BASE_TYPE_CODES as $baseType) {
            $profile = PersonalityProfile::query()->create([
                'org_id' => 0,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'type_code' => $baseType,
                'canonical_type_code' => $baseType,
                'slug' => strtolower($baseType),
                'locale' => 'en',
                'title' => $baseType.' fixture',
                'type_name' => $baseType,
                'nickname' => $baseType,
                'keywords_json' => [],
                'excerpt' => $baseType.' summary',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'published_at' => now(),
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            ]);
            foreach (['A', 'T'] as $variantCode) {
                $runtime = $baseType.'-'.$variantCode;
                $variant = PersonalityProfileVariant::query()->create([
                    'personality_profile_id' => (int) $profile->id,
                    'canonical_type_code' => $baseType,
                    'variant_code' => $variantCode,
                    'runtime_type_code' => $runtime,
                    'keywords_json' => [],
                    'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                    'is_published' => true,
                    'published_at' => now(),
                ]);
                $links = [
                    [
                        'href' => '/en/personality/'.strtolower($baseType).'-'.(
                            $variantCode === 'A' ? 't' : 'a'
                        ),
                        'anchor_text' => 'Compare '.$baseType.'-A and '.$baseType.'-T',
                        'role' => 'variant_at_pair',
                        'safe_public_route' => true,
                    ],
                    [
                        'href' => '/en/personality/'.strtolower($baseType).'-a-vs-'.strtolower($baseType).'-t',
                        'anchor_text' => $baseType.'-A vs '.$baseType.'-T',
                        'role' => 'variant_to_comparison',
                        'safe_public_route' => true,
                    ],
                ];
                if ($mutation === 'copy' && $runtime === 'INTJ-A') {
                    $links[0]['anchor_text'] = 'Changed copy';
                }
                if ($mutation === 'role' && $runtime === 'INTJ-A') {
                    $links[1]['role'] = 'related_test';
                }
                if ($mutation === 'route' && $runtime === 'INTJ-A') {
                    $links[1]['href'] = '/en/results/private?token=unsafe';
                }
                if ($mutation === 'safe_target' && $runtime === 'INTJ-A') {
                    $links[1]['href'] = '/en/personality/infj-a-vs-infj-t';
                }
                $snapshot = [
                    'mbti64_internal_link_graph_v1' => [
                        'source' => [
                            'source_sha256' => self::GRAPH_SHA,
                            'cohort_payload_sha256' => self::COHORT_SHA,
                        ],
                        'first_class_draft_fields' => ['internal_links' => $links],
                    ],
                ];
                $revision = PersonalityProfileVariantRevision::query()->create([
                    'personality_profile_variant_id' => (int) $variant->id,
                    'revision_no' => 1,
                    'snapshot_json' => $snapshot,
                    'note' => 'fixture',
                    'created_at' => now(),
                ]);
                $identity = [
                    'runtime_type_code' => $runtime,
                    'target_id' => (int) $variant->id,
                    'revision_id' => (int) $revision->id,
                    'revision_no' => 1,
                    'snapshot_sha256' => $this->rawJsonSha($snapshot),
                    'internal_links_sha256' => $this->rawJsonSha($links),
                ];
                $rows[] = array_merge($identity, [
                    'target_sha256' => $this->canonicalSha($identity),
                    'links' => $links,
                ]);
            }
        }
        usort($rows, static fn (array $left, array $right): int => $left['runtime_type_code'] <=> $right['runtime_type_code']);
        $revisionIdentity = $this->canonicalSha(array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip([
                'runtime_type_code',
                'target_id',
                'revision_id',
                'revision_no',
                'snapshot_sha256',
                'internal_links_sha256',
            ])),
            $rows
        ));
        $markers = array_map(static fn (array $row): array => [
            'runtime_type_code' => $row['runtime_type_code'],
            'target_id' => $row['target_id'],
            'section_exists' => false,
            'section_id' => null,
            'section_sha256' => null,
        ], $rows);
        $rollbackMarkers = $this->canonicalSha($markers);

        return [
            'rows' => $rows,
            'revision_identity_sha256' => $revisionIdentity,
            'rollback_markers_sha256' => $rollbackMarkers,
            'review_targets' => array_map(static fn (array $row): array => [
                'identity' => sprintf(
                    'mbti_en64_internal_link_revision:%s:target:%d:revision:%d',
                    $row['runtime_type_code'],
                    $row['target_id'],
                    $row['revision_id']
                ),
                'sha256' => $row['target_sha256'],
            ], $rows),
            'options' => [
                '--confirm-writer-deploy-sha' => self::DEPLOY_SHA,
                '--confirm-release' => basename((string) realpath(base_path('..'))),
                '--expected-graph-sha256' => self::GRAPH_SHA,
                '--expected-cohort-sha256' => self::COHORT_SHA,
                '--expected-checkpoint112-inventory-sha256' => self::CHECKPOINT112_INVENTORY_SHA,
                '--expected-revision-identity-sha256' => $revisionIdentity,
                '--expected-section-inventory-sha256' => self::CHECKPOINT112_SECTION_SHA,
                '--expected-rollback-markers-sha256' => $rollbackMarkers,
                '--expected-rows' => 32,
                '--expected-edges' => 64,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeFlags(): array
    {
        return [
            '--write' => true,
            '--production-content-write-authorized' => true,
            '--cache-mutation-authorized' => true,
            '--no-publication-change' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rollbackFlags(): array
    {
        return array_merge($this->writeFlags(), [
            '--write' => false,
            '--rollback' => true,
            '--rollback-on-readback-failure-authorized' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function sectionAttributes(array $row): array
    {
        return [
            'org_id' => 0,
            'personality_profile_variant_id' => $row['target_id'],
            'section_key' => 'mbti_content15_internal_links',
            'render_variant' => 'links',
            'body_md' => null,
            'body_html' => null,
            'payload_json' => ['items' => $row['links']],
            'sort_order' => 981,
            'is_enabled' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function rawJsonSha(mixed $value): string
    {
        return hash('sha256', (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalSha(mixed $value): string
    {
        return hash('sha256', app(ReviewAttestationCanonicalizer::class)->encode($value));
    }
}
