<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Content\BigFivePrivateResultCompileService;
use App\Services\Content\BigFivePrivateResultPackLoader;
use App\Services\Report\BigFiveReportComposer;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFivePrivateResultAuthorityRuntimeTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_new_report_uses_canonical_hashes_and_ignores_attempt_content_version(): void
    {
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_private_authority_runtime');
        $composer = app(BigFiveReportComposer::class);

        $first = $composer->composeVariant($fixture['attempt'], $fixture['result'], ReportAccess::VARIANT_FULL);
        $this->assertTrue($first['ok'], json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $authority = data_get($first, 'report._meta.big5_private_result_authority');
        $this->assertSame('fap.big5.private_result_authority.v1', $authority['schema_version'] ?? null);
        $this->assertSame('canonical', $authority['mode'] ?? null);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) ($authority['source_hash'] ?? ''));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) ($authority['compiled_hash'] ?? ''));

        $fixture['attempt']->forceFill(['dir_version' => 'retired-result-body-must-not-select'])->save();
        $second = $composer->composeVariant($fixture['attempt']->fresh(), $fixture['result'], ReportAccess::VARIANT_FULL);
        $this->assertTrue($second['ok']);
        $this->assertSame($authority, data_get($second, 'report._meta.big5_private_result_authority'));
        $this->assertSame(data_get($first, 'report.sections'), data_get($second, 'report.sections'));
    }

    public function test_private_result_compile_command_materializes_only_a_derived_pack(): void
    {
        $this->artisan('content:compile', [
            '--pack' => BigFivePrivateResultCompileService::PACK_ID,
            '--pack-version' => BigFivePrivateResultCompileService::PACK_VERSION,
        ])->assertSuccessful();

        $compiledDir = base_path('content_packs/'.BigFivePrivateResultCompileService::PACK_ID.'/v2/compiled');
        $payload = json_decode((string) file_get_contents($compiledDir.'/'.BigFivePrivateResultCompileService::ARTIFACT_FILENAME), true);
        $manifest = json_decode((string) file_get_contents($compiledDir.'/manifest.json'), true);
        $this->assertSame(BigFivePrivateResultCompileService::SCHEMA, $payload['schema'] ?? null);
        $this->assertSame($payload['source_hash'] ?? null, $manifest['source_hash'] ?? null);
        $this->assertSame($payload['compiled_hash'] ?? null, $manifest['compiled_hash'] ?? null);

        $this->artisan('packs2:publish', [
            '--pack' => BigFivePrivateResultCompileService::PACK_ID,
            '--pack-version' => BigFivePrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
            '--source_commit' => str_repeat('a', 40),
        ])->assertSuccessful();
        $activeReleaseId = DB::table('content_pack_activations')
            ->where('pack_id', BigFivePrivateResultCompileService::PACK_ID)
            ->where('pack_version', BigFivePrivateResultCompileService::PACK_VERSION)
            ->value('release_id');
        $this->assertNotEmpty($activeReleaseId);
        $this->assertSame($payload['compiled_hash'], DB::table('content_pack_releases')->where('id', $activeReleaseId)->value('compiled_hash'));

        $releaseCount = DB::table('content_pack_releases')->where('to_pack_id', BigFivePrivateResultCompileService::PACK_ID)->count();
        $this->artisan('packs2:publish', [
            '--pack' => BigFivePrivateResultCompileService::PACK_ID,
            '--pack-version' => BigFivePrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
        ])->assertSuccessful();
        $this->assertSame($releaseCount, DB::table('content_pack_releases')->where('to_pack_id', BigFivePrivateResultCompileService::PACK_ID)->count());
    }

    public function test_legacy_snapshot_body_is_preserved_and_explicitly_marked_immutable(): void
    {
        $body = ['schema_version' => 'big5.report.v1', 'sections' => [['key' => 'legacy', 'body' => 'original body']]];
        $method = new \ReflectionMethod(ReportGatekeeper::class, 'markLegacyBigFiveSnapshot');
        $marked = $method->invoke(app(ReportGatekeeper::class), $body, (object) ['scale_code' => 'BIG5_OCEAN']);

        $this->assertSame($body['sections'], $marked['sections']);
        $this->assertSame('immutable_legacy_snapshot', data_get($marked, '_meta.big5_private_result_authority.mode'));
        $this->assertSame('', data_get($marked, '_meta.big5_private_result_authority.source_hash'));
        $this->assertSame('', data_get($marked, '_meta.big5_private_result_authority.compiled_hash'));
    }

    public function test_runtime_loader_rejects_manifest_identity_drift(): void
    {
        $payload = app(BigFivePrivateResultCompileService::class)->compile()['payload'];
        $payload['registry_manifest']['source_hash'] = str_repeat('0', 64);
        $unsigned = $payload;
        unset($unsigned['compiled_hash']);
        $payload['compiled_hash'] = hash('sha256', $this->canonicalJson($unsigned));

        $method = new \ReflectionMethod(BigFivePrivateResultPackLoader::class, 'assertValid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BIG5_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        $method->invoke(app(BigFivePrivateResultPackLoader::class), $payload);
    }

    private function canonicalJson(mixed $value): string
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
