<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

final class SeoUrlTruthReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array{
     *     total_count:int,
     *     distributions:array{
     *         page_entity_type:list<array{label:string,count:int}>,
     *         locale:list<array{label:string,count:int}>,
     *         source_authority:list<array{label:string,count:int}>,
     *         indexability_state:list<array{label:string,count:int}>
     *     },
     *     safety_counts:array{
     *         private_flow_count:int,
     *         forbidden_authority_count:int,
     *         claim_unsafe_count:int
     *     }
     * }
     */
    public function read(): array
    {
        return [
            'total_count' => $this->table('seo_urls')->count(),
            'distributions' => [
                'page_entity_type' => $this->groupedCounts('seo_urls', 'page_entity_type'),
                'locale' => $this->groupedCounts('seo_urls', 'locale'),
                'source_authority' => $this->groupedCounts('seo_urls', 'source_authority'),
                'indexability_state' => $this->groupedCounts('seo_urls', 'indexability_state'),
            ],
            'safety_counts' => [
                'private_flow_count' => $this->table('seo_urls')->where('is_private_flow', true)->count(),
                'forbidden_authority_count' => $this->table('seo_urls')
                    ->whereIn('source_authority', config('seo_intel.search_channel_queue.forbidden_source_authorities', []))
                    ->count(),
                'claim_unsafe_count' => $this->claimUnsafeCount(),
            ],
        ];
    }

    private function claimUnsafeCount(): int
    {
        $count = 0;

        foreach ($this->table('seo_urls')->select('metadata_json')->cursor() as $row) {
            $metadata = $this->decodeJson($row->metadata_json ?? null);
            $state = (string) ($metadata['claim_boundary_state'] ?? 'claim_safe');
            $claimSafe = $metadata['claim_safe'] ?? null;

            if ($claimSafe === false) {
                $count++;

                continue;
            }

            if ($state !== '' && ! in_array($state, ['claim_safe', 'safe', 'approved'], true)) {
                $count++;
            }
        }

        return $count;
    }
}
