<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJob;
use Closure;
use Throwable;

final class CareerRuntimeReadModelService
{
    /**
     * @var list<string>
     */
    public const PUBLIC_LOCALES = ['en', 'zh'];

    /**
     * @var list<string>
     */
    public const EXCLUDED_SLUGS = [
        'software-developers',
        'digital-forensics-analysts',
        'computer-occupations-all-other',
    ];

    public function __construct(
        private readonly CareerRuntimePublishProjectionVisibility $runtimeProjection,
        private readonly ?Closure $legacyCmsCareerJobCountResolver = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $runtimeSlugs = $this->runtimePublicSlugs();
        $localizedPublicUrlCount = count($runtimeSlugs) * count(self::PUBLIC_LOCALES);

        return [
            'read_model_kind' => 'career_runtime_projection_ops_read_model',
            'read_model_version' => 'career.runtime_projection.ops_read_model.v1',
            'source_of_truth' => 'career_runtime_publish_projection',
            'legacy_cms_scope_label' => 'legacy_cms_career_jobs_table_scope',
            'runtime_scope_label' => 'runtime_projection_public_career_detail_scope',
            'legacy_cms_career_jobs_count' => $this->legacyCmsCareerJobCount(),
            'runtime_public_career_slug_count' => count($runtimeSlugs),
            'localized_public_career_url_count' => $localizedPublicUrlCount,
            'sitemap_career_url_count_expected' => $localizedPublicUrlCount,
            'llms_career_url_count_expected' => $localizedPublicUrlCount,
            'public_locales' => self::PUBLIC_LOCALES,
            'excluded_slugs' => self::EXCLUDED_SLUGS,
            'excluded_slugs_absent' => $this->excludedSlugAbsence($runtimeSlugs),
            'search_channel_action_performed' => false,
            'url_submission_performed' => false,
            'production_write_performed' => false,
            'public_runtime_mutation_performed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function runtimePublicSlugs(): array
    {
        $slugs = [];

        foreach ($this->runtimeProjection->publicDetailItems() as $item) {
            $slug = $this->normalizeSlug((string) ($item['slug'] ?? ''));
            if ($slug === null) {
                continue;
            }

            $slugs[$slug] = $slug;
        }

        ksort($slugs);

        return array_values($slugs);
    }

    private function legacyCmsCareerJobCount(): ?int
    {
        if ($this->legacyCmsCareerJobCountResolver instanceof Closure) {
            return (int) ($this->legacyCmsCareerJobCountResolver)();
        }

        try {
            return CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $runtimeSlugs
     * @return array<string, bool>
     */
    private function excludedSlugAbsence(array $runtimeSlugs): array
    {
        $slugSet = array_fill_keys($runtimeSlugs, true);
        $result = [];

        foreach (self::EXCLUDED_SLUGS as $slug) {
            $result[$slug] = ! isset($slugSet[$slug])
                && ! $this->runtimeProjection->detailRouteEnabled($slug)
                && ! $this->runtimeProjection->datasetVisible($slug)
                && ! $this->runtimeProjection->searchVisible($slug);
        }

        return $result;
    }

    private function normalizeSlug(string $slug): ?string
    {
        $normalized = strtolower(trim($slug));

        return $normalized === '' ? null : $normalized;
    }
}
