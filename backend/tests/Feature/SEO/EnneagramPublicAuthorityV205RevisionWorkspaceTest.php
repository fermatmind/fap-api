<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV205RevisionWorkspaceWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class EnneagramPublicAuthorityV205RevisionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_DEPLOY_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_preflight_resolves_exactly_116_targets_without_writes_and_with_stable_package_sha(): void
    {
        $this->seedPublishedEstate();

        $first = $this->writer()->preflight($this->releaseReport());
        $second = $this->writer()->preflight($this->releaseReport());

        $this->assertTrue($first['ok']);
        $this->assertSame('PASS_COLLISION_SAFE_WORKING_REVISION_PREFLIGHT', $first['status']);
        $this->assertSame(PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM, $first['framework']);
        $this->assertSame(116, $first['target_count']);
        $this->assertSame(116, $first['new_revision_count']);
        $this->assertSame(0, $first['idempotent_reuse_count']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['package_sha256']);
        $this->assertSame($first['package_sha256'], $second['package_sha256']);
        $this->assertSame($first['preflight_fingerprint'], $second['preflight_fingerprint']);
        $this->assertFalse($first['writes_committed']);
        $this->assertFalse($first['production_command_executed']);
        $this->assertTrue($first['database_migration_required']);
        $this->assertSame((string) $this->releaseReport()['package_sha256'], $first['package_sha256']);
        $this->assertSame(116, $first['candidate_snapshot_count']);
        $this->assertSame(116, $first['pending_manual_review_count']);
        $this->assertSame(116, $first['empty_media_authority_count']);
        $this->assertSame(0, $first['media_write_count']);
        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->whereNotNull('working_revision_id')->count());
    }

    public function test_write_creates_116_isolated_revisions_without_published_primary_drift_and_retry_is_idempotent(): void
    {
        $this->seedPublishedEstate();
        $before = $this->publishedPrimarySnapshots();
        $plan = $this->writer()->preflight($this->releaseReport());

        $first = $this->writer()->write(
            $this->releaseReport(),
            (string) $plan['package_sha256'],
            (string) $plan['preflight_fingerprint'],
        );
        $retryPlan = $this->writer()->preflight($this->releaseReport());
        $retry = $this->writer()->write(
            $this->releaseReport(),
            (string) $retryPlan['package_sha256'],
            (string) $retryPlan['preflight_fingerprint'],
        );

        $this->assertTrue($first['ok']);
        $this->assertSame('PASS_COLLISION_SAFE_WORKING_REVISION_WRITE', $first['status']);
        $this->assertSame(116, $first['revision_created_count']);
        $this->assertSame(0, $first['revision_reused_count']);
        $this->assertSame(0, $first['primary_content_overwrite_count']);
        $this->assertSame(0, $first['published_pointer_update_count']);
        $this->assertSame(
            $first['readback']['published_primary_fingerprint_before'],
            $first['readback']['published_primary_fingerprint_after'],
        );
        $this->assertSame($before, $this->publishedPrimarySnapshots());
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
            ->where('workflow_state', 'pending_manual_review')->count());
        $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNotNull('working_revision_id')->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->whereNotNull('published_revision_id')->count());

        $this->assertSame($plan['package_sha256'], $retryPlan['package_sha256']);
        $this->assertSame($plan['preflight_fingerprint'], $retryPlan['preflight_fingerprint']);
        $this->assertSame(0, $retryPlan['new_revision_count']);
        $this->assertSame(116, $retryPlan['idempotent_reuse_count']);
        $this->assertSame(0, $retry['revision_created_count']);
        $this->assertSame(116, $retry['revision_reused_count']);
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame($before, $this->publishedPrimarySnapshots());

        $typeFourSocial = PersonalityPublicContentAssetRevision::query()
            ->where('authority_asset_key', 'enneagram:instinctual_subtype:type-4/social:en')
            ->firstOrFail();
        $candidate = $this->candidate('en|instinctual_subtype:type-4/social');
        $this->assertSame($candidate['title'], $typeFourSocial->snapshot_json['title']);
        $this->assertSame($candidate['answer_first'], $typeFourSocial->snapshot_json['summary']);
        $this->assertSame($candidate['answer_first'], $typeFourSocial->snapshot_json['seo_json']['description']);
        $this->assertSame([], $typeFourSocial->snapshot_json['schema_json']);
        $this->assertArrayNotHasKey('media_json', $typeFourSocial->snapshot_json);
        $this->assertSame($typeFourSocial->source_hash, $typeFourSocial->snapshot_json['source_hash']);
        $this->assertSame('pending_manual_review', $typeFourSocial->snapshot_json['review_state']);
        $this->assertNull($typeFourSocial->snapshot_json['authority_json']['reviewer']);
        $this->assertSame('observation_exercise', collect($typeFourSocial->snapshot_json['content_sections_json'])->last()['key']);
    }

    public function test_foreign_working_revision_collision_fails_before_any_package_write(): void
    {
        $this->seedPublishedEstate();
        $asset = PersonalityPublicContentAsset::query()->orderBy('id')->firstOrFail();
        $revision = PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => (int) $asset->id,
            'revision_no' => 1,
            'authority_asset_key' => 'foreign:'.$asset->id,
            'source_package' => 'foreign-package',
            'source_hash' => str_repeat('b', 64),
            'authority_package_sha256' => str_repeat('c', 64),
            'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
            'snapshot_json' => $this->snapshot($asset),
            'public_runtime_fingerprint_before' => str_repeat('d', 64),
        ]);
        DB::table('personality_public_content_assets')->where('id', $asset->id)->update([
            'working_revision_id' => (int) $revision->id,
        ]);

        try {
            $this->writer()->preflight($this->releaseReport());
            $this->fail('Expected a foreign working-revision collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('foreign isolated working revision', $exception->getMessage());
        }

        $this->assertSame(1, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(1, PersonalityPublicContentAsset::query()->whereNotNull('working_revision_id')->count());
    }

    public function test_mid_transaction_revision_insert_failure_rolls_back_every_revision_and_pointer(): void
    {
        $this->seedPublishedEstate();
        $before = $this->publishedPrimarySnapshots();
        $plan = $this->writer()->preflight($this->releaseReport());
        // MySQL trigger DDL commits implicitly. Keep the service transaction real,
        // and rebuild this disposable test database before the next test.
        if (DB::connection()->getDriverName() === 'mysql') {
            self::assertSame(1, DB::transactionLevel());
            DB::commit();
            RefreshDatabaseState::$migrated = false;
        }
        DB::unprepared(DB::connection()->getDriverName() === 'mysql' ? <<<'SQL'
CREATE TRIGGER fail_enneagram_revision_workspace_insert
BEFORE INSERT ON personality_public_content_asset_revisions
FOR EACH ROW
BEGIN
    IF NEW.authority_asset_key = 'enneagram:wing:8w9:zh-CN' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced revision workspace failure';
    END IF;
END
SQL
            : <<<'SQL'
CREATE TRIGGER fail_enneagram_revision_workspace_insert
BEFORE INSERT ON personality_public_content_asset_revisions
WHEN NEW.authority_asset_key = 'enneagram:wing:8w9:zh-CN'
BEGIN
    SELECT RAISE(ABORT, 'forced revision workspace failure');
END
SQL);

        try {
            $this->writer()->write(
                $this->releaseReport(),
                (string) $plan['package_sha256'],
                (string) $plan['preflight_fingerprint'],
            );
            $this->fail('Expected the forced revision insert failure.');
        } catch (Throwable $throwable) {
            $this->assertStringContainsString('forced revision workspace failure', $throwable->getMessage());
        }

        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->whereNotNull('working_revision_id')->count());
        $this->assertSame($before, $this->publishedPrimarySnapshots());
    }

    public function test_console_preflight_is_read_only_and_unapproved_write_fails_closed(): void
    {
        $this->seedPublishedEstate();
        $plan = $this->writer()->preflight($this->releaseReport());

        $this->artisan('personality:enneagram-authority-v2-revision-workspace', ['--preflight' => true])
            ->expectsOutputToContain('status=PASS_COLLISION_SAFE_WORKING_REVISION_PREFLIGHT')
            ->expectsOutputToContain('target_count=116')
            ->expectsOutputToContain('writes_committed=0')
            ->assertSuccessful();
        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());

        $this->artisan('personality:enneagram-authority-v2-revision-workspace', [
            '--write' => true,
            '--confirm-package-sha256' => (string) $plan['package_sha256'],
            '--confirm-preflight-fingerprint' => (string) $plan['preflight_fingerprint'],
            '--confirm-writer-deploy-sha' => self::TEST_DEPLOY_SHA,
            '--operator-approved' => 'not-authorized',
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('status=FAIL_CLOSED')
            ->expectsOutputToContain('authorization phrase mismatch')
            ->assertFailed();
        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
    }

    private function writer(): EnneagramPublicAuthorityV205RevisionWorkspaceWriter
    {
        return app(EnneagramPublicAuthorityV205RevisionWorkspaceWriter::class);
    }

    /** @return array<string, mixed> */
    private function scorecard(): array
    {
        $path = base_path('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json');
        $scorecard = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($scorecard);

        return $scorecard;
    }

    /** @return array<string, mixed> */
    private function releaseReport(): array
    {
        $path = base_path('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/release-gate-report.json');
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return $report;
    }

    /** @return array<string, mixed> */
    private function candidate(string $assetKey): array
    {
        $record = collect($this->releaseReport()['asset_records'])->firstWhere('asset_key', $assetKey);
        $this->assertIsArray($record);
        $document = json_decode((string) file_get_contents(base_path((string) $record['source_path'])), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $candidate = collect($document['assets'])->first(
            static fn (array $row): bool => (string) $row['locale'].'|'.(string) $row['identity_key'] === $assetKey,
        );
        $this->assertIsArray($candidate);

        return $candidate;
    }

    private function seedPublishedEstate(): void
    {
        foreach ($this->scorecard()['rows'] as $index => $row) {
            $path = (string) $row['path'];
            $slug = (string) preg_replace('#^/(?:en|zh)/personality/#', '', $path);
            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                'entity_type' => (string) $row['entity_type'],
                'entity_key' => (string) $row['code'],
                'slug' => $slug,
                'locale' => (string) $row['locale'],
                'title' => 'Published Enneagram authority '.($index + 1),
                'summary' => 'Existing backend-authoritative public content.',
                'content_sections_json' => [['key' => 'existing', 'body_md' => 'Existing public content.']],
                'seo_json' => ['title' => 'Published Enneagram authority '.($index + 1)],
                'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                'canonical_json' => ['path' => $path, 'url' => (string) $row['canonical']],
                'hreflang_json' => $row['hreflang'],
                'faq_json' => [],
                'media_json' => [],
                'schema_json' => [],
                'method_boundary_json' => ['is_diagnostic' => false],
                'evidence_notes_json' => [],
                'authority_json' => ['source' => 'test-fixture'],
                'internal_links_json' => [],
                'is_public' => true,
                'index_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                'review_state' => 'published_no_llms',
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'source_package' => 'enneagram-90-cms-v1',
                'source_hash' => hash('sha256', (string) $row['identity_key'].'|'.$row['locale']),
                'published_at' => '2026-07-01 00:00:00',
            ]);
        }

        $this->assertSame(116, PersonalityPublicContentAsset::query()->count());
    }

    /** @return array<string, string> */
    private function publishedPrimarySnapshots(): array
    {
        $snapshots = [];
        PersonalityPublicContentAsset::query()->orderBy('id')->each(function (PersonalityPublicContentAsset $asset) use (&$snapshots): void {
            $attributes = $asset->getAttributes();
            unset($attributes['working_revision_id']);
            ksort($attributes);
            $snapshots[(string) $asset->id] = hash('sha256', json_encode(
                $attributes,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        });

        return $snapshots;
    }

    /** @return array<string, mixed> */
    private function snapshot(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = [];
        foreach ($asset->getFillable() as $field) {
            $snapshot[$field] = $asset->getAttribute($field);
        }

        return $snapshot;
    }
}
