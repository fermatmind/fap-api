<?php

return [
    'title' => 'Council roles',
    'disclaimer' => 'Role definitions do not mean independent models are running. This view does not provide live task execution status.',
    'registry_unavailable' => 'The role registry is unavailable. The role list could not be loaded.',
    'snapshot_unavailable' => 'The Council governance snapshot is unavailable.',
    'empty' => 'The registry currently contains no roles.',
    'unknown_duty' => 'No display description is configured. Check the registry using the role ID.',
    'global' => ['paused' => 'Council paused', 'disabled' => 'Council disabled', 'restricted' => 'Council restricted', 'unavailable' => 'Status unavailable', 'read_only' => 'Council read-only computation enabled'],
    'states' => ['dormant_not_authorized' => 'Not authorized to run', 'unknown' => 'Role status unknown'],
    'roles' => [
        'orchestrator' => ['name' => 'Orchestrator', 'duty' => 'Coordinate missions, select review modes and consolidate recommendations.'],
        'technical' => ['name' => 'Technical SEO expert', 'duty' => 'Check search-rule consistency across backend, frontend and live surfaces.'],
        'analytics' => ['name' => 'Search analytics expert', 'duty' => 'Analyze sanitized search data and performance measurement evidence.'],
        'quality' => ['name' => 'Content quality expert', 'duty' => 'Review content, entities, claims and duplication.'],
        'research' => ['name' => 'Competitor research expert', 'duty' => 'Analyze public competitor structures without copying full content.'],
        'stability' => ['name' => 'Content stability expert', 'duty' => 'Check public content runtime, cache and projection stability.'],
        'cro' => ['name' => 'Conversion optimization expert', 'duty' => 'Analyze aggregate funnels and suggest conversion improvements.'],
        'reviewer' => ['name' => 'Independent reviewer', 'duty' => 'Independently review evidence, policy and safety boundaries.'],
        'career' => ['name' => 'Career content candidate Agent', 'duty' => 'Produce bounded career content candidates without publishing.'],
    ],
];
