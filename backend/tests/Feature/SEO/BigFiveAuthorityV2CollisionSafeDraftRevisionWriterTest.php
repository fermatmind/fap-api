<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2CollisionSafeDraftRevisionWriter;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Concerns\UsesIsolatedSqliteDatabase;
use Tests\TestCase;

final class BigFiveAuthorityV2CollisionSafeDraftRevisionWriterTest extends TestCase
{
    use UsesIsolatedSqliteDatabase;

    private const PACKAGE = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json';

    private const LEGACY_AUTHORIZATION = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/production-authorization-packet.json';

    private const CONTRACT = '../generated/big-five-authority-v2/big5-authority-v2-collision-safe-draft-revision-writer/collision-safe-preflight-contract.json';

    private const AUTHORIZATION = '../generated/big-five-authority-v2/big5-authority-v2-collision-safe-draft-revision-writer/production-authorization-packet.json';

    private const TEST_DEPLOY_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const CONTRACT_SHA256 = 'fffcd07c97a7adbefc9d63c03b6523233f4b9f3c6a0c5733249da591254f3b49';

    private const AUTHORIZATION_SHA256 = 'c8f63fcf36e057fa25c8c3c6fc8969c2fbf3e0dea20595f47195356e8673db8d';

    /** @var list<string> */
    private const EXISTING_ARTICLE_SLUGS = [
        'big-five-conscientiousness-low-procrastination-task-plan',
        'big-five-emotional-stability-stress-recovery-communication',
        'big-five-personality-test-vs-mbti',
        'big-five-growth-guide',
        'big-five-narrative-portrait',
        'big-five-tool-guide',
    ];

    protected function requiresIsolatedSqliteDatabase(): bool
    {
        return in_array($this->name(), [
            'test_console_preflight_is_read_only_and_write_rejects_an_unapproved_phrase',
        ], true);
    }

