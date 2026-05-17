<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class SeoIssueQueueContract
{
    /**
     * @return list<string>
     */
    public function issueTypes(): array
    {
        return [
            'url_truth_drift',
            'metadata_drift',
            'canonical_drift',
            'robots_drift',
            'jsonld_drift',
            'sitemap_missing',
            'sitemap_extra',
            'llms_missing',
            'llms_extra',
            'private_flow_exposed',
            'noindex_leak',
            'crawler_error',
            'crawler_private_hit',
            'crawler_noindex_hit',
            'gsc_ctr_drop',
            'gsc_position_drop',
            'baidu_push_failed',
            'indexnow_submission_failed',
            'domestic_index_unknown',
            'landing_conversion_drop',
            'revenue_drop',
            'claim_boundary_warning',
            'pii_policy_warning',
            'internal_qa_filter_warning',
        ];
    }

    /**
     * @return list<string>
     */
    public function severityValues(): array
    {
        return ['info', 'warning', 'high', 'critical'];
    }

    /**
     * @return list<string>
     */
    public function lifecycleValues(): array
    {
        return ['open', 'acknowledged', 'resolved', 'ignored'];
    }

    /**
     * @return list<string>
     */
    public function forbiddenColumns(): array
    {
        return [
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_payload',
            'payment_payload',
            'raw_email',
            'raw_ip',
            'raw_cookie',
            'raw_user_agent',
            'token',
            'api_key',
            'secret',
        ];
    }

    public function isIssueTypeAllowed(string $issueType): bool
    {
        return in_array($issueType, $this->issueTypes(), true);
    }

    public function normalizeSeverity(mixed $severity): string
    {
        $value = strtolower(trim((string) $severity));

        return in_array($value, $this->severityValues(), true) ? $value : 'info';
    }

    public function normalizeLifecycle(mixed $lifecycle): string
    {
        $value = strtolower(trim((string) $lifecycle));

        return in_array($value, $this->lifecycleValues(), true) ? $value : 'open';
    }
}
