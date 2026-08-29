<?php

return [
    'bundle_write_enabled' => env('SEO_AGENT_EVIDENCE_BUNDLE_WRITE_ENABLED', false),
    'context_build_enabled' => env('SEO_AGENT_EVIDENCE_CONTEXT_BUILD_ENABLED', false),
    'retention_delete_enabled' => env('SEO_AGENT_EVIDENCE_RETENTION_DELETE_ENABLED', false),
    'external_fetch_enabled' => env('SEO_AGENT_EVIDENCE_EXTERNAL_FETCH_ENABLED', false),
    'agent_external_egress' => false,
    'allowed_sources' => [],
    'query_hmac_key' => env('SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY'),
    'query_hmac_key_version' => env('SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY_VERSION'),
    'query_hmac_dual_write_enabled' => env('SEO_AGENT_EVIDENCE_QUERY_HMAC_DUAL_WRITE_ENABLED', false),
    'connection' => env('SEO_INTEL_DB_CONNECTION', 'seo_intel'),
];