    public function test_preflight_reports_exact_collision_safe_actions_without_writes(): void
    {
        $this->seedProductionCollisionFixture();
        $before = $this->tableCounts();

        $plan = $this->writer()->preflight(...$this->paths());

        $this->assertTrue($plan['ok']);
        $this->assertSame('PASS_COLLISION_SAFE_READ_ONLY_PREFLIGHT', $plan['status']);
        $this->assertSame(231, $plan['asset_count']);
        $this->assertSame(106, $plan['primary_create_count']);
        $this->assertSame(125, $plan['existing_revision_count']);
        $this->assertSame(229, $plan['revision_create_count']);
        $this->assertSame(104, $plan['new_working_revision_count']);
        $this->assertSame(125, $plan['existing_pointer_update_count']);
        $this->assertSame(0, $plan['existing_primary_public_content_overwrite_count']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan['existing_public_runtime_fingerprint']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan['preflight_fingerprint']);
        $this->assertFalse($plan['writes_committed']);
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_write_creates_106_primary_drafts_and_229_revisions_without_public_runtime_drift(): void
    {
        $this->seedProductionCollisionFixture();
        $publicBefore = $this->existingPublicSnapshots();
        $plan = $this->writer()->preflight(...$this->paths());

        $result = $this->writeWithFingerprint((string) $plan['preflight_fingerprint']);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['writes_committed']);
        $this->assertSame('PASS_COLLISION_SAFE_DRAFT_REVISION_IMPORT', $result['status']);
        $this->assertSame(106, $result['primary_records_created']);
        $this->assertSame(0, $result['existing_primary_public_content_overwrites']);
        $this->assertSame(229, $result['working_or_draft_revisions_created']);
        $this->assertTrue($result['readback']['ok']);
        $this->assertSame($result['readback']['existing_public_runtime_fingerprint_before'], $result['readback']['existing_public_runtime_fingerprint_after']);
        $this->assertSame($publicBefore, $this->existingPublicSnapshots());

        $this->assertSame(109, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(4, ContentPage::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(114, PersonalityPublicContentAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, TopicProfile::query()->withoutGlobalScopes()->count());
        $this->assertSame(109, ArticleTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count());
        $this->assertSame(4, CmsTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count());
        $this->assertSame(114, PersonalityPublicContentAssetRevision::query()->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count());
        $this->assertSame(2, TopicProfileRevision::query()->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count());

        $this->assertSame(100, Article::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->where('sitemap_eligible', false)->where('llms_eligible', false)->whereNotNull('working_revision_id')->count());
        $this->assertSame(4, ContentPage::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->whereNotNull('working_revision_id')->count());
        $this->assertSame(2, LandingSurface::query()->withoutGlobalScopes()->where('status', 'draft')->where('is_public', false)->where('is_indexable', false)->count());
        $this->assertSame(114, PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)->where('is_public', true)->whereNotNull('working_revision_id')->count());
        $this->assertSame(2, TopicProfile::query()->withoutGlobalScopes()->where('status', 'published')->where('is_public', true)->whereNotNull('working_revision_id')->count());
    }

    public function test_write_aborts_before_mutation_when_public_runtime_changes_after_preflight(): void
    {
        $this->seedProductionCollisionFixture();
        $plan = $this->writer()->preflight(...$this->paths());
        Article::query()->withoutGlobalScopes()->where('status', 'published')->firstOrFail()->forceFill([
            'title' => 'Concurrent public edit',
        ])->saveQuietly();

        try {
            $this->writeWithFingerprint((string) $plan['preflight_fingerprint']);
            $this->fail('Expected stale preflight fingerprint to abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preflight fingerprint changed', $exception->getMessage());
        }

        $this->assertSame(125, $this->primaryRecordCount());
        $this->assertSame(0, $this->authorityRevisionCount());
    }

    public function test_preflight_aborts_when_existing_article_has_an_isolated_working_revision(): void
    {
        $this->seedProductionCollisionFixture();
        $article = Article::query()->withoutGlobalScopes()->where('status', 'published')->firstOrFail();
        $published = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail((int) $article->published_revision_id);
        $working = $published->replicate();
        $working->revision_number = 2;
        $working->revision_status = ArticleTranslationRevision::STATUS_HUMAN_REVIEW;
        $working->supersedes_revision_id = (int) $published->id;
        $working->save();
        DB::table('articles')->where('id', (int) $article->id)->update(['working_revision_id' => (int) $working->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated working draft');
        $this->writer()->preflight(...$this->paths());
    }

    public function test_dynamic_write_authorization_phrase_locks_deploy_and_preflight_fingerprints(): void
    {
        $writer = $this->writer();
        $fingerprint = str_repeat('b', 64);
        $phrase = $writer->approvalPhrase(self::TEST_DEPLOY_SHA, $fingerprint);

        $this->assertStringContainsString('DEPLOY_SHA='.self::TEST_DEPLOY_SHA, $phrase);
        $this->assertStringContainsString('PREFLIGHT_FINGERPRINT='.$fingerprint, $phrase);
        $this->assertStringContainsString('PRIMARY_CREATE=106 EXISTING_REVISION=125 REVISION_CREATE=229', $phrase);
        $this->assertStringContainsString('PUBLIC_CONTENT_OVERWRITE=0', $phrase);
    }

    public function test_hashed_authorization_artifacts_are_exact_and_remain_on_hold(): void
    {
        $directory = base_path('../generated/big-five-authority-v2/big5-authority-v2-collision-safe-draft-revision-writer');
        $contractPath = $directory.'/collision-safe-preflight-contract.json';
        $authorizationPath = $directory.'/production-authorization-packet.json';
        $hashesPath = $directory.'/sha256sums.json';

        $this->assertSame(self::CONTRACT_SHA256, hash_file('sha256', $contractPath));
        $this->assertSame(self::AUTHORIZATION_SHA256, hash_file('sha256', $authorizationPath));
        $this->assertSame(self::CONTRACT_SHA256, BigFiveAuthorityV2CollisionSafeDraftRevisionWriter::COLLISION_CONTRACT_SHA256);

        $hashes = json_decode(File::get($hashesPath), true, 512, JSON_THROW_ON_ERROR);
        $authorization = json_decode(File::get($authorizationPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::CONTRACT_SHA256, $hashes['files']['collision-safe-preflight-contract.json']);
        $this->assertSame(self::AUTHORIZATION_SHA256, $hashes['files']['production-authorization-packet.json']);
        $this->assertSame('HOLD_PENDING_WRITER_DEPLOY_MIGRATION_AND_READ_ONLY_PRODUCTION_PREFLIGHT', $authorization['status']);
        $this->assertFalse($authorization['production_write_currently_authorized']);
        $this->assertFalse($authorization['production_deploy_currently_authorized']);
        $this->assertFalse($authorization['production_migration_execution_currently_authorized']);
        $this->assertFalse($authorization['approval_phrase_currently_executable']);
    }

    public function test_console_preflight_is_read_only_and_write_rejects_an_unapproved_phrase(): void
    {
        $this->seedProductionCollisionFixture();
        $before = $this->tableCounts();
        $options = $this->commandOptions();

        $this->artisan('personality:big-five-authority-v2-collision-safe-draft-import', [
            ...$options,
            '--preflight' => true,
        ])
            ->expectsOutputToContain('status=PASS_COLLISION_SAFE_READ_ONLY_PREFLIGHT')
            ->expectsOutputToContain('writes_committed=0')
            ->assertExitCode(0);
        $this->assertSame($before, $this->tableCounts());

        $fingerprint = (string) $this->writer()->preflight(...$this->paths())['preflight_fingerprint'];
        $this->artisan('personality:big-five-authority-v2-collision-safe-draft-import', [
            ...$options,
            '--write' => true,
            '--confirm-preflight-fingerprint' => $fingerprint,
            '--operator-approved' => 'not-authorized',
        ])
            ->expectsOutputToContain('status=FAIL_CLOSED')
            ->expectsOutputToContain('Operator collision-safe write authorization phrase mismatch.')
            ->assertExitCode(1);
        $this->assertSame($before, $this->tableCounts());
    }

    /** @return list<string> */
    private function paths(): array
    {
        return [self::PACKAGE, self::LEGACY_AUTHORIZATION, self::CONTRACT, self::AUTHORIZATION];
    }

    private function writer(): BigFiveAuthorityV2CollisionSafeDraftRevisionWriter
    {
        return app(BigFiveAuthorityV2CollisionSafeDraftRevisionWriter::class);
    }

    /** @return array<string,string|bool> */
    private function commandOptions(): array
    {
        return [
            '--package' => self::PACKAGE,
            '--legacy-authorization-packet' => self::LEGACY_AUTHORIZATION,
            '--collision-contract' => self::CONTRACT,
            '--authorization-packet' => self::AUTHORIZATION,
            '--confirm-pr37-merge-sha' => BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA,
            '--confirm-package-sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            '--confirm-collision-contract-sha256' => self::CONTRACT_SHA256,
            '--confirm-writer-deploy-sha' => self::TEST_DEPLOY_SHA,
            '--expected-primary-create' => '106',
            '--expected-existing-revision' => '125',
            '--expected-revision-create' => '229',
            '--allow-testing' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function writeWithFingerprint(string $fingerprint): array
    {
        return $this->writer()->write(
            self::PACKAGE,
            self::LEGACY_AUTHORIZATION,
            self::CONTRACT,
            self::AUTHORIZATION,
            106,
            125,
            229,
            $fingerprint,
        );
    }

    private function seedProductionCollisionFixture(): void
    {
        $plan = app(BigFiveAuthorityV2DraftImportWriter::class)->validatedPlan(self::PACKAGE, self::LEGACY_AUTHORIZATION);
        foreach ($plan['descriptors'] as $descriptor) {
            if ($descriptor['model'] === PersonalityPublicContentAsset::class) {
                $this->createPublishedPersonality($descriptor);

                continue;
            }
            if ($descriptor['model'] === TopicProfile::class) {
                $this->createPublishedTopic($descriptor);

                continue;
            }
            if ($descriptor['model'] === Article::class
                && in_array((string) $descriptor['identity']['slug'], self::EXISTING_ARTICLE_SLUGS, true)) {
                $this->createPublishedArticle($descriptor);
            }
        }

        $this->assertSame(125, $this->primaryRecordCount());
        $this->assertSame(9, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(114, PersonalityPublicContentAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, TopicProfile::query()->withoutGlobalScopes()->count());
    }

    /** @param array<string,mixed> $descriptor */
    private function createPublishedArticle(array $descriptor): void
    {
        $attributes = $descriptor['attributes'];
        $attributes['title'] = 'Existing public article '.$descriptor['asset_id'];
        $attributes['excerpt'] = 'Existing production authority content.';
        $attributes['content_md'] = 'Existing production authority content.';
        $attributes['status'] = 'published';
        $attributes['translation_status'] = Article::TRANSLATION_STATUS_PUBLISHED;
        $attributes['is_public'] = true;
        $attributes['is_indexable'] = true;
        $attributes['sitemap_eligible'] = true;
        $attributes['llms_eligible'] = true;
        $attributes['published_at'] = '2026-07-01 00:00:00';
        $article = Article::query()->withoutGlobalScopes()->create($attributes);
        $hash = hash('sha256', 'existing-article-'.$article->id);
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) $article->locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $hash,
            'translated_from_version_hash' => $hash,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'published_at' => '2026-07-01 00:00:00',
        ]);
        DB::table('articles')->where('id', (int) $article->id)->update([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ]);
    }

    /** @param array<string,mixed> $descriptor */
    private function createPublishedPersonality(array $descriptor): void
    {
        $attributes = $descriptor['attributes'];
        $attributes['title'] = 'Existing public personality '.$descriptor['asset_id'];
        $attributes['summary'] = 'Existing production authority content.';
        $attributes['content_sections_json'] = [['key' => 'existing', 'body_md' => 'Existing production authority content.']];
        $attributes['robots'] = PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW;
        $attributes['is_public'] = true;
        $attributes['index_eligible'] = true;
        $attributes['sitemap_eligible'] = true;
        $attributes['llms_eligible'] = true;
        $attributes['launch_state'] = PersonalityPublicContentAsset::LAUNCH_PUBLISHED;
        $attributes['review_state'] = 'approved';
        $attributes['source_package'] = 'existing-production-authority';
        $attributes['source_hash'] = hash('sha256', 'existing-personality-'.$descriptor['asset_id']);
        $attributes['published_at'] = '2026-07-01 00:00:00';
        PersonalityPublicContentAsset::query()->withoutGlobalScopes()->create($attributes);
    }

    /** @param array<string,mixed> $descriptor */
    private function createPublishedTopic(array $descriptor): void
    {
        $attributes = $descriptor['attributes'];
        $attributes['title'] = 'Existing public topic '.$descriptor['asset_id'];
        $attributes['excerpt'] = 'Existing production authority content.';
        $attributes['status'] = TopicProfile::STATUS_PUBLISHED;
        $attributes['is_public'] = true;
        $attributes['is_indexable'] = true;
        $attributes['published_at'] = '2026-07-01 00:00:00';
        TopicProfile::query()->withoutGlobalScopes()->create($attributes);
    }

    /** @return array<string,string> */
    private function existingPublicSnapshots(): array
    {
        $snapshots = [];
        $queries = [
            Article::class => Article::query()->withoutGlobalScopes()
                ->where('status', 'published')
                ->where('is_public', true),
            PersonalityPublicContentAsset::class => PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)
                ->where('is_public', true),
            TopicProfile::class => TopicProfile::query()->withoutGlobalScopes()
                ->where('status', TopicProfile::STATUS_PUBLISHED)
                ->where('is_public', true),
        ];

        foreach ($queries as $model => $query) {
            $query->orderBy('id')->each(function (Model $record) use (&$snapshots, $model): void {
                $attributes = $record->getAttributes();
                unset($attributes['working_revision_id']);
                ksort($attributes);
                $snapshots[$model.':'.$record->getKey()] = hash('sha256', json_encode(
                    $attributes,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
            });
        }
        ksort($snapshots);

        return $snapshots;
    }

    /** @return array<string,int> */
    private function tableCounts(): array
    {
        return [
            'primary' => $this->primaryRecordCount(),
            'article_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()->count(),
            'cms_revisions' => CmsTranslationRevision::query()->withoutGlobalScopes()->count(),
            'personality_revisions' => PersonalityPublicContentAssetRevision::query()->count(),
            'topic_revisions' => TopicProfileRevision::query()->count(),
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

    private function authorityRevisionCount(): int
    {
        return ArticleTranslationRevision::query()->withoutGlobalScopes()->whereNotNull('authority_package_sha256')->count()
            + CmsTranslationRevision::query()->withoutGlobalScopes()->whereNotNull('authority_package_sha256')->count()
            + PersonalityPublicContentAssetRevision::query()->count()
            + TopicProfileRevision::query()->whereNotNull('authority_package_sha256')->count();
    }
}
