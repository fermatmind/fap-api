<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SeoOpportunityCardGenerator
{
    public const ID = 'gsc_page_optimization';

    public const VERSION = 'seo.opportunity_card_generator.v1';

    public function __construct(private readonly string $connection = 'seo_intel') {}

    /** GSC discovery is snapshotted before the natural writer transaction opens. */
    public function generate(CarbonImmutable $now, string $releaseSha, callable $deadline, ?array $discovery): array
    {
        $db = DB::connection($this->connection);
        if ($db->transactionLevel() < 1) {
            throw new RuntimeException('Planning requires the natural task transaction.');
        }
        $deadline();
        $summary = [
            'generator_version' => self::VERSION, 'scan_rows' => 0, 'candidate_count' => 0,
            'deduplicated_pages' => 0, 'evidence_checked_pages' => 0, 'qualified_pages' => 0,
            'created' => 0, 'refreshed' => 0, 'unchanged' => 0, 'protected' => 0,
            'hold_reasons' => [], 'cap_unprocessed_pages' => 0, 'discovery_limited' => false,
            'count_unit' => 'candidates_are_signals;validation_and_cap_are_pages;writes_are_cards',
        ];
        // Missing source schemas are an explicit HOLD, including installations with historical cards only.
        foreach (['seo_gsc_daily', 'seo_gsc_sync_runs', 'seo_urls', 'seo_url_entities'] as $table) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                $summary['hold_reasons']['source_schema_unavailable'] = 1;

                return $summary;
            }
        }
        if ($discovery === null) {
            $summary['hold_reasons']['source_schema_unavailable'] = 1;

            return $summary;
        }
        $summary['scan_rows'] = $discovery['rows_scanned'];
        $summary['candidate_count'] = count($discovery['candidates']);
        $summary['discovery_limited'] = $discovery['discovery_limited'];
        $groups = [];
        foreach ($discovery['candidates'] as $c) {
            if (($c['source_signal'] ?? '') !== 'gsc:google:search_analytics_readonly'
                || array_intersect($c['opportunity_types'] ?? [], SeoOpportunityEvidence::TYPES) === []) {
                $summary['hold_reasons']['unsupported_candidate_type'] = ($summary['hold_reasons']['unsupported_candidate_type'] ?? 0) + 1;

                continue;
            }
            $key = (string) ($c['canonical_url_hash'] ?? '').'|'.(string) ($c['locale'] ?? '');
            $groups[$key][] = $c;
        }
        ksort($groups, SORT_STRING);
        $summary['deduplicated_pages'] = count($groups);
        if (($discovery['gate']['status'] ?? '') !== 'pass') {
            foreach ($discovery['gate']['reasons'] as $reason) {
                $summary['hold_reasons'][$reason] = 1;
            }
        }
        $evidence = new SeoOpportunityEvidence($this->connection);
        $read = new SeoDecisionCardReadService($this->connection);
        $snapshot = $read->snapshot($now);
        if ($snapshot['state'] === 'unavailable') {
            throw new RuntimeException('Decision pointers unavailable.');
        }
        $current = [];
        foreach ($snapshot['items'] as $card) {
            $current[$card['cluster_uid']] = $card;
        }
        $qualified = [];
        foreach ($groups as $candidates) {
            $deadline();
            $proof = $evidence->evaluate($candidates, $now, $deadline);
            $summary['evidence_checked_pages']++;
            if (isset($proof['reason'])) {
                $summary['hold_reasons'][$proof['reason']] = ($summary['hold_reasons'][$proof['reason']] ?? 0) + 1;

                continue;
            }
            $cluster = self::cluster($proof['target']['canonical_url_hash'], $proof['target']['locale']);
            $brief = $this->brief($proof, $cluster, $now);
            $qualified[$cluster] = $brief;
            $summary['qualified_pages']++;
        }
        // Validity is checked for every current generated card independently of the write cap.
        // Protected human lifecycle states are never reset. Read projections revoke eligibility live.
        foreach ($current as $cluster => $card) {
            $deadline();
            if ($card['detector'] !== self::ID || ! in_array($card['status'], ['candidate', 'selected', 'held'], true)) {
                continue;
            }
            if (! isset($qualified[$cluster]) && ($card['executable'] ?? false)) {
                $card['measurement_state'] = 'MEASUREMENT_HOLD';
                $card['evidence_freshness'] = 'stale';
                $card['evidence_hash'] = hash('sha256', $card['evidence_hash'].'|source_not_qualified');
                (new SeoDecisionLifecycleMaterializer($this->connection))->materialize($card, 'held', 'hold:'.$card['evidence_hash'], ['evidence_fresh' => false]);
            }
        }
        uasort($qualified, static fn ($a, $b) => ($b['priority']['score'] <=> $a['priority']['score']) ?: strcmp($a['cluster_uid'], $b['cluster_uid']));
        foreach ($qualified as $cluster => $brief) {
            $deadline();
            $old = $current[$cluster] ?? null;
            if ($old && ($old['detector'] !== self::ID || ! in_array($old['status'], ['candidate', 'selected', 'held'], true))) {
                $summary['protected']++;

                continue;
            }
            $hash = SeoWeeklyDecisionReceiptValidator::hash(array_intersect_key($brief, array_flip(['target', 'evidence', 'actions', 'acceptance'])));
            if ($old && hash_equals($old['brief']['evidence_hash'] ?? $old['evidence_hash'], $hash)) {
                $summary['unchanged']++;

                continue; // Never renew expiry for identical evidence.
            }
            if ($summary['created'] + $summary['refreshed'] >= 5) {
                $summary['cap_unprocessed_pages']++;

                continue;
            }
            $ledgerId = $old['ledger_id'] ?? (string) Str::uuid();
            $brief['evidence_hash'] = $hash;
            $brief['generated_sha'] = $releaseSha;
            $expires = $now->addDays(7)->min(CarbonImmutable::parse($brief['evidence']['source_fresh_until']));
            $brief['expires_at'] = $expires->toIso8601String();
            $fields = [
                'source_json' => SeoWeeklyDecisionReceiptValidator::encode($brief),
                'authority_revision' => $brief['target']['authority_revision'],
                'baseline_window_json' => json_encode(['start_date' => $brief['evidence']['start_date'], 'end_date' => $brief['evidence']['end_date']], JSON_THROW_ON_ERROR),
                'primary_metric_json' => json_encode($brief['evidence']['metrics'], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ];
            if ($old === null) {
                $db->table('seo_change_ledgers')->insert($fields + [
                    'ledger_id' => $ledgerId, 'schema_version' => 'seo.change_ledger.v1',
                    'idempotency_key' => self::ID.':'.$cluster, 'change_type' => 'page_optimization_planning',
                    'hypothesis' => 'Observed search signals require human review; causation is unproven.',
                    'rationale' => 'Seven-day candidate query-set evidence; no execution authorized.',
                    'public_url_cohort_json' => json_encode([$brief['target']['canonical_path']], JSON_THROW_ON_ERROR),
                    'page_family' => $brief['target']['page_family'], 'locale' => $brief['target']['locale'],
                    'owner_actor_json' => '{"role":"natural_weekly_planner"}', 'current_state' => 'draft',
                    'created_at' => $now,
                ]);
            } else {
                $ledger = $db->table('seo_change_ledgers')->where('ledger_id', $ledgerId)->lockForUpdate()->first();
                if ($ledger === null || $ledger->change_type !== 'page_optimization_planning' || $ledger->current_state !== 'draft') {
                    $summary['protected']++;

                    continue;
                }
                $db->table('seo_change_ledgers')->where('ledger_id', $ledgerId)->update($fields);
            }
            $card = [
                'cluster_uid' => $cluster, 'ledger_id' => $ledgerId, 'detector' => self::ID,
                'root_cause' => 'observed_search_opportunity', 'page_family' => $brief['target']['page_family'],
                'locale' => $brief['target']['locale'], 'authority_revision' => $brief['target']['authority_revision'],
                'runtime_revision' => null, 'cache_revision' => null, 'release_revision' => $releaseSha,
                'affected_unique_url_count' => 1, 'evidence_state' => 'observed', 'evidence_freshness' => 'fresh',
                'measurement_state' => 'READY', 'measurement_independent' => false,
                'business_priority' => 'L2', 'risk_tier' => 'P3', 'estimated_fix_cost' => 'manual',
                'priority_score' => $brief['priority']['score'], 'highest_allowed_action' => 'read_only_review',
                'next_step' => 'Review evidence and deliver page-specific recommendations.', 'owner' => 'seo_ops',
                'first_observed_at' => $old['first_observed_at'] ?? $now->toDateTimeString(),
                'last_observed_at' => $now->toDateTimeString(), 'expires_at' => $expires->toDateTimeString(),
                'evidence_hash' => $hash, 'close_reason' => null, 'brief' => $brief,
            ];
            $state = $old && $old['status'] === 'selected' ? 'selected' : 'candidate';
            (new SeoDecisionLifecycleMaterializer($this->connection))->materialize($card, $state, self::ID.':'.hash('sha256', $cluster.'|'.$hash.'|'.($old['decision_revision_id'] ?? 'initial')), ['evidence_fresh' => true]);
            $summary[$old ? 'refreshed' : 'created']++;
        }
        ksort($summary['hold_reasons']);
        $deadline();

        return $summary;
    }

    public static function cluster(string $hash, string $locale): string
    {
        return 'seo_cluster_'.substr(hash('sha256', self::ID.'|'.$hash.'|'.$locale.'|page_optimization'), 0, 48);
    }

    private function brief(array $proof, string $cluster, CarbonImmutable $now): array
    {
        $inputs = [
            'cluster_uid' => $cluster, 'impact_scope' => ['affected_unique_public_urls' => 1, 'family_scope' => $proof['target']['page_family']],
            'evidence_strength' => 'observed', 'business_value' => 'L2',
            'risk' => ['severity' => 'P3', 'blast_radius' => 'low', 'direct_evidence' => false],
            'estimated_fix_cost' => 'manual',
            'evidence_freshness' => ['observed_at' => $proof['end_date'].'T00:00:00Z', 'evaluated_at' => $now->toIso8601String(), 'max_age_seconds' => (int) config('seo_intel.gsc_data_quality.max_report_age_days', 10) * 86400],
            'measurement_state' => ['complete' => true, 'quality_passed' => true, 'comparable' => true, 'lag_seconds' => 0, 'max_lag_seconds' => 0],
        ];
        $priority = (new SeoNullablePriorityEvaluator)->evaluate($inputs);
        if (! $priority['ranking_eligible']) {
            throw new RuntimeException('Qualified evidence priority rejected.');
        }
        $actions = [];
        foreach ($proof['signals'] as $signal) {
            $actions[] = ['signal' => $signal, 'action' => $signal === SeoOpportunityEvidence::TYPES[0]
                ? '检查当前标题、摘要与候选查询集合的搜索意图；交付附证据及修改位置的标题和摘要修订建议。低 CTR 不证明标题是原因。'
                : '核对页面内容覆盖与相关内链；交付具体缺口、修改位置及内链建议。排名 4–20 不证明内容质量差。'];
        }

        return [
            'schema_version' => self::VERSION, 'cluster_uid' => $cluster, 'target' => $proof['target'],
            'evidence' => array_diff_key($proof, ['target' => true]), 'actions' => $actions,
            'priority' => ['score' => $priority['priority_score'], 'inputs' => $inputs, 'components' => $priority['components'],
                'assumptions' => 'L2/P3、单页面低影响及人工评审成本是规划假设；observed 不是故障或因果证明。',
                'sort_reason' => 'existing_priority_score_desc_then_stable_cluster'],
            'acceptance' => [
                'recommendation' => '目标唯一、证据可核对、建议有明确交付物；待人工判断，无发布、实验或搜索提交授权。',
                'future_change' => '另一个实施任务上线后，观察完成数据延迟的 14 天窗口；CTR 结合排名区间比较，排名机会同时观察曝光和点击。不承诺固定增长率，不从生成日计算效果。',
            ],
            'execution_allowed' => false,
        ];
    }
}
