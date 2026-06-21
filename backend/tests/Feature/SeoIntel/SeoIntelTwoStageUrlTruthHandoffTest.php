<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentPage;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\ResearchReport;
use App\Services\SeoIntel\UrlTruthHandoffArtifact;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelTwoStageUrlTruthHandoffTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_exports_and_validates_research_report_handoff_artifact_without_writes(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);
        $this->createResearchReport([
            'slug' => 'mbti-personality-types-salary-turnover-report',
            'locale' => 'en',
            'canonical_path' => '/en/research/mbti-personality-types-salary-turnover-report',
        ]);

        $path = sys_get_temp_dir().'/research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';

        $exportExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'research_report',
        ]);
        $exportOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exportExitCode, json_encode($exportOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $exportOutput['status'] ?? null);
        $this->assertTrue((bool) ($exportOutput['dry_run'] ?? false));
        $this->assertFalse((bool) ($exportOutput['writes_committed'] ?? true));
        $this->assertSame(1, $exportOutput['planned_url_count'] ?? null);
        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);
        $this->assertSame(UrlTruthHandoffArtifact::SCHEMA_VERSION, $artifact['schema_version'] ?? null);
        $this->assertSame(['seo_urls', 'seo_url_entities'], $artifact['target_tables'] ?? null);
        $this->assertSame('research_report', data_get($artifact, 'candidates.0.page_entity_type'));
        $this->assertSame('backend_cms', data_get($artifact, 'candidates.0.source_authority'));
        $this->assertSame('/en/research/mbti-personality-types-salary-turnover-report', parse_url((string) data_get($artifact, 'candidates.0.canonical_url'), PHP_URL_PATH));

        $importExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
        ]);
        $importOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $importExitCode);
        $this->assertSame('success', $importOutput['status'] ?? null);
        $this->assertSame('import_dry_run', $importOutput['mode'] ?? null);
        $this->assertSame(1, $importOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($importOutput['writes_committed'] ?? true));
    }

    #[Test]
    public function command_exports_and_validates_article_handoff_artifact_without_writes_or_search_submission(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $article = $this->createPublishedArticle([
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'title' => 'MBTI 基础指南',
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => $article->id,
            'locale' => 'zh-CN',
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-basics',
            'is_indexable' => true,
        ]);

        $path = sys_get_temp_dir().'/article-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';

        $exportExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'article',
        ]);
        $exportOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exportExitCode, json_encode($exportOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $exportOutput['status'] ?? null);
        $this->assertSame('article', $exportOutput['page_entity_type'] ?? null);
        $this->assertTrue((bool) ($exportOutput['dry_run'] ?? false));
        $this->assertFalse((bool) ($exportOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($exportOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($exportOutput['search_url_submission'] ?? true));
        $this->assertSame(1, $exportOutput['planned_url_count'] ?? null);
        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);
        $this->assertSame(UrlTruthHandoffArtifact::SCHEMA_VERSION, $artifact['schema_version'] ?? null);
        $this->assertSame('two_stage_article_url_truth_handoff', $artifact['mode'] ?? null);
        $this->assertSame('article', data_get($artifact, 'constraints.allowed_page_entity_type'));
        $this->assertSame('^/(en|zh)/articles/[a-z0-9][a-z0-9-]*$', data_get($artifact, 'constraints.allowed_route_regex'));
        $this->assertSame('article', data_get($artifact, 'candidates.0.page_entity_type'));
        $this->assertSame('backend_cms', data_get($artifact, 'candidates.0.source_authority'));
        $this->assertSame('articles', data_get($artifact, 'candidates.0.entity_source'));
        $this->assertSame((string) $article->id, data_get($artifact, 'candidates.0.entity_id_or_slug'));
        $this->assertSame('/zh/articles/mbti-basics', parse_url((string) data_get($artifact, 'candidates.0.canonical_url'), PHP_URL_PATH));

        $importExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'article',
        ]);
        $importOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $importExitCode);
        $this->assertSame('success', $importOutput['status'] ?? null);
        $this->assertSame('article', $importOutput['page_entity_type'] ?? null);
        $this->assertSame('import_dry_run', $importOutput['mode'] ?? null);
        $this->assertSame(1, $importOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($importOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($importOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($importOutput['search_url_submission'] ?? true));
    }

    #[Test]
    public function command_exports_and_validates_filtered_content_page_handoff_artifact_without_writes(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $about = $this->createPublishedContentPage([
            'slug' => 'help-about',
            'path' => '/help/about',
            'canonical_path' => '/help/about',
            'locale' => 'zh-CN',
            'title' => 'About FermatMind',
        ]);
        $this->createPublishedContentPage([
            'slug' => 'help-contact',
            'path' => '/help/contact',
            'canonical_path' => '/help/contact',
            'locale' => 'zh-CN',
            'title' => 'Contact FermatMind',
        ]);

        $path = sys_get_temp_dir().'/content-page-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';

        $exportExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'content_page',
            '--canonical-path' => '/help/about',
        ]);
        $exportOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exportExitCode, json_encode($exportOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $exportOutput['status'] ?? null);
        $this->assertSame('content_page', $exportOutput['page_entity_type'] ?? null);
        $this->assertSame('/help/about', $exportOutput['canonical_path_filter'] ?? null);
        $this->assertTrue((bool) ($exportOutput['dry_run'] ?? false));
        $this->assertFalse((bool) ($exportOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($exportOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($exportOutput['search_url_submission'] ?? true));
        $this->assertSame(1, $exportOutput['planned_url_count'] ?? null);
        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);
        $this->assertSame(UrlTruthHandoffArtifact::SCHEMA_VERSION, $artifact['schema_version'] ?? null);
        $this->assertSame('two_stage_content_page_url_truth_handoff', $artifact['mode'] ?? null);
        $this->assertSame('content_page', data_get($artifact, 'constraints.allowed_page_entity_type'));
        $this->assertSame('content_page', data_get($artifact, 'candidates.0.page_entity_type'));
        $this->assertSame('backend_cms', data_get($artifact, 'candidates.0.source_authority'));
        $this->assertSame('content_pages', data_get($artifact, 'candidates.0.entity_source'));
        $this->assertSame('published_approved', data_get($artifact, 'candidates.0.authority_status'));
        $this->assertTrue((bool) data_get($artifact, 'candidates.0.attributes.claim_safe'));
        $this->assertSame((string) $about->slug, data_get($artifact, 'candidates.0.entity_id_or_slug'));
        $this->assertSame('/zh/help/about', parse_url((string) data_get($artifact, 'candidates.0.canonical_url'), PHP_URL_PATH));

        $importExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'content_page',
        ]);
        $importOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $importExitCode, json_encode($importOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $importOutput['status'] ?? null);
        $this->assertSame('content_page', $importOutput['page_entity_type'] ?? null);
        $this->assertSame('import_dry_run', $importOutput['mode'] ?? null);
        $this->assertSame(1, $importOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($importOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($importOutput['search_url_submission'] ?? true));
    }

    #[Test]
    public function command_exports_and_validates_mbti64_variant_handoff_artifact_without_writes(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);
        $this->seedMbti64PersonalityProfiles();

        $path = sys_get_temp_dir().'/mbti64-variant-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';

        $exportExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 100,
            '--page-type' => 'personality_profile_variant',
        ]);
        $exportOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exportExitCode, json_encode($exportOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $exportOutput['status'] ?? null);
        $this->assertSame('personality_profile_variant', $exportOutput['page_entity_type'] ?? null);
        $this->assertSame(64, $exportOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($exportOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($exportOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($exportOutput['search_url_submission'] ?? true));
        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);
        $urls = array_map(static fn (array $candidate): string => (string) $candidate['canonical_url'], $artifact['candidates'] ?? []);

        $this->assertSame('two_stage_personality_profile_variant_url_truth_handoff', $artifact['mode'] ?? null);
        $this->assertSame('personality_profile_variant', data_get($artifact, 'constraints.allowed_page_entity_type'));
        $this->assertSame('^/(en|zh)/personality/[a-z]{4}-[at]$', data_get($artifact, 'constraints.allowed_route_regex'));
        $this->assertCount(64, $urls);
        $this->assertContains('https://fermatmind.com/en/personality/intj-a', $urls);
        $this->assertContains('https://fermatmind.com/en/personality/intj-t', $urls);
        $this->assertContains('https://fermatmind.com/zh/personality/istj-a', $urls);
        $this->assertContains('https://fermatmind.com/zh/personality/infp-t', $urls);
        $this->assertFalse(collect($urls)->contains(static fn (string $url): bool => str_contains($url, '/zh-CN/')));
        $this->assertFalse(collect($artifact['candidates'] ?? [])->contains(static fn (array $candidate): bool => $candidate['page_entity_type'] !== 'personality_profile_variant'));
        $this->assertFalse(collect($artifact['candidates'] ?? [])->contains(static fn (array $candidate): bool => $candidate['entity_source'] !== 'personality_profile_variants'));

        $importExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 100,
            '--page-type' => 'personality_profile_variant',
        ]);
        $importOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $importExitCode, json_encode($importOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $importOutput['status'] ?? null);
        $this->assertSame('import_dry_run', $importOutput['mode'] ?? null);
        $this->assertSame('personality_profile_variant', $importOutput['page_entity_type'] ?? null);
        $this->assertSame(64, $importOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($importOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($importOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($importOutput['search_url_submission'] ?? true));
    }

    #[Test]
    public function command_exports_and_validates_mbti64_comparison_handoff_artifact_without_writes(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);
        $this->seedMbti64PersonalityProfiles();

        $path = sys_get_temp_dir().'/mbti64-comparison-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';

        $exportExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 100,
            '--page-type' => 'personality_profile_comparison',
        ]);
        $exportOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exportExitCode, json_encode($exportOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $exportOutput['status'] ?? null);
        $this->assertSame('personality_profile_comparison', $exportOutput['page_entity_type'] ?? null);
        $this->assertSame(32, $exportOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($exportOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($exportOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($exportOutput['search_url_submission'] ?? true));
        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);
        $urls = array_map(static fn (array $candidate): string => (string) $candidate['canonical_url'], $artifact['candidates'] ?? []);

        $this->assertSame('two_stage_personality_profile_comparison_url_truth_handoff', $artifact['mode'] ?? null);
        $this->assertSame('personality_profile_comparison', data_get($artifact, 'constraints.allowed_page_entity_type'));
        $this->assertSame('^/(en|zh)/personality/([a-z]{4})-a-vs-\2-t$', data_get($artifact, 'constraints.allowed_route_regex'));
        $this->assertCount(32, $urls);
        $this->assertContains('https://fermatmind.com/en/personality/intj-a-vs-intj-t', $urls);
        $this->assertContains('https://fermatmind.com/en/personality/intp-a-vs-intp-t', $urls);
        $this->assertFalse(collect($urls)->contains(static fn (string $url): bool => str_contains($url, '/zh-CN/')));
        $this->assertFalse(collect($artifact['candidates'] ?? [])->contains(static fn (array $candidate): bool => $candidate['page_entity_type'] !== 'personality_profile_comparison'));
        $this->assertFalse(collect($artifact['candidates'] ?? [])->contains(static fn (array $candidate): bool => $candidate['entity_source'] !== 'personality_profiles'));

        $importExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 100,
            '--page-type' => 'personality_profile_comparison',
        ]);
        $importOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $importExitCode, json_encode($importOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $importOutput['status'] ?? null);
        $this->assertSame('import_dry_run', $importOutput['mode'] ?? null);
        $this->assertSame('personality_profile_comparison', $importOutput['page_entity_type'] ?? null);
        $this->assertSame(32, $importOutput['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($importOutput['writes_committed'] ?? true));
        $this->assertFalse((bool) ($importOutput['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($importOutput['search_url_submission'] ?? true));
    }

    #[Test]
    public function personality_handoff_write_requires_artifact_sha_confirmation(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
        ]);

        $artifact = new UrlTruthHandoffArtifact;
        $path = sys_get_temp_dir().'/write-mbti64-variant-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $artifact->fromRecords([
            $this->personalityVariantRecord(),
        ], pageEntityType: 'personality_profile_variant'));

        $blockedExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--write' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'personality_profile_variant',
        ]);
        $blockedOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $blockedExitCode);
        $this->assertSame('blocked', $blockedOutput['status'] ?? null);
        $this->assertContains('artifact_sha256_confirmation_required', $blockedOutput['issues'] ?? []);
        $this->assertFalse((bool) ($blockedOutput['writes_committed'] ?? true));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_url_entities')->count());
    }

    #[Test]
    public function personality_handoff_validation_blocks_private_routes_and_sensitive_query_keys(): void
    {
        $artifact = new UrlTruthHandoffArtifact;
        $payload = $artifact->fromRecords([
            $this->personalityVariantRecord(canonicalUrl: 'https://fermatmind.com/en/results/lookup'),
            $this->personalityVariantRecord(canonicalUrl: 'https://fermatmind.com/en/personality/intj-a?token=unsafe'),
            $this->personalityComparisonRecord(canonicalUrl: 'https://fermatmind.com/en/account'),
        ], pageEntityType: 'personality_profile_variant');

        $payload['candidates'][2]['page_entity_type'] = 'personality_profile_variant';
        $payload['candidates'][2]['entity_source'] = 'personality_profile_variants';
        $payload['candidates'][2]['row_fingerprint'] = hash('sha256', json_encode([
            'canonical_url' => $payload['candidates'][2]['canonical_url'] ?? null,
            'locale' => $payload['candidates'][2]['locale'] ?? null,
            'page_entity_type' => $payload['candidates'][2]['page_entity_type'] ?? null,
            'entity_id_or_slug' => $payload['candidates'][2]['entity_id_or_slug'] ?? null,
            'source_authority' => $payload['candidates'][2]['source_authority'] ?? null,
            'indexability_state' => $payload['candidates'][2]['indexability_state'] ?? null,
            'entity_source' => $payload['candidates'][2]['entity_source'] ?? null,
            'authority_status' => $payload['candidates'][2]['authority_status'] ?? null,
            'is_private_flow' => (bool) ($payload['candidates'][2]['is_private_flow'] ?? true),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $path = sys_get_temp_dir().'/private-mbti64-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $payload);

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'personality_profile_variant',
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('candidate_forbidden_route_fragment:/results:0', $output['issues'] ?? []);
        $this->assertContains('candidate_forbidden_route_fragment:token=:1', $output['issues'] ?? []);
        $this->assertContains('candidate_forbidden_route_fragment:/account:2', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertFalse((bool) ($output['search_url_submission'] ?? true));
    }

    #[Test]
    public function content_page_handoff_validation_blocks_private_noindex_or_claim_unsafe_candidates(): void
    {
        $artifact = new UrlTruthHandoffArtifact;
        $payload = $artifact->fromRecords([
            $this->contentPageRecord(canonicalUrl: 'https://fermatmind.com/zh/account/about'),
            $this->contentPageRecord(canonicalUrl: 'https://fermatmind.com/zh/help/noindex', indexabilityState: 'noindex'),
            $this->contentPageRecord(canonicalUrl: 'https://fermatmind.com/zh/help/claim-unsafe', claimSafe: false),
        ], pageEntityType: 'content_page');

        $path = sys_get_temp_dir().'/invalid-content-page-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $payload);

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'content_page',
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('candidate_route_not_content_page:0', $output['issues'] ?? []);
        $this->assertContains('candidate_forbidden_route_fragment:/account:0', $output['issues'] ?? []);
        $this->assertContains('candidate_not_indexable:1', $output['issues'] ?? []);
        $this->assertContains('candidate_claim_unsafe:2', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertFalse((bool) ($output['search_url_submission'] ?? true));
    }

    #[Test]
    public function export_rejects_unsafe_or_existing_artifact_paths(): void
    {
        $relativeExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => '../research-url-truth-handoff.json',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $relativeOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $relativeExitCode);
        $this->assertSame('blocked', $relativeOutput['status'] ?? null);
        $this->assertContains('artifact_path_must_be_absolute', $relativeOutput['issues'] ?? []);

        $existingPath = sys_get_temp_dir().'/existing-research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($existingPath, '{}');

        $existingExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $existingPath,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $existingOutput = json_decode(trim(Artisan::output()), true);

        @unlink($existingPath);

        $this->assertSame(1, $existingExitCode);
        $this->assertSame('blocked', $existingOutput['status'] ?? null);
        $this->assertContains('artifact_path_already_exists', $existingOutput['issues'] ?? []);
    }

    #[Test]
    public function import_rejects_non_json_or_missing_artifact_paths_before_reading(): void
    {
        $textPath = sys_get_temp_dir().'/research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($textPath, '{}');

        $textExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $textPath,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $textOutput = json_decode(trim(Artisan::output()), true);

        @unlink($textPath);

        $this->assertSame(1, $textExitCode);
        $this->assertSame('blocked', $textOutput['status'] ?? null);
        $this->assertContains('artifact_path_must_be_json', $textOutput['issues'] ?? []);

        $missingPath = sys_get_temp_dir().'/missing-research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';

        $missingExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $missingPath,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $missingOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $missingExitCode);
        $this->assertSame('blocked', $missingOutput['status'] ?? null);
        $this->assertContains('artifact_path_not_regular_file', $missingOutput['issues'] ?? []);
    }

    #[Test]
    public function import_validation_rejects_non_research_article_or_claim_unsafe_candidates(): void
    {
        $artifact = new UrlTruthHandoffArtifact;
        $payload = $artifact->fromRecords([$this->validRecord()]);
        $payload['candidates'][0]['canonical_url'] = 'https://www.fermatmind.com/en/articles/mbti-personality-types-salary-turnover-report';
        $payload['candidates'][0]['canonical_url_hash'] = hash('sha256', (string) $payload['candidates'][0]['canonical_url']);
        $payload['candidates'][0]['page_entity_type'] = 'article';
        $payload['candidates'][0]['source_authority'] = 'cms_article';
        $payload['candidates'][0]['attributes']['claim_safe'] = false;

        $path = sys_get_temp_dir().'/invalid-research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $payload);

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('candidate_not_research_report:0', $output['issues'] ?? []);
        $this->assertContains('candidate_source_authority_not_backend_cms:0', $output['issues'] ?? []);
        $this->assertContains('candidate_claim_unsafe:0', $output['issues'] ?? []);
        $this->assertContains('candidate_route_not_research:0', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
    }

    #[Test]
    public function import_validation_rejects_wrong_article_source_route_or_entity_identity(): void
    {
        $artifact = new UrlTruthHandoffArtifact;
        $payload = $artifact->fromRecords([
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://www.fermatmind.com/zh/research/mbti-basics',
                locale: 'zh-CN',
                pageEntityType: 'article',
                entityIdOrSlug: '8',
                sourceAuthority: 'backend_cms',
                indexabilityState: 'indexable',
                lastmodAt: now()->subHour(),
                lastmodSource: 'articles.updated_at',
                cluster: 'articles',
                entitySource: 'research_reports',
                authorityStatus: 'published_approved',
                sourceUpdatedAt: now()->subHour(),
                isPrivateFlow: false,
                metadata: [
                    'source_table_hash' => hash('sha256', 'articles'),
                    'canonical_path_hash' => hash('sha256', '/zh/research/mbti-basics'),
                ],
                attributes: [
                    'source_authority' => 'backend_cms',
                    'claim_safe' => true,
                ],
            ),
            new UrlTruthInventoryRecord(
                canonicalUrl: 'https://www.fermatmind.com/zh/articles/big-five-tool-guide',
                locale: 'zh-CN',
                pageEntityType: 'article',
                entityIdOrSlug: 'big-five-tool-guide',
                sourceAuthority: 'backend_cms',
                indexabilityState: 'indexable',
                lastmodAt: now()->subHour(),
                lastmodSource: 'articles.updated_at',
                cluster: 'articles',
                entitySource: 'articles',
                authorityStatus: 'published_approved',
                sourceUpdatedAt: now()->subHour(),
                isPrivateFlow: false,
                metadata: [
                    'source_table_hash' => hash('sha256', 'articles'),
                    'canonical_path_hash' => hash('sha256', '/zh/articles/big-five-tool-guide'),
                ],
                attributes: [
                    'source_authority' => 'backend_cms',
                    'claim_safe' => true,
                ],
            ),
        ], pageEntityType: 'article');

        $path = sys_get_temp_dir().'/invalid-article-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $payload);

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'article',
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('candidate_entity_source_not_articles:0', $output['issues'] ?? []);
        $this->assertContains('candidate_route_not_article:0', $output['issues'] ?? []);
        $this->assertContains('candidate_forbidden_route_fragment:/research:0', $output['issues'] ?? []);
        $this->assertContains('candidate_article_entity_id_invalid:1', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
        $this->assertFalse((bool) ($output['search_url_submission'] ?? true));
    }

    #[Test]
    public function import_validation_rejects_cross_tenant_or_mismatched_research_paths(): void
    {
        $artifact = new UrlTruthHandoffArtifact;
        $payload = $artifact->fromRecords([
            $this->validRecord(canonicalUrl: 'https://evil.example/en/research/mbti-personality-types-salary-turnover-report'),
            $this->validRecord(
                canonicalUrl: 'https://www.fermatmind.com/en/research/other-report',
                entityIdOrSlug: 'mbti-personality-types-salary-turnover-report',
            ),
            $this->validRecord(metadata: [
                'canonical_path_hash' => hash('sha256', '/en/articles/mbti-personality-types-salary-turnover-report'),
                'source_table_hash' => hash('sha256', 'research_reports'),
            ]),
        ]);

        $path = sys_get_temp_dir().'/tenant-research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $payload);

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--dry-run' => true,
            '--json' => true,
            '--limit' => 20,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $output['status'] ?? null);
        $this->assertContains('candidate_untrusted_tenant_host:0', $output['issues'] ?? []);
        $this->assertContains('candidate_research_path_slug_mismatch:1', $output['issues'] ?? []);
        $this->assertContains('candidate_canonical_path_hash_mismatch:2', $output['issues'] ?? []);
        $this->assertFalse((bool) ($output['writes_committed'] ?? true));
    }

    #[Test]
    public function bounded_import_write_requires_sha_confirmation_and_targets_only_url_truth_tables(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
        ]);

        $artifact = new UrlTruthHandoffArtifact;
        $path = sys_get_temp_dir().'/write-research-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $artifact->fromRecords([$this->validRecord()]));

        $blockedExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--write' => true,
            '--json' => true,
            '--limit' => 20,
        ]);
        $blockedOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $blockedExitCode);
        $this->assertContains('artifact_sha256_confirmation_required', $blockedOutput['issues'] ?? []);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_url_entities')->count());

        $writeExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--write' => true,
            '--confirm-artifact-sha256' => $artifact->sha256($path),
            '--json' => true,
            '--limit' => 20,
        ]);
        $writeOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $writeExitCode);
        $this->assertSame('success', $writeOutput['status'] ?? null);
        $this->assertSame('import_write', $writeOutput['mode'] ?? null);
        $this->assertTrue((bool) ($writeOutput['writes_committed'] ?? false));
        $this->assertSame(['seo_urls', 'seo_url_entities'], $writeOutput['target_tables'] ?? null);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->count());
    }

    #[Test]
    public function article_import_write_can_use_exact_bounded_override_when_config_write_flags_are_disabled(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        config([
            'seo_intel.enabled' => false,
            'seo_intel.write_enabled' => false,
        ]);

        $artifact = new UrlTruthHandoffArtifact;
        $path = sys_get_temp_dir().'/write-article-url-truth-handoff-'.bin2hex(random_bytes(4)).'.json';
        $artifact->writeJson($path, $artifact->fromRecords([$this->articleRecord()], pageEntityType: 'article'));
        $sha256 = $artifact->sha256($path);
        $overrideConfirmation = sprintf(
            'I explicitly approve bounded URL Truth handoff import write for article page_entity_type using artifact sha256 %s generated at %s; no search submission, no CMS content changes, no publish, no schema/hreflang writes.',
            $sha256,
            $path,
        );

        $blockedExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--write' => true,
            '--confirm-artifact-sha256' => $sha256,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'article',
        ]);
        $blockedOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $blockedExitCode);
        $this->assertContains('seo_intel_write_flags_disabled', $blockedOutput['issues'] ?? []);
        $this->assertContains('bounded_write_override_confirmation_required', $blockedOutput['issues'] ?? []);
        $this->assertSame($overrideConfirmation, $blockedOutput['required_bounded_write_override_confirmation'] ?? null);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_url_entities')->count());

        $writeExitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--import' => $path,
            '--write' => true,
            '--confirm-artifact-sha256' => $sha256,
            '--confirm-bounded-write-override' => $overrideConfirmation,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'article',
        ]);
        $writeOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $writeExitCode, json_encode($writeOutput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame('success', $writeOutput['status'] ?? null);
        $this->assertSame('import_write', $writeOutput['mode'] ?? null);
        $this->assertSame('article', $writeOutput['page_entity_type'] ?? null);
        $this->assertSame('bounded_command_override', $writeOutput['write_authorization'] ?? null);
        $this->assertTrue((bool) ($writeOutput['config_write_flags_bypassed'] ?? false));
        $this->assertTrue((bool) ($writeOutput['writes_committed'] ?? false));
        $this->assertFalse((bool) ($writeOutput['search_url_submission'] ?? true));
        $this->assertSame(['seo_urls', 'seo_url_entities'], $writeOutput['target_tables'] ?? null);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->count());
    }

    #[Test]
    public function generated_artifact_locks_two_stage_handoff_boundary(): void
    {
        $path = base_path('docs/seo/generated/seo-intel-two-stage-url-truth-handoff.v1.json');

        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);

        $this->assertSame('seo-intel-two-stage-url-truth-handoff.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-INTEL-TWO-STAGE-URL-TRUTH-HANDOFF-PR-00', $artifact['task'] ?? null);
        $this->assertSame(UrlTruthHandoffArtifact::SCHEMA_VERSION, $artifact['handoff_artifact_schema'] ?? null);
        $this->assertSame('dry_run_no_write', data_get($artifact, 'source_environment.allowed_mode'));
        $this->assertFalse((bool) data_get($artifact, 'source_environment.writes_allowed', true));
        $this->assertSame(['seo_urls', 'seo_url_entities'], data_get($artifact, 'runner_environment.target_tables'));
        $this->assertSame('research_report', data_get($artifact, 'allowed_candidates.page_entity_type'));
        $this->assertSame('backend_cms', data_get($artifact, 'allowed_candidates.source_authority'));
        $this->assertFalse((bool) ($artifact['tencent_prod_direct_write_to_aliyun_rds_allowed'] ?? true));
        $this->assertTrue((bool) ($artifact['no_cross_cloud_private_networking_required'] ?? false));
        $this->assertTrue((bool) ($artifact['no_search_submission'] ?? false));
        $this->assertTrue((bool) data_get($artifact, 'artifact_path_safety.absolute_path_required'));
        $this->assertTrue((bool) data_get($artifact, 'artifact_path_safety.json_extension_required'));
        $this->assertFalse((bool) data_get($artifact, 'artifact_path_safety.stream_wrappers_allowed', true));
        $this->assertFalse((bool) data_get($artifact, 'artifact_path_safety.export_overwrite_allowed', true));
        $this->assertContains('/articles', data_get($artifact, 'forbidden.route_fragments', []));
        $this->assertContains('seo_issue_queue', data_get($artifact, 'forbidden.tables', []));
        $this->assertSame('RESEARCH-PUBLISH-02-RERUN', $artifact['next_task'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function validRecord(
        string $canonicalUrl = 'https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
        string $entityIdOrSlug = 'mbti-personality-types-salary-turnover-report',
        ?array $metadata = null,
    ): UrlTruthInventoryRecord {
        return new UrlTruthInventoryRecord(
            canonicalUrl: $canonicalUrl,
            locale: 'en',
            pageEntityType: 'research_report',
            entityIdOrSlug: $entityIdOrSlug,
            sourceAuthority: 'backend_cms',
            indexabilityState: 'indexable',
            lastmodAt: now()->subHour(),
            lastmodSource: 'research_reports.updated_at',
            cluster: 'research',
            entitySource: 'research_reports',
            authorityStatus: 'published_approved',
            sourceUpdatedAt: now()->subHour(),
            isPrivateFlow: false,
            metadata: $metadata ?? [
                'canonical_path_hash' => hash('sha256', '/en/research/mbti-personality-types-salary-turnover-report'),
                'source_table_hash' => hash('sha256', 'research_reports'),
            ],
            attributes: [
                'claim_safe' => true,
                'source_authority' => 'backend_cms',
            ],
        );
    }

    private function articleRecord(
        string $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist',
        string $entityIdOrSlug = '53',
    ): UrlTruthInventoryRecord {
        $path = (string) parse_url($canonicalUrl, PHP_URL_PATH);

        return new UrlTruthInventoryRecord(
            canonicalUrl: $canonicalUrl,
            locale: 'zh-CN',
            pageEntityType: 'article',
            entityIdOrSlug: $entityIdOrSlug,
            sourceAuthority: 'backend_cms',
            indexabilityState: 'indexable',
            lastmodAt: now()->subHour(),
            lastmodSource: 'articles.updated_at',
            cluster: 'articles',
            entitySource: 'articles',
            authorityStatus: 'published_approved',
            sourceUpdatedAt: now()->subHour(),
            isPrivateFlow: false,
            metadata: [
                'canonical_path_hash' => hash('sha256', $path),
                'source_table_hash' => hash('sha256', 'articles'),
                'claim_boundary_state' => 'claim_safe',
                'claim_safe' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'publication_state' => 'published',
                'robots' => 'index',
            ],
            attributes: [
                'claim_safe' => true,
                'source_authority' => 'backend_cms',
                'article_id_hash' => hash('sha256', $entityIdOrSlug),
            ],
        );
    }

    private function contentPageRecord(
        string $canonicalUrl = 'https://fermatmind.com/zh/help/about',
        string $entityIdOrSlug = 'help-about',
        string $indexabilityState = 'indexable',
        bool $claimSafe = true,
    ): UrlTruthInventoryRecord {
        $path = (string) parse_url($canonicalUrl, PHP_URL_PATH);

        return new UrlTruthInventoryRecord(
            canonicalUrl: $canonicalUrl,
            locale: 'zh-CN',
            pageEntityType: 'content_page',
            entityIdOrSlug: $entityIdOrSlug,
            sourceAuthority: 'backend_cms',
            indexabilityState: $indexabilityState,
            lastmodAt: now()->subHour(),
            lastmodSource: 'content_pages.updated_at',
            cluster: 'content_pages',
            entitySource: 'content_pages',
            authorityStatus: 'published_approved',
            sourceUpdatedAt: now()->subHour(),
            isPrivateFlow: false,
            metadata: [
                'canonical_path_hash' => hash('sha256', $path),
                'source_table_hash' => hash('sha256', 'content_pages'),
                'claim_boundary_state' => $claimSafe ? 'claim_safe' : 'claim_unsafe',
                'claim_safe' => $claimSafe,
                'publication_state' => 'published',
                'robots' => $indexabilityState === 'indexable' ? 'index' : 'noindex',
                'frontend_fallback' => false,
                'static_sitemap_fallback' => false,
                'static_llms_fallback' => false,
                'private_flow' => false,
            ],
            attributes: [
                'claim_safe' => $claimSafe,
                'source_authority' => 'backend_cms',
            ],
        );
    }

    private function personalityVariantRecord(
        string $canonicalUrl = 'https://fermatmind.com/en/personality/intj-a',
        string $locale = 'en',
        string $entityIdOrSlug = '101',
    ): UrlTruthInventoryRecord {
        $path = (string) parse_url($canonicalUrl, PHP_URL_PATH);

        return new UrlTruthInventoryRecord(
            canonicalUrl: $canonicalUrl,
            locale: $locale,
            pageEntityType: 'personality_profile_variant',
            entityIdOrSlug: $entityIdOrSlug,
            sourceAuthority: 'backend_cms',
            indexabilityState: 'indexable',
            lastmodAt: now()->subHour(),
            lastmodSource: 'personality_profile_variants.updated_at',
            cluster: 'personality',
            entitySource: 'personality_profile_variants',
            authorityStatus: 'published_approved',
            sourceUpdatedAt: now()->subHour(),
            isPrivateFlow: false,
            metadata: [
                'canonical_path_hash' => hash('sha256', $path),
                'source_table_hash' => hash('sha256', 'personality_profile_variants'),
                'claim_boundary_state' => 'claim_safe',
                'claim_safe' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'publication_state' => 'published',
                'robots' => 'index',
            ],
            attributes: [
                'claim_safe' => true,
                'source_authority' => 'backend_cms',
                'variant_id_hash' => hash('sha256', $entityIdOrSlug),
            ],
        );
    }

    private function personalityComparisonRecord(
        string $canonicalUrl = 'https://fermatmind.com/en/personality/intj-a-vs-intj-t',
        string $locale = 'en',
        string $entityIdOrSlug = '201',
    ): UrlTruthInventoryRecord {
        $path = (string) parse_url($canonicalUrl, PHP_URL_PATH);

        return new UrlTruthInventoryRecord(
            canonicalUrl: $canonicalUrl,
            locale: $locale,
            pageEntityType: 'personality_profile_comparison',
            entityIdOrSlug: $entityIdOrSlug,
            sourceAuthority: 'backend_cms',
            indexabilityState: 'indexable',
            lastmodAt: now()->subHour(),
            lastmodSource: 'personality_profiles.updated_at',
            cluster: 'personality',
            entitySource: 'personality_profiles',
            authorityStatus: 'published_approved',
            sourceUpdatedAt: now()->subHour(),
            isPrivateFlow: false,
            metadata: [
                'canonical_path_hash' => hash('sha256', $path),
                'source_table_hash' => hash('sha256', 'personality_profiles'),
                'claim_boundary_state' => 'claim_safe',
                'claim_safe' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'publication_state' => 'published',
                'robots' => 'index',
            ],
            attributes: [
                'claim_safe' => true,
                'source_authority' => 'backend_cms',
                'profile_id_hash' => hash('sha256', $entityIdOrSlug),
            ],
        );
    }

    private function seedMbti64PersonalityProfiles(): void
    {
        foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $profile = $this->createPersonalityProfile($locale, $typeCode);
                $this->createPersonalityVariant($profile, 'A');
                $this->createPersonalityVariant($profile, 'T');
            }
        }
    }

    private function createPersonalityProfile(string $locale, string $typeCode): PersonalityProfile
    {
        return PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $typeCode.' Personality',
            'type_name' => $typeCode,
            'nickname' => $typeCode,
            'keywords_json' => [$typeCode, 'MBTI'],
            'subtitle' => $typeCode.' public personality profile.',
            'excerpt' => $typeCode.' public personality profile excerpt.',
            'hero_kicker' => 'Personality',
            'hero_summary_md' => $typeCode.' public profile summary.',
            'hero_summary_html' => '<p>'.$typeCode.' public profile summary.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subHour(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
    }

    private function createPersonalityVariant(PersonalityProfile $profile, string $variantCode): PersonalityProfileVariant
    {
        $runtimeTypeCode = ((string) $profile->type_code).'-'.$variantCode;

        return PersonalityProfileVariant::query()->create([
            'org_id' => 0,
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => (string) $profile->type_code,
            'variant_code' => $variantCode,
            'runtime_type_code' => $runtimeTypeCode,
            'type_name' => $runtimeTypeCode,
            'nickname' => $runtimeTypeCode,
            'keywords_json' => [$runtimeTypeCode, 'MBTI'],
            'hero_summary_md' => $runtimeTypeCode.' public variant summary.',
            'hero_summary_html' => '<p>'.$runtimeTypeCode.' public variant summary.</p>',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createResearchReport(array $overrides = []): ResearchReport
    {
        $slug = (string) ($overrides['slug'] ?? 'safe-research-report');
        $locale = (string) ($overrides['locale'] ?? 'en');
        $localeSegment = $locale === 'zh-CN' ? 'zh' : $locale;

        return ResearchReport::query()->create($overrides + [
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Safe Research Report',
            'executive_summary' => 'Directional research summary.',
            'body_md' => 'Research body.',
            'research_type' => 'salary_turnover',
            'methodology' => 'Modeled index methodology.',
            'sample_disclaimer' => 'Exploratory, non-diagnostic, not hiring advice.',
            'claim_boundary' => 'No salary guarantee or individual prediction.',
            'author_name' => 'FermatMind Research',
            'reviewer_name' => 'FermatMind Review',
            'references' => [['title' => 'Reference', 'url' => 'https://example.com/reference']],
            'downloadable_asset_placeholder' => 'Dataset schema blocked for first publish.',
            'status' => ResearchReport::STATUS_PUBLISHED,
            'review_state' => ResearchReport::REVIEW_APPROVED,
            'is_public' => true,
            'is_indexable' => true,
            'last_reviewed_at' => now()->subDay(),
            'published_at' => now()->subHour(),
            'seo_title' => 'Safe Research Report',
            'seo_description' => 'Safe Research Report description.',
            'canonical_path' => '/'.$localeSegment.'/research/'.$slug,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPublishedArticle(array $overrides = []): Article
    {
        /** @var Article $article */
        $article = Article::unguarded(fn (): Article => Article::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'safe-article',
            'locale' => 'en',
            'translation_group_id' => 'article-test-group',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Safe Article',
            'excerpt' => 'Safe article excerpt.',
            'content_md' => 'Safe article body.',
            'content_html' => '<p>Safe article body.</p>',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subHour(),
        ]));

        /** @var ArticleTranslationRevision $revision */
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => $article->id,
            'source_article_id' => null,
            'locale' => $article->locale,
            'source_locale' => $article->locale,
            'translation_group_id' => $article->translation_group_id,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $article->content_md,
            'source_version_hash' => $article->source_version_hash,
            'published_at' => now()->subHour(),
            'created_by' => null,
        ]);

        $article->forceFill([
            'published_revision_id' => $revision->id,
            'working_revision_id' => $revision->id,
        ])->save();

        return $article->refresh();
    }

    private function prepareSeoIntelSqliteConnection(): void
    {
        config([
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('seo_intel');

        Schema::connection('seo_intel')->create('seo_urls', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->string('lastmod_source', 64)->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale']);
        });

        Schema::connection('seo_intel')->create('seo_url_entities', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPublishedContentPage(array $overrides = []): ContentPage
    {
        return ContentPage::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'help-about',
            'path' => '/help/about',
            'canonical_path' => '/help/about',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'About FermatMind',
            'summary' => 'About FermatMind.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'content_md' => 'About FermatMind content.',
            'content_html' => '<p>About FermatMind content.</p>',
            'seo_title' => 'About FermatMind',
            'seo_description' => 'About FermatMind description.',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'published_revision_id' => 88,
            'published_at' => now()->subHour(),
        ]);
    }
}
