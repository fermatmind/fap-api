<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

use RuntimeException;

final class EnneagramAssetPreviewFixtureGenerator
{
    public function __construct(
        private readonly EnneagramAssetPreviewPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * @param  array<string,mixed>  $merged
     * @return array{count:int,files:list<string>}
     */
    public function generate(array $merged, string $outputDir): array
    {
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('Unable to create ENNEAGRAM asset preview fixture directory: '.$outputDir);
        }

        $files = [];
        foreach ($this->payloadBuilder->buildAll($merged) as $payload) {
            $typeId = (string) data_get($payload, 'preview_context.type_id', 'unknown');
            $state = (string) data_get($payload, 'preview_context.interpretation_scope', 'unknown');
            $path = rtrim($outputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'enneagram_asset_preview_t'.$typeId.'_'.$state.'.json';
            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $files[] = $path;
        }

        return [
            'count' => count($files),
            'files' => $files,
        ];
    }
}
