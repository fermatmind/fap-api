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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64CmsProjectionDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PILOT_PATHS = [
        '/en/personality/intj-a-vs-intj-t',
        '/zh/personality/istj-a',
        '/en/personality/intp-a-vs-intp-t',
        '/zh/personality/infp-t',
        '/en/personality/intj-a',
        '/en/personality/intj-t',
        '/zh/personality/intj-a',
        '/zh/personality/intj-t',
    ];

    private const VISIBLE_QUERY_BACKED_3_URLS = [
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
    ];

    private const FRESH_QUERY_BACKED_3_URLS = [
        'https://fermatmind.com/zh/personality/istp-a',
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfj-a',
    ];

    private const FRESH_QUERY_BACKED_5_URLS = [
        'https://fermatmind.com/en/personality/enfp-a',
        'https://fermatmind.com/zh/personality/istp-a',
        'https://fermatmind.com/en/personality/esfj-a',
        'https://fermatmind.com/zh/personality/esfj-a',
        'https://fermatmind.com/en/personality/intp-a',
    ];

    private const NEXT_BATCH_6_URLS = [
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/en/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
        'https://fermatmind.com/en/personality/esfp-a',
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/enfj-a',
    ];

    public function test_dry_run_plans_eighty_eight_projection_drafts_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(88, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(30, $payload['comparison_row_count']);
        $this->assertSame(88, $payload['would_create_revision_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_dry_run_plans_only_approved_urls_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['would_create_revision_count']);
        $this->assertSame('visible_query_backed_3', $payload['subset']['mode']);
        $this->assertTrue($payload['subset']['enabled']);
        $this->assertFalse($payload['subset']['dry_run_only']);
        $this->assertTrue($payload['subset']['write_allowed_with_strict_approval']);

        $plannedUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($plannedUrls);
        $expectedUrls = self::VISIBLE_QUERY_BACKED_3_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $plannedUrls);
        $this->assertSame($expectedUrls, array_values($this->sortedStrings((array) $payload['subset']['allowed_urls'])));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_write_requires_visible_three_approval_token(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--visible-query-backed-3'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=MBTI64-CMS-PROJECTION-DRAFT-VISIBLE-3-WRITE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_write_creates_only_approved_variant_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $profileBefore = $this->profileLiveState($targets['en|ENFJ']);
        $variantBefore = $this->variantLiveState($targets['en|ENFJ-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--visible-query-backed-3'] = true;
        $options['--operator-approved'] = 'MBTI64-CMS-PROJECTION-DRAFT-VISIBLE-3-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame('visible_query_backed_3', $payload['subset']['mode']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(3, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|ENFJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|ENFJ-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $createdUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($createdUrls);
        $expectedUrls = self::VISIBLE_QUERY_BACKED_3_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $createdUrls);

        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot($revision->snapshot_json);
        }
    }

    public function test_visible_query_backed_three_second_write_is_idempotent_for_same_source_hash(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--visible-query-backed-3'] = true;
        $options['--operator-approved'] = 'MBTI64-CMS-PROJECTION-DRAFT-VISIBLE-3-WRITE-01';

        $firstExit = Artisan::call('personality:mbti64-cms-projection-draft', $options);
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(3, $payload['skipped_existing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(3, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_fails_closed_when_allowlisted_url_is_missing(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        foreach ($package['recommendations'] as &$recommendation) {
            if (($recommendation['target_url'] ?? null) === 'https://fermatmind.com/en/personality/enfj-a') {
                $recommendation['target_url'] = 'https://fermatmind.com/en/personality/entj-a';
                break;
            }
        }
        unset($recommendation);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('visible_query_backed_subset_required_urls_missing', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_fresh_query_backed_three_dry_run_plans_only_fresh_approved_urls_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--fresh-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['would_create_revision_count']);
        $this->assertSame('fresh_query_backed_3', $payload['subset']['mode']);
        $this->assertTrue($payload['subset']['enabled']);
        $this->assertFalse($payload['subset']['dry_run_only']);
        $this->assertTrue($payload['subset']['write_allowed_with_strict_approval']);

        $plannedUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($plannedUrls);
        $expectedUrls = self::FRESH_QUERY_BACKED_3_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $plannedUrls);
        $this->assertSame($expectedUrls, array_values($this->sortedStrings((array) $payload['subset']['allowed_urls'])));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_fresh_query_backed_three_write_requires_fresh_three_approval_token(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--fresh-query-backed-3'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=MBTI64-CMS-PROJECTION-DRAFT-FRESH-3-WRITE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_fresh_query_backed_three_write_creates_only_fresh_approved_variant_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $profileBefore = $this->profileLiveState($targets['zh-CN|ISTP']);
        $variantBefore = $this->variantLiveState($targets['zh-CN|ISTP-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--fresh-query-backed-3'] = true;
        $options['--operator-approved'] = 'MBTI64-CMS-PROJECTION-DRAFT-FRESH-3-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame('fresh_query_backed_3', $payload['subset']['mode']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(3, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['zh-CN|ISTP']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['zh-CN|ISTP-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $createdUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($createdUrls);
        $expectedUrls = self::FRESH_QUERY_BACKED_3_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $createdUrls);

        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot($revision->snapshot_json);
        }
    }

    public function test_fresh_query_backed_three_rejects_other_subset_mode_combinations_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--fresh-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('exclusive_subset_modes_required', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_fresh_query_backed_five_dry_run_plans_only_fresh_approved_urls_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--fresh-query-backed-5' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(5, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(5, $payload['would_create_revision_count']);
        $this->assertSame('fresh_query_backed_5', $payload['subset']['mode']);
        $this->assertTrue($payload['subset']['enabled']);
        $this->assertFalse($payload['subset']['dry_run_only']);
        $this->assertTrue($payload['subset']['write_allowed_with_strict_approval']);

        $plannedUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($plannedUrls);
        $expectedUrls = self::FRESH_QUERY_BACKED_5_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $plannedUrls);
        $this->assertSame($expectedUrls, array_values($this->sortedStrings((array) $payload['subset']['allowed_urls'])));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_fresh_query_backed_five_write_requires_fresh_five_approval_token(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--fresh-query-backed-5'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=MBTI64-CMS-PROJECTION-DRAFT-FRESH-5-WRITE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_fresh_query_backed_five_write_creates_only_fresh_approved_variant_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $profileBefore = $this->profileLiveState($targets['en|ENFP']);
        $variantBefore = $this->variantLiveState($targets['en|ENFP-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--fresh-query-backed-5'] = true;
        $options['--operator-approved'] = 'MBTI64-CMS-PROJECTION-DRAFT-FRESH-5-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(5, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(5, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame('fresh_query_backed_5', $payload['subset']['mode']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(5, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|ENFP']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|ENFP-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $createdUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($createdUrls);
        $expectedUrls = self::FRESH_QUERY_BACKED_5_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $createdUrls);

        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot($revision->snapshot_json);
        }
    }

    public function test_fresh_query_backed_five_fails_closed_when_allowlisted_url_is_missing(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        foreach ($package['recommendations'] as &$recommendation) {
            if (($recommendation['target_url'] ?? null) === 'https://fermatmind.com/en/personality/enfp-a') {
                $recommendation['target_url'] = 'https://fermatmind.com/en/personality/entp-a';
                break;
            }
        }
        unset($recommendation);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--fresh-query-backed-5' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('fresh_query_backed_5_subset_required_urls_missing', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_dry_run_accepts_handoff_artifact_and_requires_approval_queue_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->nextBatchSixPackage(), $this->nextBatchSixQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['would_create_revision_count']);
        $this->assertSame('next_batch_6', $payload['subset']['mode']);
        $this->assertTrue($payload['subset']['approval_queue_required']);
        $this->assertFalse($payload['subset']['arbitrary_url_subset_allowed']);
        $this->assertTrue($payload['approval_queue']['required_for_write']);
        $this->assertFalse($payload['approval_queue']['ready_for_write']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(6, $payload['approval_queue']['missing_count']);

        $plannedUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($plannedUrls);
        $expectedUrls = self::NEXT_BATCH_6_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $plannedUrls);
        $this->assertSame($expectedUrls, array_values($this->sortedStrings((array) $payload['subset']['allowed_urls'])));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_v2_dry_run_accepts_competitor_gap_artifact_and_approved_queue_without_writes(): void
    {
        $this->seedAllTargets();
        $package = $this->nextBatchSixV2Package();
        $qa = $this->nextBatchSixV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 6, 0);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['would_create_revision_count']);
        $this->assertSame('next_batch_6', $payload['subset']['mode']);
        $this->assertTrue($payload['approval_queue']['ready_for_write']);
        $this->assertSame(6, $payload['approval_queue']['approved_count']);
        $this->assertSame(0, $payload['approval_queue']['qa_not_pass_count']);

        $firstPayload = $payload['rows'][0]['snapshot_preview']['mbti64_agent_projection_draft_v1'];
        $this->assertSame('MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01', $firstPayload['source']['artifact']);
        $this->assertSame('PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR', $firstPayload['source']['qa_final_decision']);
        $this->assertNotSame('', $firstPayload['first_class_draft_fields']['seo']['title']);
        $this->assertNotSame('', $firstPayload['first_class_draft_fields']['seo']['description']);
        $this->assertNotSame('', $firstPayload['first_class_draft_fields']['seo']['h1']);
        $content = $firstPayload['first_class_draft_fields']['content'];
        $this->assertNotSame('', $content['quick_answer']);
        foreach ($this->expectedV2FirstClassSectionKeys() as $sectionKey) {
            $this->assertArrayHasKey($sectionKey, $content);
            $this->assertNotSame('', $content[$sectionKey]['body'] ?? '');
            $this->assertSame('mbti64_competitor_gap_v2_first_class_section', $content[$sectionKey]['source'] ?? null);
        }
        $this->assertStringContainsString('| Dimension | Assertive side | Turbulent side |', (string) ($content['a_t_difference']['body'] ?? ''));
        $this->assertCount(9, $firstPayload['first_class_draft_fields']['faq']);
        $this->assertNotEmpty($firstPayload['first_class_draft_fields']['internal_links']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_handoff_artifact_is_rejected_without_explicit_subset_flag(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->nextBatchSixPackage(), $this->nextBatchSixQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('unsupported_package_artifact', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_write_requires_next_batch_approval_token(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->nextBatchSixPackage(), $this->nextBatchSixQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--next-batch-6'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_write_without_approved_queue_rows_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->nextBatchSixPackage(), $this->nextBatchSixQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--next-batch-6'] = true;
        $options['--operator-approved'] = 'PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(6, $payload['approval_queue']['missing_count']);
        $this->assertContains('agent_batch_approval_required', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_write_creates_only_approved_variant_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        $package = $this->nextBatchSixPackage();
        $qa = $this->nextBatchSixQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 6, 0);
        $profileBefore = $this->profileLiveState($targets['zh-CN|INTP']);
        $variantBefore = $this->variantLiveState($targets['zh-CN|INTP-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--next-batch-6'] = true;
        $options['--operator-approved'] = 'PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame('next_batch_6', $payload['subset']['mode']);
        $this->assertTrue($payload['approval_queue']['ready_for_write']);
        $this->assertSame(6, $payload['approval_queue']['approved_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(6, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['zh-CN|INTP']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['zh-CN|INTP-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $createdUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($createdUrls);
        $expectedUrls = self::NEXT_BATCH_6_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $createdUrls);

        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot($revision->snapshot_json, 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-01', 'PASS_READY_FOR_APPROVAL_REVIEW');
        }
    }

    public function test_next_batch_six_v2_write_creates_only_approved_variant_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        $package = $this->nextBatchSixV2Package();
        $qa = $this->nextBatchSixV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 6, 0);
        $profileBefore = $this->profileLiveState($targets['zh-CN|INTP']);
        $variantBefore = $this->variantLiveState($targets['zh-CN|INTP-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--next-batch-6'] = true;
        $options['--operator-approved'] = 'PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['created_revision_count']);
        $this->assertSame('next_batch_6', $payload['subset']['mode']);
        $this->assertTrue($payload['approval_queue']['ready_for_write']);
        $this->assertSame(6, $payload['approval_queue']['approved_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(6, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['zh-CN|INTP']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['zh-CN|INTP-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot(
                $revision->snapshot_json,
                'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
                'PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR',
                9
            );
        }
    }

    public function test_remaining_fifty_eight_v2_dry_run_accepts_competitor_gap_artifact_and_approved_queue_without_writes(): void
    {
        $this->seedAllTargets();
        $package = $this->remainingFiftyEightV2Package();
        $qa = $this->remainingFiftyEightV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 58, 0);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--remaining-58' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(58, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(58, $payload['would_create_revision_count']);
        $this->assertSame('remaining_58', $payload['subset']['mode']);
        $this->assertTrue($payload['subset']['approval_queue_required']);
        $this->assertFalse($payload['subset']['arbitrary_url_subset_allowed']);
        $this->assertTrue($payload['approval_queue']['ready_for_write']);
        $this->assertSame(58, $payload['approval_queue']['approved_count']);

        $plannedUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($plannedUrls);
        $expectedUrls = $this->remainingFiftyEightUrls();
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $plannedUrls);
        $this->assertSame($expectedUrls, array_values($this->sortedStrings((array) $payload['subset']['allowed_urls'])));
        $firstContent = $payload['rows'][0]['snapshot_preview']['mbti64_agent_projection_draft_v1']['first_class_draft_fields']['content'];
        foreach ($this->expectedV2FirstClassSectionKeys() as $sectionKey) {
            $this->assertArrayHasKey($sectionKey, $firstContent);
        }
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_remaining_fifty_eight_write_requires_remaining_fifty_eight_approval_token(): void
    {
        $this->seedAllTargets();
        $package = $this->remainingFiftyEightV2Package();
        $qa = $this->remainingFiftyEightV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 58, 0);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--remaining-58'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=MBTI64-REMAINING-58-COMPETITOR-GAP-CMS-DRAFT-WRITE-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_remaining_fifty_eight_write_without_approved_queue_rows_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->remainingFiftyEightV2Package();
        $qa = $this->remainingFiftyEightV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--remaining-58'] = true;
        $options['--operator-approved'] = 'MBTI64-REMAINING-58-COMPETITOR-GAP-CMS-DRAFT-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(58, $payload['row_count']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(58, $payload['approval_queue']['missing_count']);
        $this->assertContains('agent_batch_approval_required', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_remaining_fifty_eight_write_creates_only_approved_variant_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        $package = $this->remainingFiftyEightV2Package();
        $qa = $this->remainingFiftyEightV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 58, 0);
        $profileBefore = $this->profileLiveState($targets['en|ENFJ']);
        $variantBefore = $this->variantLiveState($targets['en|ENFJ-T']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--remaining-58'] = true;
        $options['--operator-approved'] = 'MBTI64-REMAINING-58-COMPETITOR-GAP-CMS-DRAFT-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(58, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(58, $payload['created_revision_count']);
        $this->assertSame('remaining_58', $payload['subset']['mode']);
        $this->assertTrue($payload['approval_queue']['ready_for_write']);
        $this->assertSame(58, $payload['approval_queue']['approved_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(58, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|ENFJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|ENFJ-T']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $firstRevision = PersonalityProfileVariantRevision::query()->firstOrFail();
        $firstSnapshot = $firstRevision->snapshot_json['mbti64_agent_projection_draft_v1'] ?? [];
        $firstContent = $firstSnapshot['first_class_draft_fields']['content'] ?? [];
        foreach ($this->expectedV2FirstClassSectionKeys() as $sectionKey) {
            $this->assertArrayHasKey($sectionKey, $firstContent);
        }
        $this->assertStringContainsString('| Dimension | Assertive side | Turbulent side |', (string) ($firstContent['a_t_difference']['body'] ?? ''));

        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot(
                $revision->snapshot_json,
                'MBTI64-REMAINING-58-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
                'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
                9
            );
        }
    }

    public function test_remaining_fifty_eight_fails_closed_when_handoff_contains_arbitrary_url(): void
    {
        $this->seedAllTargets();
        $package = $this->remainingFiftyEightV2Package();
        $package['recommendations'][0] = $this->recommendation('/en/personality/enfj-a');
        $package['recommendations'][0]['recommendation_id'] = 'mbti64-remaining-58-competitor-gap:/en/personality/enfj-a:v2';
        $qa = $this->remainingFiftyEightV2Qa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--remaining-58' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('remaining_58_url_set_mismatch', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('remaining_58_subset_required_urls_missing', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_write_with_partial_approval_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->nextBatchSixPackage();
        $qa = $this->nextBatchSixQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--next-batch-6'] = true;
        $options['--operator-approved'] = 'PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(5, $payload['approval_queue']['approved_count']);
        $this->assertSame(1, $payload['approval_queue']['missing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_next_batch_six_fails_closed_when_handoff_contains_arbitrary_url(): void
    {
        $this->seedAllTargets();
        $package = $this->nextBatchSixPackage();
        $package['recommendations'][0] = $this->recommendation('/en/personality/entp-a');
        $qa = $this->nextBatchSixQa($package);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('next_batch_6_url_set_mismatch', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('next_batch_6_subset_required_urls_missing', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_dry_run_plans_five_artifact_order_rows_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--agent-batch-size' => '5',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $expectedUrls = $this->expectedBatchUrls(5, 0);
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(5, $payload['would_create_revision_count']);
        $this->assertSame('agent_batch_safe', $payload['subset']['mode']);
        $this->assertSame('5', $payload['subset']['batch_size']);
        $this->assertSame('0', $payload['subset']['batch_offset']);
        $this->assertFalse($payload['subset']['arbitrary_url_subset_allowed']);
        $this->assertSame([5, 10], $payload['subset']['allowed_batch_sizes']);
        $this->assertSame($expectedUrls, $payload['subset']['selected_urls']);
        $this->assertTrue($payload['approval_queue']['required_for_write']);
        $this->assertFalse($payload['approval_queue']['ready_for_write']);
        $this->assertTrue($payload['approval_queue']['write_blocked_until_approved']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(5, $payload['approval_queue']['missing_count']);
        $this->assertSame($expectedUrls, array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_dry_run_plans_ten_rows_with_offset_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--agent-batch-size' => '10',
            '--agent-batch-offset' => '5',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $expectedUrls = $this->expectedBatchUrls(10, 5);
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(10, $payload['row_count']);
        $this->assertSame('agent_batch_safe', $payload['subset']['mode']);
        $this->assertSame('10', $payload['subset']['batch_size']);
        $this->assertSame('5', $payload['subset']['batch_offset']);
        $this->assertSame($expectedUrls, $payload['subset']['selected_urls']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_requires_batch_approval_token(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString(
            '--operator-approved=MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01 is required',
            (string) ($payload['errors'][0]['message'] ?? '')
        );
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_without_approved_queue_rows_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(5, $payload['approval_queue']['missing_count']);
        $this->assertTrue($payload['approval_queue']['write_blocked_until_approved']);
        $this->assertContains('agent_batch_approval_required', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_creates_only_selected_draft_revisions(): void
    {
        $targets = $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0);
        $profileBefore = $this->profileLiveState($targets['en|ENFJ']);
        $variantBefore = $this->variantLiveState($targets['en|ENFJ-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(5, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame('agent_batch_safe', $payload['subset']['mode']);
        $this->assertTrue($payload['approval_queue']['ready_for_write']);
        $this->assertSame(5, $payload['approval_queue']['approved_count']);
        $this->assertSame($this->expectedBatchUrls(5, 0), $payload['subset']['selected_urls']);
        $this->assertSame($payload['comparison_row_count'], PersonalityProfileRevision::query()->count());
        $this->assertSame($payload['variant_row_count'], PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|ENFJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|ENFJ-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        foreach (PersonalityProfileRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot($revision->snapshot_json);
        }
        foreach (PersonalityProfileVariantRevision::query()->get() as $revision) {
            $this->assertProjectionSnapshot($revision->snapshot_json);
        }
    }

    public function test_agent_batch_safe_second_write_is_idempotent_for_same_source_hash(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $firstExit = Artisan::call('personality:mbti64-cms-projection-draft', $options);
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(5, $payload['skipped_existing_count']);
        $this->assertSame(5, PersonalityProfileRevision::query()->count() + PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_with_partial_approval_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 4, 0);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(4, $payload['approval_queue']['approved_count']);
        $this->assertSame(1, $payload['approval_queue']['missing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_with_rejected_item_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0, [
            $this->expectedBatchUrls(5, 0)[0] => [
                'approval_state' => 'rejected',
                'approved_at' => null,
                'rejected_at' => now(),
            ],
        ]);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(4, $payload['approval_queue']['approved_count']);
        $this->assertSame(1, $payload['approval_queue']['rejected_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_with_stale_package_hash_approval_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0, [], 'stale-package-sha');
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(5, $payload['approval_queue']['missing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_with_stale_qa_hash_approval_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0, [], null, 'stale-qa-sha');
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, $payload['approval_queue']['approved_count']);
        $this->assertSame(5, $payload['approval_queue']['missing_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_write_with_recommendation_hash_mismatch_fails_closed_without_revisions(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $qa = $this->validQa();
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $qa);
        $this->seedApprovedAgentApprovalRows($package, $qa, $packagePath, $qaPath, 5, 0, [
            $this->expectedBatchUrls(5, 0)[0] => [
                'recommendation_sha256' => str_repeat('a', 64),
            ],
        ]);
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--agent-batch-size'] = '5';
        $options['--operator-approved'] = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(4, $payload['approval_queue']['approved_count']);
        $this->assertSame(1, $payload['approval_queue']['recommendation_hash_mismatch_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_rejects_invalid_batch_size_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--agent-batch-size' => '11',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('agent_batch_size_not_allowed', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_rejects_out_of_range_offset_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--agent-batch-size' => '10',
            '--agent-batch-offset' => '79',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('agent_batch_window_out_of_range', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_agent_batch_safe_rejects_visible_three_combination_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--agent-batch-size' => '5',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('exclusive_subset_modes_required', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_creates_eighty_eight_draft_revisions_without_changing_live_records(): void
    {
        $targets = $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $profileBefore = $this->profileLiveState($targets['en|ENFJ']);
        $variantBefore = $this->variantLiveState($targets['en|ENFJ-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(88, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame(30, PersonalityProfileRevision::query()->count());
        $this->assertSame(58, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|ENFJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|ENFJ-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $comparison = PersonalityProfileRevision::query()
            ->where('profile_id', (int) $targets['en|ENFJ']->id)
            ->firstOrFail();
        $variant = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENFJ-A']->id)
            ->firstOrFail();

        $this->assertProjectionSnapshot($comparison->snapshot_json);
        $this->assertProjectionSnapshot($variant->snapshot_json);
    }

    public function test_second_write_is_idempotent_for_same_source_package_hash(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $firstExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(88, $payload['skipped_existing_count']);
        $this->assertSame(30, PersonalityProfileRevision::query()->count());
        $this->assertSame(58, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_changed_source_hash_creates_next_projection_revision_version(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $mutatedPackage = $package;
        $mutatedPackage['generated_at'] = '2026-06-21T00:00:01Z';
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());
        [$mutatedPackagePath] = $this->writeArtifacts($mutatedPackage, $this->validQa());

        $firstExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($mutatedPackagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertSame(88, $payload['created_revision_count']);
        $this->assertSame(60, PersonalityProfileRevision::query()->count());
        $this->assertSame(116, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_missing_required_write_flag_fails_closed_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        unset($options['--no-search-release']);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('--no-search-release is required', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_missing_target_blocks_entire_write_batch(): void
    {
        $this->seedAllTargets(skip: ['en|ENFJ-A']);
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

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

    public function test_forbidden_private_route_pattern_is_rejected_before_writes(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $package['recommendations'][0]['recommendations']['internal_links'][] = [
            'href' => '/results/lookup',
            'anchor_text' => 'Forbidden',
            'role' => 'forbidden',
            'safe_public_route' => false,
        ];
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_public_route_pattern_present', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_qa_not_pass_blocks_writes(): void
    {
        $this->seedAllTargets();
        $qa = $this->validQa();
        $qa['final_decision'] = 'NO_GO_BLOCKED_BY_QA';
        $qa['summary']['blocked_count'] = 1;
        $qa['blockers'] = ['fixture blocker'];
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $qa);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('qa_not_ready_for_cms_draft', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
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
            'variant_sections' => PersonalityProfileVariantSection::query()->count(),
            'profile_seo_meta' => PersonalityProfileSeoMeta::query()->count(),
            'variant_seo_meta' => PersonalityProfileVariantSeoMeta::query()->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function assertProjectionSnapshot(
        array $snapshot,
        string $expectedArtifact = 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01',
        string $expectedQaDecision = 'PASS_READY_FOR_CMS_DRAFT',
        int $expectedFaqCount = 5,
    ): void {
        $this->assertArrayHasKey('mbti64_agent_projection_draft_v1', $snapshot);
        $payload = $snapshot['mbti64_agent_projection_draft_v1'];
        $this->assertSame($expectedArtifact, $payload['source']['artifact']);
        $this->assertSame($expectedQaDecision, $payload['source']['qa_final_decision']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['seo']['title']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['seo']['description']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['seo']['h1']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['content']['quick_answer']);
        $this->assertCount($expectedFaqCount, $payload['first_class_draft_fields']['faq']);
        $this->assertNotEmpty($payload['first_class_draft_fields']['internal_links']);
        $this->assertFalse((bool) $payload['safety_holds']['publish_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['index_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['sitemap_llms_release_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['search_release_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['runtime_content_updated']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        $recommendations = [];
        foreach ($this->targetPaths() as $path) {
            $recommendations[] = $this->recommendation($path);
        }

        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01',
            'version' => 'mbti64.agent_expansion_88_recommendations.v1',
            'generated_at' => '2026-06-21T00:00:00Z',
            'status' => 'pass_ready_for_qa_gates',
            'summary' => [
                'recommendation_count' => 88,
                'variant_pages' => 58,
                'comparison_pages' => 30,
                'pilot_urls_excluded' => 8,
                'gsc_evidence_state' => 'GSC_EVIDENCE_PENDING',
                'qa_gate_required_count' => 8,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => ['GSC_EVIDENCE_PENDING'],
            'recommended_next_task' => 'PERSONALITY-AGENT-QA-GATES-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validQa(): array
    {
        $pageResults = [];
        foreach ($this->targetPaths() as $path) {
            $pageResults[] = [
                'target_url' => 'https://fermatmind.com'.$path,
                'locale' => str_starts_with($path, '/zh/') ? 'zh-CN' : 'en',
                'page_type' => str_contains($path, '-a-vs-') ? 'comparison' : 'variant',
                'gates' => [
                    'schema_validation' => 'pass',
                    'trademark_claim_gate' => 'pass',
                    'claim_risk_gate' => 'pass',
                    'duplicate_template_gate' => 'pass',
                    'private_route_gate' => 'pass',
                    'result_page_leakage_gate' => 'pass',
                    'seo_projection_gate' => 'pass',
                    'bilingual_consistency_gate' => 'pass',
                ],
                'blockers' => [],
                'warnings' => ['GSC_EVIDENCE_PENDING'],
                'decision' => 'PASS_READY_FOR_CMS_DRAFT',
            ];
        }

        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01',
            'generated_at' => '2026-06-21T00:00:00Z',
            'status' => 'pass_ready_for_cms_draft',
            'input_artifact' => 'docs/seo/personality/mbti64-agent-expansion-88-recommendations-2026-06-21.json',
            'summary' => [
                'checked_recommendation_count' => 88,
                'pass_ready_for_cms_draft_count' => 88,
                'blocked_count' => 0,
                'warning_count' => 1,
            ],
            'page_results' => $pageResults,
            'blockers' => [],
            'warnings' => ['GSC_EVIDENCE_PENDING'],
            'final_decision' => 'PASS_READY_FOR_CMS_DRAFT',
            'recommended_next_task' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nextBatchSixPackage(): array
    {
        $recommendations = [];
        foreach (self::NEXT_BATCH_6_URLS as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $recommendations[] = $this->recommendation($path);
        }

        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-01',
            'generated_at' => '2026-06-25T00:00:00Z',
            'status' => 'pass',
            'summary' => [
                'recommendation_count' => 6,
                'query_backed_count' => 3,
                'bilingual_paired_counterpart_count' => 3,
                'variant_pages' => 6,
                'comparison_pages' => 0,
                'source_qa_pass_count' => 6,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => [],
            'recommended_next_task' => 'PERSONALITY-AGENT-APPROVAL-QUEUE-NEXT-BATCH-6-DRY-RUN-01',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $package
     * @return array<string,mixed>
     */
    private function nextBatchSixQa(?array $package = null): array
    {
        $package ??= $this->nextBatchSixPackage();
        $pageResults = [];
        foreach ((array) ($package['recommendations'] ?? []) as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
            $pageResults[] = [
                'target_url' => $targetUrl,
                'locale' => str_starts_with($path, '/zh/') ? 'zh-CN' : 'en',
                'page_type' => 'variant',
                'gates' => [
                    'schema_validation' => 'pass',
                    'trademark_claim_gate' => 'pass',
                    'claim_risk_gate' => 'pass',
                    'duplicate_template_gate' => 'pass',
                    'private_route_gate' => 'pass',
                    'result_page_leakage_gate' => 'pass',
                    'seo_projection_gate' => 'pass',
                    'bilingual_consistency_gate' => 'pass',
                ],
                'blockers' => [],
                'warnings' => [],
                'decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
            ];
        }

        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-QA-01',
            'generated_at' => '2026-06-25T00:00:00Z',
            'status' => 'pass',
            'summary' => [
                'checked_recommendation_count' => 6,
                'pass_ready_for_approval_review_count' => 6,
                'query_backed_count' => 3,
                'bilingual_paired_counterpart_count' => 3,
                'blocked_count' => 0,
            ],
            'page_results' => $pageResults,
            'blockers' => [],
            'warnings' => [],
            'final_decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
            'recommended_next_task' => 'PERSONALITY-AGENT-APPROVAL-QUEUE-NEXT-BATCH-6-WRITE-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nextBatchSixV2Package(): array
    {
        $recommendations = [];
        foreach (self::NEXT_BATCH_6_URLS as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $recommendation = $this->recommendation($path);
            $locale = str_starts_with($path, '/zh/') ? 'zh' : 'en';
            $typeCode = strtoupper(basename($path));
            $recommended = $recommendation['recommendations'];
            $recommendation['recommendation_id'] = 'mbti64-next-batch-6-competitor-gap:'.$path.':v2';
            $recommendation['locale'] = $locale;
            $recommendation['type_code'] = $typeCode;
            $isQueryBacked = str_contains($path, '/zh/personality/intp-a')
                || str_contains($path, '/zh/personality/esfp-a')
                || str_contains($path, '/en/personality/enfj-a');
            $recommendation['evidence_class'] = $isQueryBacked ? 'query_backed' : 'bilingual_paired_counterpart';
            $recommendation['competitor_gap_basis'] = ['fixture competitor gap'];
            $recommendation['recommendations'] = [
                'title' => (string) ($recommended['title']['recommended'] ?? ''),
                'description' => (string) ($recommended['description']['recommended'] ?? ''),
                'h1' => (string) ($recommended['h1']['recommended'] ?? ''),
                'quick_answer' => (string) ($recommended['quick_answer']['recommended'] ?? ''),
                'sections' => $this->v2SectionsForFixture($path, 'Fixture'),
                'faq' => array_map(
                    static fn (int $index): array => [
                        'question' => 'Fixture expanded question '.$index.'?',
                        'answer' => 'Fixture expanded answer '.$index.' for safe content expansion.',
                    ],
                    range(1, 9)
                ),
                'internal_links' => (array) ($recommended['internal_links'] ?? []),
                'bilingual_parity_notes' => ['paired counterpart fixture'],
                'claim_boundary_notes' => ['no official MBTI affiliation fixture'],
            ];
            $recommendations[] = $recommendation;
        }

        return [
            'artifact' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
            'generated_at' => '2026-06-27T00:00:00Z',
            'status' => 'pass',
            'target_count' => 6,
            'recommendations' => $recommendations,
            'final_decision' => 'PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR',
            'safety_boundary' => [
                'cms_write_attempted' => false,
                'publish_attempted' => false,
                'search_release_attempted' => false,
            ],
            'recommended_next_task' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-QA-01',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $package
     * @return array<string,mixed>
     */
    private function nextBatchSixV2Qa(?array $package = null): array
    {
        $package ??= $this->nextBatchSixV2Package();
        $pageResults = [];
        foreach ((array) ($package['recommendations'] ?? []) as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
            $pageResults[] = [
                'target_url' => $targetUrl,
                'path' => $path,
                'locale' => str_starts_with($path, '/zh/') ? 'zh' : 'en',
                'type_code' => strtoupper(basename($path)),
                'section_count' => 8,
                'faq_count' => 9,
                'private_route_hits' => [],
                'qa_decision' => 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
                'blocked_reason' => null,
                'gates' => [
                    'schema_shape' => 'pass',
                    'at_difference_table' => 'pass',
                    'cognitive_function_mechanism' => 'pass',
                    'work_relationship_communication' => 'pass',
                    'safe_use_boundary' => 'pass',
                    'trademark_claim_boundary' => 'pass',
                    'deterministic_claim_boundary' => 'pass',
                    'private_route_boundary' => 'pass',
                    'bilingual_parity' => 'pass',
                    'duplicate_template_risk' => 'pass_with_monitoring',
                ],
            ];
        }

        return [
            'artifact' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-QA-01',
            'generated_at' => '2026-06-27T00:00:00Z',
            'input_artifact' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
            'summary' => [
                'target_count' => 6,
                'pass_count' => 6,
                'no_go_count' => 0,
                'section_count_min' => 8,
                'section_count_max' => 8,
                'faq_count_min' => 9,
                'faq_count_max' => 9,
            ],
            'page_results' => $pageResults,
            'final_decision' => 'PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR',
            'safety_boundary' => [
                'cms_write_attempted' => false,
                'publish_attempted' => false,
                'search_release_attempted' => false,
            ],
            'recommended_next_task' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-APPROVAL-QUEUE-WRITE-01',
        ];
    }

    /**
     * @return list<string>
     */
    private function expectedV2FirstClassSectionKeys(): array
    {
        return [
            'meaning',
            'a_t_difference',
            'core_traits',
            'careers_work_style',
            'relationships_communication',
            'strengths_blind_spots',
            'common_misreads',
            'similar_types',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function v2SectionsForFixture(string $path, string $prefix): array
    {
        return [
            [
                'key' => 'how_to_read',
                'title' => $prefix.' how to read '.basename($path),
                'body' => $prefix.' how-to-read body for '.$path.'.',
            ],
            [
                'key' => 'at_difference_table',
                'title' => $prefix.' A/T difference table for '.basename($path),
                'body' => $prefix.' A/T difference body for '.$path.'.',
                'comparison_rows' => [
                    [
                        'dimension' => 'Feedback rhythm',
                        'assertive' => 'Uses feedback as a calibration input',
                        'turbulent' => 'Reviews feedback with more self-monitoring',
                    ],
                ],
            ],
            [
                'key' => 'cognitive_function_mechanism',
                'title' => $prefix.' cognitive mechanism for '.basename($path),
                'body' => $prefix.' cognitive-function body for '.$path.'.',
            ],
            [
                'key' => 'work_scenario',
                'title' => $prefix.' work scenario for '.basename($path),
                'body' => $prefix.' work scenario body for '.$path.'.',
            ],
            [
                'key' => 'relationship_communication',
                'title' => $prefix.' relationship communication for '.basename($path),
                'body' => $prefix.' communication body for '.$path.'.',
            ],
            [
                'key' => 'stress_growth',
                'title' => $prefix.' stress and growth for '.basename($path),
                'body' => $prefix.' stress-growth body for '.$path.'.',
            ],
            [
                'key' => 'common_misreads',
                'title' => $prefix.' common misreads for '.basename($path),
                'body' => $prefix.' common misreads body for '.$path.'.',
            ],
            [
                'key' => 'how_to_use_not_use',
                'title' => $prefix.' how to use this page for '.basename($path),
                'body' => $prefix.' safe-use body for '.$path.'.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function remainingFiftyEightV2Package(): array
    {
        $recommendations = [];
        foreach ($this->remainingFiftyEightUrls() as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $recommendation = $this->recommendation($path);
            $locale = str_starts_with($path, '/zh/') ? 'zh' : 'en';
            $recommended = $recommendation['recommendations'];
            $recommendation['recommendation_id'] = 'mbti64-remaining-58-competitor-gap:'.$path.':v2';
            $recommendation['locale'] = $locale;
            $recommendation['type_code'] = strtoupper(basename($path));
            $recommendation['evidence_class'] = 'gsc_pending';
            $recommendation['competitor_gap_basis'] = ['fixture competitor gap'];
            $recommendation['recommendations'] = [
                'title' => (string) ($recommended['title']['recommended'] ?? ''),
                'description' => (string) ($recommended['description']['recommended'] ?? ''),
                'h1' => (string) ($recommended['h1']['recommended'] ?? ''),
                'quick_answer' => (string) ($recommended['quick_answer']['recommended'] ?? ''),
                'sections' => $this->v2SectionsForFixture($path, 'Fixture remaining'),
                'faq' => array_map(
                    static fn (int $index): array => [
                        'question' => 'Fixture remaining question '.$index.'?',
                        'answer' => 'Fixture remaining answer '.$index.' for safe content expansion.',
                    ],
                    range(1, 9)
                ),
                'internal_links' => (array) ($recommended['internal_links'] ?? []),
                'bilingual_parity_notes' => ['paired counterpart fixture'],
                'claim_boundary_notes' => ['no official MBTI affiliation fixture'],
            ];
            $recommendations[] = $recommendation;
        }

        return [
            'artifact' => 'MBTI64-REMAINING-58-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
            'generated_at' => '2026-06-28T12:00:00Z',
            'status' => 'pass',
            'target_count' => 58,
            'final_decision' => 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
            'recommendations' => $recommendations,
            'safety_boundary' => [
                'cms_write' => false,
                'approval_queue_write' => false,
                'live_promotion' => false,
                'publish_index_search' => false,
                'sitemap_llms_mutation' => false,
            ],
            'recommended_next_task' => 'MBTI64-REMAINING-58-COMPETITOR-GAP-APPROVAL-QUEUE-DRY-RUN-01',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $package
     * @return array<string,mixed>
     */
    private function remainingFiftyEightV2Qa(?array $package = null): array
    {
        $package ??= $this->remainingFiftyEightV2Package();
        $pageResults = [];
        foreach ((array) ($package['recommendations'] ?? []) as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
            $pageResults[] = [
                'target_url' => $targetUrl,
                'path' => $path,
                'locale' => str_starts_with($path, '/zh/') ? 'zh' : 'en',
                'page_type' => 'variant',
                'type_code' => strtoupper(basename($path)),
                'section_count' => 8,
                'faq_count' => 9,
                'private_route_hits' => [],
                'qa_decision' => 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
                'blocked_reason' => null,
                'gates' => [
                    'target_scope' => 'pass',
                    'schema_shape' => 'pass',
                    'at_difference_table' => 'pass',
                    'cognitive_function_mechanism' => 'pass',
                    'work_relationship_communication' => 'pass',
                    'safe_use_boundary' => 'pass',
                    'trademark_claim_boundary' => 'pass',
                    'deterministic_claim_boundary' => 'pass',
                    'private_route_boundary' => 'pass',
                    'competitor_copy_boundary' => 'pass',
                    'bilingual_parity' => 'pass',
                    'duplicate_template_risk' => 'pass_with_monitoring',
                ],
            ];
        }

        return [
            'artifact' => 'MBTI64-REMAINING-58-COMPETITOR-GAP-CONTENT-EXPANSION-V2-QA-01',
            'generated_at' => '2026-06-28T12:00:00Z',
            'input_artifact' => 'docs/seo/personality/mbti64-remaining-58-competitor-gap-content-expansion-v2-2026-06-28.json',
            'summary' => [
                'target_count' => 58,
                'pass_count' => 58,
                'no_go_count' => 0,
                'variant_pages' => 58,
                'comparison_pages' => 0,
            ],
            'page_results' => $pageResults,
            'blockers' => [],
            'final_decision' => 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
            'recommended_next_task' => 'MBTI64-REMAINING-58-COMPETITOR-GAP-APPROVAL-QUEUE-WRITE-01',
        ];
    }

    /**
     * @return list<string>
     */
    private function remainingFiftyEightUrls(): array
    {
        $excluded = array_fill_keys(self::NEXT_BATCH_6_URLS, true);
        $urls = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                foreach (['a', 't'] as $variantCode) {
                    $url = 'https://fermatmind.com/'.$prefix.'/personality/'.strtolower($typeCode).'-'.$variantCode;
                    if (! isset($excluded[$url])) {
                        $urls[] = $url;
                    }
                }
            }
        }

        sort($urls);

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function targetPaths(): array
    {
        $paths = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $lower = strtolower($typeCode);
                $comparison = '/'.$prefix.'/personality/'.$lower.'-a-vs-'.$lower.'-t';
                if (! in_array($comparison, self::PILOT_PATHS, true)) {
                    $paths[] = $comparison;
                }
                foreach (['a', 't'] as $variant) {
                    $path = '/'.$prefix.'/personality/'.$lower.'-'.$variant;
                    if (! in_array($path, self::PILOT_PATHS, true)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function expectedBatchUrls(int $size, int $offset): array
    {
        return array_map(
            static fn (string $path): string => 'https://fermatmind.com'.$path,
            array_slice($this->targetPaths(), $offset, $size)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendation(string $path): array
    {
        $locale = str_starts_with($path, '/zh/') ? 'zh-CN' : 'en';
        $slug = basename($path);
        $pageType = str_contains($path, '-a-vs-') ? 'comparison' : 'variant';
        $title = $locale === 'zh-CN'
            ? strtoupper(substr($slug, 0, 4)).' 页面含义 | FermatMind'
            : strtoupper(substr($slug, 0, 4)).' Meaning Guide | FermatMind';

        return [
            'recommendation_id' => 'fixture:'.$path,
            'target_url' => 'https://fermatmind.com'.$path,
            'framework' => 'mbti64',
            'locale' => $locale,
            'source_inputs' => [
                'cms_or_api_snapshot' => 'fixture',
                'reference_pack' => 'fixture',
                'seo_signal' => 'GSC_EVIDENCE_PENDING',
            ],
            'current_surface' => [
                'title' => 'Current '.$slug,
                'description' => 'Current description',
                'h1' => 'Current H1',
                'quick_answer' => '',
                'faq_count' => 0,
                'internal_link_count' => 0,
            ],
            'observed_signal' => [
                'evidence_state' => 'gsc_pending',
                'impressions' => null,
                'clicks' => null,
            ],
            'reference_patterns_used' => [
                ['pattern_id' => 'fixture_reference', 'source_url' => 'https://fermatmind.com/en/personality/intj-a'],
            ],
            'recommendations' => [
                'title' => ['current' => 'Current '.$slug, 'recommended' => $title, 'reason' => 'Fixture reason.'],
                'description' => [
                    'current' => 'Current description',
                    'recommended' => $locale === 'zh-CN'
                        ? '理解这个公开人格页面的行为倾向、压力线索、沟通方式和自我核对，不作为诊断或职业决定。'
                        : 'Understand this public personality page through behavior patterns, stress cues, communication style and self-check prompts.',
                    'reason' => 'Fixture reason.',
                ],
                'h1' => ['current' => 'Current H1', 'recommended' => strtoupper($slug).' Meaning', 'reason' => 'Fixture reason.'],
                'quick_answer' => [
                    'current' => '',
                    'recommended' => $locale === 'zh-CN'
                        ? '这是一个公开人格解释页面，用于自我理解、沟通和学习反思，不是诊断、招聘筛选或确定性结论。'
                        : 'This is a public personality explanation page for self-understanding, communication and learning reflection, not a diagnosis or deterministic verdict.',
                    'reason' => 'Fixture reason.',
                ],
                'faq' => array_map(
                    static fn (int $index): array => [
                        'question' => 'Fixture question '.$index.'?',
                        'answer' => 'Fixture answer '.$index.' for safe public profile reading.',
                        'reason' => 'Fixture QA.',
                    ],
                    [1, 2, 3, 4, 5]
                ),
                'internal_links' => [
                    [
                        'href' => $locale === 'zh-CN' ? '/zh/personality' : '/en/personality',
                        'anchor_text' => $locale === 'zh-CN' ? '人格首页' : 'Personality hub',
                        'role' => 'hub',
                        'safe_public_route' => true,
                    ],
                    [
                        'href' => $locale === 'zh-CN'
                            ? '/zh/tests/mbti-personality-test-16-personality-types'
                            : '/en/tests/mbti-personality-test-16-personality-types',
                        'anchor_text' => $locale === 'zh-CN' ? 'MBTI 测试' : 'MBTI test',
                        'role' => 'related_test',
                        'safe_public_route' => true,
                    ],
                ],
                'differentiation_notes' => [
                    'Fixture differentiation note one.',
                    'Fixture differentiation note two.',
                ],
            ],
            'qa_required' => [
                'schema_validation',
                'trademark_claim_gate',
                'claim_risk_gate',
                'duplicate_template_gate',
                'private_route_gate',
                'result_page_leakage_gate',
                'seo_projection_gate',
                'bilingual_consistency_gate',
            ],
            'blocked_reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array{0:string,1:string}
     */
    private function writeArtifacts(array $package, array $qa): array
    {
        $packagePath = sys_get_temp_dir().'/mbti64-cms-projection-package-'.bin2hex(random_bytes(6)).'.json';
        $qaPath = sys_get_temp_dir().'/mbti64-cms-projection-qa-'.bin2hex(random_bytes(6)).'.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $qaPath];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath, string $qaPath): array
    {
        return [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--write' => true,
            '--json' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,array<string,mixed>>  $overridesByUrl
     */
    private function seedApprovedAgentApprovalRows(
        array $package,
        array $qa,
        string $packagePath,
        string $qaPath,
        int $size,
        int $offset,
        array $overridesByUrl = [],
        ?string $sourceSha256 = null,
        ?string $qaSha256 = null,
    ): void {
        $sourceSha256 ??= hash_file('sha256', $packagePath);
        $qaSha256 ??= hash_file('sha256', $qaPath);
        $recommendations = array_slice((array) $package['recommendations'], $offset, $size);
        $qaResultsByUrl = [];
        foreach ((array) $qa['page_results'] as $qaResult) {
            if (is_array($qaResult)) {
                $qaResultsByUrl[(string) ($qaResult['target_url'] ?? '')] = $qaResult;
            }
        }

        $batchId = (int) DB::table('personality_agent_approval_batches')->insertGetId([
            'framework' => 'mbti64',
            'source_artifact' => (string) ($package['artifact'] ?? ''),
            'source_artifact_path' => $packagePath,
            'source_package_sha256' => $sourceSha256,
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'qa_artifact_path' => $qaPath,
            'qa_sha256' => $qaSha256,
            'status' => 'pending_review',
            'planned_item_count' => count($recommendations),
            'queued_item_count' => count($recommendations),
            'blocked_item_count' => 0,
            'safety_holds_json' => $this->jsonString([
                'cms_write_attempted' => false,
                'publish_attempted' => false,
                'search_release_attempted' => false,
            ]),
            'summary_json' => $this->jsonString(['fixture' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($recommendations as $recommendation) {
            $this->assertIsArray($recommendation);
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
            $override = $overridesByUrl[$targetUrl] ?? [];
            $qaResult = $qaResultsByUrl[$targetUrl] ?? [];
            $row = array_merge([
                'batch_id' => $batchId,
                'framework' => 'mbti64',
                'target_url' => $targetUrl,
                'path' => $path,
                'locale' => str_starts_with($path, '/zh/') ? 'zh-CN' : 'en',
                'page_type' => str_contains($path, '-a-vs-') ? 'personality_profile_comparison' : 'personality_profile_variant',
                'recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
                'recommendation_sha256' => hash('sha256', $this->jsonString($recommendation)),
                'qa_decision' => (string) ($qaResult['decision'] ?? ($qaResult['qa_decision'] ?? 'PASS_READY_FOR_CMS_DRAFT')),
                'approval_state' => 'approved',
                'approved_at' => now(),
                'rejected_at' => null,
                'blocked_reason' => null,
                'safety_holds_json' => $this->jsonString([
                    'cms_write_attempted' => false,
                    'publish_attempted' => false,
                    'search_release_attempted' => false,
                ]),
                'recommendation_json' => $this->jsonString($recommendation),
                'qa_json' => $this->jsonString(is_array($qaResult) ? $qaResult : []),
                'created_at' => now(),
                'updated_at' => now(),
            ], $override);

            DB::table('personality_agent_approval_items')->insert($row);
        }
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

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_map(static fn (string $value): string => $value, $values));
        sort($values);

        return $values;
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
