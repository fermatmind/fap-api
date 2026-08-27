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

    public const ALLOWED_TRANSITIONS = [
        'draft' => ['evidence_ready', 'held', 'rejected'],
        'evidence_ready' => ['draft', 'policy_review', 'held', 'rejected'],
        'policy_review' => ['approved', 'held', 'rejected'],
        'approved' => ['canary_ready', 'held'],
        'canary_ready' => ['canary_running', 'held'],
        'canary_running' => ['observing', 'held', 'rollback_required'],
        'observing' => ['expanded', 'held', 'rollback_required', 'closed'],
        'expanded' => ['held', 'rollback_required', 'closed'],
        'held' => ['draft', 'evidence_ready', 'policy_review', 'approved', 'canary_ready', 'observing', 'rejected'],
        'rollback_required' => ['rolled_back', 'held'],
        'rolled_back' => ['closed', 'held'],
        'closed' => [],
        'rejected' => [],
    ];

    public const DETERMINISTIC_DENIALS = [
        'private_url_or_entity',
        'authority_unknown',
        'page_family_unclassified',
        'evidence_insufficient_or_stale',
        'open_p0_or_p1',
        'rollback_unavailable',
        'search_submission_requested',
        'blast_radius_outside_signed_scope',
    ];

    public const DISABLED_WRITE_STATES = ['canary_running', 'expanded'];

    private function __construct() {}
}
