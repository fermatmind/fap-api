<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\BigFive\AuthorityV2\PromotionReadiness\BigFiveZh6PromotionReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BigFiveAuthorityV249Test extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_DIR = 'generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49';

    public function test_builder_is_deterministic_and_checked_in_hold_package_validates(): void
    {
        $packagePath = $this->repositoryPath(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        $before = hash_file('sha256', $packagePath);

        $this->runNode('build-package.mjs');

        $this->assertSame($before, hash_file('sha256', $packagePath));
        $this->runNode('validate-package.mjs');
    }

    public function test_exact_owner_editorial_source_runtime_and_rollback_authority_is_hash_bound(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        $owner = $this->readJson(self::PACKAGE_DIR.'/pr48-owner-authority.json');
        $review = $package['editorial_authority']['review_record'];

        $this->assertSame('HOLD_FAIL_CLOSED_MEDIA_AUTHORITY', $package['status']);
        $this->assertSame(6, $package['counts']['assets']);
        $this->assertSame(6, $package['counts']['reviewed_assets']);
        $this->assertSame(6, $package['counts']['source_permission_assets']);
        $this->assertSame(18, $package['counts']['visible_sources']);
        $this->assertSame(6, $package['counts']['runtime_baselines']);
        $this->assertSame('solo_operator', $review['mode']);
        $this->assertTrue($review['explicit_self_review']);
        $this->assertSame(1, $review['author_admin_user_id']);
        $this->assertSame(1, $review['reviewer_admin_user_id']);
        $this->assertFalse($review['global_role_separation_relaxed']);
        $this->assertSame('2026-07-16T09:24:18Z', $review['reviewed_at']);
        $this->assertSame(4990228962, $review['external_human_authority']['comment_database_id']);
        $this->assertSame('OWNER', $review['external_human_authority']['author_association']);
        $this->assertSame(hash('sha256', $owner['confirmation_phrase']), $review['external_human_authority']['confirmation_phrase_sha256']);
        $this->assertSame(6, $owner['approval_scope']['snapshot_assets']);
        $this->assertFalse($owner['approval_scope']['media_authority']);
        $this->assertFalse($owner['approval_scope']['working_revision_write']);

        foreach ($package['source_permissions']['rows'] as $row) {
            $this->assertTrue($row['approved']);
            $this->assertCount(3, $row['source_ids']);
        }
        foreach ($package['rollback_baseline']['rows'] as $row) {
            $this->assertTrue($row['exact_target_bound']);
            $this->assertTrue($row['abort_on_missing_or_drifted_target']);
        }
    }

    public function test_production_observation_keeps_zero_eligible_hub_media_and_every_mutation_zero(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        $observation = $this->readJson(self::PACKAGE_DIR.'/production-observation.json');

        $this->assertSame(49, $observation['media_inventory']['all_media_assets']);
        $this->assertSame(23, $observation['media_inventory']['published_public_synced_cdn_verified']);
        $this->assertSame(22, $observation['media_inventory']['with_verified_hero_and_og']);
        $this->assertSame(0, $observation['media_inventory']['authority_complete_hero_og_count']);
        $this->assertSame([], $observation['media_inventory']['authority_complete_hero_og']);
        $this->assertCount(4, $observation['media_inventory']['big_five_named_hero_og']);
        $this->assertSame(0, $package['counts']['eligible_hub_media_candidates']);
        $this->assertSame(0, $package['counts']['selected_hub_media_assets']);
        $this->assertFalse($package['ready_for_working_revision']);
        $this->assertFalse($package['ready_for_promotion']);
        $this->assertFalse($package['permissions']['media']['approved']);
        $this->assertContains('unique_hub_hero_og_media_missing', $package['blockers']);

        foreach ($observation['media_inventory']['big_five_named_hero_og'] as $candidate) {
            $this->assertSame(
                ['locale', 'rights', 'license', 'provenance', 'operator_approval_ref', 'content_identity'],
                $candidate['missing_authority_fields'],
            );
            $this->assertNotContains('hub', $candidate['declared_usage']);
        }
        foreach ($package['actions'] as $name => $value) {
            if ($name === 'production_database_read_only_observation') {
                $this->assertTrue($value);
            } else {
                $this->assertSame(0, $value, $name.' must remain zero');
            }
        }
    }

    public function test_media_gate_selects_one_complete_candidate_and_holds_on_multiple(): void
    {
        $observation = $this->readJson(self::PACKAGE_DIR.'/production-observation.json');
        $candidate = $this->completeMediaCandidate(9001, 'media.big-five.zh-hub.v1');

        $unique = $this->buildTemporaryPackage($observation, [$candidate]);
        $this->assertSame('PASS_PROMOTION_READINESS_ZERO_WRITE', $unique['package']['status']);
        $this->assertTrue($unique['package']['ready_for_working_revision']);
        $this->assertSame(1, $unique['package']['counts']['selected_hub_media_assets']);
        $this->assertTrue($unique['package']['permissions']['media']['approved']);
        $this->assertSame([], $unique['package']['blockers']);
        $uniqueResult = app(BigFiveZh6PromotionReadiness::class)->packageOnly($unique['package_path']);
        $this->assertTrue($uniqueResult['contract_valid']);
        $this->assertTrue($uniqueResult['ready']);
        $this->assertSame('PASS_PROMOTION_READINESS_ZERO_WRITE', $uniqueResult['status']);
        $this->cleanupTemporaryPackage($unique['directory']);

        $multiple = $this->buildTemporaryPackage($observation, [
            $candidate,
            $this->completeMediaCandidate(9002, 'media.big-five.zh-hub.v2'),
        ]);
        $this->assertSame('HOLD_FAIL_CLOSED_MEDIA_AUTHORITY', $multiple['package']['status']);
        $this->assertFalse($multiple['package']['ready_for_working_revision']);
        $this->assertSame(0, $multiple['package']['counts']['selected_hub_media_assets']);
        $this->assertContains('multiple_hub_hero_og_media_candidates', $multiple['package']['blockers']);
        $this->cleanupTemporaryPackage($multiple['directory']);
    }

    public function test_builder_rejects_a_self_asserted_or_tampered_owner_phrase(): void
    {
        $owner = $this->readJson(self::PACKAGE_DIR.'/pr48-owner-authority.json');
        $owner['confirmation_phrase'] = 'approved';
        $temporaryDirectory = $this->temporaryDirectory();
        $ownerPath = $temporaryDirectory.'/owner.json';
        $this->writeJson($ownerPath, $owner);

        try {
            $process = $this->nodeProcess('build-package.mjs', [
                'PR49_OWNER_AUTHORITY_PATH' => $ownerPath,
                'PR49_OUTPUT_PATH' => $temporaryDirectory.'/package.json',
                'PR49_OUTPUT_HASH_PATH' => $temporaryDirectory.'/package.sha256',
            ]);
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'OWNER authority phrase does not match the locked three hashes',
                $process->getErrorOutput().$process->getOutput(),
            );
        } finally {
            $this->cleanupTemporaryPackage($temporaryDirectory);
        }
    }

    public function test_readiness_command_validates_the_hold_package_without_database_writes(): void
    {
        $assetCount = MediaAsset::query()->withoutGlobalScopes()->count();
        $variantCount = MediaVariant::query()->count();
        $packagePath = '../'.self::PACKAGE_DIR.'/promotion-readiness-package.json';
        $result = app(BigFiveZh6PromotionReadiness::class)->packageOnly($packagePath);

        $this->assertTrue($result['contract_valid']);
        $this->assertFalse($result['ready']);
        $this->assertSame('HOLD_FAIL_CLOSED_MEDIA_AUTHORITY', $result['status']);
        $this->assertSame(0, $result['actions']['database_reads']);
        $this->assertSame(0, $result['actions']['database_writes']);

        $this->artisan('personality:big-five-authority-v2-zh6-promotion-readiness', [
            '--package' => $packagePath,
            '--package-only' => true,
        ])
            ->expectsOutputToContain('contract_valid=1')
            ->expectsOutputToContain('ready=0')
            ->expectsOutputToContain('status=HOLD_FAIL_CLOSED_MEDIA_AUTHORITY')
            ->assertSuccessful();

        $this->assertSame($assetCount, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame($variantCount, MediaVariant::query()->count());
    }

    public function test_readiness_service_rejects_a_self_consistent_but_unapproved_package_rewrite(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        $package['status'] = 'PASS_PROMOTION_READINESS_ZERO_WRITE';
        unset($package['package_payload_sha256']);
        $package['package_payload_sha256'] = $this->canonicalSha256($package);
        $directory = $this->temporaryDirectory();
        $path = $directory.'/rewritten-package.json';
        $this->writeJson($path, $package);
        $this->assertNotFalse(file_put_contents(
            $directory.'/rewritten-package.sha256',
            hash_file('sha256', $path)."\n",
        ));

        try {
            app(BigFiveZh6PromotionReadiness::class)->packageOnly($path);
            $this->fail('Expected an unapproved package rewrite to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('media readiness disposition is inconsistent', $exception->getMessage());
        } finally {
            $this->cleanupTemporaryPackage($directory);
        }
    }

    public function test_readiness_service_rejects_rehashed_source_rows_not_bound_to_the_snapshot(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        $package['source_permissions']['rows'][0]['source_ids'][0] = 'source:unrelated';
        $sourceSha256 = $this->canonicalSha256($package['source_permissions']['rows']);
        $package['source_permissions']['source_permission_sha256'] = $sourceSha256;
        $package['permissions']['sources']['authority_reference'] = 'source_permissions:'.$sourceSha256;
        $permissionMaterial = $package['permissions'];
        unset($permissionMaterial['permissions_sha256']);
        $permissionsSha256 = $this->canonicalSha256($permissionMaterial);
        $package['permissions']['permissions_sha256'] = $permissionsSha256;
        $package['release_lock_material']['source_permission_sha256'] = $sourceSha256;
        $package['release_lock_material']['permissions_sha256'] = $permissionsSha256;
        $package['release_snapshot_sha256'] = $this->canonicalSha256($package['release_lock_material']);

        $directory = $this->temporaryDirectory();
        $observationPath = $directory.'/production-observation.json';
        $this->assertTrue(copy(
            $this->repositoryPath(self::PACKAGE_DIR.'/production-observation.json'),
            $observationPath,
        ));
        $package['inputs']['production_observation_path'] = $observationPath;
        unset($package['package_payload_sha256']);
        $package['package_payload_sha256'] = $this->canonicalSha256($package);
        $path = $directory.'/rewritten-source-package.json';
        $this->writeJson($path, $package);
        $this->assertNotFalse(file_put_contents(
            $directory.'/rewritten-source-package.sha256',
            hash_file('sha256', $path)."\n",
        ));

        try {
            app(BigFiveZh6PromotionReadiness::class)->packageOnly($path);
            $this->fail('Expected rehashed source rows outside the locked snapshot to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('source rows do not match the locked snapshot', $exception->getMessage());
        } finally {
            $this->cleanupTemporaryPackage($directory);
        }
    }

    public function test_readiness_service_rejects_rehashed_rollback_rows_not_bound_to_runtime(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        $package['rollback_baseline']['rows'][0]['primary_id'] = 999999;
        $rollbackSha256 = $this->canonicalSha256($package['rollback_baseline']['rows']);
        $package['rollback_baseline']['rollback_baseline_sha256'] = $rollbackSha256;
        $package['release_lock_material']['rollback_baseline_sha256'] = $rollbackSha256;
        $package['release_snapshot_sha256'] = $this->canonicalSha256($package['release_lock_material']);
        $temporary = $this->writeTemporaryRehashedPackage($package, 'rewritten-rollback-package');

        try {
            app(BigFiveZh6PromotionReadiness::class)->packageOnly($temporary['path']);
            $this->fail('Expected rehashed rollback rows outside the runtime baseline to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback rows do not match the runtime baseline', $exception->getMessage());
        } finally {
            $this->cleanupTemporaryPackage($temporary['directory']);
        }
    }

    public function test_readiness_service_requires_the_complete_zero_mutation_action_evidence(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/promotion-readiness-package.json');
        unset($package['actions']);
        $temporary = $this->writeTemporaryRehashedPackage($package, 'missing-actions-package');

        try {
            app(BigFiveZh6PromotionReadiness::class)->packageOnly($temporary['path']);
            $this->fail('Expected missing zero-mutation action evidence to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('action evidence must include the exact read-only observation', $exception->getMessage());
        } finally {
            $this->cleanupTemporaryPackage($temporary['directory']);
        }
    }

    public function test_database_preflight_fails_closed_on_missing_runtime_without_mutation(): void
    {
        $result = app(BigFiveZh6PromotionReadiness::class)->databasePreflight(
            '../'.self::PACKAGE_DIR.'/promotion-readiness-package.json',
        );

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['ready']);
        $this->assertSame('FAIL_CLOSED_RUNTIME_OR_AUTHORITY_DRIFT', $result['status']);
        $this->assertContains('admin_user_1_authority_mismatch', $result['drift_codes']);
        $this->assertContains('runtime_baseline_drift', $result['drift_codes']);
        $this->assertSame(9, $result['actions']['database_reads']);
        $this->assertSame(0, array_sum(collect($result['actions'])->except('database_reads')->all()));
    }

    public function test_database_media_inventory_requires_one_complete_hub_identity_and_rejects_article_media(): void
    {
        $this->createMediaAsset('article.big-five.cover', [], true);
        $inspection = app(BigFiveZh6PromotionReadiness::class)->inspectDatabase();
        $this->assertSame(0, $inspection['media_inventory']['eligible_candidate_count']);
        $this->assertSame('blocked_zero_eligible_candidates', $inspection['media_inventory']['selection_status']);

        $first = $this->createMediaAsset('big5.model-hub.zh-cn.hero-og.v1', [
            'locale' => 'zh-CN',
            'content_identity' => BigFiveZh6PromotionReadiness::HUB_MEDIA_CONTENT_IDENTITY,
            'rights' => 'FermatMind-owned original artwork',
            'license' => 'internal-original-v1',
            'provenance' => 'Media Library original upload BIG5-ZH6-HUB-001',
            'operator_approval_ref' => 'operator-approval:BIG5-ZH6-HUB-001',
        ], true);
        $inspection = app(BigFiveZh6PromotionReadiness::class)->inspectDatabase();
        $this->assertSame(1, $inspection['media_inventory']['eligible_candidate_count']);
        $this->assertSame('unique_eligible_candidate', $inspection['media_inventory']['selection_status']);
        $candidate = $inspection['media_inventory']['eligible_candidates'][0];
        $this->assertSame($first->getKey(), $candidate['media_asset_id']);
        $this->assertSame(['hero', 'og'], $candidate['variant_keys']);
        $candidateSha256 = $candidate['candidate_sha256'];
        unset($candidate['candidate_sha256']);
        $this->assertSame($this->canonicalSha256($candidate), $candidateSha256);

        $this->createMediaAsset('big5.model-hub.zh-cn.hero-og.v2', [
            'locale' => 'zh-CN',
            'content_identity' => BigFiveZh6PromotionReadiness::HUB_MEDIA_CONTENT_IDENTITY,
            'rights' => 'FermatMind-owned original artwork',
            'license' => 'internal-original-v1',
            'provenance' => 'Media Library original upload BIG5-ZH6-HUB-002',
            'operator_approval_ref' => 'operator-approval:BIG5-ZH6-HUB-002',
        ], true);
        $inspection = app(BigFiveZh6PromotionReadiness::class)->inspectDatabase();
        $this->assertSame(2, $inspection['media_inventory']['eligible_candidate_count']);
        $this->assertSame('blocked_multiple_eligible_candidates', $inspection['media_inventory']['selection_status']);
    }

    /**
     * @param  array<string, mixed>  $observation
     * @param  list<array<string, mixed>>  $candidates
     * @return array{directory:string,package_path:string,package:array<string,mixed>}
     */
    private function buildTemporaryPackage(array $observation, array $candidates): array
    {
        $directory = $this->temporaryDirectory();
        $observation['media_inventory']['authority_complete_hero_og_count'] = count($candidates);
        $observation['media_inventory']['authority_complete_hero_og'] = $candidates;
        $observationPath = $directory.'/production-observation.json';
        $packagePath = $directory.'/package.json';
        $hashPath = $directory.'/package.sha256';
        $this->writeJson($observationPath, $observation);

        $environment = [
            'PR49_OBSERVATION_PATH' => $observationPath,
            'PR49_OUTPUT_PATH' => $packagePath,
            'PR49_OUTPUT_HASH_PATH' => $hashPath,
        ];
        $builder = $this->nodeProcess('build-package.mjs', $environment);
        $this->assertTrue($builder->isSuccessful(), $builder->getErrorOutput().$builder->getOutput());

        $validator = $this->nodeProcess('validate-package.mjs', [
            'PR49_OBSERVATION_PATH' => $observationPath,
            'PR49_PACKAGE_PATH' => $packagePath,
            'PR49_PACKAGE_HASH_PATH' => $hashPath,
        ]);
        $this->assertTrue($validator->isSuccessful(), $validator->getErrorOutput().$validator->getOutput());

        return [
            'directory' => $directory,
            'package_path' => $packagePath,
            'package' => $this->readAbsoluteJson($packagePath),
        ];
    }

    /** @return array<string, mixed> */
    private function completeMediaCandidate(int $id, string $key): array
    {
        return [
            'media_asset_id' => $id,
            'media_asset_key' => $key,
            'locale' => 'zh-CN',
            'content_identity' => 'big5:model_hub:zh-CN:hero-og',
            'status' => 'published_public_synced_cdn_verified',
            'variant_keys' => ['hero', 'og'],
            'public_urls' => [
                'hero' => 'https://assets.fermatmind.com/media/'.$id.'/hero.webp',
                'og' => 'https://assets.fermatmind.com/media/'.$id.'/og.webp',
            ],
            'alt' => '大五人格五维连续谱与复盘路径示意图',
            'rights' => 'operator_owned',
            'license' => 'FermatMind editorial use',
            'provenance' => 'Media Library original upload',
            'operator_approval_ref' => 'operator-approval:test-'.$id,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function createMediaAsset(string $key, array $payload, bool $withHeroAndOg): MediaAsset
    {
        $asset = MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => $key,
            'disk' => 'public',
            'path' => 'media/big5/'.$key.'/source.webp',
            'url' => 'https://assets.fermatmind.com/storage/media/big5/'.$key.'/source.webp',
            'mime_type' => 'image/webp',
            'alt' => '大五人格五维连续谱与复盘路径示意图',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_VERIFIED,
            'payload_json' => $payload,
        ]);
        if ($withHeroAndOg) {
            foreach (['hero', 'og'] as $variantKey) {
                $asset->variants()->create([
                    'variant_key' => $variantKey,
                    'path' => 'media/big5/'.$key.'/'.$variantKey.'.webp',
                    'url' => 'https://assets.fermatmind.com/storage/media/big5/'.$key.'/'.$variantKey.'.webp',
                    'mime_type' => 'image/webp',
                    'sync_status' => MediaAsset::SYNC_SYNCED,
                    'cdn_status' => MediaAsset::CDN_VERIFIED,
                ]);
            }
        }

        return $asset->fresh('variants');
    }

    /** @param array<mixed> $value */
    private function canonicalSha256(array $value): string
    {
        $sort = function (array &$items) use (&$sort): void {
            foreach ($items as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
            unset($item);
            if (! array_is_list($items)) {
                ksort($items);
            }
        };
        $sort($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string, string> $environment */
    private function nodeProcess(string $filename, array $environment = []): Process
    {
        $process = new Process(['node', self::PACKAGE_DIR.'/'.$filename], $this->repositoryPath(), $environment + $_ENV);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function runNode(string $filename): void
    {
        $process = $this->nodeProcess($filename);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return $this->readAbsoluteJson($this->repositoryPath($path));
    }

    /** @return array<string, mixed> */
    private function readAbsoluteJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $this->assertNotFalse(file_put_contents(
            $path,
            json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        ));
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{directory:string,path:string}
     */
    private function writeTemporaryRehashedPackage(array $package, string $filename): array
    {
        $directory = $this->temporaryDirectory();
        $observationPath = $directory.'/production-observation.json';
        $this->assertTrue(copy(
            $this->repositoryPath(self::PACKAGE_DIR.'/production-observation.json'),
            $observationPath,
        ));
        $package['inputs']['production_observation_path'] = $observationPath;
        unset($package['package_payload_sha256']);
        $package['package_payload_sha256'] = $this->canonicalSha256($package);
        $path = $directory.'/'.$filename.'.json';
        $this->writeJson($path, $package);
        $this->assertNotFalse(file_put_contents(
            $directory.'/'.$filename.'.sha256',
            hash_file('sha256', $path)."\n",
        ));

        return ['directory' => $directory, 'path' => $path];
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/big-five-pr49-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory));

        return $directory;
    }

    private function cleanupTemporaryPackage(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    private function repositoryPath(string $path = ''): string
    {
        $root = dirname(base_path());

        return $path === '' ? $root : $root.'/'.$path;
    }
}
