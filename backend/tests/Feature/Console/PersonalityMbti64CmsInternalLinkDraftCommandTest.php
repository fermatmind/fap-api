<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64CmsInternalLinkDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_ninety_six_internal_link_drafts_without_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', [
            '--graph' => $graphPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(96, $payload['row_count']);
        $this->assertSame(64, $payload['variant_row_count']);
        $this->assertSame(32, $payload['comparison_row_count']);
        $this->assertSame(96, $payload['would_create_revision_count']);
        $this->assertSame(96, $payload['blocked_edge_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_dry_run_plans_exact_en_variant_cohort_without_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();

        $exitCode = Artisan::call(
            'personality:mbti64-cms-internal-link-draft',
            $this->boundedOptions($graphPath, dryRun: true)
        );

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame([
            'locale' => 'en',
            'page_type' => 'variant',
            'expected_rows' => 32,
            'expected_edges' => 64,
        ], $payload['bounded_scope']);
        $this->assertSame(32, $payload['row_count']);
        $this->assertSame(32, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(64, $payload['active_internal_link_count']);
        $this->assertSame(
            'ed99662788d106d371bc14ca676df57edf860bb50bb2e62fa3e551f2bd4c7f23',
            $payload['cohort_payload_sha256']
        );
        foreach ($payload['rows'] as $row) {
            $this->assertSame('en', $row['locale']);
            $this->assertSame('variant', $row['page_type']);
            $links = $row['snapshot_preview']['mbti64_internal_link_graph_v1']['first_class_draft_fields']['internal_links'];
            $this->assertCount(2, $links);
            $type = $row['identity']['canonical_type_code'];
            $this->assertSame(
                ['variant_at_pair', 'variant_to_comparison'],
                array_column($links, 'role')
            );
            $this->assertSame(
                ['Compare '.$type.'-A and '.$type.'-T', $type.'-A vs '.$type.'-T'],
                array_column($links, 'anchor_text')
            );
        }
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_creates_thirty_two_bounded_draft_revisions_without_changing_live_records(): void
    {
        $targets = $this->seedAllTargets();
        $graphPath = $this->graphPath();
        $profileBefore = $this->profileLiveState($targets['en|INTJ']);
        $variantBefore = $this->variantLiveState($targets['en|INTJ-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(32, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(32, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|INTJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|INTJ-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $variant = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $targets['en|INTJ-A']->id)
            ->firstOrFail();

        $this->assertInternalLinkSnapshot($variant->snapshot_json);
        $source = $variant->snapshot_json['mbti64_internal_link_graph_v1']['source'];
        $this->assertSame(
            'ed99662788d106d371bc14ca676df57edf860bb50bb2e62fa3e551f2bd4c7f23',
            $source['cohort_payload_sha256']
        );
    }

    public function test_second_write_is_idempotent_for_same_graph_hash(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();

        $firstExit = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(32, $payload['skipped_existing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(32, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_changed_graph_hash_creates_next_revision_version(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();
        $mutatedGraphPath = $this->graphPath(static function (array $graph): array {
            $graph['generated_at'] = '2026-06-18T00:00:01Z';

            return $graph;
        });

        $firstExit = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($mutatedGraphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertSame(32, $payload['created_revision_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(64, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_missing_target_blocks_entire_write_batch(): void
    {
        $this->seedAllTargets(skip: ['en|INTJ-A']);
        $graphPath = $this->graphPath();

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('target_not_found', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_unsafe_or_blocked_graph_edges_are_not_active_internal_links(): void
    {
        $targets = $this->seedAllTargets();
        $graphPath = $this->graphPath();

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame(96, $payload['blocked_edge_count']);

        $revision = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $targets['en|INTJ-A']->id)
            ->firstOrFail();
        $snapshot = $revision->snapshot_json['mbti64_internal_link_graph_v1'];
        $links = $snapshot['first_class_draft_fields']['internal_links'];

        $this->assertNotEmpty($links);
        foreach ($links as $link) {
            $this->assertTrue((bool) ($link['safe_public_route'] ?? false));
            $this->assertStringNotContainsString('/results', (string) ($link['href'] ?? ''));
            $this->assertStringNotContainsString('/orders', (string) ($link['href'] ?? ''));
        }
    }

    public function test_forbidden_recommended_edge_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            $graph['recommendedEdges'][] = [
                'source_url' => 'https://fermatmind.com/en/personality/intj-a',
                'target_url' => 'https://fermatmind.com/en/results/lookup',
                'source_path' => '/en/personality/intj-a',
                'target_path' => '/en/results/lookup',
                'locale' => 'en',
                'edge_type' => 'forbidden_private_route',
                'anchor_text_suggestion' => 'Forbidden',
                'priority' => 'P0',
                'reason' => 'fixture forbidden route',
                'safe_public_route' => true,
                'publish_blocker_if_any' => '',
            ];

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_recommended_edge_target', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_fails_closed_when_any_required_safety_flag_is_missing(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();
        $options = $this->writeOptions($graphPath);
        unset($options['--no-llms']);

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('--no-llms is required', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_fails_closed_without_exact_bounded_options(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();
        $options = $this->writeOptions($graphPath);
        unset($options['--expected-edges']);

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString(
            '--expected-edges=64 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_unknown_edge_type_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            $edge = $graph['recommendedEdges'][0];
            $edge['edge_type'] = 'unknown_bounded_role';
            $edge['source_path'] = '/en/personality/intj-a';
            $edge['source_url'] = 'https://fermatmind.com/en/personality/intj-a';
            $edge['target_path'] = '/en/personality/intj-t';
            $edge['target_url'] = 'https://fermatmind.com/en/personality/intj-t';
            $edge['locale'] = 'en';
            $graph['recommendedEdges'][] = $edge;

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains(
            'unsupported_bounded_edge_type',
            array_column($payload['errors'], 'code')
        );
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_count_options_reject_expanded_or_partial_cohorts(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath();

        foreach ([
            ['--expected-rows', 33, '--expected-rows=32 is required'],
            ['--expected-edges', 63, '--expected-edges=64 is required'],
            ['--expected-edges', 65, '--expected-edges=64 is required'],
        ] as [$option, $value, $message]) {
            $options = $this->writeOptions($graphPath);
            $options[$option] = $value;

            $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $options);
            $payload = $this->jsonOutput();

            $this->assertSame(1, $exitCode);
            $this->assertFalse($payload['ok']);
            $this->assertStringContainsString($message, (string) ($payload['errors'][0]['message'] ?? ''));
        }

        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_missing_required_edge_role_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            $graph['recommendedEdges'] = array_values(array_filter(
                $graph['recommendedEdges'],
                static fn (array $edge): bool => ! (
                    ($edge['source_path'] ?? null) === '/en/personality/intj-a'
                    && ($edge['edge_type'] ?? null) === 'variant_to_comparison'
                )
            ));

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('bounded_edge_role_count_mismatch', array_column($payload['errors'], 'code'));
        $this->assertContains('bounded_edge_count_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_cross_locale_target_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            foreach ($graph['recommendedEdges'] as &$edge) {
                if (($edge['source_path'] ?? null) === '/en/personality/intj-a'
                    && ($edge['edge_type'] ?? null) === 'variant_at_pair') {
                    $edge['target_path'] = '/zh/personality/intj-t';
                    $edge['target_url'] = 'https://fermatmind.com/zh/personality/intj-t';
                    break;
                }
            }
            unset($edge);

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('bounded_edge_locale_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_same_locale_wrong_target_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            foreach ($graph['recommendedEdges'] as &$edge) {
                if (($edge['source_path'] ?? null) === '/en/personality/intj-a'
                    && ($edge['edge_type'] ?? null) === 'variant_at_pair') {
                    $edge['target_path'] = '/en/personality/infj-t';
                    $edge['target_url'] = 'https://fermatmind.com/en/personality/infj-t';
                    break;
                }
            }
            unset($edge);

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('bounded_edge_target_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_cased_duplicate_source_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            foreach ($graph['nodes'] as &$node) {
                if (($node['path'] ?? null) === '/en/personality/intp-a') {
                    $node['path'] = '/en/personality/INTJ-A';
                    $node['url'] = 'https://fermatmind.com/en/personality/INTJ-A';
                    break;
                }
            }
            unset($node);

            foreach ($graph['recommendedEdges'] as &$edge) {
                if (($edge['source_path'] ?? null) !== '/en/personality/intp-a') {
                    continue;
                }

                $edge['source_path'] = '/en/personality/INTJ-A';
                $edge['source_url'] = 'https://fermatmind.com/en/personality/INTJ-A';
                if (($edge['edge_type'] ?? null) === 'variant_at_pair') {
                    $edge['target_path'] = '/en/personality/intj-t';
                    $edge['target_url'] = 'https://fermatmind.com/en/personality/intj-t';
                } elseif (($edge['edge_type'] ?? null) === 'variant_to_comparison') {
                    $edge['target_path'] = '/en/personality/intj-a-vs-intj-t';
                    $edge['target_url'] = 'https://fermatmind.com/en/personality/intj-a-vs-intj-t';
                }
            }
            unset($edge);

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('bounded_source_inventory_mismatch', array_column($payload['errors'], 'code'));
        $this->assertContains('bounded_target_inventory_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_tokenized_raw_target_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            foreach ($graph['recommendedEdges'] as &$edge) {
                if (($edge['source_path'] ?? null) === '/en/personality/intj-a'
                    && ($edge['edge_type'] ?? null) === 'variant_at_pair') {
                    $edge['target_url'] = 'https://fermatmind.com/en/personality/intj-t?token=must-not-persist';
                    break;
                }
            }
            unset($edge);

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_recommended_edge_target', array_column($payload['errors'], 'code'));
        $this->assertContains('unsafe_bounded_edge', array_column($payload['errors'], 'code'));
        $this->assertStringNotContainsString('must-not-persist', Artisan::output());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_bounded_tokenized_raw_source_fails_closed_before_writes(): void
    {
        $this->seedAllTargets();
        $graphPath = $this->graphPath(static function (array $graph): array {
            foreach ($graph['recommendedEdges'] as &$edge) {
                if (($edge['source_path'] ?? null) === '/en/personality/intj-a'
                    && ($edge['edge_type'] ?? null) === 'variant_at_pair') {
                    $edge['source_url'] = 'https://fermatmind.com/en/personality/intj-a?token=must-not-persist';
                    break;
                }
            }
            unset($edge);

            return $graph;
        });

        $exitCode = Artisan::call('personality:mbti64-cms-internal-link-draft', $this->writeOptions($graphPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_recommended_edge_source', array_column($payload['errors'], 'code'));
        $this->assertContains('unsafe_bounded_edge', array_column($payload['errors'], 'code'));
        $this->assertStringNotContainsString('must-not-persist', Artisan::output());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    /**
     * @return array<string,PersonalityProfile|PersonalityProfileVariant>
     */
    private function seedAllTargets(array $skip = []): array
    {
        $targets = [];
        foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $profile = $this->createProfile($locale, $typeCode);
                $targets[$locale.'|'.$typeCode] = $profile;

                foreach (['A', 'T'] as $variantCode) {
                    $runtimeTypeCode = $typeCode.'-'.$variantCode;
                    if (in_array($locale.'|'.$runtimeTypeCode, $skip, true)) {
                        continue;
                    }

                    $targets[$locale.'|'.$runtimeTypeCode] = $this->createVariant($profile, $runtimeTypeCode);
                }
            }
        }

        return $targets;
    }

    private function createProfile(string $locale, string $typeCode): PersonalityProfile
    {
        return PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $typeCode.' fixture',
            'type_name' => $typeCode,
            'nickname' => $typeCode,
            'rarity_text' => null,
            'keywords_json' => [],
            'subtitle' => null,
            'excerpt' => $locale === 'zh-CN' ? $typeCode.' 类型摘要' : $typeCode.' type summary',
            'hero_kicker' => $typeCode,
            'hero_quote' => null,
            'hero_summary_md' => null,
            'hero_summary_html' => null,
            'hero_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
    }

    private function createVariant(PersonalityProfile $profile, string $runtimeTypeCode): PersonalityProfileVariant
    {
        [$typeCode, $variantCode] = explode('-', $runtimeTypeCode);

        return PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => $typeCode,
            'variant_code' => $variantCode,
            'runtime_type_code' => $runtimeTypeCode,
            'type_name' => null,
            'nickname' => null,
            'rarity_text' => null,
            'keywords_json' => [],
            'hero_summary_md' => null,
            'hero_summary_html' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function profileLiveState(PersonalityProfile $profile): array
    {
        $fresh = $profile->fresh();
        $this->assertInstanceOf(PersonalityProfile::class, $fresh);

        return [
            'status' => (string) $fresh->status,
            'is_public' => (bool) $fresh->is_public,
            'is_indexable' => (bool) $fresh->is_indexable,
            'published_at' => $fresh->published_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function variantLiveState(PersonalityProfileVariant $variant): array
    {
        $fresh = $variant->fresh();
        $this->assertInstanceOf(PersonalityProfileVariant::class, $fresh);

        return [
            'is_published' => (bool) $fresh->is_published,
            'published_at' => $fresh->published_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function liveSurfaceCounts(): array
    {
        return [
            'profile_sections' => PersonalityProfileSection::query()->count(),
            'profile_seo_meta' => PersonalityProfileSeoMeta::query()->count(),
            'variant_sections' => PersonalityProfileVariantSection::query()->count(),
            'variant_seo_meta' => PersonalityProfileVariantSeoMeta::query()->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function assertInternalLinkSnapshot(array $snapshot): void
    {
        $this->assertArrayHasKey('mbti64_internal_link_graph_v1', $snapshot);
        $payload = $snapshot['mbti64_internal_link_graph_v1'];
        $this->assertSame('mbti64.internal_link_graph.v1', $payload['source']['version']);
        $this->assertNotEmpty($payload['first_class_draft_fields']['internal_links']);
        $this->assertTrue((bool) $payload['safety_holds']['draft_only']);
        $this->assertFalse((bool) $payload['safety_holds']['publish_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['index_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['sitemap_llms_release_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['search_release_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['runtime_content_updated']);
    }

    private function graphPath(?callable $mutate = null): string
    {
        $graph = json_decode(
            (string) File::get(base_path('docs/seo/personality/internal-link-graph-2026-06-18.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($graph);

        if ($mutate !== null) {
            $graph = $mutate($graph);
            $this->assertIsArray($graph);
        }

        $path = sys_get_temp_dir().'/mbti64-internal-link-graph-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $graphPath): array
    {
        return array_merge($this->boundedOptions($graphPath), [
            '--graph' => $graphPath,
            '--write' => true,
            '--json' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'MBTI64-CMS-INTERNAL-LINK-DRAFT-01',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function boundedOptions(string $graphPath, bool $dryRun = false): array
    {
        return [
            '--graph' => $graphPath,
            '--dry-run' => $dryRun,
            '--json' => true,
            '--locale' => 'en',
            '--page-type' => 'variant',
            '--expected-rows' => 32,
            '--expected-edges' => 64,
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
}
