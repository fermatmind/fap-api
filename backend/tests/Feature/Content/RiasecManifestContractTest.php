<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Tests\TestCase;

final class RiasecManifestContractTest extends TestCase
{
    public function test_riasec_compiled_manifests_match_flagship_shape(): void
    {
        $this->assertRiasecManifest(
            base_path('content_packs/RIASEC/v1-standard-60/compiled'),
            'v1-standard-60',
            'riasec_60',
            60
        );
        $this->assertRiasecManifest(
            base_path('content_packs/RIASEC/v1-enhanced-140/compiled'),
            'v1-enhanced-140',
            'riasec_140',
            140
        );
    }

    private function assertRiasecManifest(string $compiledDir, string $packVersion, string $formCode, int $questionCount): void
    {
        $manifestPath = $compiledDir.'/manifest.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame('fap.content_pack.manifest.v1', $manifest['schema'] ?? null);
        $this->assertSame('RIASEC', $manifest['pack_id'] ?? null);
        $this->assertSame('RIASEC', $manifest['scale_code'] ?? null);
        $this->assertSame($packVersion, $manifest['pack_version'] ?? null);
        $this->assertSame($packVersion, $manifest['content_package_version'] ?? null);
        $this->assertSame($packVersion, $manifest['dir_version'] ?? null);
        $this->assertSame($formCode, $manifest['form_code'] ?? null);
        $this->assertSame('riasec_60', $manifest['default_form_code'] ?? null);
        $this->assertSame(['riasec_60', 'riasec_140'], $manifest['public_forms'] ?? null);
        $this->assertSame('holland-career-interest-test-riasec', $manifest['primary_slug'] ?? null);
        $this->assertSame($questionCount, $manifest['question_count'] ?? null);

        $this->assertSame(
            hash_file('sha256', $compiledDir.'/questions.compiled.json'),
            $manifest['hashes']['questions.compiled.json'] ?? null
        );
        $this->assertSame(
            hash_file('sha256', $compiledDir.'/policy.compiled.json'),
            $manifest['hashes']['policy.compiled.json'] ?? null
        );
        $this->assertSame(['questions.compiled.json', 'policy.compiled.json'], $manifest['compiled_files'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($manifest['compiled_hash'] ?? ''));
    }
}
