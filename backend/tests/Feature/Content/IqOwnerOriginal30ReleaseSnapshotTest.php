<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Exceptions\Api\ApiProblemException;
use App\Services\Iq\IqOwnerOriginal30BankService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IqOwnerOriginal30ReleaseSnapshotTest extends TestCase
{
    public function test_release_snapshot_remains_authoritative_after_shared_link_and_rolls_back_with_release(): void
    {
        $root = sys_get_temp_dir().'/iq-release-'.bin2hex(random_bytes(8));
        $originalBase = base_path();
        $source = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.IqOwnerOriginal30BankService::DIR_VERSION);
        $candidatePack = $root.'/content_packages/default/CN_MAINLAND/zh-CN/'.IqOwnerOriginal30BankService::DIR_VERSION;
        try {
            File::copyDirectory($source, $candidatePack);
            $process = new Process(['bash', base_path('scripts/iq/freeze_iq_owner30_release.sh'), $root]);
            $process->mustRun();
            $snapshot = $root.'/backend/resources/iq_owner_original30';
            $keyPath = $snapshot.'/banks/IQ_OWNER_ORIGINAL_30/answer_key.json';
            $key = json_decode(File::get($keyPath), true, flags: JSON_THROW_ON_ERROR);
            $key['answer_key_version'] = 'release_snapshot_fixture';
            File::put($keyPath, json_encode($key, JSON_THROW_ON_ERROR));
            File::deleteDirectory($root.'/content_packages');
            symlink(base_path('../content_packages'), $root.'/content_packages');

            $this->app->setBasePath($root.'/backend');
            $service = new IqOwnerOriginal30BankService;
            $this->assertSame('release_snapshot_fixture', $service->runtimeScoringSpec()['answer_key_version']);
            $asset = $service->publicAsset('iq_owner_original_30/q02/q2-option-b.webp');
            $this->assertStringStartsWith(realpath($snapshot).'/assets/', $asset['absolute_path']);
            $this->assertSame(hash_file('sha256', $source.'/assets/iq_owner_original_30/q02/q2-option-b.webp'), hash_file('sha256', $asset['absolute_path']));

            // A late snapshot operation must never capture stale shared files.
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertSame('release_snapshot_fixture', $service->runtimeScoringSpec()['answer_key_version']);

            // Career-only incremental materialization copies the accepted snapshot.
            $inherited = new Process(['bash', $originalBase.'/scripts/iq/freeze_iq_owner30_release.sh', $root, '--inherited-snapshot']);
            $inherited->mustRun();
            $this->assertSame('release_snapshot_fixture', $service->runtimeScoringSpec()['answer_key_version']);
            $this->assertSame(hash_file('sha256', $source.'/assets/iq_owner_original_30/q02/q2-option-b.webp'), hash_file('sha256', $asset['absolute_path']));
            File::delete($snapshot.'/banks/IQ_OWNER_ORIGINAL_30/manifest.json');
            $inherited->run();
            $this->assertFalse($inherited->isSuccessful());
            $this->assertSame('release_snapshot_fixture', $service->runtimeScoringSpec()['answer_key_version']);

            $this->app->setBasePath($originalBase);
            $this->assertSame('owner_original_30_answer_key_2026_10_04', $service->runtimeScoringSpec()['answer_key_version']);
        } finally {
            $this->app->setBasePath($originalBase);
            if (is_link($root.'/content_packages')) {
                unlink($root.'/content_packages');
            }
            File::deleteDirectory($root);
        }
    }

    public function test_production_missing_snapshot_does_not_fall_back_to_shared_bank(): void
    {
        $originalBase = base_path();
        $originalEnvironment = $this->app->environment();
        $this->app->setBasePath(sys_get_temp_dir().'/missing-iq-'.bin2hex(random_bytes(8)));
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(ApiProblemException::class);
            (new IqOwnerOriginal30BankService)->runtimeScoringSpec();
        } finally {
            $this->app->setBasePath($originalBase);
            $this->app->detectEnvironment(fn () => $originalEnvironment);
        }
    }
}
