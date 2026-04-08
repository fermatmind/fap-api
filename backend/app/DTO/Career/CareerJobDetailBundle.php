<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerJobDetailBundle
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $localePolicy
     * @param  array<string, mixed>  $titles
     * @param  list<array<string, mixed>>  $aliasIndex
     * @param  array<string, mixed>  $ontology
     * @param  array<string, mixed>  $truthLayer
     * @param  array<string, mixed>  $trustManifest
     * @param  array<string, mixed>  $scoreBundle
     * @param  array<string, mixed>  $warnings
     * @param  array<string, mixed>  $claimPermissions
     * @param  array<string, mixed>  $integritySummary
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     */
    public function __construct(
        public readonly array $identity,
        public readonly array $localePolicy,
        public readonly array $titles,
        public readonly array $aliasIndex,
        public readonly array $ontology,
        public readonly array $truthLayer,
        public readonly array $trustManifest,
        public readonly array $scoreBundle,
        public readonly array $warnings,
        public readonly array $claimPermissions,
        public readonly array $integritySummary,
        public readonly array $seoContract,
        public readonly array $provenanceMeta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bundle_kind' => 'career_job_detail',
            'bundle_version' => 'career.protocol.job_detail.v1',
            'identity' => $this->identity,
            'locale_policy' => $this->localePolicy,
            'titles' => $this->titles,
            'alias_index' => $this->aliasIndex,
            'ontology' => $this->ontology,
            'truth_layer' => $this->truthLayer,
            'trust_manifest' => $this->trustManifest,
            'score_bundle' => $this->scoreBundle,
            'warnings' => $this->warnings,
            'claim_permissions' => $this->claimPermissions,
            'integrity_summary' => $this->integritySummary,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];
    }
}
