<?php

namespace App\Services\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MemoryProposer
{
    public function proposeFromInsights(int $userId): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('ai_insights')) {
            return ['ok' => false, 'error' => 'ai_insights_missing', 'items' => []];
        }

        $rows = DB::table('ai_insights')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'kind' => 'insight',
                'title' => 'Insight snapshot',
                'content' => (string) ($row->summary ?? $row->insight_text ?? ''),
                'evidence' => json_decode((string) ($row->evidence_json ?? '[]'), true) ?? [],
                'source_refs' => [
                    ['type' => 'ai_insights', 'id' => (string) ($row->id ?? '')],
                ],
                'consent_version' => $row->consent_version ?? null,
            ];
        }

        return ['ok' => true, 'items' => $items];
    }
}
