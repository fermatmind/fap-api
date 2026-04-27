<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

use JsonException;
use RuntimeException;

final class EnneagramAssetItemStreamLoader
{
    /**
     * @return array{source_file:string,metadata:array<string,mixed>,items:list<array<string,mixed>>,raw:array<string,mixed>}
     */
    public function load(string $path): array
    {
        $sourceFile = trim($path);
        if ($sourceFile === '' || ! is_file($sourceFile)) {
            throw new RuntimeException('ENNEAGRAM asset item stream file not found: '.$sourceFile);
        }

        $raw = file_get_contents($sourceFile);
        if ($raw === false) {
            throw new RuntimeException('ENNEAGRAM asset item stream file unreadable: '.$sourceFile);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('ENNEAGRAM asset item stream JSON invalid: '.$error->getMessage(), previous: $error);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('ENNEAGRAM asset item stream must decode to an object.');
        }

        $items = $decoded['items'] ?? $decoded['assets'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('ENNEAGRAM asset item stream must contain items[] or assets[].');
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalizedItems[] = $item;
            }
        }

        $metadata = $decoded;
        unset($metadata['items']);
        unset($metadata['assets']);

        return [
            'source_file' => $sourceFile,
            'metadata' => $metadata,
            'items' => $normalizedItems,
            'raw' => $decoded,
        ];
    }
}
