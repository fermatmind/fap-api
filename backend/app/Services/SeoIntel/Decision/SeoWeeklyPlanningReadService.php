<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeoWeeklyPlanningReadService
{
    public function __construct(private readonly string $connection = 'seo_intel') {}

    /** Backward compatible additions: legacy decisions remain a preview, never a receipt. */
    public function additions(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $read = new SeoDecisionCardReadService($this->connection);
        $snapshot = $read->snapshot($now);

        return [
            'selection_kind' => 'current_ranking_preview',
            'candidates' => (new SeoWeeklyDecisionSelector($read))->snapshot($now, 5),
            'this_week_selected' => $this->selected($now),
            'held_cards' => array_values(array_filter($snapshot['items'], fn ($c) => ! $c['executable'])),
            'permissions' => [
                'natural_task_planning_write_allowed' => true, 'get_write_allowed' => false,
                'model_allowed' => false, 'tools_allowed' => false, 'publish_allowed' => false,
                'experiments_allowed' => false, 'search_submission_allowed' => false,
            ],
        ];
    }

    private function selected(CarbonImmutable $now): array
    {
        $base = ['state' => 'pending_natural_run', 'decisions' => [], 'count' => 0, 'iso_week' => $now->format('o-\WW')];
        try {
            $db = DB::connection($this->connection);
            $rows = $db->table('seo_weekly_decision_capability_receipts')->where('iso_week', $base['iso_week'])->get();
            if ($rows->isEmpty()) {
                return $base;
            }
            if ($rows->count() !== 1) {
                return array_replace($base, ['state' => 'receipt_invalid']);
            }
            $row = $rows->first();
            $selections = $db->table('seo_weekly_decision_receipts')->where('iso_week', $base['iso_week'])->get();
            if ($selections->count() !== 1) {
                return array_replace($base, ['state' => 'receipt_invalid']);
            }
            $v = SeoWeeklyDecisionReceiptValidator::validatePair($row, $selections->first(), $row->capability_revision, $base['iso_week']);
            if (! $v['valid']) {
                return array_replace($base, ['state' => 'receipt_invalid']);
            }
            $p = $v['capability_receipt'];
            $cards = [];
            foreach ($p['decision_revision_ids'] as $i => $id) {
                $card = $db->table('seo_decision_cards')->where('decision_revision_id', $id)->first();
                if ($card === null || $card->decision_card_id !== $p['decision_card_ids'][$i]
                    || ! $db->table('seo_change_ledgers')->where('ledger_id', $card->ledger_id)->exists()) {
                    return array_replace($base, ['state' => 'receipt_invalid']);
                }
                $cards[] = (new SeoDecisionCardReadService($this->connection))->present($card, $now);
            }

            return array_replace($base, [
                'state' => $cards === [] ? 'verified_zero' : 'available', 'count' => count($cards), 'decisions' => $cards,
                'scheduled_for' => $p['scheduled_for'], 'release_sha' => $p['release_sha'],
                'selection_revision' => $p['selection_revision'], 'receipt_hash' => $row->receipt_hash,
                'selection_receipt_hash' => $selections->first()->receipt_hash,
                'generation_summary' => isset($p['generation_summary']) ? self::summary($p['generation_summary']) : null,
            ]);
        } catch (Throwable) {
            return array_replace($base, ['state' => 'unavailable']);
        }
    }

    private static function summary(array $summary): array
    {
        $out = array_intersect_key($summary, array_flip(['generator_version', 'scan_rows', 'candidate_count', 'deduplicated_pages',
            'evidence_checked_pages', 'qualified_pages', 'created', 'refreshed', 'unchanged', 'protected', 'cap_unprocessed_pages', 'discovery_limited', 'count_unit']));
        $out['hold_reasons'] = array_filter($summary['hold_reasons'] ?? [], static fn ($v, $k) => is_int($v) && preg_match('/\A[a-z0-9_]{1,80}\z/', $k), ARRAY_FILTER_USE_BOTH);

        return $out;
    }
}
