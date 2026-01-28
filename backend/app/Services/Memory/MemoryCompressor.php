<?php

namespace App\Services\Memory;

final class MemoryCompressor
{
    public function compress(array $memories): array
    {
        $summary = [];
        foreach ($memories as $memory) {
            $summary[] = [
                'id' => (string) ($memory->id ?? ''),
                'kind' => (string) ($memory->kind ?? ''),
                'title' => (string) ($memory->title ?? ''),
            ];
        }

        return [
            'ok' => true,
            'kind' => 'summary',
            'content' => 'Summary snapshot of confirmed memories.',
            'meta' => [
                'count' => count($summary),
                'items' => $summary,
            ],
        ];
    }
}
