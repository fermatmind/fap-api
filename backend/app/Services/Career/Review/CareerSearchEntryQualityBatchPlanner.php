<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;

/** @review-surface career_trust_manifest */
final class CareerSearchEntryQualityBatchPlanner
{
    public const SCHEMA_VERSION = 'career.search_entry_quality_batch.v1';

    public function __construct(
        private readonly CareerSearchEntryQualityBatchManifestReader $manifestReader,
        private readonly CareerSearchEntryQualityEvaluator $evaluator,
        private readonly CareerPilotReviewEvidenceBridge $reviewBridge,
        private readonly ReviewAttestationCanonicalizer $canonicalizer,
    ) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $this->evaluator->resetEvaluationSnapshot();
        $manifest = $this->manifestReader->read();
        $manifestSlugs = array_column($manifest['candidates'], 'canonical_slug');
        $this->evaluator->primePublicationSnapshot($manifestSlugs);
        $evaluations = array_map(
            fn (array $candidate): array => $this->evaluator->evaluate($candidate['canonical_slug']),
            $manifest['candidates'],
        );
        $rejected = array_values(array_filter(
            $evaluations,
            static fn (array $evaluation): bool => $evaluation['blockers'] !== [],
        ));
        if ($rejected !== []) {
            $summary = array_map(
                static fn (array $evaluation): string => $evaluation['canonical_slug'].'='
                    .implode(',', $evaluation['blockers']),
                $rejected,
            );
            throw new \RuntimeException(
                'Career search-entry quality batch has insufficient qualified candidates: '.implode('; ', $summary),
            );
        }

        usort($evaluations, static function (array $left, array $right): int {
            $trackRank = static fn (string $track): int => $track === 'stable' ? 0 : 1;

            return [
                $trackRank($left['publish_track']),
                -$left['quality_score'],
                $left['canonical_slug'],
            ] <=> [
                $trackRank($right['publish_track']),
                -$right['quality_score'],
                $right['canonical_slug'],
            ];
        });

        $slugs = array_column($evaluations, 'canonical_slug');
        if (count($slugs) !== $manifest['expected_candidate_count']
            || count($slugs) > CareerSearchEntryQualityBatchManifestReader::MAX_CANDIDATES) {
            throw new \RuntimeException('Career search-entry quality batch candidate count failed closed.');
        }

        $reviewPackage = $this->reviewBridge->buildPackage(
            $slugs,
            $this->evaluator->publicationSnapshot($slugs),
            $this->evaluator->indexSnapshot($slugs),
        );
        $targetsBySlug = [];
        foreach ($reviewPackage['targets'] as $target) {
            $parts = explode(':', $target['identity']);
            if (count($parts) !== 4) {
                throw new \RuntimeException('Career search-entry quality review target identity is invalid.');
            }
            $targetsBySlug[$parts[1]][] = $target;
        }

        $candidates = [];
        foreach ($evaluations as $rank => $evaluation) {
            $slug = $evaluation['canonical_slug'];
            $reviewTargets = $targetsBySlug[$slug] ?? [];
            $currentTargetShas = $this->currentTargetShas($slug, $reviewTargets);
            $candidates[] = [
                'selection_rank' => $rank + 1,
                ...$evaluation,
                'review_state' => 'awaiting_exact_approved_all_binding',
                'search_entry_tier' => CareerSearchEntryTierResolver::TIER_INELIGIBLE,
                'current_content_sha256_by_locale' => $currentTargetShas['content'],
                'current_seo_sha256_by_locale' => $currentTargetShas['seo'],
                'review_target_sha256_by_identity' => array_column($reviewTargets, 'sha256', 'identity'),
                'review_targets' => $reviewTargets,
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task_id' => CareerSearchEntryQualityBatchManifestReader::TASK_ID,
            'mode' => 'dry_run',
            'selection_policy' => 'stable_then_approved_candidate_then_quality_score_desc_then_slug',
            'candidate_count' => count($candidates),
            'bilingual_url_count' => count($candidates) * 2,
            'slugs' => $slugs,
            'canonical_urls' => array_values(array_merge(...array_map(
                static fn (array $candidate): array => array_values($candidate['canonical_urls']),
                $candidates,
            ))),
            'candidates' => $candidates,
            'review_scope_identity' => $reviewPackage['scope_identity'],
            'target_count' => $reviewPackage['target_count'],
            'target_set_sha256' => $reviewPackage['target_set_sha256'],
            'package_sha256' => $reviewPackage['package_sha256'],
            'index_item_sha256_by_slug' => $reviewPackage['index_item_sha256_by_slug'],
            'negative_guarantees' => [
                'database_writes' => 0,
                'cms_writes' => 0,
                'cache_writes' => 0,
                'queue_dispatches' => 0,
                'publication_writes' => 0,
                'indexability_writes' => 0,
                'sitemap_writes' => 0,
                'llms_writes' => 0,
                'search_channel_actions' => 0,
                'url_submissions' => 0,
                'deploys' => 0,
                'held_slug_releases' => 0,
            ],
        ];
        $payload['quality_package_sha256'] = hash('sha256', $this->canonicalizer->encode($payload));

        return $payload;
    }

