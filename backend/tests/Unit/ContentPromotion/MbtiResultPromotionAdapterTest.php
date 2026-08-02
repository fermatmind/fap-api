<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\Content\ContentPackV2Resolver;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\Mbti\MbtiResultPersonalizationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\AssertsExactPackagePromotionConformance;

require_once __DIR__.'/Concerns/AssertsExactPackagePromotionConformance.php';

final class MbtiResultPromotionAdapterTest extends TestCase
{
    use AssertsExactPackagePromotionConformance;
    use RefreshDatabase;

    /** @var list<string> */
    private array $packageDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(
            'content_promotion.mbti_result_external_w9_root',
            base_path('content_assets/en-content-parity/W9/mbti-results/9325013b-renderer-643b7a80'),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->packageDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_exact_database_authority_is_idempotent_and_rollback_restores_only_the_previous_activation(): void
    {
        $directory = $this->copyPackage();
        $context = $this->context($directory, $this->packageSha($directory));
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results');

        $this->assertExactPhaseResult($adapter->preflight($context), $context, 'preflight');
        self::assertSame(0, DB::table('content_pack_releases')->count());
        self::assertSame(0, DB::table('content_pack_activations')->count());
        $draft = $adapter->draftImport($context);
        $this->assertExactPhaseResult($draft, $context, 'draft-import');
        self::assertSame(46, $draft['written_count']);
        $draftReplay = $adapter->draftImport($context);
        self::assertSame(0, $draftReplay['written_count']);
        self::assertSame(1, DB::table('content_pack_releases')->count());
        self::assertSame('mbti_result_promotion.v2', DB::table('content_release_manifests')->value('schema_version'));
        $draftAuthority = json_decode((string) DB::table('content_release_manifests')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(21, $draftAuthority['counts']['candidate_assets']);
        self::assertSame(1, $draftAuthority['counts']['fixture_rows']);
        self::assertSame(0, DB::table('content_pack_activations')->count());

        $previousReleaseId = '11111111-1111-5111-8111-111111111111';
        DB::table('content_pack_releases')->insert([
            'id' => $previousReleaseId,
            'action' => 'preexisting_mbti_result_authority',
            'region' => 'GLOBAL',
            'locale' => 'en',
            'dir_alias' => 'MBTI-GLOBAL-en-v0.3',
            'to_pack_id' => 'MBTI.global.en.default',
            'status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'MBTI.global.en.default',
            'pack_version' => 'v0.3',
            'release_id' => $previousReleaseId,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publish = $adapter->publish($context);
        $this->assertExactPhaseResult($publish, $context, 'publish');
        self::assertSame(46, $publish['written_count']);
        self::assertNotSame($previousReleaseId, DB::table('content_pack_activations')->value('release_id'));
        self::assertSame(0, $adapter->publish($context)['written_count']);
        self::assertSame(
            $context->packageSha256,
            app(ContentPackV2Resolver::class)->resolveActiveMbtiResultAuthority()['source']['package_sha256'] ?? null,
        );
        $releaseId = (string) DB::table('content_pack_activations')->value('release_id');
        $canonicalJson = (string) DB::table('content_pack_releases')->where('id', $releaseId)->value('manifest_json');
        $tampered = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        $tampered['counts']['rows'] = 46;
        $tampered['authority']['locale'] = 'en';
        $tampered['rows'][0]['content']['body_md'] = 'tampered';
        $tamperedJson = json_encode($tampered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('content_pack_releases')->where('id', $releaseId)->update(['manifest_json' => $tamperedJson]);
        DB::table('content_release_manifests')->where('content_pack_release_id', $releaseId)->update(['payload_json' => $tamperedJson]);
        self::assertNull(app(ContentPackV2Resolver::class)->resolveActiveMbtiResultAuthority());
        DB::table('content_pack_releases')->where('id', $releaseId)->update(['manifest_json' => $canonicalJson]);
        DB::table('content_release_manifests')->where('content_pack_release_id', $releaseId)->update(['payload_json' => $canonicalJson]);
        $runtimeProjection = app(MbtiResultPersonalizationService::class)->applyToProjection([
            'sections' => [['key' => 'traits.why_this_type', 'title' => 'Legacy title', 'body_md' => 'Legacy body', 'payload' => []]],
        ], ['locale' => 'en', 'type_code' => 'INTJ', 'sections' => []]);
        self::assertSame('content_promotion_w1_mbti_results_v2', $runtimeProjection['sections'][0]['_meta']['authority_source']);
        self::assertStringContainsString('INTJ', $runtimeProjection['sections'][0]['body_md']);
        $this->assertExactPhaseResult($adapter->liveQa($context), $context, 'live-qa');

        $adapter->rollback($context, (string) $publish['rollback_reference']);
        self::assertSame($previousReleaseId, DB::table('content_pack_activations')->value('release_id'));
    }

    public function test_package_sha_drift_is_rejected_before_external_w9_can_be_used(): void
    {
        $directory = $this->copyPackage();
        File::append($directory.'/README.md', "\nDynamic package-chain regression fixture.\n");
        $sha = $this->recomputePackageSha($directory);
        $context = $this->context($directory, $sha);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results');

        $this->expectExceptionObject(new DomainException('mbti_result_package_sha256_mismatch'));
        $adapter->preflight($context);
    }

    public function test_replaying_the_same_package_from_a_new_executor_commit_is_idempotent(): void
    {
        $directory = $this->copyPackage();
        $sha = $this->packageSha($directory);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results');
        $first = $this->context($directory, $sha);
        $adapter->draftImport($first);
        $replayed = $this->context($directory, $sha, str_repeat('f', 40));

        self::assertSame(0, $adapter->draftImport($replayed)['written_count']);
    }

    public function test_external_w9_evidence_is_required_before_any_draft_authority_is_created(): void
    {
        $directory = $this->copyPackage();
        config()->set('content_promotion.mbti_result_external_w9_root', sys_get_temp_dir().'/missing-mbti-result-w9');
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results');

        $this->expectExceptionObject(new DomainException('mbti_result_external_w9_evidence_incomplete'));
        $adapter->preflight($this->context($directory, $this->packageSha($directory)));
    }

    public function test_external_w9_report_and_envelope_drift_fail_closed_without_database_writes(): void
    {
        $directory = $this->copyPackage();
        $evidenceRoot = sys_get_temp_dir().'/mbti-result-w9-'.bin2hex(random_bytes(8));
        File::copyDirectory(base_path('content_assets/en-content-parity/W9/mbti-results/9325013b-renderer-643b7a80'), $evidenceRoot);
        config()->set('content_promotion.mbti_result_external_w9_root', $evidenceRoot);
        File::append($evidenceRoot.'/external_evidence_envelope.json', "\n");

        try {
            app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results')->preflight($this->context($directory, $this->packageSha($directory)));
            self::fail('External W9 envelope drift must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('mbti_result_external_w9_evidence_incomplete', $exception->getMessage());
        }
        self::assertSame(0, DB::table('content_pack_releases')->count());
        self::assertSame(0, DB::table('content_pack_activations')->count());
        File::deleteDirectory($evidenceRoot);
    }

    public function test_external_w9_symlink_root_is_rejected(): void
    {
        $directory = $this->copyPackage();
        $link = sys_get_temp_dir().'/mbti-result-w9-link-'.bin2hex(random_bytes(8));
        symlink(base_path('content_assets/en-content-parity/W9/mbti-results/9325013b-renderer-643b7a80'), $link);
        config()->set('content_promotion.mbti_result_external_w9_root', $link);

        try {
            app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results')->preflight($this->context($directory, $this->packageSha($directory)));
            self::fail('A symlinked W9 root must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('mbti_result_external_w9_evidence_incomplete', $exception->getMessage());
        } finally {
            @unlink($link);
        }
    }

    public function test_locale_private_payload_cjk_and_release_collisions_fail_closed(): void
    {
        $directory = $this->copyPackage();
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-results');

        $assets = $this->jsonFile($directory.'/assets.json');
        $assets['assets'][0]['content']['title'] = '中文泄漏';
        File::put($directory.'/assets.json', json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $sha = $this->recomputePackageSha($directory);
        try {
            $adapter->preflight($this->context($directory, $sha));
            self::fail('CJK reader content must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('mbti_result_package_sha256_mismatch', $exception->getMessage());
        }

        $cleanDirectory = $this->copyPackage();
        $context = $this->context($cleanDirectory, $this->packageSha($cleanDirectory));
        $adapter->draftImport($context);
        DB::table('content_pack_releases')->update(['compiled_hash' => str_repeat('0', 64)]);
        try {
            $adapter->draftImport($context);
            self::fail('A deterministic release identity collision must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('mbti_result_release_identity_collision', $exception->getMessage());
        }
    }

    private function copyPackage(): string
    {
        $directory = sys_get_temp_dir().'/mbti-result-promotion-'.bin2hex(random_bytes(8));
        File::copyDirectory(base_path('content_assets/en-content-parity/W1-mbti/result-content'), $directory);
        $this->packageDirectories[] = $directory;

        return $directory;
    }

    private function packageSha(string $directory): string
    {
        return (string) ($this->jsonFile($directory.'/package_manifest.json')['package_sha256'] ?? '');
    }

    private function recomputePackageSha(string $directory): string
    {
        $manifest = $this->jsonFile($directory.'/package_manifest.json');
        $chain = '';
        foreach ($manifest['files'] as $position => $entry) {
            $path = (string) $entry['path'];
            $sha = hash_file('sha256', $directory.'/'.$path);
            $manifest['files'][$position]['sha256'] = $sha;
            $chain .= $path."\0".$sha."\n";
        }
        $manifest['package_sha256'] = hash('sha256', $chain);
        File::put($directory.'/package_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        return $manifest['package_sha256'];
    }

    /** @return array<string,mixed> */
    private function jsonFile(string $path): array
    {
        return json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(string $directory, string $packageSha, string $sourceCommit = ''): PromotionContext
    {
        return new PromotionContext(
            packageDirectory: $directory,
            packageSha256: $packageSha,
            lane: 'W1',
            subscope: 'mbti-results',
            sourceCommit: $sourceCommit !== '' ? $sourceCommit : str_repeat('a', 40),
            executorReleaseSha256: str_repeat('b', 64),
            releasePolicySha256: str_repeat('c', 64),
            workflowRunId: '123',
            workflowRunAttempt: 1,
            workflowSignature: str_repeat('d', 64),
            expectedRowCount: 46,
            idempotencyKey: str_repeat('e', 64),
        );
    }
}
