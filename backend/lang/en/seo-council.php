<?php

return [
    'missions' => ['GSC freshness and runtime', 'URL Truth, cluster/dedupe and D1', 'Privacy, Policy and Evidence drift'],
    'overview' => 'Daily read-only checks',
    'actionable' => 'Issues requiring attention',
    'next_run' => 'Next run (UTC)',
    'source_gap' => 'Evidence is missing or stale. Expand Trace to check source status and time; missing necessary sources do not complete wiring acceptance.',
    'time_unknown' => 'Source time unavailable',
    'sources' => ['gsc_scheduled_receipt' => 'Scheduled GSC receipt', 'scheduled_runtime_probe' => 'Runtime probe receipt',
        'public_api_health' => 'Public API probe', 'url_truth_reconciliation' => 'URL Truth reconciliation', 'issue_cluster' => 'Issue clusters',
        'd1_observation' => 'D1 observation', 'sitemap_observation' => 'Cached sitemap observation', 'private_route_negative_set' => 'Private-route negative set',
        'evidence_expiry' => 'Evidence expiry', 'registry_version_vector' => 'Version vector', 'stored_evidence_safety' => 'Minimized evidence verification', 'council_tool_audit' => 'Tool audit'],
    'inspect_trace' => 'A check found an issue. Inspect Trace for its reason and source, then use the relevant operations workspace.',
    'trace' => 'Inspect evidence',
    'states' => ['READY' => 'Check healthy', 'HOLD' => 'Review required', 'NOT_STARTED' => 'Not started',
        'RUNNING' => 'Running', 'UNAVAILABLE' => 'Evidence unavailable', 'STALE' => 'Result is stale'],
];
