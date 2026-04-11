<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\Models\CareerImportRun;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\TrustManifest;
use App\Services\Career\CareerRecommendationCompiler;

final class FirstWavePublishSeedMaterializer
{
    public function __construct(
        private readonly CareerIndexLifecycleCompiler $indexLifecycleCompiler,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $occupations
     * @return array{applied:int,skipped:int,issues_by_slug:array<string,list<string>>}
     */
    public function apply(CareerImportRun $importRun, array $occupations): array
    {
        $applied = 0;
        $skipped = 0;
        $issuesBySlug = [];

        foreach ($occupations as $manifestOccupation) {
            $slug = (string) ($manifestOccupation['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
            if (! $occupation instanceof Occupation) {
                $issuesBySlug[$slug][] = 'occupation_missing';
                $skipped++;

                continue;
            }

            if ($occupation->id !== (string) ($manifestOccupation['occupation_uuid'] ?? '')) {
                $issuesBySlug[$slug][] = 'occupation_uuid_mismatch';
                $skipped++;

                continue;
            }

            if ($occupation->family_id !== (string) ($manifestOccupation['family_uuid'] ?? '')) {
                $issuesBySlug[$slug][] = 'family_uuid_mismatch';
                $skipped++;

                continue;
            }

            $truthMetric = $occupation->truthMetrics()
                ->where('import_run_id', $importRun->id)
                ->latest('created_at')
                ->first();

            if ($truthMetric === null) {
                $issuesBySlug[$slug][] = 'truth_metric_missing_for_import_run';
                $skipped++;

                continue;
            }

            $trustSeed = is_array($manifestOccupation['trust_seed'] ?? null) ? $manifestOccupation['trust_seed'] : [];
            $reviewerSeed = is_array($manifestOccupation['reviewer_seed'] ?? null) ? $manifestOccupation['reviewer_seed'] : [];
            $indexSeed = is_array($manifestOccupation['index_seed'] ?? null) ? $manifestOccupation['index_seed'] : [];
            $claimSeed = is_array($manifestOccupation['claim_seed'] ?? null) ? $manifestOccupation['claim_seed'] : [];

            $confidenceScore = (int) round((float) ($trustSeed['confidence_score'] ?? 0));
            $reviewerStatus = strtolower(trim((string) ($reviewerSeed['status'] ?? 'pending')));
            $reviewedAt = in_array($reviewerStatus, ['approved', 'reviewed'], true) ? now() : null;
            $rawIndexState = strtolower(trim((string) ($indexSeed['state'] ?? IndexStateValue::NOINDEX)));
            $rawIndexEligible = (bool) ($indexSeed['index_eligible'] ?? false);
            $reasonCodes = array_values(array_unique(array_filter([
                ...((array) ($manifestOccupation['publish_reason_codes'] ?? [])),
                ...((array) ($indexSeed['reason_codes'] ?? [])),
                'first_wave_publish_seed',
            ])));

            $trustPayload = [
                'occupation_id' => $occupation->id,
                'content_version' => 'career_first_wave.publish_seed.v1',
                'data_version' => (string) ($importRun->dataset_version ?? 'dataset.unknown'),
                'logic_version' => CareerRecommendationCompiler::COMPILER_VERSION,
                'locale_context' => [
                    'truth_market' => $occupation->truth_market,
                    'display_market' => $occupation->display_market,
                ],
                'methodology' => [
                    'dataset_name' => $importRun->dataset_name,
                    'scope_mode' => $importRun->scope_mode,
                    'seed_source' => 'first_wave_manifest',
                ],
                'reviewer_status' => $reviewerStatus,
                'reviewer_id' => null,
                'reviewed_at' => $reviewedAt,
                'ai_assistance' => [
                    'ingestion' => 'first_wave_publish_seed',
                    'allow_strong_claim_expected' => (bool) ($claimSeed['allow_strong_claim'] ?? false),
                ],
                'quality' => [
                    'confidence' => $confidenceScore / 100,
                    'confidence_score' => $confidenceScore,
                    'review_required' => false,
                ],
                'last_substantive_update_at' => now(),
                'next_review_due_at' => null,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_id' => $occupation->id,
                    'content_version' => 'career_first_wave.publish_seed.v1',
                    'reviewer_status' => $reviewerStatus,
                    'confidence_score' => $confidenceScore,
                ]),
            ];

            TrustManifest::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $trustPayload['row_fingerprint'],
                ],
                $trustPayload,
            );

            $previousIndexState = $occupation->indexStates()
                ->orderByDesc('changed_at')
                ->orderByDesc('updated_at')
                ->first();
            $lifecycle = $this->indexLifecycleCompiler->compile([
                'crosswalk_mode' => $occupation->crosswalk_mode,
                'confidence_score' => $confidenceScore,
                'reviewer_status' => $reviewerStatus,
                'raw_index_state' => $rawIndexState,
                'index_eligible' => $rawIndexEligible,
                'allow_strong_claim' => (bool) ($claimSeed['allow_strong_claim'] ?? false),
                'previous_index_state' => $previousIndexState?->index_state,
                'reason_codes' => $reasonCodes,
            ]);

            $indexPayload = [
                'occupation_id' => $occupation->id,
                'index_state' => $lifecycle['index_state'],
                'index_eligible' => $lifecycle['index_eligible'],
                'canonical_path' => '/career/jobs/'.$occupation->canonical_slug,
                'canonical_target' => null,
                'reason_codes' => $lifecycle['reason_codes'],
                'changed_at' => now(),
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_id' => $occupation->id,
                    'index_state' => $lifecycle['index_state'],
                    'index_eligible' => $lifecycle['index_eligible'],
                    'reason_codes' => $lifecycle['reason_codes'],
                ]),
            ];

            IndexState::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $indexPayload['row_fingerprint'],
                ],
                $indexPayload,
            );

            $applied++;
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'issues_by_slug' => $issuesBySlug,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded === false ? serialize($payload) : $encoded);
    }
}
