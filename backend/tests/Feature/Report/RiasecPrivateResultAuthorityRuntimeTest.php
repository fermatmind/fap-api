<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Content\RiasecPrivateResultCompileService;
use App\Services\Content\RiasecPrivateResultPackLoader;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class RiasecPrivateResultAuthorityRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_result_compile_command_materializes_and_activates_an_idempotent_release(): void
    {
        $this->artisan('content:lint', [
            '--pack' => RiasecPrivateResultCompileService::PACK_ID,
            '--pack-version' => RiasecPrivateResultCompileService::PACK_VERSION,
        ])->assertSuccessful();
        $this->artisan('content:compile', [
            '--pack' => RiasecPrivateResultCompileService::PACK_ID,
            '--pack-version' => RiasecPrivateResultCompileService::PACK_VERSION,
        ])->assertSuccessful();

        $compiledDir = base_path('content_assets/riasec/compiled');
        $payload = json_decode((string) file_get_contents($compiledDir.'/'.RiasecPrivateResultCompileService::ARTIFACT_FILENAME), true);
        $manifest = json_decode((string) file_get_contents($compiledDir.'/manifest.json'), true);
        $this->assertSame(RiasecPrivateResultCompileService::SCHEMA, $payload['schema'] ?? null);
        $this->assertSame($payload['source_hash'] ?? null, $manifest['source_hash'] ?? null);
        $this->assertSame($payload['compiled_hash'] ?? null, $manifest['compiled_hash'] ?? null);

        $this->artisan('packs2:publish', [
            '--pack' => RiasecPrivateResultCompileService::PACK_ID,
            '--pack-version' => RiasecPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
            '--source_commit' => str_repeat('a', 40),
        ])->assertSuccessful();
        $activeReleaseId = DB::table('content_pack_activations')
            ->where('pack_id', RiasecPrivateResultCompileService::PACK_ID)
            ->where('pack_version', RiasecPrivateResultCompileService::PACK_VERSION)
            ->value('release_id');
        $this->assertNotEmpty($activeReleaseId);
        $this->assertSame($payload['compiled_hash'], DB::table('content_pack_releases')->where('id', $activeReleaseId)->value('compiled_hash'));
        $loaded = app(RiasecPrivateResultPackLoader::class)->load('zh-CN');
        $this->assertSame($payload['source_hash'], data_get($loaded, 'authority.source_hash'));
        $this->assertSame($payload['compiled_hash'], data_get($loaded, 'authority.compiled_hash'));

        $releaseCount = DB::table('content_pack_releases')->where('to_pack_id', RiasecPrivateResultCompileService::PACK_ID)->count();
        $this->artisan('packs2:publish', [
            '--pack' => RiasecPrivateResultCompileService::PACK_ID,
            '--pack-version' => RiasecPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
        ])->assertSuccessful();
        $this->assertSame($releaseCount, DB::table('content_pack_releases')->where('to_pack_id', RiasecPrivateResultCompileService::PACK_ID)->count());
    }

    public function test_legacy_snapshot_body_is_preserved_and_explicitly_marked_immutable(): void
    {
        $body = ['schema_version' => 'riasec.report.v1', 'sections' => [['key' => 'legacy', 'body' => 'original body']]];
        $method = new \ReflectionMethod(ReportGatekeeper::class, 'markLegacyPrivateResultSnapshot');
        $marked = $method->invoke(app(ReportGatekeeper::class), $body, (object) ['scale_code' => 'RIASEC']);

        $this->assertSame($body['sections'], $marked['sections']);
        $this->assertSame('immutable_legacy_snapshot', data_get($marked, '_meta.riasec_private_result_authority.mode'));
        $this->assertSame('', data_get($marked, '_meta.riasec_private_result_authority.source_hash'));
        $this->assertSame('', data_get($marked, '_meta.riasec_private_result_authority.compiled_hash'));
    }

    public function test_runtime_loader_rejects_authority_identity_drift(): void
    {
        $payload = app(RiasecPrivateResultCompileService::class)->compile()['payload'];
        $payload['authority_id'] = 'retired-authority';
        $unsigned = $payload;
        unset($unsigned['compiled_hash']);
        $payload['compiled_hash'] = hash('sha256', $this->canonicalJson($unsigned));

        $method = new \ReflectionMethod(RiasecPrivateResultPackLoader::class, 'assertValid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        $method->invoke(app(RiasecPrivateResultPackLoader::class), $payload);
    }

    public function test_runtime_loader_rejects_manifest_digest_drift(): void
    {
        $compiled = app(RiasecPrivateResultCompileService::class)->compile();
        $manifest = $compiled['manifest'];
        $manifest['source_files'][0]['sha256'] = str_repeat('0', 64);
        $method = new \ReflectionMethod(RiasecPrivateResultPackLoader::class, 'assertManifestValid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RIASEC_PRIVATE_RESULT_ACTIVE_MANIFEST_CONTRACT_INVALID');
        $method->invoke(app(RiasecPrivateResultPackLoader::class), $manifest, $compiled['payload'], $compiled['bytes']);
    }

    public function test_production_runtime_fails_closed_without_an_active_release(): void
    {
        $previousEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app(RiasecPrivateResultPackLoader::class)->load('zh-CN');
            $this->fail('Production loader accepted a missing active release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('RIASEC_PRIVATE_RESULT_ACTIVE_RELEASE_MISSING', $exception->getMessage());
        } finally {
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
        }
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
