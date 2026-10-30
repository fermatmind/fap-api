<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Content\RiasecPrivateResultCompileService;
use App\Services\Riasec\RiasecEnglishReportSourceContract;
use PHPUnit\Framework\Assert;

/** Restore the actual accepted 1.9 reader before deriving earlier immutable fixtures. */
final class RiasecLegacyReaderCopyFixture
{
    public static function restoreCompiled(array $compiled): array
    {
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/riasec_reader_copy_precloseout_1_9.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            Assert::assertArrayHasKey($row['asset'], $payload['assets']);
            data_set($payload['assets'][$row['asset']], implode('.', $row['path']), $row['copy']);
        }
        $compiler = app(RiasecPrivateResultCompileService::class);
        $canonical = new \ReflectionMethod($compiler, 'canonicalJson');
        $coverage = (new \ReflectionMethod($compiler, 'coverage'))->invoke($compiler, $payload['assets']);
        $payload['coverage'] = $manifest['coverage'] = $coverage;
        foreach ($manifest['source_files'] as &$source) {
            $source['sha256'] = RiasecEnglishReportSourceContract::digest($payload['assets'][$source['path']]);
        }
        unset($source);
        $sourceHash = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = $sourceHash;
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = $manifest['compiled_hash'] = RiasecEnglishReportSourceContract::digest($payload);
        $bytes = $canonical->invoke($compiler, $payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);

        Assert::assertSame('58910dbd3a03a1c57c099c365ab55470218dc87c8828545caacc95af1758ce43', $sourceHash);
        Assert::assertSame('0e53b7c1cfd3b731fac97a94b735393fb8f268ca5a7a7f3ef99baecc0f9d46ac', $payload['compiled_hash']);

        return ['payload' => $payload, 'manifest' => $manifest, 'bytes' => $bytes,
            'manifest_bytes' => $canonical->invoke($compiler, $manifest)."\n",
            'source_hash' => $sourceHash, 'compiled_hash' => $payload['compiled_hash']];
    }
}
