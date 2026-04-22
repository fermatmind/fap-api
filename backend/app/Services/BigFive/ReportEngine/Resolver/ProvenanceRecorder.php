<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

final class ProvenanceRecorder
{
    /**
     * @param  list<string>  $atomicRefs
     * @param  list<string>  $modifierRefs
     * @param  list<string>  $synergyRefs
     * @param  list<string>  $facetRefs
     * @return array<string,list<string>>
     */
    public function record(array $atomicRefs = [], array $modifierRefs = [], array $synergyRefs = [], array $facetRefs = []): array
    {
        return [
            'atomic_refs' => array_values(array_unique($atomicRefs)),
            'modifier_refs' => array_values(array_unique($modifierRefs)),
            'synergy_refs' => array_values(array_unique($synergyRefs)),
            'facet_refs' => array_values(array_unique($facetRefs)),
        ];
    }
}
