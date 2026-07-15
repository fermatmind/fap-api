<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class PersonalityMbtiFullCmsPromoteCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_PACKAGE_SHA = '840288581ce02e26afdd40dde1e25cf995fe334791b0a306929a13c76247a78d';

    public function test_command_is_registered_and_dry_run_generates_exact_hashes_without_live_writes(): void
    {
        self::assertArrayHasKey('personality:mbti-full-cms-promote', Artisan::all());
        [$path] = $this->seedAndStage();

        $exitCode = Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, true));
        $summary = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($summary['ok']);
        self::assertSame(43, $summary['row_count']);
        self::assertSame(28, $summary['profile_row_count']);
        self::assertSame(15, $summary['at_comparison_row_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['promotion_package_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['authorization_payload_sha256']);
        self::assertSame(43, count($summary['exact_urls']));
        self::assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        self::assertSame(0, PersonalityProfileVariantSection::query()->count());
        self::assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_dry_run_accepts_database_normalized_json_key_order_for_the_exact_draft(): void
    {
        [$path] = $this->seedAndStage();
        $variant = PersonalityProfileVariant::query()->where('runtime_type_code', 'INTJ-A')->firstOrFail();
        $revision = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', $variant->id)
            ->orderByDesc('revision_no')
            ->firstOrFail();
        $snapshot = $revision->snapshot_json;
        $payload = data_get($snapshot, 'mbti_cms_import_40_profile_draft_v1.payload');
        self::assertIsArray($payload);
        data_set($snapshot, 'mbti_cms_import_40_profile_draft_v1.payload', array_reverse($payload, true));
        $revision->forceFill(['snapshot_json' => $snapshot])->save();

        $sourcePackage = json_decode((string) File::get($path), true);
        self::assertIsArray($sourcePackage);
        $sourcePayload = data_get($sourcePackage, 'repair_records.0.import_payload');
        $storedSnapshot = $revision->fresh()->snapshot_json;
        $storedDraft = data_get($storedSnapshot, 'mbti_cms_import_40_profile_draft_v1');
        $sourceRecord = data_get($sourcePackage, 'repair_records.0');
        $storedPayload = data_get($storedDraft, 'payload');
        self::assertIsArray($sourcePayload);
        self::assertIsArray($sourceRecord);
        self::assertIsArray($storedDraft);
        self::assertIsArray($storedPayload);
        self::assertSame($this->canonicalize($sourcePayload), $this->canonicalize($storedPayload));
        self::assertSame($sourceRecord['approval_record_id'], $storedDraft['approval_record_id']);
        self::assertSame($this->hashJson($sourcePayload), $storedDraft['payload_sha256']);
        self::assertSame($sourceRecord['target_path'], $storedDraft['target_path']);
        self::assertSame($sourceRecord['entity_kind'], $storedDraft['entity_kind']);
        self::assertSame('draft_only', $storedDraft['visibility']);
        self::assertFalse($storedDraft['public_projection_promoted']);
        self::assertFalse($storedDraft['indexability_mutated']);
        self::assertFalse($storedDraft['sitemap_eligibility_mutated']);
        self::assertFalse($storedDraft['llms_eligibility_mutated']);

        $exitCode = Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, true));
        $summary = $this->jsonOutput();

        self::assertSame(0, $exitCode, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));
        self::assertTrue($summary['ok']);
        self::assertSame('pass', $summary['status']);
        self::assertSame(43, $summary['row_count']);
        self::assertFalse($summary['writes_committed']);
    }

    public function test_write_requires_guards_and_exact_dry_run_hashes(): void
    {
        $exitCode = Artisan::call('personality:mbti-full-cms-promote', [
            '--package' => '/tmp/not-read.json',
            '--write' => true,
            '--json' => true,
        ]);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--public-content-promotion-authorized is required', (string) data_get($this->jsonOutput(), 'errors.0.message'));

        [$path] = $this->seedAndStage();
        $plan = $this->plan($path);
        $options = $this->promotionOptions($path, false, $plan);
        $options['--promotion-package-sha256'] = str_repeat('a', 64);
        $writeExit = Artisan::call('personality:mbti-full-cms-promote', $options);
        $summary = $this->jsonOutput();

        self::assertSame(1, $writeExit);
        self::assertFalse($summary['ok']);
        self::assertFalse($summary['writes_committed']);
        self::assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        self::assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_exact_write_promotes_all_43_public_content_records_without_indexability_mutation_and_is_idempotent(): void
    {
        [$path, $profiles] = $this->seedAndStage();
        $cache = app(PersonalityPublicReadModelCache::class);
        $initialToken = $cache->versionToken('INTJ-A', 'zh-CN', 0, 'MBTI');
        $before = $profiles->mapWithKeys(fn (PersonalityProfile $profile): array => [(int) $profile->id => (bool) $profile->is_indexable])->all();
        $plan = $this->plan($path);

        $firstExit = Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, false, $plan));
        $first = $this->jsonOutput();

        self::assertSame(0, $firstExit);
        self::assertTrue($first['ok']);
        self::assertSame(43, $first['promoted_count']);
        self::assertSame(28, $first['read_model_cache_invalidated_count']);
        $firstToken = $cache->versionToken('INTJ-A', 'zh-CN', 0, 'MBTI');
        self::assertNotSame($initialToken, $firstToken);
        self::assertFalse($first['indexability_mutated']);
        self::assertFalse($first['sitemap_mutated']);
        self::assertFalse($first['llms_mutated']);
        self::assertFalse($first['search_release_mutated']);
        self::assertSame(28, PersonalityProfileVariantSeoMeta::query()->count());
        self::assertSame(15, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());
        self::assertSame(28, PersonalityProfileVariantSection::query()->where('section_key', 'faq')->count());
        self::assertSame(28, PersonalityProfileVariantSection::query()->where('section_key', 'mbti_content15_internal_links')->count());

        foreach ($before as $profileId => $isIndexable) {
            self::assertSame($isIndexable, (bool) PersonalityProfile::query()->withoutGlobalScopes()->findOrFail($profileId)->is_indexable);
        }
        self::assertSame('noindex,follow', PersonalityProfileVariantSeoMeta::query()->firstOrFail()->robots);
        self::assertSame('MBTI 人格', data_get(
            PersonalityProfileVariantSection::query()->where('section_key', 'mbti_content15_internal_links')->firstOrFail()->payload_json,
            'items.0.anchor_text'
        ));

        $secondExit = Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, false, $plan));
        $second = $this->jsonOutput();
        self::assertSame(0, $secondExit);
        self::assertSame(0, $second['promoted_count']);
        self::assertSame(43, $second['skipped_existing_count']);
        self::assertSame(28, $second['read_model_cache_invalidated_count']);
        self::assertNotSame($firstToken, $cache->versionToken('INTJ-A', 'zh-CN', 0, 'MBTI'));
    }

    public function test_a_newer_unapproved_revision_fails_closed_without_partial_live_writes(): void
    {
        [$path] = $this->seedAndStage();
        $plan = $this->plan($path);
        $variant = PersonalityProfileVariant::query()->where('runtime_type_code', 'INTJ-A')->firstOrFail();
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $variant->id,
            'revision_no' => 2,
            'snapshot_json' => ['unrelated_newer_revision' => ['status' => 'draft']],
            'note' => 'newer unrelated draft',
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, false, $plan));
        $summary = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($summary['ok']);
        self::assertSame('latest_revision_contract_mismatch', data_get($summary, 'errors.0.code'));
        self::assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        self::assertSame(0, PersonalityProfileVariantSection::query()->count());
        self::assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_full_indexability_release_requires_all_exact_public_content_to_be_live(): void
    {
        [$path] = $this->seedAndStage();

        [$exitCode, $summary] = $this->callIndexability($this->indexabilityOptions($path, true));

        self::assertSame(1, $exitCode);
        self::assertFalse($summary['ok']);
        self::assertContains('prestate_mismatch', array_column($summary['errors'], 'code'));
        self::assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        self::assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_full_indexability_release_dry_run_returns_exact_hashes_without_mutation(): void
    {
        self::assertArrayHasKey('personality:mbti-full-indexability-promote', Artisan::all());
        [$path] = $this->seedAndStage();
        $this->promotePublicContent($path);

        [$exitCode, $summary] = $this->callIndexability($this->indexabilityOptions($path, true));

        self::assertSame(0, $exitCode, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));
        self::assertTrue($summary['ok']);
        self::assertSame(43, $summary['record_count']);
        self::assertSame(28, $summary['profile_row_count']);
        self::assertSame(15, $summary['at_comparison_row_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['promotion_package_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['authorization_payload_sha256']);
        self::assertFalse($summary['writes_committed']);
        self::assertFalse($summary['gsc_executed']);
        self::assertFalse($summary['url_inspection_executed']);
        self::assertSame(28, PersonalityProfileVariantSeoMeta::query()->where('robots', 'noindex,follow')->count());
        self::assertSame(15, PersonalityProfileSection::query()
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->get()
            ->filter(static fn (PersonalityProfileSection $section): bool => data_get($section->payload_json, 'robots') === 'noindex,follow')
            ->count());
    }

    public function test_full_indexability_release_requires_exact_guards_and_promotes_all_43_idempotently(): void
    {
        [$path] = $this->seedAndStage();
        $this->promotePublicContent($path);
        $plan = $this->indexabilityPlan($path);

        $bad = $this->indexabilityOptions($path, false, $plan);
        unset($bad['--no-search-submission']);
        [$badExit, $badSummary] = $this->callIndexability($bad);
        self::assertSame(1, $badExit);
        self::assertStringContainsString('--no-search-submission is required', (string) data_get($badSummary, 'errors.0.message'));
        self::assertSame(28, PersonalityProfileVariantSeoMeta::query()->where('robots', 'noindex,follow')->count());

        [$firstExit, $first] = $this->callIndexability($this->indexabilityOptions($path, false, $plan));
        self::assertSame(0, $firstExit, (string) json_encode($first, JSON_UNESCAPED_SLASHES));
        self::assertTrue($first['ok']);
        self::assertTrue($first['writes_committed']);
        self::assertSame(43, $first['already_promoted_count']);
        self::assertSame(28, $first['read_model_cache_invalidated_count']);
        self::assertFalse($first['gsc_executed']);
        self::assertFalse($first['url_inspection_executed']);
        self::assertSame(28, PersonalityProfileVariantSeoMeta::query()->where('robots', 'index,follow')->count());
        self::assertSame(14, PersonalityProfile::query()->withoutGlobalScopes()
            ->whereIn('canonical_type_code', array_slice($this->baseTypes(), 0, 14))
            ->where('is_indexable', true)
            ->count());
        self::assertSame(15, PersonalityProfileSection::query()
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->get()
            ->filter(static fn (PersonalityProfileSection $section): bool => data_get($section->payload_json, 'robots') === 'index,follow'
                && data_get($section->payload_json, 'indexability_held') === false)
            ->count());

        [$secondExit, $second] = $this->callIndexability($this->indexabilityOptions($path, false, $plan));
        self::assertSame(0, $secondExit, (string) json_encode($second, JSON_UNESCAPED_SLASHES));
        self::assertSame(43, $second['already_promoted_count']);
        self::assertTrue($second['writes_committed']);
    }

    public function test_full_indexability_release_uses_zh_cn_authority_when_runtime_codes_overlap_across_locales(): void
    {
        $englishProfile = $this->seedEnglishProfile('INTJ');
        [$path] = $this->seedAndStage();
        $this->promotePublicContent($path);

        foreach ($englishProfile->variants as $variant) {
            PersonalityProfileVariantSeoMeta::query()->create([
                'personality_profile_variant_id' => $variant->id,
                'seo_title' => $variant->runtime_type_code.' English',
                'seo_description' => 'English authority must remain held.',
                'canonical_url' => 'https://fermatmind.com/en/personality/'.strtolower($variant->runtime_type_code),
                'robots' => 'noindex,follow',
            ]);
        }

        $plan = $this->indexabilityPlan($path);
        $intjRows = collect($plan['rows'])
            ->where('entity_kind', 'profile')
            ->whereIn('slug', ['intj-a', 'intj-t'])
            ->values();
        self::assertCount(2, $intjRows);
        self::assertSame(
            ['zh-CN'],
            $intjRows->map(fn (array $row): string => (string) PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->findOrFail($row['profile_id'])
                ->locale)->unique()->values()->all()
        );

        [$exitCode, $summary] = $this->callIndexability($this->indexabilityOptions($path, false, $plan));
        self::assertSame(0, $exitCode, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));
        self::assertFalse((bool) $englishProfile->fresh()->is_indexable);
        self::assertSame(
            2,
            PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()
                ->whereIn('personality_profile_variant_id', $englishProfile->variants->pluck('id'))
                ->where('robots', 'noindex,follow')
                ->count()
        );

        $zhProfile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('locale', 'zh-CN')
            ->where('canonical_type_code', 'INTJ')
            ->firstOrFail();
        self::assertTrue((bool) $zhProfile->is_indexable);
        self::assertSame(
            2,
            PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()
                ->whereIn('personality_profile_variant_id', $zhProfile->variants()->pluck('id'))
                ->where('robots', 'index,follow')
                ->count()
        );
    }

    /** @return array{string,\Illuminate\Database\Eloquent\Collection<int,PersonalityProfile>} */
    private function seedAndStage(): array
    {
        $profiles = $this->seedProfiles();
        $path = $this->writePackage($this->validPackage());
        $exitCode = Artisan::call('personality:mbti-full-cms-import', [
            '--package' => $path,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--authorization-payload-sha256' => 'e44d567ad6092d61076ae70009e5cfa39d1d7b3f5b3a78367e0d241a28ede31e',
            '--import-scope-mode' => 'full_chinese_mbti_repair_batch_only',
            '--record-count' => '43',
            '--write' => true,
            '--production-import-authorized' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--json' => true,
        ]);
        self::assertSame(0, $exitCode, Artisan::output());

        return [$path, $profiles];
    }

    /** @return array<string,mixed> */
    private function plan(string $path): array
    {
        self::assertSame(0, Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, true)));

        return $this->jsonOutput();
    }

    private function promotePublicContent(string $path): void
    {
        $plan = $this->plan($path);
        self::assertSame(0, Artisan::call('personality:mbti-full-cms-promote', $this->promotionOptions($path, false, $plan)), Artisan::output());
    }

    /** @return array<string,mixed> */
    private function indexabilityPlan(string $path): array
    {
        [$exitCode, $summary] = $this->callIndexability($this->indexabilityOptions($path, true));
        self::assertSame(0, $exitCode, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));

        return $summary;
    }

    /** @param array<string,mixed> $options @return array{int,array<string,mixed>} */
    private function callIndexability(array $options): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('personality:mbti-full-indexability-promote', $options, $output);
        $decoded = json_decode(trim($output->fetch()), true);
        self::assertIsArray($decoded);

        return [$exitCode, $decoded];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function indexabilityOptions(string $path, bool $dryRun, array $plan = []): array
    {
        $options = [
            '--package' => $path,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--import-scope-mode' => 'full_chinese_mbti_repair_batch_only',
            '--record-count' => '43',
            $dryRun ? '--dry-run' : '--write' => true,
            '--json' => true,
        ];
        if (! $dryRun) {
            $options += [
                '--promotion-package-sha256' => (string) ($plan['promotion_package_sha256'] ?? ''),
                '--authorization-payload-sha256' => (string) ($plan['authorization_payload_sha256'] ?? ''),
                '--production-promotion-authorized' => true,
                '--release-indexability' => true,
                '--release-sitemap' => true,
                '--release-llms' => true,
                '--no-gsc' => true,
                '--no-url-inspection' => true,
                '--no-search-submission' => true,
            ];
        }

        return $options;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function promotionOptions(string $path, bool $dryRun, array $plan = []): array
    {
        $options = [
            '--package' => $path,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--import-scope-mode' => 'full_chinese_mbti_repair_batch_only',
            '--record-count' => '43',
            $dryRun ? '--dry-run' : '--write' => true,
            '--json' => true,
        ];
        if (! $dryRun) {
            $options += [
                '--promotion-package-sha256' => (string) ($plan['promotion_package_sha256'] ?? ''),
                '--authorization-payload-sha256' => (string) ($plan['authorization_payload_sha256'] ?? ''),
                '--public-content-promotion-authorized' => true,
                '--no-index' => true,
                '--no-sitemap' => true,
                '--no-llms' => true,
                '--no-search-release' => true,
            ];
        }

        return $options;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,PersonalityProfile> */
    private function seedProfiles(): \Illuminate\Database\Eloquent\Collection
    {
        foreach ($this->baseTypes() as $index => $type) {
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
                'is_indexable' => $index % 2 === 0,
                'published_at' => now()->subMinute(),
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            ]);
            foreach (['A', 'T'] as $variantCode) {
                PersonalityProfileVariant::query()->create([
                    'org_id' => 0,
                    'personality_profile_id' => $profile->id,
                    'canonical_type_code' => $type,
                    'variant_code' => $variantCode,
                    'runtime_type_code' => $type.'-'.$variantCode,
                    'type_name' => $type.' '.$variantCode,
                    'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                    'is_published' => true,
                    'published_at' => now()->subMinute(),
                ]);
            }
        }

        return PersonalityProfile::query()->withoutGlobalScopes()->orderBy('id')->get();
    }

    private function seedEnglishProfile(string $type): PersonalityProfile
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $type,
            'canonical_type_code' => $type,
            'slug' => strtolower($type),
            'locale' => 'en',
            'title' => $type,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        foreach (['A', 'T'] as $variantCode) {
            PersonalityProfileVariant::query()->create([
                'org_id' => 0,
                'personality_profile_id' => $profile->id,
                'canonical_type_code' => $type,
                'variant_code' => $variantCode,
                'runtime_type_code' => $type.'-'.$variantCode,
                'type_name' => $type.' '.$variantCode,
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                'is_published' => true,
                'published_at' => now()->subMinute(),
            ]);
        }

        return $profile->load('variants');
    }

    /** @return array<string,mixed> */
    private function validPackage(): array
    {
        $records = [];
        foreach (array_slice($this->baseTypes(), 0, 14) as $base) {
            foreach (['A', 'T'] as $variant) {
                $records[] = $this->record('profile', strtolower($base.'-'.$variant), [
                    'canonical_type_code' => $base,
                    'runtime_type_code' => $base.'-'.$variant,
                ]);
            }
        }
        foreach (array_slice($this->baseTypes(), 0, 15) as $base) {
            $records[] = $this->record('at_comparison', strtolower($base).'-a-vs-'.strtolower($base).'-t', [
                'base_type_code' => $base,
                'left_type_code' => $base.'-A',
                'right_type_code' => $base.'-T',
                'comparison_slug' => strtolower($base).'-a-vs-'.strtolower($base).'-t',
            ]);
        }

        return [
            'artifact' => 'MBTI-CMS-APPROVAL-39-EXACT-43-RECORD-REPAIR-APPROVAL-PACKAGE',
            'status' => 'approved_for_fail_closed_importer_preflight',
            'exact_package' => [
                'source_package_sha256' => self::SOURCE_PACKAGE_SHA,
                'authorization_payload_sha256' => 'e44d567ad6092d61076ae70009e5cfa39d1d7b3f5b3a78367e0d241a28ede31e',
                'import_scope_mode' => 'full_chinese_mbti_repair_batch_only',
                'record_count' => 43,
                'production_import_authorized' => false,
                'production_import_executed' => false,
            ],
            'repair_records' => $records,
        ];
    }

    /** @param array<string,string> $identity @return array<string,mixed> */
    private function record(string $kind, string $slug, array $identity): array
    {
        $payload = [
            'locale' => 'zh-CN',
            'page_type' => $kind === 'profile' ? 'variant' : 'comparison',
            'comparison_kind' => $kind === 'at_comparison' ? 'at' : null,
            'identity' => $identity,
            'canonical_target' => '/zh/personality/'.$slug,
            'canonical' => 'https://fermatmind.com/zh/personality/'.$slug,
            'robots' => 'noindex,follow',
            'seo' => [
                'seo_title' => $slug.' title',
                'seo_description' => $slug.' description',
                'h1' => $slug.' heading',
                'quick_answer_summary' => $slug.' answer',
            ],
            'content' => ['quick_answer' => $slug.' answer'],
            'content_sections' => [['key' => 'definition', 'title' => '定义', 'body' => $slug.' body']],
            'faq' => [['question' => $slug.' question', 'answer' => $slug.' answer']],
            'internal_links' => [['href' => '/zh/personality', 'label' => 'MBTI 人格', 'purpose' => 'personality_hub', 'safe_public_route' => true]],
            'structured_metadata' => ['primary_query' => $slug],
            'import_visibility' => [
                'draft_only' => true,
                'no_public_promotion' => true,
                'no_indexability_mutation' => true,
                'no_sitemap_mutation' => true,
                'no_llms_mutation' => true,
            ],
        ];

        return [
            'approval_record_id' => 'approval:'.$slug,
            'source_asset_id' => 'asset:'.$slug,
            'target_path' => '/zh/personality/'.$slug,
            'target_url' => 'https://fermatmind.com/zh/personality/'.$slug,
            'locale' => 'zh-CN',
            'slug' => $slug,
            'entity_kind' => $kind,
            'expected_pre_state' => [
                'record_must_exist' => true,
                'public_projection_must_remain_unchanged_by_import' => true,
                'locale' => 'zh-CN',
                'framework' => 'mbti64',
                'entity_kind' => $kind,
            ],
            'expected_post_state' => [
                'revision_staged' => true,
                'revision_visibility' => 'draft_only',
                'public_projection_promoted' => false,
                'is_indexable_mutated' => false,
                'sitemap_eligibility_mutated' => false,
                'llms_eligibility_mutated' => false,
                'content_payload_sha256' => $this->hashJson($payload),
            ],
            'import_payload' => $payload,
        ];
    }

    /** @return list<string> */
    private function baseTypes(): array
    {
        return ['INTJ', 'INTP', 'ENTJ', 'ENTP', 'INFJ', 'INFP', 'ENFJ', 'ENFP', 'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ', 'ISTP', 'ISFP', 'ESTP', 'ESFP'];
    }

    /** @param array<string,mixed> $package */
    private function writePackage(array $package): string
    {
        $path = storage_path('framework/testing/mbti-cms-promotion-'.uniqid('', true).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $output = trim(Artisan::output());
        self::assertNotSame('', $output, 'Artisan command did not emit JSON.');
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, 'Invalid JSON: '.$output.'; error='.json_last_error_msg());

        return $decoded;
    }

    /** @param array<string,mixed> $value */
    private function hashJson(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
