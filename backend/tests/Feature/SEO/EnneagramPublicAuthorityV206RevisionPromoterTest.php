<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV206RevisionPromoter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class EnneagramPublicAuthorityV206RevisionPromoterTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_DEPLOY_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_preflight_resolves_exactly_116_approved_targets_without_writes(): void
    {
        $targets = $this->seedRevisionEstate();
        $before = $this->databaseFingerprint();

        $first = $this->promoter()->preflight($targets);
        $second = $this->promoter()->preflight(array_reverse($targets));

        $this->assertTrue($first['ok']);
        $this->assertSame('PASS_POINTER_SAFE_PROMOTION_PREFLIGHT', $first['status']);
        $this->assertSame(116, $first['target_count']);
        $this->assertSame($first['preflight_fingerprint'], $second['preflight_fingerprint']);
        $this->assertFalse($first['writes_committed']);
        $this->assertFalse($first['production_execution']);
        $this->assertSame($before, $this->databaseFingerprint());
    }

    public function test_promote_and_signed_rollback_are_atomic_pointer_safe_and_restore_all_116_assets(): void
    {
        $targets = $this->seedRevisionEstate();
        $before = $this->publishedSnapshots();
        $plan = $this->promoter()->preflight($targets);

        $this->travelTo('2026-07-16 00:00:00');
        try {
            $promoted = $this->promoter()->promote($targets, (string) $plan['preflight_fingerprint']);

            $this->assertSame('PASS_POINTER_SAFE_PROMOTION', $promoted['status']);
            $this->assertSame(116, $promoted['promoted_count']);
            $this->assertTrue($promoted['writes_committed']);
            $this->assertFalse($promoted['production_execution']);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/', (string) $promoted['rollback_token']);
            $this->assertSame(0, $promoted['public_release_count']);
            $this->assertSame(0, $promoted['indexability_change_count']);
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
            $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
                ->where('source_package', 'enneagram-authority-v2-reviewed')
                ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PUBLISHED)->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()
                ->where('title', 'like', 'Promoted Enneagram authority %')->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()
                ->where('updated_at', '2026-07-16 00:00:00')->count());

            $this->travelTo('2026-07-16 00:01:00');
            $rolledBack = $this->promoter()->rollback((string) $promoted['rollback_token']);

            $this->assertSame('PASS_POINTER_SAFE_ROLLBACK', $rolledBack['status']);
            $this->assertSame(116, $rolledBack['rolled_back_count']);
            $this->assertFalse($rolledBack['production_execution']);
            $this->assertSame($before, $this->publishedSnapshots());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
            $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
                ->where('source_package', 'enneagram-authority-v2-reviewed')
                ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_ROLLED_BACK)->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()
                ->where('updated_at', '2026-07-16 00:01:00')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_stale_pointer_is_rejected_before_any_write(): void
    {
        $targets = $this->seedRevisionEstate();
        $targets[0]['expected_current_published_revision_id'] = 999999;
        $before = $this->databaseFingerprint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pointer is stale');

        try {
            $this->promoter()->preflight($targets);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_wrong_package_sha_is_rejected_before_any_write(): void
    {
        $targets = $this->seedRevisionEstate();
        $targets[0]['expected_package_sha256'] = str_repeat('f', 64);
        $before = $this->databaseFingerprint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package SHA');

        try {
            $this->promoter()->preflight($targets);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_wrong_source_hash_is_rejected_before_any_write(): void
    {
        $targets = $this->seedRevisionEstate();
        $targets[0]['expected_source_hash'] = str_repeat('e', 64);
        $before = $this->databaseFingerprint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source hash');

        try {
            $this->promoter()->preflight($targets);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_public_fingerprint_drift_is_rejected_before_any_promotion_write(): void
    {
        $targets = $this->seedRevisionEstate();
        DB::table('personality_public_content_assets')
            ->where('id', (int) $targets[0]['asset_id'])
            ->update(['title' => 'Concurrent public content drift']);
        $before = $this->databaseFingerprint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('public fingerprint changed');

        try {
            $this->promoter()->preflight($targets);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_pending_manual_review_is_rejected_before_any_write(): void
    {
        $targets = $this->seedRevisionEstate();
        DB::table('personality_public_content_asset_revisions')
            ->where('id', (int) $targets[0]['expected_working_revision_id'])
            ->update(['workflow_state' => EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW]);
        $before = $this->databaseFingerprint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completed manual review');

        try {
            $this->promoter()->preflight($targets);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_partial_batch_update_failure_rolls_back_all_content_pointers_and_states(): void
    {
        $targets = $this->seedRevisionEstate();
        $plan = $this->promoter()->preflight($targets);
        $before = $this->databaseFingerprint();
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_enneagram_revision_promotion
BEFORE UPDATE ON personality_public_content_assets
WHEN NEW.title = 'Promoted Enneagram authority 116'
BEGIN
    SELECT RAISE(ABORT, 'forced partial promotion failure');
END
SQL);

        try {
            $this->promoter()->promote($targets, (string) $plan['preflight_fingerprint']);
            $this->fail('Expected the forced partial promotion failure.');
        } catch (Throwable $throwable) {
            $this->assertStringContainsString('forced partial promotion failure', $throwable->getMessage());
        }

        $this->assertSame($before, $this->databaseFingerprint());
        $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
            ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED)->count());
    }

    public function test_tampered_rollback_token_fails_closed_without_writes(): void
    {
        $targets = $this->seedRevisionEstate();
        $plan = $this->promoter()->preflight($targets);
        $promoted = $this->promoter()->promote($targets, (string) $plan['preflight_fingerprint']);
        $before = $this->databaseFingerprint();
        $token = (string) $promoted['rollback_token'];
        $tampered = ($token[0] === 'a' ? 'b' : 'a').substr($token, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature is invalid');

        try {
            $this->promoter()->rollback($tampered);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_rollback_rejects_previous_published_revision_lineage_drift_without_writes(): void
    {
        $targets = $this->seedRevisionEstate();
        $plan = $this->promoter()->preflight($targets);
        $promoted = $this->promoter()->promote($targets, (string) $plan['preflight_fingerprint']);
        DB::table('personality_public_content_asset_revisions')
            ->where('id', (int) $targets[0]['expected_current_published_revision_id'])
            ->update(['source_hash' => str_repeat('f', 64)]);
        $before = $this->databaseFingerprint();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('previous published revision identity changed');

        try {
            $this->promoter()->rollback((string) $promoted['rollback_token']);
        } finally {
            $this->assertSame($before, $this->databaseFingerprint());
        }
    }

    public function test_console_preflight_is_read_only_and_unapproved_promotion_fails_closed(): void
    {
        $targets = $this->seedRevisionEstate();
        $plan = $this->promoter()->preflight($targets);
        $path = storage_path('framework/testing/enneagram-authority-v2-promotion-plan.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode(['targets' => $targets], JSON_THROW_ON_ERROR));
        $before = $this->databaseFingerprint();

        $this->artisan('personality:enneagram-authority-v2-revision-promoter', [
            '--plan' => $path,
            '--preflight' => true,
        ])
            ->expectsOutputToContain('status=PASS_POINTER_SAFE_PROMOTION_PREFLIGHT')
            ->expectsOutputToContain('target_count=116')
            ->expectsOutputToContain('writes_committed=0')
            ->assertSuccessful();

        $this->artisan('personality:enneagram-authority-v2-revision-promoter', [
            '--plan' => $path,
            '--promote' => true,
            '--confirm-preflight-fingerprint' => (string) $plan['preflight_fingerprint'],
            '--confirm-writer-deploy-sha' => self::TEST_DEPLOY_SHA,
            '--operator-approved' => 'not-authorized',
            '--allow-testing' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"status": "FAIL_CLOSED"')
            ->assertFailed();

        $this->assertSame($before, $this->databaseFingerprint());
        @unlink($path);
    }

    public function test_console_promotion_requires_json_before_any_write(): void
    {
        $targets = $this->seedRevisionEstate();
        $plan = $this->promoter()->preflight($targets);
        $path = storage_path('framework/testing/enneagram-authority-v2-promotion-json-guard-plan.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode(['targets' => $targets], JSON_THROW_ON_ERROR));
        $before = $this->databaseFingerprint();

        $this->artisan('personality:enneagram-authority-v2-revision-promoter', [
            '--plan' => $path,
            '--promote' => true,
            '--confirm-preflight-fingerprint' => (string) $plan['preflight_fingerprint'],
            '--confirm-writer-deploy-sha' => self::TEST_DEPLOY_SHA,
            '--operator-approved' => $this->promoter()->approvalPhrase(
                self::TEST_DEPLOY_SHA,
                (string) $plan['preflight_fingerprint'],
            ),
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('status=FAIL_CLOSED')
            ->expectsOutputToContain('--promote requires --json')
            ->assertFailed();

        $this->assertSame($before, $this->databaseFingerprint());
        @unlink($path);
    }

    private function promoter(): EnneagramPublicAuthorityV206RevisionPromoter
    {
        return app(EnneagramPublicAuthorityV206RevisionPromoter::class);
    }

    /** @return list<array<string, mixed>> */
    private function seedRevisionEstate(): array
    {
        $targets = [];
        foreach ($this->scorecard()['rows'] as $index => $row) {
            $number = $index + 1;
            $path = (string) $row['path'];
            $slug = (string) preg_replace('#^/(?:en|zh)/personality/#', '', $path);
            $asset = PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                'entity_type' => (string) $row['entity_type'],
                'entity_key' => (string) $row['code'],
                'slug' => $slug,
                'locale' => (string) $row['locale'],
                'title' => 'Published Enneagram authority '.$number,
                'summary' => 'Existing backend-authoritative public content.',
                'content_sections_json' => [['key' => 'existing', 'body_md' => 'Existing public content.']],
                'seo_json' => ['title' => 'Published Enneagram authority '.$number],
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
                'source_hash' => hash('sha256', 'published|'.(string) $row['identity_key']),
                'published_at' => '2026-07-01 00:00:00',
            ]);
            $asset->refresh();
            $assetKey = sprintf('enneagram:%s:%s:%s', $asset->entity_type, $asset->entity_key, $asset->locale);
            $published = PersonalityPublicContentAssetRevision::query()->create([
                'asset_id' => (int) $asset->id,
                'revision_no' => 1,
                'authority_asset_key' => $assetKey,
                'source_package' => 'enneagram-90-cms-v1',
                'source_hash' => (string) $asset->source_hash,
                'authority_package_sha256' => hash('sha256', 'published-package|'.$assetKey),
                'workflow_state' => EnneagramPublicAuthorityV206RevisionPromoter::STATE_PUBLISHED,
                'snapshot_json' => $this->revisionSnapshot($asset),
                'public_runtime_fingerprint_before' => str_repeat('0', 64),
            ]);
            DB::table('personality_public_content_assets')->where('id', $asset->id)->update([
                'published_revision_id' => (int) $published->id,
            ]);
            $asset->refresh();
            $fingerprint = $this->publicFingerprint($asset);
            $promotedSourceHash = hash('sha256', 'promoted|'.$assetKey);
            $promotedPackageSha = hash('sha256', 'promoted-package|'.$assetKey);
            $snapshot = $this->revisionSnapshot($asset);
            $snapshot['title'] = 'Promoted Enneagram authority '.$number;
            $snapshot['summary'] = 'Reviewed Enneagram Authority V2 content.';
            $snapshot['contract_version'] = PersonalityPublicContentAsset::CONTRACT_VERSION_V2;
            $snapshot['source_package'] = 'enneagram-authority-v2-reviewed';
            $snapshot['source_hash'] = $promotedSourceHash;
            $snapshot['review_state'] = 'human_review_approved';
            $working = PersonalityPublicContentAssetRevision::query()->create([
                'asset_id' => (int) $asset->id,
                'revision_no' => 2,
                'authority_asset_key' => $assetKey,
                'source_package' => 'enneagram-authority-v2-reviewed',
                'source_hash' => $promotedSourceHash,
                'authority_package_sha256' => $promotedPackageSha,
                'workflow_state' => EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED,
                'snapshot_json' => $snapshot,
                'public_runtime_fingerprint_before' => $fingerprint,
            ]);
            DB::table('personality_public_content_assets')->where('id', $asset->id)->update([
                'working_revision_id' => (int) $working->id,
            ]);
            $targets[] = [
                'asset_id' => (int) $asset->id,
                'asset_key' => $assetKey,
                'expected_current_published_revision_id' => (int) $published->id,
                'expected_working_revision_id' => (int) $working->id,
                'expected_package_sha256' => $promotedPackageSha,
                'expected_source_hash' => $promotedSourceHash,
                'expected_public_fingerprint_before' => $fingerprint,
            ];
        }

        $this->assertCount(116, $targets);

        return $targets;
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
    private function revisionSnapshot(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = [];
        foreach ($asset->getFillable() as $field) {
            $snapshot[$field] = $asset->getAttribute($field);
        }

        return $snapshot;
    }

    private function publicFingerprint(PersonalityPublicContentAsset $asset): string
    {
        $attributes = $asset->getAttributes();
        unset($attributes['working_revision_id']);

        return $this->fingerprint($attributes);
    }

    /** @return array<string, string> */
    private function publishedSnapshots(): array
    {
        $snapshots = [];
        PersonalityPublicContentAsset::query()->orderBy('id')->each(function (PersonalityPublicContentAsset $asset) use (&$snapshots): void {
            $attributes = $asset->getAttributes();
            unset($attributes['working_revision_id'], $attributes['updated_at']);
            $snapshots[(string) $asset->id] = $this->fingerprint($attributes);
        });

        return $snapshots;
    }

    private function databaseFingerprint(): string
    {
        return $this->fingerprint([
            'assets' => DB::table('personality_public_content_assets')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'revisions' => DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ]);
    }

    /** @param array<string, mixed>|list<mixed> $value */
    private function fingerprint(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursive($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