    /** @param array<string,mixed> $expected @return array<string,mixed> */
    public function verify(array $expected): array
    {
        foreach (['status', 'output_path', 'expected_package_verified'] as $envelopeField) {
            unset($expected[$envelopeField]);
        }
        $claimedExpectedSha = $expected['quality_package_sha256'] ?? null;
        $unsignedExpected = $expected;
        unset($unsignedExpected['quality_package_sha256']);
        $computedExpectedSha = hash('sha256', $this->canonicalizer->encode($unsignedExpected));
        if (! is_string($claimedExpectedSha)
            || ! hash_equals($computedExpectedSha, $claimedExpectedSha)) {
            throw new \RuntimeException(
                'Career search-entry quality expected package authentication failed.',
            );
        }

        $current = $this->build();
        foreach ([
            'quality_package_sha256',
            'package_sha256',
            'target_set_sha256',
            'candidate_count',
            'bilingual_url_count',
            'slugs',
            'canonical_urls',
        ] as $field) {
            if (($expected[$field] ?? null) !== $current[$field]) {
                throw new \RuntimeException('Career search-entry quality batch drift detected at '.$field.'.');
            }
        }
        if (! hash_equals(
            hash('sha256', $this->canonicalizer->encode($current)),
            hash('sha256', $this->canonicalizer->encode($expected)),
        )) {
            throw new \RuntimeException(
                'Career search-entry quality batch drift detected in complete package.',
            );
        }

        return $current;
    }

    /**
     * @param  list<array{identity:string,sha256:string}>  $reviewTargets
     * @return array{content:array{en:string,zh-CN:string},seo:array{en:string,zh-CN:string}}
     */
    private function currentTargetShas(string $slug, array $reviewTargets): array
    {
        if (count($reviewTargets) !== 6) {
            throw new \RuntimeException('Career search-entry quality candidate review target count is invalid.');
        }

        $resolved = ['content' => [], 'seo' => []];
        foreach ($reviewTargets as $target) {
            $parts = explode(':', $target['identity']);
            if (count($parts) !== 4 || $parts[1] !== $slug) {
                throw new \RuntimeException('Career search-entry quality candidate review target identity is invalid.');
            }
            [, , $locale, $kind] = $parts;
            if (isset($resolved[$kind]) && in_array($locale, ['en', 'zh-CN'], true)) {
                $resolved[$kind][$locale] = $target['sha256'];
            }
        }

        foreach (['content', 'seo'] as $kind) {
            foreach (['en', 'zh-CN'] as $locale) {
                $sha = $resolved[$kind][$locale] ?? null;
                if (! is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
                    throw new \RuntimeException(
                        "Career search-entry quality candidate current {$kind} SHA is invalid.",
                    );
                }
            }
            $resolved[$kind] = [
                'en' => $resolved[$kind]['en'],
                'zh-CN' => $resolved[$kind]['zh-CN'],
            ];
        }

        /** @var array{content:array{en:string,zh-CN:string},seo:array{en:string,zh-CN:string}} $resolved */
        return $resolved;
    }
}
