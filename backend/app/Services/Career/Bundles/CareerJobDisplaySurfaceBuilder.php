<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerJobDetailBundle;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use Illuminate\Support\Str;

final class CareerJobDisplaySurfaceBuilder
{
    private const PILOT_SLUGS = [
        'actors',
        'data-scientists',
        'registered-nurses',
        'accountants-and-auditors',
    ];

    private const READY_STATUS = 'ready_for_pilot';

    private const ASSET_TYPE = 'career_job_public_display';

    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function buildForBundle(CareerJobDetailBundle $bundle, string $locale): ?array
    {
        $identity = $bundle->identity;
        $slug = strtolower((string) ($identity['canonical_slug'] ?? ''));
        if (! $this->isPilotSlug($slug)) {
            return null;
        }

        $occupationUuid = (string) ($identity['occupation_uuid'] ?? '');
        $query = Occupation::query()->where('canonical_slug', $slug);

        if (Str::isUuid($occupationUuid)) {
            $query->whereKey($occupationUuid);
        }

        $occupation = $query->first();
        if (! $occupation instanceof Occupation) {
            return null;
        }

        return $this->buildForOccupation($occupation, $locale);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildForOccupation(Occupation $occupation, string $locale): ?array
    {
        $canonicalSlug = strtolower((string) $occupation->canonical_slug);
        if (! $this->isPilotSlug($canonicalSlug)) {
            return null;
        }

        $asset = $occupation->displayAssets()
            ->where('canonical_slug', $canonicalSlug)
            ->where('status', self::READY_STATUS)
            ->where('asset_type', self::ASSET_TYPE)
            ->orderByDesc('updated_at')
            ->first();

        if (! $asset instanceof CareerJobDisplayAsset) {
            return null;
        }

        $normalizedLocale = $this->normalizeLocale($locale);
        $localizedPages = $this->localizedPages($asset);
        $pageContent = $localizedPages[$normalizedLocale] ?? null;
        if (! is_array($pageContent)) {
            return null;
        }

        return [
            'surface_version' => (string) $asset->surface_version,
            'asset_version' => (string) $asset->asset_version,
            'template_version' => (string) $asset->template_version,
            'asset_type' => (string) $asset->asset_type,
            'asset_role' => (string) $asset->asset_role,
            'status' => (string) $asset->status,
            'subject' => $this->subject($occupation),
            'available_locales' => $this->availableLocales($localizedPages),
            'page' => [
                'locale' => $this->publicLocale($normalizedLocale),
                'content' => $this->stripForbiddenKeys($pageContent),
            ],
            'component_order' => $this->stripForbiddenKeys($asset->component_order_json ?? []),
            'sources' => $this->stripForbiddenKeys($asset->sources_json ?? []),
            'structured_data_from_visible_content' => $this->stripForbiddenKeys($asset->structured_data_json ?? []),
            'implementation_contract' => $this->stripForbiddenKeys($asset->implementation_contract_json ?? []),
        ];
    }

    private function isPilotSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::PILOT_SLUGS, true);
    }

    private function normalizeLocale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            default => 'zh',
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function localizedPages(CareerJobDisplayAsset $asset): array
    {
        $payload = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $normalized = [];

        foreach ($pages as $locale => $content) {
            if (! is_string($locale) || ! is_array($content)) {
                continue;
            }

            $normalized[$this->normalizeLocale($locale)] = $content;
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $localizedPages
     * @return list<string>
     */
    private function availableLocales(array $localizedPages): array
    {
        $locales = [];
        foreach (array_keys($localizedPages) as $locale) {
            $locales[] = $this->publicLocale((string) $locale);
        }

        return array_values(array_unique($locales));
    }

    private function publicLocale(string $normalizedLocale): string
    {
        return $normalizedLocale === 'en' ? 'en' : 'zh-CN';
    }

    /**
     * @return array<string, mixed>
     */
    private function subject(Occupation $occupation): array
    {
        $occupation->loadMissing('crosswalks');

        return [
            'occupation_uuid' => (string) $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'soc_code' => $this->firstCrosswalkCode($occupation, '/^\d{2}-\d{4}$/'),
            'onet_code' => $this->firstCrosswalkCode($occupation, '/^\d{2}-\d{4}\.\d{2}$/'),
        ];
    }

    private function firstCrosswalkCode(Occupation $occupation, string $pattern): ?string
    {
        /** @var OccupationCrosswalk $crosswalk */
        foreach ($occupation->crosswalks as $crosswalk) {
            $code = is_string($crosswalk->source_code) ? $crosswalk->source_code : null;
            if ($code !== null && preg_match($pattern, $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function stripForbiddenKeys(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->stripForbiddenKeys($value) : $value;
        }

        return $clean;
    }
}
