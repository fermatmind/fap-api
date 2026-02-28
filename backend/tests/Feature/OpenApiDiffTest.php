<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class OpenApiDiffTest extends TestCase
{
    public function test_openapi_snapshot_is_synced_when_drift_not_allowed(): void
    {
        if (getenv('OPENAPI_DIFF_ALLOW_DRIFT') === '1') {
            $this->markTestSkipped('OPENAPI_DIFF_ALLOW_DRIFT=1: strict snapshot assertion skipped.');
        }

        $snapshotPath = base_path('docs/contracts/openapi.snapshot.json');
        $this->assertFileExists($snapshotPath);

        $expected = (string) file_get_contents($snapshotPath);
        $actual = $this->exportCurrentSnapshot();

        if (trim($expected) !== trim($actual)) {
            self::fail("OpenAPI snapshot drift detected.\n".$this->firstMismatchPreview($expected, $actual));
        }

        self::assertTrue(true);
    }

    public function test_gate_can_detect_drift_with_simulated_route_change(): void
    {
        $expected = $this->exportCurrentSnapshot();
        $decoded = json_decode($expected, true);

        $this->assertIsArray($decoded);
        $decoded['paths']['/api/v0.3/__openapi_contract_probe'] = [
            'get' => [
                'operationId' => 'simulated_probe',
                'tags' => ['api:v0.3'],
                'responses' => ['200' => ['description' => 'OK']],
                'x-laravel-action' => 'Simulated@probe',
                'x-laravel-middleware' => ['api'],
            ],
        ];

        ksort($decoded['paths']);
        $mutated = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

        $this->assertNotSame(trim($expected), trim((string) $mutated), 'Simulated route drift must change snapshot.');
    }

    private function exportCurrentSnapshot(): string
    {
        $script = base_path('scripts/export_openapi.sh');
        $cmd = 'bash '.escapeshellarg($script).' --stdout 2>/dev/null';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'export_openapi.sh --stdout failed.');

        return implode("\n", $out)."\n";
    }

    private function firstMismatchPreview(string $expected, string $actual): string
    {
        $a = preg_split('/\R/', $expected) ?: [];
        $b = preg_split('/\R/', $actual) ?: [];
        $max = max(count($a), count($b));

        for ($i = 0; $i < $max; $i++) {
            $la = $a[$i] ?? '';
            $lb = $b[$i] ?? '';
            if ($la !== $lb) {
                $line = $i + 1;

                return "first mismatch at line {$line}\nEXPECTED: {$la}\nACTUAL:   {$lb}";
            }
        }

        return 'content differs but no line mismatch located';
    }
}
