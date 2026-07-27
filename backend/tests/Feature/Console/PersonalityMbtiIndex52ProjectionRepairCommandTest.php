<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Services\Cms\Mbti64CrossTypeComparisonPublicReadModel;
use App\Services\Cms\MbtiIndex52ProjectionRepairPackage;
use App\Services\Cms\MbtiIndex52ProjectionRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PersonalityMbtiIndex52ProjectionRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CONTROL_PLANE_SHA = '1111111111111111111111111111111111111111';

    private const ACTIVE_REVISION = '2222222222222222222222222222222222222222';

    public function test_dry_run_binds_exact_23_and_writes_nothing(): void
    {
        [$package, $authorization] = $this->seedExactCohort();

        self::assertSame(0, Artisan::call('personality:mbti-index52-projection-repair', [
            '--expected-control-plane-sha' => self::CONTROL_PLANE_SHA,
            '--expected-active-revision' => self::ACTIVE_REVISION,
            '--json' => true,
        ]));
        $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($summary['ok']);
        self::assertSame('dry_run', $summary['mode']);
        self::assertSame(23, $summary['record_count']);
        self::assertSame(MbtiIndex52ProjectionRepairPackage::PACKAGE_SHA256, $summary['package_sha256']);
        self::assertSame(MbtiIndex52ProjectionRepairPackage::AUTHORIZATION_SHA256, $summary['authorization_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['current_state_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['rollback_manifest_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['readback_contract_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['production_authorization_sha256']);
        self::assertFalse($summary['writes_committed']);
        self::assertFalse($summary['publication_or_indexability_mutated']);
        self::assertNull(data_get(
            PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->first()?->payload_json,
            'claim_boundary',
        ));
        self::assertArrayNotHasKey(
            'answer_surface_v1',
            (array) MbtiCrossTypeComparisonAuthority::query()->where('slug', 'intj-vs-intp')->first()?->content_payload_json,
        );

        $tampered = $package;
        $tampered['records'][0]['patch']['claim_boundary'] .= ' drift';
        $this->app->make(MbtiIndex52ProjectionRepairService::class);
        $this->expectExceptionMessage('package hash or contract mismatch');
        $this->app->make(MbtiIndex52ProjectionRepairService::class)->plan(
            $tampered,
            $authorization,
            self::CONTROL_PLANE_SHA,
            self::ACTIVE_REVISION,
        );
    }

    public function test_exact_authorization_updates_only_projection_fields_atomically(): void
    {
        [$package, $authorization] = $this->seedExactCohort();
        $service = $this->app->make(MbtiIndex52ProjectionRepairService::class);
        $plan = $service->plan(
            $package,
            $authorization,
            self::CONTROL_PLANE_SHA,
            self::ACTIVE_REVISION,
        );
        config()->set('app.mbti_index52_test_active_revision', self::ACTIVE_REVISION);
        config()->set('app.mbti_index52_control_plane_sha', str_repeat('3', 40));
        try {
            $service->publish(
                $package,
                $authorization,
                $plan['current_state_sha256'],
                self::CONTROL_PLANE_SHA,
                self::ACTIVE_REVISION,
                $plan['required_production_authorization'],
            );
            self::fail('Changed control-plane SHA unexpectedly passed the write gate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Production release binding changed before transaction.', $exception->getMessage());
        }
        config()->set('app.mbti_index52_control_plane_sha', self::CONTROL_PLANE_SHA);
        $beforeCross = MbtiCrossTypeComparisonAuthority::query()->where('slug', 'intj-vs-intp')->firstOrFail();
        $beforeContent = (array) $beforeCross->content_payload_json;
        $beforeInvariants = $beforeCross->only([
            'review_status', 'publish_status', 'indexability_status', 'is_public', 'is_indexable',
            'sitemap_eligible', 'llms_eligible', 'search_submission_eligible',
        ]);

        self::assertSame(0, Artisan::call('personality:mbti-index52-projection-repair', [
            '--execute' => true,
            '--expected-current-state-sha256' => $plan['current_state_sha256'],
            '--expected-control-plane-sha' => self::CONTROL_PLANE_SHA,
            '--expected-active-revision' => self::ACTIVE_REVISION,
            '--production-authorization' => $plan['required_production_authorization'],
            '--json' => true,
        ]));
        $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($summary['writes_committed']);
        self::assertSame('projection_repair_committed', $summary['status']);
        self::assertSame(self::CONTROL_PLANE_SHA, $summary['control_plane_sha']);
        self::assertSame(self::ACTIVE_REVISION, $summary['active_revision']);
        self::assertFalse($summary['body_or_faq_mutated']);
        self::assertFalse($summary['publication_or_indexability_mutated']);
        $afterCross = $beforeCross->fresh();
        self::assertInstanceOf(MbtiCrossTypeComparisonAuthority::class, $afterCross);
        self::assertSame($beforeInvariants, $afterCross->only(array_keys($beforeInvariants)));
        self::assertSame($beforeContent['sections'], $afterCross->content_payload_json['sections']);
        self::assertSame($beforeContent['faq'], $afterCross->content_payload_json['faq']);
        self::assertCount(5, $afterCross->content_payload_json['internal_links']);
        self::assertNotEmpty($afterCross->content_payload_json['answer_surface_v1']);
        self::assertSame(
            'https://fermatmind.com/en/personality/intj-vs-intp',
            $afterCross->content_payload_json['alternates']['en'],
        );
        $englishAlternate = $this->app->make(Mbti64CrossTypeComparisonPublicReadModel::class)
            ->find('intj-vs-intp', 'en');
        self::assertIsArray($englishAlternate);
        self::assertSame('en', $englishAlternate['locale']);
        self::assertSame(
            'https://fermatmind.com/en/personality/intj-vs-intp',
            $englishAlternate['canonical_url'],
        );
        self::assertNotEmpty(data_get(
            PersonalityProfileSection::query()->whereHas('profile', fn ($query) => $query->where('canonical_type_code', 'INTJ'))
                ->where('section_key', 'mbti64_comparison_a_vs_t')->firstOrFail()->payload_json,
            'claim_boundary',
        ));
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function seedExactCohort(): array
    {
        $package = $this->jsonAsset(base_path(MbtiIndex52ProjectionRepairPackage::PACKAGE_PATH));
        $authorization = $this->jsonAsset(base_path(MbtiIndex52ProjectionRepairPackage::AUTHORIZATION_PATH));

        foreach ($package['records'] as $record) {
            if ($record['record_kind'] === 'at_comparison') {
                $type = strtoupper(substr((string) $record['slug'], 0, 4));
                $profile = PersonalityProfile::query()->create([
                    'org_id' => 0,
                    'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                    'type_code' => $type,
                    'canonical_type_code' => $type,
                    'slug' => strtolower($type),
                    'locale' => 'zh-CN',
                    'title' => $type,
                    'status' => 'published',
                    'is_public' => true,
                    'is_indexable' => true,
                    'published_at' => now()->subMinute(),
                    'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                ]);
                PersonalityProfileSection::query()->create([
                    'org_id' => 0,
                    'profile_id' => $profile->id,
                    'section_key' => 'mbti64_comparison_a_vs_t',
                    'title' => $type.' A/T',
                    'render_variant' => 'rich_text',
                    'body_md' => 'existing summary',
                    'payload_json' => [
                        'sections' => $record['expected_runtime_sections'],
                        'faq' => [['question' => 'existing?', 'answer' => 'existing.']],
                        'internal_links' => [],
                    ],
                    'sort_order' => 920,
                    'is_enabled' => true,
                ]);

                continue;
            }
            $slug = (string) $record['slug'];
            [$left, $right] = explode('-vs-', $slug);
            MbtiCrossTypeComparisonAuthority::query()->create([
                'org_id' => 0,
                'locale' => 'zh-CN',
                'slug' => $slug,
                'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
                'left_type_code' => strtoupper($left),
                'right_type_code' => strtoupper($right),
                'title' => strtoupper($left).' vs '.strtoupper($right),
                'seo_title' => strtoupper($left).' vs '.strtoupper($right),
                'seo_description' => 'existing description',
                'summary' => 'existing summary',
                'content_payload_json' => [
                    'sections' => $record['expected_runtime_sections'],
                    'faq' => $record['patch']['answer_surface_v1']['faq_blocks'],
                    'internal_links' => [],
                ],
                'claim_boundary' => 'existing claim boundary',
                'source_package_id' => 'existing-package',
                'source_sha256' => $record['source_revision_sha256'],
                'authority_contract_version' => MbtiCrossTypeComparisonAuthority::AUTHORITY_CONTRACT_VERSION,
                'readmodel_contract_version' => MbtiCrossTypeComparisonAuthority::READMODEL_CONTRACT_VERSION,
                'review_status' => 'approved',
                'publish_status' => 'published',
                'indexability_status' => 'released_by_mbti_cross_publisher_49',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'search_submission_eligible' => false,
                'published_at' => now()->subMinute(),
                'imported_at' => now()->subMinute(),
            ]);
        }

        return [$package, $authorization];
    }

    /** @return array<string,mixed> */
    private function jsonAsset(string $path): array
    {
        return (array) json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
