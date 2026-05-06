<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerMaterializeDuplicateAliasMapCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('framework/testing/career-duplicate-alias-materialization'));
        $this->ledgerPath = $this->writeLedgerFixture();
        $this->seedCanonicalTargetOccupations();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/career-duplicate-alias-materialization'));

        parent::tearDown();
    }

    public function test_dry_run_selects_87_ledger_approved_aliases_and_writes_nothing(): void
    {
        $outputPath = storage_path('framework/testing/career-duplicate-alias-materialization/dry-run.json');

        $exitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--dry-run' => true,
            '--json' => true,
            '--ledger' => $this->ledgerPath,
            '--timestamp' => 'duplicate-alias-dry-run-test',
            '--output' => $outputPath,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame(87, (int) ($payload['alias_count'] ?? 0));
        $this->assertSame(87, (int) ($payload['ledger_approved_aliases'] ?? 0));
        $this->assertSame(254, (int) ($payload['duplicate_identity_rows'] ?? 0));
        $this->assertSame(167, (int) ($payload['blocked_duplicate_rows'] ?? 0));
        $this->assertSame(167, (int) ($payload['blocked_duplicate_rows_after_target_gate'] ?? 0));
        $this->assertSame(0, (int) ($payload['aliases_blocked_due_target_release'] ?? -1));
        $this->assertSame(793, (int) ($payload['canonical_public_assets'] ?? 0));
        $this->assertSame(87, (int) ($payload['canonical_targets_valid'] ?? 0));
        $this->assertSame(0, (int) ($payload['canonical_promotions'] ?? -1));
        $this->assertFalse((bool) ($payload['did_write'] ?? true));
        $this->assertTrue((bool) ($payload['would_write'] ?? false));
        $this->assertSame(87, (int) ($payload['aliases_to_create'] ?? 0));
        $this->assertSame(0, (int) ($payload['aliases_to_update'] ?? -1));
        $this->assertSame(0, (int) ($payload['aliases_to_disable'] ?? -1));
        $this->assertSame(0, (int) ($payload['career_job_display_assets_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupations_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupation_crosswalks_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_alias_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_alias_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_alias_urls'] ?? -1));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertSame(0, OccupationAlias::query()->count());
        $this->assertFileExists($outputPath);
    }

    public function test_force_materializes_87_aliases_and_idempotency_dry_run_is_clean(): void
    {
        $forceExitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--force' => true,
            '--json' => true,
            '--ledger' => $this->ledgerPath,
            '--timestamp' => 'duplicate-alias-force-test',
        ]);
        $forcePayload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $forceExitCode, Artisan::output());
        $this->assertSame(87, (int) ($forcePayload['alias_count'] ?? 0));
        $this->assertSame(87, (int) ($forcePayload['ledger_approved_aliases'] ?? 0));
        $this->assertSame(87, (int) ($forcePayload['aliases_to_create'] ?? 0));
        $this->assertSame(0, (int) ($forcePayload['aliases_to_update'] ?? -1));
        $this->assertSame(0, (int) ($forcePayload['aliases_to_disable'] ?? -1));
        $this->assertTrue((bool) ($forcePayload['did_write'] ?? false));
        $this->assertSame(87, (int) ($forcePayload['activated_aliases'] ?? 0));
        $this->assertSame(0, (int) ($forcePayload['career_job_display_assets_delta'] ?? -1));
        $this->assertSame(0, (int) ($forcePayload['occupations_delta'] ?? -1));
        $this->assertSame(0, (int) ($forcePayload['occupation_crosswalks_delta'] ?? -1));

        $this->assertSame(87, OccupationAlias::query()
            ->where('register', 'public_resolution_duplicate_alias')
            ->where('intent_scope', 'duplicate_identity')
            ->where('target_kind', 'ledger_public_alias_redirect')
            ->count());
        $this->assertSame(87, OccupationAlias::query()->count());

        $idempotencyExitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--dry-run' => true,
            '--json' => true,
            '--ledger' => $this->ledgerPath,
            '--timestamp' => 'duplicate-alias-idempotency-test',
        ]);
        $idempotencyPayload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $idempotencyExitCode, Artisan::output());
        $this->assertFalse((bool) ($idempotencyPayload['did_write'] ?? true));
        $this->assertFalse((bool) ($idempotencyPayload['would_write'] ?? true));
        $this->assertSame(0, (int) ($idempotencyPayload['aliases_to_create'] ?? -1));
        $this->assertSame(0, (int) ($idempotencyPayload['aliases_to_update'] ?? -1));
        $this->assertSame(0, (int) ($idempotencyPayload['aliases_to_disable'] ?? -1));
        $this->assertSame(87, (int) ($idempotencyPayload['activated_aliases'] ?? 0));
        $this->assertSame(167, (int) ($idempotencyPayload['blocked_duplicate_rows'] ?? 0));
    }

    public function test_command_blocks_and_disables_aliases_whose_targets_are_not_release_eligible(): void
    {
        $this->markTargetNoindex('canonical-0001');
        $occupation = Occupation::query()->where('canonical_slug', 'canonical-0001')->firstOrFail();
        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'duplicate-0001',
            'normalized' => 'duplicate-0001',
            'lang' => 'en-US',
            'register' => 'public_resolution_duplicate_alias',
            'intent_scope' => 'duplicate_identity',
            'target_kind' => 'ledger_public_alias_redirect',
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
        ]);

        $dryRunExitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--dry-run' => true,
            '--json' => true,
            '--ledger' => $this->ledgerPath,
            '--timestamp' => 'duplicate-alias-target-release-dry-run-test',
        ]);
        $dryRunPayload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $dryRunExitCode, Artisan::output());
        $this->assertSame(87, (int) ($dryRunPayload['ledger_approved_aliases'] ?? 0));
        $this->assertSame(86, (int) ($dryRunPayload['alias_count'] ?? 0));
        $this->assertSame(1, (int) ($dryRunPayload['aliases_blocked_due_target_release'] ?? 0));
        $this->assertSame(168, (int) ($dryRunPayload['blocked_duplicate_rows_after_target_gate'] ?? 0));
        $this->assertSame(1, (int) ($dryRunPayload['aliases_to_disable'] ?? 0));
        $this->assertSame(86, (int) ($dryRunPayload['aliases_to_create'] ?? 0));
        $this->assertFalse((bool) ($dryRunPayload['did_write'] ?? true));
        $this->assertSame(1, OccupationAlias::query()->count());

        $forceExitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--force' => true,
            '--json' => true,
            '--ledger' => $this->ledgerPath,
            '--timestamp' => 'duplicate-alias-target-release-force-test',
        ]);
        $forcePayload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $forceExitCode, Artisan::output());
        $this->assertSame(86, (int) ($forcePayload['alias_count'] ?? 0));
        $this->assertSame(1, (int) ($forcePayload['aliases_to_disable'] ?? 0));
        $this->assertSame(86, (int) ($forcePayload['activated_aliases'] ?? 0));
        $this->assertFalse(OccupationAlias::query()
            ->where('register', 'public_resolution_duplicate_alias')
            ->where('intent_scope', 'duplicate_identity')
            ->where('target_kind', 'ledger_public_alias_redirect')
            ->where('normalized', 'duplicate-0001')
            ->exists());
    }

    public function test_command_rejects_alias_targets_outside_the_approved_canonical_set(): void
    {
        $badLedgerPath = $this->writeLedgerFixture(badAliasTarget: true);

        $exitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--force' => true,
            '--json' => true,
            '--ledger' => $badLedgerPath,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertContains('alias_target_not_approved_canonical:duplicate-0001', $payload['blockers'] ?? []);
        $this->assertSame(0, OccupationAlias::query()->count());
    }

    public function test_command_rejects_dry_run_and_force_together(): void
    {
        $exitCode = Artisan::call('career:materialize-duplicate-alias-map', [
            '--dry-run' => true,
            '--force' => true,
            '--json' => true,
            '--ledger' => $this->ledgerPath,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertContains('Choose either --dry-run or --force, not both.', $payload['blockers'] ?? []);
    }

    private function seedCanonicalTargetOccupations(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'career-duplicate-alias-targets',
            'title_en' => 'Career Duplicate Alias Targets',
            'title_zh' => '职业重复别名目标',
        ]);

        for ($index = 1; $index <= 87; $index++) {
            $slug = sprintf('canonical-%04d', $index);
            Occupation::query()->create([
                'family_id' => $family->id,
                'canonical_slug' => $slug,
                'entity_level' => 'market_child',
                'truth_market' => 'US',
                'display_market' => 'US',
                'crosswalk_mode' => 'direct_match',
                'canonical_title_en' => 'Canonical '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'canonical_title_zh' => '标准职业'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'search_h1_zh' => '标准职业'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            ]);
            $this->createReleaseEligibleCareerJobs($slug);
        }
    }

    private function createReleaseEligibleCareerJobs(string $slug): void
    {
        foreach (CareerJob::SUPPORTED_LOCALES as $locale) {
            $job = CareerJob::query()->create([
                'org_id' => 0,
                'job_code' => $slug,
                'slug' => $slug,
                'locale' => $locale,
                'title' => 'Canonical '.$slug,
                'excerpt' => 'Canonical target excerpt',
                'status' => CareerJob::STATUS_PUBLISHED,
                'is_public' => true,
                'is_indexable' => true,
                'schema_version' => 'v1',
                'sort_order' => 0,
                'published_at' => Carbon::now()->subDay(),
            ]);

            CareerJobSeoMeta::query()->create([
                'job_id' => (int) $job->id,
                'seo_title' => 'Canonical '.$slug,
                'seo_description' => 'Canonical target description',
                'canonical_url' => 'https://example.test/'.($locale === 'zh-CN' ? 'zh' : $locale).'/career/jobs/'.$slug,
                'og_title' => 'Canonical '.$slug,
                'og_description' => 'Canonical target description',
                'og_image_url' => 'https://example.test/images/career.png',
                'twitter_title' => 'Canonical '.$slug,
                'twitter_description' => 'Canonical target description',
                'twitter_image_url' => 'https://example.test/images/career.png',
                'robots' => 'index,follow',
            ]);
        }
    }

    private function markTargetNoindex(string $slug): void
    {
        $jobs = CareerJob::query()->where('slug', $slug)->get();
        foreach ($jobs as $job) {
            $job->forceFill(['is_indexable' => false])->save();
            $job->seoMeta()->update(['robots' => 'noindex,follow']);
        }
    }

    private function writeLedgerFixture(bool $badAliasTarget = false): string
    {
        $path = storage_path('framework/testing/career-duplicate-alias-materialization/'.($badAliasTarget ? 'bad-' : '').'ledger.json');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, (string) json_encode([
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'career.release_ledger.full_342.v1',
            'public_resolution' => [
                'ledger_kind' => 'career_public_resolution_ledger',
                'ledger_version' => 'career.public_resolution_ledger.2786.v1',
                'counts' => [
                    'total_rows' => 2786,
                    'public_canonical_job' => 793,
                    'public_alias_redirect' => 87,
                    'duplicate_identity_hold' => 254,
                    'duplicate_alias_decisions' => 87,
                    'duplicate_blocked_non_public' => 167,
                    'duplicate_canonical_promotions' => 0,
                ],
                'rows' => $this->ledgerRows($badAliasTarget),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(bool $badAliasTarget): array
    {
        $rows = [];
        $rowNumber = 2;

        for ($index = 1; $index <= 793; $index++) {
            $slug = sprintf('canonical-%04d', $index);
            $rows[] = [
                'row_number' => $rowNumber++,
                'source_slug' => $slug,
                'current_status' => $index <= 311 ? 'already_imported_validated' : 'upload_candidate',
                'governance_decision' => 'baseline_public_canonical',
                'public_resolution_type' => 'public_canonical_job',
                'target_canonical_slug' => $slug,
                'redirect_target' => null,
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ];
        }

        for ($index = 1; $index <= 254; $index++) {
            $sourceSlug = sprintf('duplicate-%04d', $index);
            $approvedAlias = $index <= 87;
            $targetSlug = $badAliasTarget && $index === 1 ? 'not-approved-target' : sprintf('canonical-%04d', $index);
            $rows[] = [
                'row_number' => $rowNumber++,
                'source_slug' => $sourceSlug,
                'current_status' => 'duplicate_identity_hold',
                'governance_decision' => $approvedAlias
                    ? 'duplicate_identity_high_confidence_alias_redirect'
                    : 'duplicate_identity_blocked_until_review',
                'public_resolution_type' => $approvedAlias
                    ? 'public_alias_redirect'
                    : 'blocked_until_governance_approval',
                'target_canonical_slug' => $approvedAlias ? $targetSlug : null,
                'redirect_target' => $approvedAlias ? '/career/jobs/'.$targetSlug : null,
                'indexability' => $approvedAlias ? 'no_independent_index' : 'not_public',
                'public_eligible' => $approvedAlias,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ];
        }

        for ($index = 1; $index <= 1663; $index++) {
            $rows[] = $this->blockedHoldRow($rowNumber++, sprintf('cn-proxy-%04d', $index), 'CN_proxy_hold');
        }

        for ($index = 1; $index <= 75; $index++) {
            $rows[] = $this->blockedHoldRow($rowNumber++, sprintf('broad-group-%04d', $index), 'broad_group_hold');
        }

        $rows[] = $this->blockedHoldRow($rowNumber, 'software-developers', 'manual_hold', 'keep_non_public_with_policy');

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedHoldRow(
        int $rowNumber,
        string $sourceSlug,
        string $status,
        string $resolutionType = 'blocked_until_governance_approval',
    ): array {
        return [
            'row_number' => $rowNumber,
            'source_slug' => $sourceSlug,
            'current_status' => $status,
            'governance_decision' => 'default_hold_requires_governance',
            'public_resolution_type' => $resolutionType,
            'target_canonical_slug' => null,
            'redirect_target' => null,
            'indexability' => 'not_public',
            'public_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
        ];
    }
}
