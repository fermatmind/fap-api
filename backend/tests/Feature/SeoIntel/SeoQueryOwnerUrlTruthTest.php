<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Seo\QueryFamily;
use App\Models\Seo\QueryFamilyQuery;
use App\Models\Seo\QueryUrlBinding;
use App\Services\SeoIntel\QueryOwnerUrlTruthReadModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoQueryOwnerUrlTruthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.core_entry_slo.private_path_segments' => [
                'take',
                'result',
                'results',
                'attempt',
                'attempts',
                'order',
                'orders',
                'payment',
                'checkout',
                'report',
                'share',
            ],
        ]);

        DB::purge('seo_intel');
        $this->createSeoUrlsTable();
        $this->queryOwnerMigration()->up();
    }

    #[Test]
    public function migration_creates_normalized_query_owner_tables_without_private_identifiers(): void
    {
        $schema = Schema::connection('seo_intel');

        $this->assertTrue($schema->hasTable('seo_query_families'));
        $this->assertTrue($schema->hasTable('seo_query_family_queries'));
        $this->assertTrue($schema->hasTable('seo_query_url_bindings'));
        $this->assertTrue($schema->hasColumns('seo_query_families', [
            'family_key',
            'locale',
            'intent_type',
            'source_authority',
            'state',
        ]));
        $this->assertTrue($schema->hasColumns('seo_query_url_bindings', [
            'url_hash',
            'url_role',
            'target_owner_url_hash',
            'hreflang_locale',
        ]));

        $source = (string) file_get_contents($this->migrationPath());
        foreach (['email', 'attempt_id', 'order_no', 'payment_id', 'raw_query'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}'", $source);
        }
    }

    #[Test]
    public function one_owner_per_locale_reconciles_canonical_hreflang_sitemap_and_internal_link_targets(): void
    {
        [$zhFamily, $zhOwner] = $this->seedFamily('mbti-direct', 'zh-CN', '/zh/tests/mbti');
        [$enFamily, $enOwner] = $this->seedFamily('mbti-direct', 'en', '/en/tests/mbti');
        $support = $this->insertUrl('/zh/articles/mbti-guide');

        $this->bind($zhFamily, $enOwner, QueryUrlBinding::ROLE_ALTERNATE_LOCALE, $enOwner, 'en');
        $this->bind($enFamily, $zhOwner, QueryUrlBinding::ROLE_ALTERNATE_LOCALE, $zhOwner, 'zh-CN');
        $this->bind($zhFamily, $support, QueryUrlBinding::ROLE_SUPPORTING_URL, $zhOwner);
        $this->bind(
            $zhFamily,
            hash('sha256', 'https://fermatmind.com/zh/tests/old-mbti'),
            QueryUrlBinding::ROLE_REDIRECT_ALIAS,
            $zhOwner,
            null,
            'backend_redirect_catalog',
            '/zh/tests/old-mbti',
        );

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('mbti-direct');

        $this->assertTrue(
            $report['ok'] ?? false,
            json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('pass', $report['status'] ?? null);
        $this->assertSame(2, $report['family_count'] ?? null);
        $this->assertSame(0, $report['conflict_count'] ?? null);
        $this->assertSame(0, $report['private_binding_exclusion_count'] ?? null);

        foreach ($report['families'] ?? [] as $family) {
            $this->assertSame('pass', $family['status'] ?? null);
            $this->assertSame('primary_owner', $family['owner_role'] ?? null);
            $this->assertSame('pass', data_get($family, 'checks.canonical_owner'));
            $this->assertSame('pass', data_get($family, 'checks.hreflang_owner'));
            $this->assertSame('pass', data_get($family, 'checks.sitemap_member_owner'));
            $this->assertSame('pass', data_get($family, 'checks.internal_link_target_owner'));
        }
    }

    #[Test]
    public function multiple_primary_owners_fail_closed_as_a_conflict(): void
    {
        [$family] = $this->seedFamily(
            'free-personality-test',
            'zh-CN',
            '/zh/tests',
            ['hreflang_required' => false],
        );
        $secondOwner = $this->insertUrl('/zh/topics/personality');
        $this->bind($family, $secondOwner, QueryUrlBinding::ROLE_PRIMARY_OWNER, $secondOwner);

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('free-personality-test');
        $familyReport = $report['families'][0] ?? [];

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame('blocked', $report['status'] ?? null);
        $this->assertSame(1, $report['conflict_count'] ?? null);
        $this->assertSame('conflict', $familyReport['status'] ?? null);
        $this->assertContains('multiple_primary_owner', $familyReport['issues'] ?? []);
        $this->assertCount(2, $familyReport['owner_hashes'] ?? []);
    }

    #[Test]
    public function private_owner_urls_are_excluded_and_never_emitted(): void
    {
        [$family, $privateHash] = $this->seedFamily(
            'private-result',
            'zh-CN',
            '/zh/results/private-token',
            ['hreflang_required' => false],
            true,
        );

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('private-result');
        $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame(1, $report['private_binding_exclusion_count'] ?? null);
        $this->assertContains('private_url_excluded', $report['families'][0]['issues'] ?? []);
        $this->assertNotContains($privateHash, $report['families'][0]['owner_hashes'] ?? []);
        $this->assertStringNotContainsString('/zh/results/private-token', $encoded);
    }

    #[Test]
    public function explicit_hold_is_distinct_from_conflict(): void
    {
        $family = QueryFamily::query()->create([
            'family_key' => 'career-interest-broad',
            'locale' => 'zh-CN',
            'intent_type' => 'exploration',
            'source_authority' => 'backend_query_owner_registry',
            'state' => 'hold',
            'metadata_json' => ['hreflang_required' => false],
        ]);
        QueryFamilyQuery::query()->create([
            'query_family_id' => $family->id,
            'query_hash' => hash('sha256', 'career interest'),
            'source_engine' => 'google',
            'source_authority' => 'backend_query_owner_registry',
            'authority_status' => 'active',
        ]);
        $this->bind(
            $family,
            hash('sha256', 'hold:career-interest-broad'),
            QueryUrlBinding::ROLE_HOLD,
            null,
        );

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('career-interest-broad');

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame('hold', $report['status'] ?? null);
        $this->assertSame(1, $report['hold_count'] ?? null);
        $this->assertSame(0, $report['conflict_count'] ?? null);
        $this->assertSame('hold', $report['families'][0]['status'] ?? null);
    }

    #[Test]
    public function owner_must_be_backend_authoritative_indexable_and_in_the_sitemap(): void
    {
        [$family, $ownerHash] = $this->seedFamily(
            'big-five-direct',
            'zh-CN',
            '/zh/tests/big-five',
            ['hreflang_required' => false],
            false,
            false,
            'frontend_fallback',
        );

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('big-five-direct');
        $issues = $report['families'][0]['issues'] ?? [];

        $this->assertFalse($report['ok'] ?? true);
        $this->assertContains('primary_owner_not_sitemap_member', $issues);
        $this->assertContains('primary_owner_url_truth_not_backend_owned', $issues);
        $this->assertSame([$ownerHash], $report['families'][0]['owner_hashes'] ?? []);
    }

    #[Test]
    public function supporting_and_redirect_targets_must_converge_on_the_primary_owner(): void
    {
        [$family, $ownerHash] = $this->seedFamily(
            'riasec-direct',
            'zh-CN',
            '/zh/tests/riasec',
            ['hreflang_required' => false],
        );
        $supportHash = $this->insertUrl('/zh/articles/riasec-guide');
        $wrongTarget = hash('sha256', 'wrong-owner');
        $this->bind($family, $supportHash, QueryUrlBinding::ROLE_SUPPORTING_URL, $wrongTarget);
        $this->bind(
            $family,
            hash('sha256', 'https://fermatmind.com/zh/tests/holland-old'),
            QueryUrlBinding::ROLE_REDIRECT_ALIAS,
            $wrongTarget,
            null,
            'backend_redirect_catalog',
            '/zh/tests/holland-old',
        );

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('riasec-direct');
        $issues = $report['families'][0]['issues'] ?? [];

        $this->assertFalse($report['ok'] ?? true);
        $this->assertSame([$ownerHash], $report['families'][0]['owner_hashes'] ?? []);
        $this->assertContains('supporting_url_owner_target_mismatch', $issues);
        $this->assertContains('redirect_alias_owner_target_mismatch', $issues);
        $this->assertSame('blocked', data_get($report, 'families.0.checks.internal_link_target_owner'));
    }

    #[Test]
    public function hreflang_pair_must_be_reciprocal(): void
    {
        [$zhFamily] = $this->seedFamily('mbti-career', 'zh-CN', '/zh/careers/mbti');
        [, $enOwner] = $this->seedFamily('mbti-career', 'en', '/en/careers/mbti');
        $this->bind($zhFamily, $enOwner, QueryUrlBinding::ROLE_ALTERNATE_LOCALE, $enOwner, 'en');

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('mbti-career');
        $zhReport = collect($report['families'] ?? [])->firstWhere('locale', 'zh-CN') ?? [];

        $this->assertFalse($report['ok'] ?? true);
        $this->assertContains(
            'alternate_locale_reciprocal_binding_missing',
            $zhReport['issues'] ?? [],
        );
        $this->assertSame('blocked', data_get($zhReport, 'checks.hreflang_owner'));
    }

    #[Test]
    public function redirect_alias_requires_the_redirect_catalog_and_a_safe_public_path(): void
    {
        [$family, $ownerHash] = $this->seedFamily(
            'big-five-alias',
            'zh-CN',
            '/zh/tests/big-five',
            ['hreflang_required' => false],
            false,
            true,
            'scale_catalog',
        );
        $this->bind(
            $family,
            hash('sha256', 'javascript:alert(1)'),
            QueryUrlBinding::ROLE_REDIRECT_ALIAS,
            $ownerHash,
            null,
            'backend_redirect_catalog',
            'javascript:alert(1)',
        );
        $this->bind(
            $family,
            hash('sha256', '/zh/tests/big-five-old'),
            QueryUrlBinding::ROLE_REDIRECT_ALIAS,
            $ownerHash,
            null,
            'backend_query_owner_registry',
            '/zh/tests/big-five-old',
        );

        $report = app(QueryOwnerUrlTruthReadModel::class)->report('big-five-alias');
        $issues = $report['families'][0]['issues'] ?? [];

        $this->assertFalse($report['ok'] ?? true);
        $this->assertContains('private_url_excluded', $issues);
        $this->assertContains('redirect_alias_source_authority_invalid', $issues);
        $this->assertSame([$ownerHash], $report['families'][0]['owner_hashes'] ?? []);
    }

    #[Test]
    public function command_is_read_only_and_returns_nonzero_for_conflicts(): void
    {
        [$family] = $this->seedFamily(
            'enneagram-direct',
            'zh-CN',
            '/zh/tests/enneagram',
            ['hreflang_required' => false],
        );
        $secondOwner = $this->insertUrl('/zh/topics/enneagram');
        $this->bind($family, $secondOwner, QueryUrlBinding::ROLE_PRIMARY_OWNER, $secondOwner);
        $before = $this->tableCounts();

        $exitCode = Artisan::call('seo-intel:query-owner-url-truth-report', [
            '--family' => 'enneagram-direct',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame(1, $payload['conflict_count'] ?? null);
        $this->assertTrue($payload['read_only'] ?? false);
        $this->assertSame($before, $this->tableCounts());
    }

    #[Test]
    public function missing_schema_and_unknown_family_fail_closed_without_writes(): void
    {
        $unknown = app(QueryOwnerUrlTruthReadModel::class)->report('unknown-family');
        $this->assertSame(['query_family_not_found'], $unknown['issues'] ?? []);

        Schema::connection('seo_intel')->drop('seo_query_url_bindings');
        $missingSchema = app(QueryOwnerUrlTruthReadModel::class)->report();

        $this->assertFalse($missingSchema['ok'] ?? true);
        $this->assertSame(['query_owner_schema_unavailable'], $missingSchema['issues'] ?? []);
        $this->assertFalse(data_get($missingSchema, 'negative_guarantees.database_write', true));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{QueryFamily, string}
     */
    private function seedFamily(
        string $familyKey,
        string $locale,
        string $path,
        array $metadata = ['hreflang_required' => true],
        bool $private = false,
        bool $sitemapEligible = true,
        string $urlSourceAuthority = 'backend_sitemap_source',
    ): array {
        $family = QueryFamily::query()->create([
            'family_key' => $familyKey,
            'locale' => $locale,
            'intent_type' => 'direct_test',
            'source_authority' => 'backend_query_owner_registry',
            'state' => 'active',
            'metadata_json' => $metadata,
        ]);
        QueryFamilyQuery::query()->create([
            'query_family_id' => $family->id,
            'query_hash' => hash('sha256', $familyKey.'|'.$locale),
            'source_engine' => 'google',
            'query_display_masked' => $familyKey,
            'source_authority' => 'backend_query_owner_registry',
            'authority_status' => 'active',
        ]);

        $ownerHash = $this->insertUrl($path, $private, $sitemapEligible, $urlSourceAuthority);
        $this->bind($family, $ownerHash, QueryUrlBinding::ROLE_PRIMARY_OWNER, $ownerHash);

        return [$family, $ownerHash];
    }

    private function insertUrl(
        string $path,
        bool $private = false,
        bool $sitemapEligible = true,
        string $sourceAuthority = 'backend_sitemap_source',
    ): string {
        $url = 'https://fermatmind.com'.$path;
        $hash = hash('sha256', $url);

        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => $url,
            'locale' => str_starts_with($path, '/en/') ? 'en' : 'zh-CN',
            'page_entity_type' => 'test_detail',
            'source_authority' => $sourceAuthority,
            'indexability_state' => 'indexable',
            'is_private_flow' => $private,
            'metadata_json' => json_encode(
                ['sitemap_eligible' => $sitemapEligible],
                JSON_THROW_ON_ERROR
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $hash;
    }

    private function bind(
        QueryFamily $family,
        string $urlHash,
        string $role,
        ?string $targetOwnerHash,
        ?string $hreflangLocale = null,
        string $sourceAuthority = 'backend_query_owner_registry',
        ?string $urlPath = null,
    ): QueryUrlBinding {
        return QueryUrlBinding::query()->create([
            'query_family_id' => $family->id,
            'url_hash' => $urlHash,
            'url_path' => $urlPath,
            'url_role' => $role,
            'target_owner_url_hash' => $targetOwnerHash,
            'hreflang_locale' => $hreflangLocale,
            'source_authority' => $sourceAuthority,
            'authority_status' => 'active',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'families' => QueryFamily::query()->count(),
            'queries' => QueryFamilyQuery::query()->count(),
            'bindings' => QueryUrlBinding::query()->count(),
            'urls' => DB::connection('seo_intel')->table('seo_urls')->count(),
        ];
    }

    private function createSeoUrlsTable(): void
    {
        Schema::connection('seo_intel')->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['canonical_url_hash', 'locale']);
        });
    }

    private function queryOwnerMigration(): object
    {
        return require $this->migrationPath();
    }

    private function migrationPath(): string
    {
        return base_path(
            'database/migrations/seo_intel/2026_07_24_060000_create_seo_query_owner_url_truth_tables.php'
        );
    }
}
