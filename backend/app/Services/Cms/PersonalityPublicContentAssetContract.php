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
            'entity_key' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_\\-.\\/]*$/i'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9\\-\\/]*$/i'],
            'locale' => ['required', Rule::in(PersonalityPublicContentAsset::SUPPORTED_LOCALES)],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'content_sections' => ['required', 'array'],
            'content_sections.*.key' => ['required_with:content_sections', 'string', 'max:96'],
            'content_sections.*.title' => ['nullable', 'string', 'max:255'],
            'content_sections.*.body_md' => ['nullable', 'string'],
            'seo' => ['required', 'array'],
            'canonical' => ['required', 'array'],
            'hreflang' => ['present', 'array'],
            'faq' => ['present', 'array'],
            'media' => ['present', 'array'],
            'schema' => ['present', 'array'],
            'method_boundary' => ['present', 'array'],
            'evidence_notes' => ['present', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'index_eligible' => ['nullable', 'boolean'],
            'sitemap_eligible' => ['nullable', 'boolean'],
            'llms_eligible' => ['nullable', 'boolean'],
            'launch_state' => ['nullable', Rule::in(PersonalityPublicContentAsset::LAUNCH_STATES)],
            'review_state' => ['nullable', 'string', 'max:32'],
            'contract_version' => ['nullable', 'string', 'max:64'],
            'source_package' => ['nullable', 'string', 'max:160'],
            'source_hash' => ['nullable', 'string', 'max:64'],
        ]);

        $validator->after(function ($validator) use ($normalized): void {
            $this->validateFrameworkEntityPair($validator, $normalized);
            $this->validateLaunchGate($validator, $normalized);
            $this->validateForbiddenProgrammaticPages($validator, $normalized);
            $this->validateNoPrivateResultModules($validator, $normalized);
            $this->validateCanonicalForIndexable($validator, $normalized);
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
        $payload['entity_key'] = PersonalityPublicContentAsset::normalizeEntityKey((string) ($payload['entity_key'] ?? ''));
        $payload['slug'] = PersonalityPublicContentAsset::normalizeSlug((string) ($payload['slug'] ?? ''));
        $payload['locale'] = PersonalityPublicContentAsset::normalizeLocale((string) ($payload['locale'] ?? 'en'));
        $payload['content_sections'] = is_array($payload['content_sections'] ?? null) ? $payload['content_sections'] : [];
        $payload['seo'] = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $payload['canonical'] = is_array($payload['canonical'] ?? null) ? $payload['canonical'] : [];
        $payload['hreflang'] = is_array($payload['hreflang'] ?? null) ? $payload['hreflang'] : [];
        $payload['faq'] = is_array($payload['faq'] ?? null) ? $payload['faq'] : [];
        $payload['media'] = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $payload['schema'] = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
        $payload['method_boundary'] = is_array($payload['method_boundary'] ?? null) ? $payload['method_boundary'] : [];
        $payload['evidence_notes'] = is_array($payload['evidence_notes'] ?? null) ? $payload['evidence_notes'] : [];
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
        $indexEligible = (bool) ($payload['index_eligible'] ?? false);
        $sitemapEligible = (bool) ($payload['sitemap_eligible'] ?? false);
        $llmsEligible = (bool) ($payload['llms_eligible'] ?? false);

        if ($indexEligible && $launchState !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED) {
            $validator->errors()->add('index_eligible', 'index_eligible=true requires launch_state=published.');
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
}
