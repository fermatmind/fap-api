<?php

declare(strict_types=1);

namespace Tests\Feature\BigFive;

use App\Models\PersonalityPublicContentAsset;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52RuntimeVerifier;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class BigFiveEn52RuntimeVerifyTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVED_SHA = '110749a534183b5ab28108c027a14842ec06b860';

    private const RELEASE_NAME = '20260719T071900Z-en52';

    private string $packagePath;

    /** @var array<string,mixed> */
    private array $package;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/backend/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = dirname(__DIR__, 4).'/generated/big-five-en52-release/release-package.json';
        $this->package = json_decode((string) file_get_contents($this->packagePath), true, flags: JSON_THROW_ON_ERROR);
        config(['seo_intel.connection' => config('database.default')]);
        Http::preventStrayRequests();
    }

    public function test_exact_runtime_readback_passes_without_any_database_write(): void
    {
        $this->seedAndPublish();
        $verifier = app(BigFiveEn52RuntimeVerifier::class);
        $approval = $this->approval($verifier);
        $this->fakeHealthyPublicRuntime();
        $before = $this->databaseBytes();

        $result = $verifier->verify($approval, ['sha' => self::APPROVED_SHA, 'name' => self::RELEASE_NAME]);

        $this->assertTrue($result['ok']);
        $this->assertSame(52, $result['asset_count']);
        $this->assertSame(52, $result['revision_count']);
        $this->assertSame(52, $result['public_api_count']);
        $this->assertSame(104, $result['canonical_total_count']);
        $this->assertSame(20, $result['permanent_single_hop_redirect_count']);
        $this->assertSame(0, $result['canonical_redirect_count']);
        $this->assertSame(0, $result['media_exposure_count']);
        $this->assertSame(0, $result['search_action_count']);
        $this->assertFalse($result['writes_committed']);
        $this->assertSame($before, $this->databaseBytes());
    }

    public function test_wrong_sha_release_package_and_51_or_53_row_boundaries_fail_closed(): void
    {
        $this->seedAndPublish();
        $verifier = app(BigFiveEn52RuntimeVerifier::class);
        $approval = $this->approval($verifier);

        foreach ([
            ['sha' => str_repeat('a', 40), 'name' => self::RELEASE_NAME],
            ['sha' => self::APPROVED_SHA, 'name' => 'wrong-release'],
        ] as $identity) {
            try {
                $verifier->verify($approval, $identity);
                $this->fail('Wrong deployed identity must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('release_identity_mismatch', $exception->getMessage());
            }
        }

        $badPackage = $approval;
        $badPackage['package_path'] = __FILE__;
        $this->expectFailureCode(fn () => $verifier->verify($badPackage, $this->identity()), 'database_or_package_boundary_mismatch');

        $extra = $this->seedBoundaryRow('big_five', 'domain', 'extra', 'big-five/extra', 'en');
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'database_or_package_boundary_mismatch');
        $extra->delete();

        $target = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('locale', 'en')->firstOrFail();
        DB::table('personality_public_content_assets')->where('id', $target->id)->delete();
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'database_or_package_boundary_mismatch');
    }

    public function test_mixed_revision_alias_reappearance_and_non_target_drift_fail_closed(): void
    {
        $this->seedAndPublish();
        $verifier = app(BigFiveEn52RuntimeVerifier::class);
        $approval = $this->approval($verifier);

        $nonTarget = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->firstOrFail();
        $originalTitle = (string) $nonTarget->title;
        DB::table('personality_public_content_assets')->where('id', $nonTarget->id)->update(['title' => 'drift']);
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'zh_fingerprint_mismatch');
        DB::table('personality_public_content_assets')->where('id', $nonTarget->id)->update(['title' => $originalTitle]);

        $unrelated = $this->seedBoundaryRow('enneagram', 'hub', 'enneagram', 'enneagram', 'en');
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'non_target_fingerprint_mismatch');
        $unrelated->delete();

        $this->seedBoundaryRow('big_five', 'polarity', 'high-openness', 'big-five/high-openness', 'en');
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'database_or_package_boundary_mismatch');
        DB::table('personality_public_content_assets')->where('entity_key', 'high-openness')->delete();

        DB::table('personality_public_content_asset_revisions')->where('authority_package_sha256', BigFiveEn52Publisher::PACKAGE_FILE_SHA256)->limit(1)->delete();
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'database_or_package_boundary_mismatch');
    }

    public function test_incomplete_sitemap_llms_public_projection_and_timeout_fail_closed(): void
    {
        $this->seedAndPublish();
        $verifier = app(BigFiveEn52RuntimeVerifier::class);
        $approval = $this->approval($verifier);
        $this->fakeHealthyPublicRuntime('/en/personality/big-five/openness');
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'sitemap_source_cohort_mismatch');

        $this->fakeHealthyPublicRuntime('/en/personality/big-five/openness', '/llms.txt');
        $this->expectFailureCode(fn () => $verifier->verify($approval, $this->identity()), 'llms_cohort_mismatch');

        Http::fake(fn () => throw new RuntimeException('secret=https://private.example.test token=abc'));
        $this->expectException(RuntimeException::class);
        $verifier->verify($approval, $this->identity());
    }

    public function test_command_failure_is_sanitized_and_never_echoes_sensitive_input(): void
    {
        $secret = 'secret-token-and-private-topology';
        $this->artisan('personality:big-five-en52-runtime-verify', [
            '--approved-sha' => $secret,
            '--release-name' => $secret,
            '--api-origin' => 'https://private.example.test',
            '--frontend-origin' => 'https://private.example.test',
            '--expected-zh-fingerprint' => $secret,
            '--expected-non-target-fingerprint' => $secret,
            '--expected-search-fingerprint' => $secret,
            '--json' => true,
        ])->assertFailed()->expectsOutputToContain('approval_sha_invalid')->doesntExpectOutputToContain($secret);
    }

    private function seedAndPublish(): void
    {
        if (PersonalityPublicContentAsset::query()->withoutGlobalScopes()->exists()) {
            return;
        }
        foreach ($this->package['assets'] as $entry) {
            $asset = $entry['asset'];
            $hreflang = [
                'en' => BigFiveCanonicalRouteCatalog::expectedPath('en', $asset['entity_type'], $asset['entity_key']),
                'zh-CN' => BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', $asset['entity_type'], $asset['entity_key']),
            ];
            $en = $this->seedBoundaryRow('big_five', $asset['entity_type'], $asset['entity_key'], $asset['slug'], 'en', $hreflang);
            $en->forceFill(['canonical_json' => $asset['canonical']])->save();
            $zh = $this->seedBoundaryRow('big_five', $asset['entity_type'], $asset['entity_key'], $asset['slug'], 'zh-CN', $hreflang);
            $zh->forceFill([
                'canonical_json' => ['path' => $hreflang['zh-CN']],
                'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                'is_public' => true, 'index_eligible' => true, 'sitemap_eligible' => true, 'llms_eligible' => true,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED, 'published_at' => now()->subDay(),
            ])->save();
        }
        app(BigFiveEn52Publisher::class)->publish($this->packagePath, BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID);
    }

    /** @return array<string,string> */
    private function approval(BigFiveEn52RuntimeVerifier $verifier): array
    {
        $method = new ReflectionMethod($verifier, 'databaseCohort');
        $cohort = $method->invoke($verifier);

        return [
            'approved_sha' => self::APPROVED_SHA,
            'release_name' => self::RELEASE_NAME,
            'api_origin' => 'https://api.example.test',
            'frontend_origin' => 'https://www.example.test',
            'package_path' => $this->packagePath,
            'expected_zh_fingerprint' => $cohort['zh_fingerprint'],
            'expected_non_target_fingerprint' => $cohort['non_target_fingerprint'],
            'expected_search_fingerprint' => $cohort['search_fingerprint'],
        ];
    }

    /** @return array{sha:string,name:string} */
    private function identity(): array
    {
        return ['sha' => self::APPROVED_SHA, 'name' => self::RELEASE_NAME];
    }

    private function fakeHealthyPublicRuntime(?string $omitPath = null, ?string $omitOnlySurface = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $completePaths = [...array_column(BigFiveCanonicalRouteCatalog::canonicalEntries('en'), 'path'), ...array_column(BigFiveCanonicalRouteCatalog::canonicalEntries('zh-CN'), 'path')];
        $allPaths = $omitPath !== null && $omitOnlySurface === null
            ? array_values(array_diff($completePaths, [$omitPath]))
            : $completePaths;
        $aliases = BigFiveCanonicalRouteCatalog::reviewedRedirectPaths();
        Http::fake(function (Request $request) use ($allPaths, $aliases, $completePaths, $omitPath, $omitOnlySurface) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/api/v0.5/personality-content-assets') {
                $items = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('framework', 'big_five')->where('locale', 'en')
                    ->orderBy('entity_type')->orderBy('entity_key')->get()->map(fn ($asset) => [
                        'framework' => 'big_five', 'locale' => 'en', 'entity_type' => $asset->entity_type,
                        'code' => $asset->entity_key, 'canonical_path' => data_get($asset->canonical_json, 'path'),
                        'hreflang' => $asset->hreflang_json, 'robots' => $asset->robots, 'is_public' => (bool) $asset->is_public,
                        'index_eligible' => (bool) $asset->index_eligible, 'sitemap_eligible' => (bool) $asset->sitemap_eligible,
                        'llms_eligible' => (bool) $asset->llms_eligible, 'source_package' => $asset->source_package,
                    ])->all();

                return Http::response(['ok' => true, 'items' => $items, 'pagination' => ['total' => 52]], 200);
            }
            if ($path === '/api/v0.5/seo/sitemap-source') {
                return Http::response(['ok' => true, 'items' => array_map(fn ($item) => ['loc' => 'https://www.example.test'.$item], $allPaths)], 200);
            }
            if (in_array($path, ['/sitemap.xml', '/llms.txt', '/llms-full.txt'], true)) {
                $surfacePaths = $path === $omitOnlySurface && $omitPath !== null
                    ? array_values(array_diff($completePaths, [$omitPath]))
                    : $allPaths;

                return Http::response(implode("\n", array_map(fn ($item) => 'https://www.example.test'.$item, $surfacePaths)), 200);
            }
            if (isset($aliases[$path])) {
                return Http::response('', 301, ['Location' => 'https://www.example.test'.$aliases[$path]]);
            }
            if (in_array($path, $allPaths, true)) {
                return Http::response('ok', 200);
            }

            return Http::response('', 404);
        });
    }

    /** @param array<string,mixed> $hreflang */
    private function seedBoundaryRow(string $framework, string $entityType, string $entityKey, string $slug, string $locale, array $hreflang = []): PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'framework' => $framework, 'entity_type' => $entityType, 'entity_key' => $entityKey,
            'slug' => $slug, 'locale' => $locale, 'title' => 'existing authority row', 'summary' => 'existing summary',
            'content_sections_json' => [['key' => 'existing', 'heading' => 'Existing', 'body' => 'Existing body']],
            'seo_json' => ['title' => 'Existing', 'description' => 'Existing'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/'.$locale.'/existing/'.$framework.'/'.$entityKey], 'hreflang_json' => $hreflang,
            'faq_json' => [], 'schema_json' => [], 'method_boundary_json' => [], 'evidence_notes_json' => [],
            'authority_json' => [], 'internal_links_json' => [], 'is_public' => false, 'index_eligible' => false,
            'sitemap_eligible' => false, 'llms_eligible' => false, 'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'existing', 'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => 'test-existing-authority', 'source_hash' => str_repeat('a', 64),
        ]);
    }

    private function databaseBytes(): string
    {
        return hash('sha256', json_encode([
            DB::table('personality_public_content_assets')->orderBy('id')->get()->all(),
            DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function expectFailureCode(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail('Expected verifier to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }
}
