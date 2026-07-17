<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\DTO\Personality\PersonalityPublicContentAssetData;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PersonalityPublicContentAssetContract
{
    public const SOURCE_TYPES = [
        'peer_reviewed_research',
        'official_documentation',
        'professional_standard',
        'book',
        'dataset',
        'other_public_source',
    ];

    private const FORBIDDEN_PROGRAMMATIC_PAGE_PATTERNS = [
        '/32[-_]?ocean/i',
        '/ocean[-_]?32/i',
        '/(?:^|[\s\/_-])54(?:[\s\/_-]|$)/i',
        '/wing[-_]?instinct/i',
        '/tritype/i',
    ];

    private const PRIVATE_RESULT_MODULE_PATTERNS = [
        '/private[_-]?result/i',
        '/result[_-]?page[_-]?module/i',
        '/report[_-]?module/i',
        '/entitlement/i',
    ];

    /**
     * @param  array<string,mixed>  $payload
     *
     * @throws ValidationException
     */
    public function validateAsset(array $payload): PersonalityPublicContentAssetData
    {
        $normalized = $this->withDefaults($payload);

        $validator = Validator::make($normalized, [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'framework' => ['required', Rule::in(PersonalityPublicContentAsset::FRAMEWORKS)],
            'entity_type' => ['required', Rule::in(PersonalityPublicContentAsset::ENTITY_TYPES)],
            'code' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_\\-.\\/]*$/i'],
            'entity_key' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_\\-.\\/]*$/i'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9\\-\\/]*$/i'],
            'locale' => ['required', Rule::in(PersonalityPublicContentAsset::SUPPORTED_LOCALES)],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'content_sections' => ['required', 'array'],
            'content_sections.*.key' => ['required_with:content_sections', 'string', 'max:96'],
            'content_sections.*.title' => ['nullable', 'string', 'max:255'],
            'content_sections.*.body_md' => ['nullable', 'string'],
            'content_sections.*.body_html' => ['nullable', 'string'],
            'seo' => ['required', 'array'],
            'seo.og_image_url' => ['prohibited'],
            'seo.twitter_image_url' => ['prohibited'],
            'seo.image' => ['prohibited'],
            'seo.image_url' => ['prohibited'],
            'seo.og.image' => ['prohibited'],
            'seo.og.image_url' => ['prohibited'],
            'seo.open_graph.image' => ['prohibited'],
            'seo.open_graph.image_url' => ['prohibited'],
            'seo.twitter.image' => ['prohibited'],
            'seo.twitter.image_url' => ['prohibited'],
            'seo.twitter_card.image' => ['prohibited'],
            'seo.twitter_card.image_url' => ['prohibited'],
            'robots' => ['required', Rule::in(PersonalityPublicContentAsset::ROBOTS_VALUES)],
            'canonical_path' => ['nullable', 'string', 'max:255'],
            'canonical' => ['required', 'array'],
            'hreflang' => ['present', 'array'],
            'faq' => ['present', 'array'],
            'media' => ['prohibited'],
            'media_authority' => ['prohibited'],
            'schema' => ['present', 'array'],
            'method_boundary' => ['present', 'array'],
            'evidence_notes' => ['present', 'array'],
            'authority' => ['present', 'array'],
            'authority.media' => ['prohibited'],
            'authority.media_authority' => ['prohibited'],
            'authority.media_deferred_by_operator' => ['prohibited'],
            'authority.sources' => ['sometimes', 'array'],
            'authority.sources.*' => ['array'],
            'authority.sources.*.id' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_.-]*$/i'],
            'authority.sources.*.title' => ['required', 'string', 'max:500'],
            'authority.sources.*.author_or_organization' => ['required', 'string', 'max:500'],
            'authority.sources.*.year' => ['required', 'integer', 'min:1800', 'max:'.now()->year],
            'authority.sources.*.source_type' => ['required', Rule::in(self::SOURCE_TYPES)],
            'authority.sources.*.doi' => ['nullable', 'string', 'max:255', 'regex:/^10\.\d{4,9}\/[\-._;()\/:a-z0-9]+$/i'],
            'authority.sources.*.public_url' => ['nullable', 'string', 'max:2048'],
            'authority.sources.*.accessed_at' => ['nullable', 'date'],
            'authority.sources.*.claim_ids' => ['present', 'array'],
            'authority.sources.*.claim_ids.*' => ['string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_.-]*$/i'],
            'authority.sources.*.limitation' => ['nullable', 'string', 'max:2000'],
            'authority.claim_mapping' => ['sometimes', 'array'],
            'authority.claim_mapping.*' => ['array'],
            'authority.claim_mapping.*.claim_id' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_.-]*$/i'],
            'authority.claim_mapping.*.source_ids' => ['present', 'array', 'min:1'],
            'authority.claim_mapping.*.source_ids.*' => ['string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_.-]*$/i'],
            'authority.claim_mapping.*.limitation' => ['nullable', 'string', 'max:2000'],
            'authority.limitations' => ['sometimes', 'array'],
            'authority.limitations.*' => ['string', 'max:2000'],
            'authority.author' => ['nullable', 'array'],
            'authority.author.name' => ['nullable', 'string', 'max:255'],
            'authority.author.organization' => ['nullable', 'string', 'max:255'],
            'authority.author.role' => ['nullable', 'string', 'max:255'],
            'authority.reviewer' => ['nullable', 'array'],
            'authority.reviewer.name' => ['nullable', 'string', 'max:255'],
            'authority.reviewer.organization' => ['nullable', 'string', 'max:255'],
            'authority.reviewer.role' => ['nullable', 'string', 'max:255'],
            'authority.visible_evidence_eligible' => ['sometimes', 'boolean'],
            'authority.schema_eligible' => ['sometimes', 'boolean'],
            'internal_links' => ['present', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'index_eligible' => ['nullable', 'boolean'],
            'sitemap_eligible' => ['nullable', 'boolean'],
            'llms_eligible' => ['nullable', 'boolean'],
            'launch_state' => ['nullable', Rule::in(PersonalityPublicContentAsset::LAUNCH_STATES)],
            'review_state' => ['nullable', 'string', 'max:32'],
            'contract_version' => ['nullable', Rule::in(PersonalityPublicContentAsset::CONTRACT_VERSIONS)],
            'source_package' => ['nullable', 'string', 'max:160'],
            'source_hash' => ['nullable', 'string', 'max:64'],
            'last_reviewed_at' => ['nullable', 'date'],
        ]);

        $validator->after(function ($validator) use ($normalized): void {
            $this->validateFrameworkEntityPair($validator, $normalized);
            $this->validateLaunchGate($validator, $normalized);
            $this->validateForbiddenProgrammaticPages($validator, $normalized);
            $this->validateNoPrivateResultModules($validator, $normalized);
            $this->validateNoContentImages($validator, $normalized);
            $this->validateCanonicalForIndexable($validator, $normalized);
            $this->validateAuthorityV2($validator, $normalized);
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return PersonalityPublicContentAssetData::fromValidatedPayload($validator->validated());
    }

    /**
     * @param  array<int,array<string,mixed>>  $assets
     * @return array{valid:list<PersonalityPublicContentAssetData>, errors:list<array{index:int, errors:array<string,mixed>}>}
     */
    public function validateMany(array $assets): array
    {
        $valid = [];
        $errors = [];

        foreach ($assets as $index => $asset) {
            try {
                $valid[] = $this->validateAsset(is_array($asset) ? $asset : []);
            } catch (ValidationException $exception) {
                $errors[] = [
                    'index' => $index,
                    'errors' => $exception->errors(),
                ];
            }
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withDefaults(array $payload): array
    {
        $payload['org_id'] = max(0, (int) ($payload['org_id'] ?? 0));
        $payload['framework'] = PersonalityPublicContentAsset::normalizeToken((string) ($payload['framework'] ?? ''));
        $payload['entity_type'] = PersonalityPublicContentAsset::normalizeToken((string) ($payload['entity_type'] ?? ''));
        $payload['code'] = PersonalityPublicContentAsset::normalizeEntityKey((string) ($payload['code'] ?? $payload['entity_key'] ?? ''));
        $payload['entity_key'] = PersonalityPublicContentAsset::normalizeEntityKey((string) ($payload['entity_key'] ?? $payload['code'] ?? ''));
        $payload['slug'] = PersonalityPublicContentAsset::normalizeSlug((string) ($payload['slug'] ?? ''));
        $payload['locale'] = PersonalityPublicContentAsset::normalizeLocale((string) ($payload['locale'] ?? 'en'));
        $payload['content_sections'] = is_array($payload['content_sections'] ?? null)
            ? $payload['content_sections']
            : (is_array($payload['sections'] ?? null) ? $payload['sections'] : []);
        $payload['seo'] = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $payload['robots'] = PersonalityPublicContentAsset::normalizeRobots((string) ($payload['robots'] ?? PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        if (! isset($payload['canonical']) && isset($payload['canonical_path'])) {
            $payload['canonical'] = ['path' => (string) $payload['canonical_path']];
        }
        $payload['canonical'] = is_array($payload['canonical'] ?? null) ? $payload['canonical'] : [];
        $payload['canonical_path'] = trim((string) ($payload['canonical_path'] ?? data_get($payload, 'canonical.path', '')));
        $payload['hreflang'] = is_array($payload['hreflang'] ?? null) ? $payload['hreflang'] : [];
        $payload['faq'] = is_array($payload['faq'] ?? null) ? $payload['faq'] : [];
        $payload['schema'] = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
        $payload['method_boundary'] = is_array($payload['method_boundary'] ?? null) ? $payload['method_boundary'] : [];
        $payload['evidence_notes'] = is_array($payload['evidence_notes'] ?? null) ? $payload['evidence_notes'] : [];
        $payload['internal_links'] = is_array($payload['internal_links'] ?? null) ? $payload['internal_links'] : [];
        $payload['is_public'] = (bool) ($payload['is_public'] ?? true);
        $payload['index_eligible'] = (bool) ($payload['index_eligible'] ?? false);
        $payload['sitemap_eligible'] = (bool) ($payload['sitemap_eligible'] ?? false);
        $payload['llms_eligible'] = (bool) ($payload['llms_eligible'] ?? false);
        $payload['launch_state'] = PersonalityPublicContentAsset::normalizeLaunchState(
            (string) ($payload['launch_state'] ?? PersonalityPublicContentAsset::LAUNCH_DRAFT)
        );
        $payload['review_state'] = trim((string) ($payload['review_state'] ?? 'draft')) ?: 'draft';
        $payload['contract_version'] = trim((string) ($payload['contract_version'] ?? PersonalityPublicContentAsset::CONTRACT_VERSION_V1))
            ?: PersonalityPublicContentAsset::CONTRACT_VERSION_V1;
        $payload['authority'] = is_array($payload['authority'] ?? null) ? $payload['authority'] : [];
        if ($payload['contract_version'] === PersonalityPublicContentAsset::CONTRACT_VERSION_V2) {
            $payload['authority']['sources'] = array_values(is_array($payload['authority']['sources'] ?? null)
                ? $payload['authority']['sources']
                : []);
            $payload['authority']['claim_mapping'] = array_values(is_array($payload['authority']['claim_mapping'] ?? null)
                ? $payload['authority']['claim_mapping']
                : []);
            $payload['authority']['limitations'] = array_values(is_array($payload['authority']['limitations'] ?? null)
                ? $payload['authority']['limitations']
                : []);
            $payload['authority']['author'] = is_array($payload['authority']['author'] ?? null)
                ? $payload['authority']['author']
                : null;
            $payload['authority']['reviewer'] = is_array($payload['authority']['reviewer'] ?? null)
                ? $payload['authority']['reviewer']
                : null;
            $payload['authority']['visible_evidence_eligible'] = (bool) ($payload['authority']['visible_evidence_eligible'] ?? false);
            $payload['authority']['schema_eligible'] = (bool) ($payload['authority']['schema_eligible'] ?? false);
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function validateFrameworkEntityPair($validator, array $payload): void
    {
        $framework = (string) ($payload['framework'] ?? '');
        $entityType = (string) ($payload['entity_type'] ?? '');
        $allowed = PersonalityPublicContentAsset::FRAMEWORK_ENTITY_TYPES[$framework] ?? [];

        if (! in_array($entityType, $allowed, true)) {
            $validator->errors()->add(
                'entity_type',
                sprintf('entity_type "%s" is not supported for framework "%s".', $entityType, $framework)
            );
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function validateLaunchGate($validator, array $payload): void
    {
        $launchState = (string) ($payload['launch_state'] ?? PersonalityPublicContentAsset::LAUNCH_DRAFT);
        $robots = PersonalityPublicContentAsset::normalizeRobots((string) ($payload['robots'] ?? PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        $indexEligible = (bool) ($payload['index_eligible'] ?? false);
        $sitemapEligible = (bool) ($payload['sitemap_eligible'] ?? false);
        $llmsEligible = (bool) ($payload['llms_eligible'] ?? false);

        if ($indexEligible && $launchState !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED) {
            $validator->errors()->add('index_eligible', 'index_eligible=true requires launch_state=published.');
        }

        if ($indexEligible && $robots !== PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW) {
            $validator->errors()->add('robots', 'index_eligible=true requires robots=index,follow.');
        }

        if ($robots === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW && (! $indexEligible || $launchState !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED)) {
            $validator->errors()->add('robots', 'robots=index,follow requires published index_eligible assets.');
        }

        if (($sitemapEligible || $llmsEligible) && (! $indexEligible || $launchState !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED)) {
            $validator->errors()->add('sitemap_eligible', 'sitemap/llms eligibility requires published index_eligible assets.');
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function validateForbiddenProgrammaticPages($validator, array $payload): void
    {
        $surface = implode(' ', [
            (string) ($payload['framework'] ?? ''),
            (string) ($payload['entity_type'] ?? ''),
            (string) ($payload['code'] ?? ''),
            (string) ($payload['entity_key'] ?? ''),
            (string) ($payload['slug'] ?? ''),
            (string) ($payload['title'] ?? ''),
        ]);

        foreach (self::FORBIDDEN_PROGRAMMATIC_PAGE_PATTERNS as $pattern) {
            if (preg_match($pattern, $surface) === 1) {
                $validator->errors()->add('entity_key', 'Forbidden programmatic personality page family is outside this contract.');

                return;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function validateNoPrivateResultModules($validator, array $payload): void
    {
        $serialized = (string) json_encode(
            $payload['content_sections'] ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        foreach (self::PRIVATE_RESULT_MODULE_PATTERNS as $pattern) {
            if (preg_match($pattern, $serialized) === 1) {
                $validator->errors()->add('content_sections', 'Public SEO content assets must not reference private result/report modules.');

                return;
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function validateNoContentImages($validator, array $payload): void
    {
        foreach ((array) ($payload['content_sections'] ?? []) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $bodyMd = (string) ($section['body_md'] ?? $section['bodyMd'] ?? $section['body'] ?? '');
            if (preg_match('/!\[[^\]]*\]\s*\([^\)]*\)/u', $bodyMd) === 1 || preg_match('/<img\b/iu', $bodyMd) === 1) {
                $validator->errors()->add("content_sections.{$index}.body_md", 'Personality public content does not support images.');
            }

            $bodyHtml = (string) ($section['body_html'] ?? $section['bodyHtml'] ?? '');
            if (preg_match('/<img\b/iu', $bodyHtml) === 1) {
                $validator->errors()->add("content_sections.{$index}.body_html", 'Personality public content does not support images.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function validateCanonicalForIndexable($validator, array $payload): void
    {
        if (! (bool) ($payload['index_eligible'] ?? false)) {
            return;
        }

        $path = trim((string) data_get($payload, 'canonical.path', ''));
        if ($path === '' || ! str_starts_with($path, '/')) {
            $validator->errors()->add('canonical.path', 'index_eligible assets require a canonical.path beginning with "/".');
        }
    }

    /** @param array<string,mixed> $payload */
    private function validateAuthorityV2($validator, array $payload): void
    {
        $authority = is_array($payload['authority'] ?? null) ? $payload['authority'] : [];
        $contractVersion = (string) ($payload['contract_version'] ?? PersonalityPublicContentAsset::CONTRACT_VERSION_V1);

        if ($authority !== [] && $contractVersion !== PersonalityPublicContentAsset::CONTRACT_VERSION_V2) {
            $validator->errors()->add('contract_version', 'Structured authority requires personality_public_asset.v2.');

            return;
        }

        if ($contractVersion !== PersonalityPublicContentAsset::CONTRACT_VERSION_V2) {
            return;
        }

        foreach (['author', 'reviewer'] as $actor) {
            $value = $authority[$actor] ?? null;
            if (is_array($value) && trim((string) ($value['name'] ?? '')) === '') {
                $validator->errors()->add("authority.{$actor}.name", "{$actor}.name is required when {$actor} authority is provided.");
            }
        }

        $sourceIds = [];
        foreach ((array) ($authority['sources'] ?? []) as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $sourceId = trim((string) ($source['id'] ?? ''));
            if ($sourceId !== '' && isset($sourceIds[$sourceId])) {
                $validator->errors()->add("authority.sources.{$index}.id", 'Source ids must be unique within one asset.');
            }
            if ($sourceId !== '') {
                $sourceIds[$sourceId] = true;
            }

            $publicUrl = trim((string) ($source['public_url'] ?? ''));
            if ($publicUrl !== '' && ! $this->isPublicHttpsUrl($publicUrl)) {
                $validator->errors()->add("authority.sources.{$index}.public_url", 'Source public_url must be a public HTTPS URL.');
            }
        }

        foreach ((array) ($authority['claim_mapping'] ?? []) as $index => $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            foreach ((array) ($mapping['source_ids'] ?? []) as $sourceId) {
                if (! isset($sourceIds[(string) $sourceId])) {
                    $validator->errors()->add(
                        "authority.claim_mapping.{$index}.source_ids",
                        'Every claim mapping source_id must resolve to authority.sources.'
                    );
                }
            }
        }

        $visibleEvidenceEligible = (bool) ($authority['visible_evidence_eligible'] ?? false);
        if ($visibleEvidenceEligible && ($sourceIds === [] || (array) ($authority['claim_mapping'] ?? []) === [])) {
            $validator->errors()->add(
                'authority.visible_evidence_eligible',
                'Visible evidence eligibility requires at least one structured source and claim mapping.'
            );
        }

        if ((bool) ($authority['schema_eligible'] ?? false)) {
            $schemaReady = $visibleEvidenceEligible
                && (bool) ($payload['index_eligible'] ?? false)
                && (string) ($payload['launch_state'] ?? '') === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
                && PersonalityPublicContentAsset::normalizeRobots((string) ($payload['robots'] ?? '')) === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW
                && (array) ($payload['schema'] ?? []) !== [];

            if (! $schemaReady) {
                $validator->errors()->add(
                    'authority.schema_eligible',
                    'Schema eligibility requires explicit visible evidence plus the existing published/indexable/schema gates.'
                );
            }
        }
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || (int) ($parts['port'] ?? 443) !== 443) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
