<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use Tests\Concerns\UsesIsolatedSqliteDatabase;
use Tests\TestCase;

final class BigFiveAuthorityV2DraftImportWriterTest extends TestCase
{
    use UsesIsolatedSqliteDatabase;

    private const PACKAGE = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json';

    private const AUTHORIZATION = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/production-authorization-packet.json';

    protected function requiresIsolatedSqliteDatabase(): bool
    {
        return in_array($this->name(), [
            'test_write_requires_exact_authorization_and_commits_only_fail_closed_drafts',
            'test_existing_identity_aborts_authorized_create_only_retry_without_mutation',
        ], true);
    }

    public function test_preflight_reports_exact_231_create_zero_update_without_writes(): void
    {
        $plan = app(BigFiveAuthorityV2DraftImportWriter::class)->preflight(self::PACKAGE, self::AUTHORIZATION);

        $this->assertTrue($plan['ok']);
        $this->assertSame('PASS_READ_ONLY_PREFLIGHT', $plan['status']);
        $this->assertSame(231, $plan['asset_count']);
        $this->assertSame(231, $plan['create_count']);
        $this->assertSame(0, $plan['update_count']);
        $this->assertFalse($plan['writes_committed']);
        $this->assertSame([
            'CMS Article' => 109,
            'CMS content_pages' => 4,
            'CMS landing_surfaces/page_blocks' => 2,
            'CMS personality_public_content_assets' => 114,
            'CMS topic_profiles' => 2,
        ], $plan['surface_counts']);
        $this->assertSame(0, $this->primaryRecordCount());
    }

    public function test_write_requires_exact_authorization_and_commits_only_fail_closed_drafts(): void
    {
        $this->artisan('personality:big-five-authority-v2-draft-import', [
            '--package' => self::PACKAGE,
            '--authorization-packet' => self::AUTHORIZATION,
            '--confirm-pr37-merge-sha' => BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA,
            '--confirm-package-sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            '--expected-create' => '231',
            '--expected-update' => '0',
            '--operator-approved' => 'wrong phrase',
            '--write' => true,
            '--allow-testing' => true,
            '--json' => true,
        ])->assertExitCode(1);
        $this->assertSame(0, $this->primaryRecordCount());

        $this->artisan('personality:big-five-authority-v2-draft-import', $this->authorizedOptions(write: true))
            ->expectsOutputToContain('PASS_DRAFT_ONLY_PRODUCTION_IMPORT')
            ->assertExitCode(0);

        $this->assertSame(231, $this->primaryRecordCount());
        $this->assertSame(109, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(4, ContentPage::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(114, PersonalityPublicContentAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, TopicProfile::query()->withoutGlobalScopes()->count());

        $this->assertSame(109, Article::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->where('sitemap_eligible', false)->where('llms_eligible', false)->count());
        $this->assertSame(4, ContentPage::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->where('publish_allowed', false)->count());
        $this->assertSame(2, LandingSurface::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->count());
        $this->assertSame(114, PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_DRAFT)->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->where('is_public', false)->where('index_eligible', false)->where('sitemap_eligible', false)->where('llms_eligible', false)->count());
        $this->assertSame(2, TopicProfile::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->count());
    }

    public function test_existing_identity_aborts_authorized_create_only_retry_without_mutation(): void
    {
        $this->artisan('personality:big-five-authority-v2-draft-import', $this->authorizedOptions(write: true))
            ->assertExitCode(0);
        $timestamps = Article::query()->withoutGlobalScopes()->pluck('updated_at', 'id')->map->toISOString()->all();

        $this->artisan('personality:big-five-authority-v2-draft-import', $this->authorizedOptions(write: false))
            ->expectsOutputToContain('Production preflight count mismatch')
            ->assertExitCode(1);

        $this->assertSame(231, $this->primaryRecordCount());
        $this->assertSame($timestamps, Article::query()->withoutGlobalScopes()->pluck('updated_at', 'id')->map->toISOString()->all());
    }

    /** @return array<string,mixed> */
    private function authorizedOptions(bool $write): array
    {
        return [
            '--package' => self::PACKAGE,
            '--authorization-packet' => self::AUTHORIZATION,
            '--confirm-pr37-merge-sha' => BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA,
            '--confirm-package-sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            '--expected-create' => '231',
            '--expected-update' => '0',
            '--operator-approved' => BigFiveAuthorityV2DraftImportWriter::APPROVAL_PHRASE,
            $write ? '--write' : '--preflight' => true,
            '--allow-testing' => true,
            '--json' => true,
        ];
    }

    private function primaryRecordCount(): int
    {
        return Article::query()->withoutGlobalScopes()->withTrashed()->count()
            + ContentPage::query()->withoutGlobalScopes()->count()
            + LandingSurface::query()->withoutGlobalScopes()->count()
            + PersonalityPublicContentAsset::query()->withoutGlobalScopes()->count()
            + TopicProfile::query()->withoutGlobalScopes()->count();
    }
}
