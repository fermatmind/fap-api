<?php

declare(strict_types=1);

namespace App\DTO\Personality;

use App\Models\PersonalityPublicContentAsset;

final readonly class PersonalityPublicContentAssetData
{
    /**
     * @param  array<int,array<string,mixed>>  $contentSections
     * @param  array<string,mixed>  $seo
     * @param  array<string,mixed>  $canonical
     * @param  array<string,mixed>  $hreflang
     * @param  array<int,array<string,mixed>>  $faq
     * @param  array<string,mixed>  $media
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $methodBoundary
     * @param  array<int,array<string,mixed>>  $evidenceNotes
     */
    public function __construct(
        public int $orgId,
        public string $framework,
        public string $entityType,
        public string $entityKey,
        public string $slug,
        public string $locale,
        public string $title,
        public ?string $summary,
        public array $contentSections,
        public array $seo,
        public array $canonical,
        public array $hreflang,
        public array $faq,
        public array $media,
        public array $schema,
        public array $methodBoundary,
        public array $evidenceNotes,
        public bool $isPublic,
        public bool $indexEligible,
        public bool $sitemapEligible,
        public bool $llmsEligible,
        public string $launchState,
        public string $reviewState,
        public string $contractVersion,
        public ?string $sourcePackage,
        public ?string $sourceHash,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromValidatedPayload(array $payload): self
    {
        return new self(
            orgId: max(0, (int) ($payload['org_id'] ?? 0)),
            framework: PersonalityPublicContentAsset::normalizeToken((string) $payload['framework']),
            entityType: PersonalityPublicContentAsset::normalizeToken((string) $payload['entity_type']),
            entityKey: PersonalityPublicContentAsset::normalizeEntityKey((string) $payload['entity_key']),
            slug: PersonalityPublicContentAsset::normalizeSlug((string) $payload['slug']),
            locale: PersonalityPublicContentAsset::normalizeLocale((string) $payload['locale']),
            title: trim((string) $payload['title']),
            summary: self::nullableString($payload['summary'] ?? null),
            contentSections: array_values(is_array($payload['content_sections'] ?? null) ? $payload['content_sections'] : []),
            seo: is_array($payload['seo'] ?? null) ? $payload['seo'] : [],
            canonical: is_array($payload['canonical'] ?? null) ? $payload['canonical'] : [],
            hreflang: is_array($payload['hreflang'] ?? null) ? $payload['hreflang'] : [],
            faq: array_values(is_array($payload['faq'] ?? null) ? $payload['faq'] : []),
            media: is_array($payload['media'] ?? null) ? $payload['media'] : [],
            schema: is_array($payload['schema'] ?? null) ? $payload['schema'] : [],
            methodBoundary: is_array($payload['method_boundary'] ?? null) ? $payload['method_boundary'] : [],
            evidenceNotes: array_values(is_array($payload['evidence_notes'] ?? null) ? $payload['evidence_notes'] : []),
            isPublic: (bool) ($payload['is_public'] ?? true),
            indexEligible: (bool) ($payload['index_eligible'] ?? false),
            sitemapEligible: (bool) ($payload['sitemap_eligible'] ?? false),
            llmsEligible: (bool) ($payload['llms_eligible'] ?? false),
            launchState: PersonalityPublicContentAsset::normalizeLaunchState((string) ($payload['launch_state'] ?? PersonalityPublicContentAsset::LAUNCH_DRAFT)),
            reviewState: trim((string) ($payload['review_state'] ?? 'draft')) ?: 'draft',
            contractVersion: trim((string) ($payload['contract_version'] ?? PersonalityPublicContentAsset::CONTRACT_VERSION_V1)) ?: PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            sourcePackage: self::nullableString($payload['source_package'] ?? null),
            sourceHash: self::nullableString($payload['source_hash'] ?? null),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'org_id' => $this->orgId,
            'framework' => $this->framework,
            'entity_type' => $this->entityType,
            'entity_key' => $this->entityKey,
            'slug' => $this->slug,
            'locale' => $this->locale,
            'title' => $this->title,
            'summary' => $this->summary,
            'content_sections_json' => $this->contentSections,
            'seo_json' => $this->seo,
            'canonical_json' => $this->canonical,
            'hreflang_json' => $this->hreflang,
            'faq_json' => $this->faq,
            'media_json' => $this->media,
            'schema_json' => $this->schema,
            'method_boundary_json' => $this->methodBoundary,
            'evidence_notes_json' => $this->evidenceNotes,
            'is_public' => $this->isPublic,
            'index_eligible' => $this->indexEligible,
            'sitemap_eligible' => $this->sitemapEligible,
            'llms_eligible' => $this->llmsEligible,
            'launch_state' => $this->launchState,
            'review_state' => $this->reviewState,
            'contract_version' => $this->contractVersion,
            'source_package' => $this->sourcePackage,
            'source_hash' => $this->sourceHash,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
