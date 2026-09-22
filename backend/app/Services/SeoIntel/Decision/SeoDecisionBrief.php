<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Brief authority is the immutable materialization event, never mutable ledger JSON. */
final class SeoDecisionBrief
{
    public function __construct(private readonly string $connection = 'seo_intel') {}

    public function load(object $card): ?array
    {
        if ($card->detector !== SeoOpportunityCardGenerator::ID) {
            return null;
        }
        $event = DB::connection($this->connection)->table('seo_change_ledger_events')
            ->where('idempotency_key', $card->idempotency_key)->where('ledger_id', $card->ledger_id)->first();
        if ($event === null) {
            throw new RuntimeException('Decision brief event missing.');
        }
        $payload = SeoOpportunityEvidence::json($event->evidence_json);
        if (! hash_equals($event->evidence_hash, SeoWeeklyDecisionReceiptValidator::hash($payload))
            || ($payload['decision_revision_id'] ?? '') !== $card->decision_revision_id) {
            throw new RuntimeException('Decision brief event integrity failed.');
        }

        return is_array($payload['brief'] ?? null) ? $payload['brief'] : null;
    }

    public function holdReason(array $brief, CarbonImmutable $now): ?string
    {
        $target = $brief['target'] ?? [];
        $current = (new SeoOpportunityEvidence($this->connection))->target((string) ($target['canonical_url_hash'] ?? ''), (string) ($target['locale'] ?? ''), $now);
        if (isset($current['reason'])) {
            return $current['reason'];
        }
        if (SeoWeeklyDecisionReceiptValidator::hash($current['target']) !== SeoWeeklyDecisionReceiptValidator::hash($target)) {
            return 'authority_changed';
        }
        $evidence = $brief['evidence'] ?? [];
        if (! isset($evidence['source_fresh_until']) || CarbonImmutable::parse($evidence['source_fresh_until'])->lte($now)) {
            return 'source_expired';
        }
        $sources = $evidence['sources'] ?? [];
        if ($sources === []) {
            return 'source_references_missing';
        }
        $db = DB::connection($this->connection);
        $runs = $db->table('seo_gsc_sync_runs')->whereIn('sync_run_uid', array_unique(array_column($sources, 'sync_run_uid')))->get()->keyBy('sync_run_uid');
        $rows = $db->table('seo_gsc_daily')->whereIn('id', array_column($sources, 'row_id'))->get()->keyBy('id');
        foreach ($sources as $source) {
            $row = $rows[$source['row_id']] ?? null;
            if ($row === null || ! hash_equals($source['row_hash'] ?? '', SeoOpportunityEvidence::rowHash((array) $row))) {
                return 'source_changed_or_missing';
            }
            $run = $runs[$source['sync_run_uid']] ?? null;
            $r = SeoOpportunityEvidence::json($run?->receipt_json);
            $hash = $r['receipt_hash'] ?? '';
            unset($r['receipt_hash']);
            if ($run === null || $run->status !== 'success' || $hash !== $source['sync_receipt_hash']
                || ! hash_equals($hash, SeoWeeklyDecisionReceiptValidator::hash($r))) {
                return 'source_revoked';
            }
        }

        return null;
    }

    /** Explicit field projection; query hashes, arbitrary ledger values and raw text never escape. */
    public static function project(?array $brief): ?array
    {
        if ($brief === null || ($brief['schema_version'] ?? '') !== SeoOpportunityCardGenerator::VERSION) {
            return null;
        }
        $pick = static fn (array $data, array $keys): array => array_intersect_key($data, array_flip($keys));
        $target = $pick($brief['target'] ?? [], ['canonical_path', 'locale', 'page_family', 'authority_revision']);
        $target['canonical_path'] = \App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator::normalizePublicPath($target['canonical_path'] ?? null);
        if ($target['canonical_path'] === null) {
            return null;
        }
        $evidence = $pick($brief['evidence'] ?? [], ['start_date', 'end_date', 'scope', 'query_count', 'query_set_hash', 'search_type', 'source_fresh_until']);
        $evidence['metrics'] = $pick($brief['evidence']['metrics'] ?? [], ['clicks', 'impressions', 'ctr', 'average_position', 'position_impressions', 'position_coverage']);
        $evidence['sources'] = array_map(fn ($row) => $pick($row, ['row_id', 'sync_run_uid', 'sync_receipt_hash']), $brief['evidence']['sources'] ?? []);
        $evidence['candidate_refs'] = array_values($brief['evidence']['candidate_refs'] ?? []);
        $evidence['dimensions'] = SeoOpportunityEvidence::DIMENSIONS;

        $priority = $pick($brief['priority'] ?? [], ['score', 'assumptions', 'sort_reason']);
        $inputs = $brief['priority']['inputs'] ?? [];
        $priority['inputs'] = $pick($inputs, ['evidence_strength', 'business_value', 'estimated_fix_cost']);
        $priority['inputs']['impact_scope'] = $pick($inputs['impact_scope'] ?? [], ['affected_unique_public_urls', 'family_scope']);
        $priority['inputs']['risk'] = $pick($inputs['risk'] ?? [], ['severity', 'blast_radius', 'direct_evidence']);
        $priority['components'] = $pick($brief['priority']['components'] ?? [], ['impact_scope', 'evidence_strength', 'business_value', 'risk', 'estimated_fix_cost', 'evidence_freshness', 'measurement_state']);

        return [
            'target' => $target, 'evidence' => $evidence,
            'actions' => array_map(fn ($a) => $pick($a, ['signal', 'action']), $brief['actions'] ?? []),
            'priority' => $priority,
            'acceptance' => $pick($brief['acceptance'] ?? [], ['recommendation', 'future_change']),
            'generated_sha' => $brief['generated_sha'] ?? null, 'evidence_hash' => $brief['evidence_hash'] ?? null,
            'expires_at' => $brief['expires_at'] ?? null, 'execution_allowed' => false,
        ];
    }
}
