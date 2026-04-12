<?php

declare(strict_types=1);

namespace App\Domain\Career\Links;

use App\Models\PersonalityProfile;
use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;

final class CareerRecommendationSupportLinkageBuilder
{
    public function __construct(
        private readonly CareerRecommendationDetailBundleBuilder $recommendationDetailBundleBuilder,
        private readonly CareerCanonicalSupportRouteRegistry $canonicalSupportRouteRegistry,
    ) {}

    /**
     * @return array{
     *     subject_kind:string,
     *     subject_identity:array{
     *         type_code:?string,
     *         canonical_type_code:?string,
     *         public_route_slug:?string,
     *         display_title:?string
     *     },
     *     support_links:list<array{
     *         subject_kind:string,
     *         subject_identity:array<string, ?string>,
     *         route_kind:string,
     *         canonical_path:string,
     *         canonical_slug:string,
     *         link_reason_code:string
     *     }>
     * }|null
     */
    public function buildByType(string $type, string $locale = 'en'): ?array
    {
        $normalizedType = trim($type);
        if ($normalizedType === '') {
            return null;
        }

        $bundle = $this->recommendationDetailBundleBuilder->buildByType($normalizedType);
        if ($bundle === null) {
            return null;
        }

        $bundlePayload = $bundle->toArray();
        $subjectMeta = is_array($bundlePayload['recommendation_subject_meta'] ?? null)
            ? $bundlePayload['recommendation_subject_meta']
            : [];

        $subjectIdentity = [
            'type_code' => $this->normalizeNullableString($subjectMeta['type_code'] ?? null),
            'canonical_type_code' => $this->normalizeNullableString($subjectMeta['canonical_type_code'] ?? null),
            'public_route_slug' => $this->normalizeNullableString($subjectMeta['public_route_slug'] ?? null),
            'display_title' => $this->normalizeNullableString($subjectMeta['display_title'] ?? null),
        ];

        if (! $this->isMbtiRecommendationSubject($subjectIdentity)) {
            return null;
        }

        $registryRows = collect($this->canonicalSupportRouteRegistry->list($locale))
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->keyBy(static fn (array $row): string => sprintf(
                '%s|%s',
                (string) ($row['route_kind'] ?? ''),
                strtolower(trim((string) data_get($row, 'metadata.scale_code', data_get($row, 'metadata.topic_code', ''))))
            ));

        $supportLinks = [];

        $testLandingRoute = $registryRows->get('test_landing|'.strtolower(PersonalityProfile::SCALE_CODE_MBTI));
        if (is_array($testLandingRoute)) {
            $supportLinks[] = $this->buildLinkageRow($subjectIdentity, $testLandingRoute, 'canonical_test_landing');
        }

        $topicRoute = $registryRows->get('topic_detail|mbti');
        if (is_array($topicRoute)) {
            $supportLinks[] = $this->buildLinkageRow($subjectIdentity, $topicRoute, 'canonical_topic_detail');
        }

        return [
            'subject_kind' => 'recommendation_subject',
            'subject_identity' => $subjectIdentity,
            'support_links' => array_values(collect($supportLinks)
                ->unique(static fn (array $row): string => sprintf(
                    '%s|%s|%s',
                    (string) ($row['route_kind'] ?? ''),
                    (string) ($row['canonical_path'] ?? ''),
                    (string) ($row['canonical_slug'] ?? ''),
                ))
                ->all()),
        ];
    }

    /**
     * @param  array<string, ?string>  $subjectIdentity
     * @param  array<string, mixed>  $registryRow
     * @return array{
     *     subject_kind:string,
     *     subject_identity:array<string, ?string>,
     *     route_kind:string,
     *     canonical_path:string,
     *     canonical_slug:string,
     *     link_reason_code:string
     * }
     */
    private function buildLinkageRow(array $subjectIdentity, array $registryRow, string $reasonCode): array
    {
        return [
            'subject_kind' => 'recommendation_subject',
            'subject_identity' => $subjectIdentity,
            'route_kind' => (string) ($registryRow['route_kind'] ?? ''),
            'canonical_path' => (string) ($registryRow['canonical_path'] ?? ''),
            'canonical_slug' => (string) ($registryRow['canonical_slug'] ?? ''),
            'link_reason_code' => $reasonCode,
        ];
    }

    /**
     * @param  array<string, ?string>  $subjectIdentity
     */
    private function isMbtiRecommendationSubject(array $subjectIdentity): bool
    {
        $canonicalTypeCode = strtoupper(trim((string) ($subjectIdentity['canonical_type_code'] ?? '')));
        if ($canonicalTypeCode !== '' && in_array($canonicalTypeCode, PersonalityProfile::BASE_TYPE_CODES, true)) {
            return true;
        }

        $runtimeTypeCode = strtoupper(trim((string) ($subjectIdentity['type_code'] ?? '')));
        if ($runtimeTypeCode !== '') {
            $runtimeBaseType = strtoupper(trim(strtok($runtimeTypeCode, '-') ?: $runtimeTypeCode));

            return in_array($runtimeBaseType, PersonalityProfile::BASE_TYPE_CODES, true);
        }

        return false;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
