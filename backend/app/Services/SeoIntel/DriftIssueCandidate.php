<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final readonly class DriftIssueCandidate
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $issueType,
        public string $severity,
        public ?string $canonicalUrlHash,
        public ?string $locale,
        public ?string $pageEntityType,
        public ?string $entityIdOrSlug,
        public ?string $cluster,
        public string $summary,
        public string $recommendation,
        public array $metadata = [],
    ) {}

    public function issueUid(): string
    {
        return 'drift:'.hash('sha256', implode('|', [
            $this->issueType,
            $this->canonicalUrlHash ?? 'none',
            $this->locale ?? 'none',
            $this->pageEntityType ?? 'none',
            $this->entityIdOrSlug ?? 'none',
        ]));
    }

    public function evidenceHash(): string
    {
        return hash('sha256', json_encode([
            'issue_type' => $this->issueType,
            'canonical_url_hash' => $this->canonicalUrlHash,
            'locale' => $this->locale,
            'page_entity_type' => $this->pageEntityType,
            'entity_id_or_slug_hash' => $this->entityIdOrSlug === null ? null : hash('sha256', $this->entityIdOrSlug),
            'metadata' => $this->metadata,
        ], JSON_THROW_ON_ERROR));
    }
}
