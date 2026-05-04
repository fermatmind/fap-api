<?php

declare(strict_types=1);

namespace App\Services\Career\Governance;

final class CareerTrustFactReviewPolicy
{
    public const POLICY_VERSION = 'career.trust_fact_review_policy.v1';

    /** @var list<string> */
    private const REQUIRED_CLAIM_TYPES = [
        'definition',
        'tasks',
        'salary',
        'employment',
        'growth',
        'education',
        'training',
        'AI exposure',
        'RIASEC interpretation',
        'personality interpretation',
        'China market reference',
    ];

    /** @var list<string> */
    private const REQUIRED_LEDGER_COLUMNS = [
        'Claim_Type',
        'Claim_Text',
        'Source_Key',
        'Source_URL',
        'Source_Usage',
        'Reviewer',
        'Reviewed_At',
        'Review_Result',
        'Risk_Level',
        'Notes',
    ];

    /**
     * @return array<string, mixed>
     */
    public function workbookSummary(bool $factReviewLedgerPresent): array
    {
        return [
            'policy_version' => self::POLICY_VERSION,
            'fact_review_ledger_present' => $factReviewLedgerPresent,
            'fact_review_ledger_status' => $factReviewLedgerPresent ? 'present_unverified' : 'missing',
            'required_ledger_columns' => self::REQUIRED_LEDGER_COLUMNS,
            'required_claim_types' => self::REQUIRED_CLAIM_TYPES,
            'sitemap_llms_blocked_until_fact_review_passes' => true,
            'writes_database' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateRow(mixed $sourceRefs, bool $factReviewLedgerPresent): array
    {
        $text = $this->encodedText($sourceRefs);
        $hasOfficialSource = $sourceRefs !== null && (
            str_contains($text, 'bls.gov')
            || str_contains($text, 'onetonline.org')
            || str_contains($text, 'onetcenter.org')
            || str_contains($text, 'stats.gov.cn')
            || str_contains($text, 'official')
            || str_contains($text, 'government')
            || str_contains($text, '政府')
        );
        $hasFermatInterpretation = $sourceRefs !== null
            && (str_contains($text, 'fermatmind') || str_contains($text, 'interpretation') || str_contains($text, '解释'));

        $blockers = [];
        if (! $hasOfficialSource) {
            $blockers[] = 'missing_official_source_trace';
        }
        if (! $hasFermatInterpretation) {
            $blockers[] = 'missing_fermat_interpretation_label';
        }
        if (! $factReviewLedgerPresent) {
            $blockers[] = 'missing_fact_review_ledger';
        }

        return [
            'policy_version' => self::POLICY_VERSION,
            'source_trace_parse' => $sourceRefs !== null,
            'official_source_trace_present' => $hasOfficialSource,
            'fermat_interpretation_labeled' => $hasFermatInterpretation,
            'fact_review_ledger_present' => $factReviewLedgerPresent,
            'reviewer_workflow_status' => $factReviewLedgerPresent ? 'requires_ledger_row_validation' : 'missing_ledger',
            'claim_level_evidence_status' => $blockers === [] ? 'ready_for_fact_review' : 'blocked',
            'sitemap_llms_release_ready' => false,
            'blockers' => $blockers,
        ];
    }

    private function encodedText(mixed $value): string
    {
        if (is_string($value)) {
            return strtolower($value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strtolower(is_string($encoded) ? $encoded : '');
    }
}
