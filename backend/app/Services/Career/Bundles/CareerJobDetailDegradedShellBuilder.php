<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\Display\CareerDisplayAssetComponentContract;

final class CareerJobDetailDegradedShellBuilder
{
    /**
     * Build a projection-only recovery shell. This path must remain free of
     * database, CMS, scoring, evidence, and SEO authority assembly reads.
     *
     * @param  array<string, mixed>  $projectionItem
     * @return array<string, mixed>
     */
    public function build(string $slug, string $publicLocale, array $projectionItem): array
    {
        $normalizedSlug = strtolower(trim($slug));
        $locale = str_starts_with(strtolower(trim($publicLocale)), 'zh') ? 'zh-CN' : 'en';
        $isZh = $locale === 'zh-CN';
        $pathPrefix = $isZh ? '/zh' : '/en';
        $path = $pathPrefix.'/career/jobs/'.$normalizedSlug;
        $directoryPath = $pathPrefix.'/career/jobs';
        $titleEn = $this->projectionTitle($projectionItem, ['canonical_title_en', 'title_en'])
            ?? str($normalizedSlug)->replace('-', ' ')->title()->toString();
        $titleZh = $this->projectionTitle($projectionItem, ['canonical_title_zh', 'title_zh']);
        $title = $isZh ? ($titleZh ?? $titleEn) : $titleEn;
        $recoveryMessage = $isZh
            ? '完整职业详情正在恢复，请稍后重试或先返回职业库。'
            : 'The full career detail is recovering. Please retry shortly or return to the career directory.';

        return [
            'bundle_kind' => 'career_job_detail',
            'bundle_version' => 'career.protocol.job_detail.degraded.v1',
            'identity' => [
                'canonical_slug' => $normalizedSlug,
            ],
            'titles' => array_filter([
                'canonical_en' => $titleEn,
                'canonical_zh' => $titleZh,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'locale_policy' => [
                'requested_locale' => $locale,
                'available_locales' => [$locale],
                'locale_warning' => 'detail_projection_cache_recovering',
                'truth_notice_required' => true,
            ],
            'truth_layer' => [],
            'warnings' => [
                'red_flags' => [],
                'amber_flags' => ['detail_projection_cache_recovering'],
                'blocked_claims' => ['full_career_detail_unavailable'],
            ],
            'claim_permissions' => [
                'integrity_state' => 'restricted',
                'allow_strong_claim' => false,
                'allow_ai_strategy' => false,
                'allow_salary_comparison' => false,
                'allow_market_signal' => false,
                'allow_local_proxy_wage' => false,
                'blocked_claims' => ['full_career_detail_unavailable'],
            ],
            'seo_contract' => [
                'canonical_path' => $path,
                'index_state' => 'degraded_cache_recovery',
                'index_eligible' => false,
                'robots_policy' => 'noindex,follow',
                'reason_codes' => ['detail_projection_cache_recovering'],
                'dataset_eligible' => false,
                'article_eligible' => false,
            ],
            'integrity_summary' => [
                'integrity_state' => 'restricted',
                'critical_missing_fields' => ['detail_projection_cache'],
                'confidence_cap' => 0,
                'degradation_factor' => 0,
            ],
            'structured_data' => [
                'occupation' => [],
                'breadcrumb_list' => [],
            ],
            'detail_availability_v1' => [
                'state' => 'recovering',
                'retryable' => true,
                'reason_code' => 'detail_projection_cache_miss',
            ],
            'display_surface_v1' => $this->displaySurface(
                $normalizedSlug,
                $locale,
                $path,
                $directoryPath,
                $title,
                $recoveryMessage,
                $projectionItem,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $projectionItem
     * @param  list<string>  $keys
     */
    private function projectionTitle(array $projectionItem, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $projectionItem[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $projectionItem
     * @return array<string, mixed>
     */
    private function displaySurface(
        string $slug,
        string $locale,
        string $path,
        string $directoryPath,
        string $title,
        string $recoveryMessage,
        array $projectionItem,
    ): array {
        $isZh = $locale === 'zh-CN';

        return [
            'surface_version' => 'display.surface.v1',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'subject' => [
                'canonical_slug' => $slug,
            ],
            'available_locales' => [$locale],
            'claim_permissions' => [
                'integrity_state' => 'restricted',
                'allow_strong_claim' => false,
                'allow_ai_strategy' => false,
                'allow_salary_comparison' => false,
                'allow_market_signal' => false,
                'allow_local_proxy_wage' => false,
                'blocked_claims' => ['detail_projection_cache_recovering'],
                'warnings' => [$recoveryMessage],
                'evidence_basis' => [
                    'salary' => 'missing',
                    'ai_exposure' => 'missing',
                    'market_signal' => 'missing',
                    'crosswalk' => 'missing',
                ],
            ],
            'page' => [
                'locale' => $locale,
                'content' => [
                    'path' => $path,
                    'hero' => [
                        'h1' => $title,
                        'title' => $title,
                        'quick_answer' => $recoveryMessage,
                        'primary_cta' => [
                            'label' => $isZh ? '返回职业库' : 'Return to career directory',
                            'href' => $directoryPath,
                        ],
                    ],
                    'definition_block' => $recoveryMessage,
                    'next_steps_block' => [
                        'steps' => [[
                            'title' => $isZh ? '详情恢复中' : 'Detail recovery in progress',
                            'items' => [$recoveryMessage],
                        ]],
                    ],
                    'boundary_notice' => [
                        'body' => $isZh
                            ? '恢复期间不展示未经验证的职业内容、薪资、录用或发展结论。'
                            : 'Unverified career content, salary, hiring, and outcome claims remain hidden during recovery.',
                    ],
                    'final_cta' => [
                        'label' => $isZh ? '返回职业库' : 'Return to career directory',
                        'href' => $directoryPath,
                    ],
                ],
            ],
            'component_order' => CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
            'sources' => [[
                'key' => 'runtime_publish_projection',
                'label' => 'Career runtime publish projection',
                'usage' => 'Route authority only; full detail projection is recovering.',
            ]],
            'structured_data_from_visible_content' => [],
            'implementation_contract' => [
                'authority' => 'runtime_publish_projection',
                'projection_state' => $projectionItem['runtime_publish_state'] ?? null,
                'release_gate_pass' => (bool) ($projectionItem['release_gate_pass'] ?? false),
                'surface_policy' => 'restricted_cache_recovery_shell',
            ],
        ];
    }
}
