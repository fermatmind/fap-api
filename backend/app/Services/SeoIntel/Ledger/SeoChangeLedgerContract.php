<?php

namespace App\Services\SeoIntel\Ledger;

final class SeoChangeLedgerContract
{
    public const SCHEMA_VERSION = 'seo.change_ledger.v1';

    public const FIELDS = [
        'ledger_id',
        'change_type',
        'hypothesis',
        'rationale',
        'source',
        'public_url_cohort',
        'page_family',
        'locale',
        'authority_revision',
        'baseline_window',
        'primary_metric',
        'guardrail_metrics',
        'observation_window',
        'change_revision',
        'canary_scope',
        'blast_radius',
        'public_runtime_readback',
        'gsc_funnel_evidence_state',
        'rollback_plan',
        'owner_actor',
        'approval_policy_decision',
        'current_state',
        'close_reason',
    ];

    public const STATES = [
        'draft',
        'evidence_ready',
        'policy_review',
        'approved',
        'canary_ready',
        'canary_running',
        'observing',
        'expanded',
        'held',
        'rollback_required',
        'rolled_back',
        'closed',
        'rejected',
    ];

    private function __construct() {}
}
