<?php

declare(strict_types=1);

namespace App\Services\Cms;

use RuntimeException;

final class MbtiCrossPublisher49Package
{
    public const PACKAGE_SHA256 = '604851b56031d22d48036e87a5358bf85c9e13268655dbe36d2ab798b3f58dae';

    public const AUTHORIZATION_SHA256 = 'be4f17484334074cf2c90d57898ab80b6074093b2510a4b7b4b0432a164b4670';

    public const PACKAGE_PATH = 'content_assets/personality_public/mbti-cross-approval-48-package-2026-07-23.json';

    public const AUTHORIZATION_PATH = 'content_assets/personality_public/mbti-cross-approval-48-operator-authorization-r2-2026-07-23.json';

    public const EXACT_SLUGS = [
        'enfp-vs-entp',
        'estj-vs-entj',
        'isfp-vs-infp',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorization
     * @return list<array<string,mixed>>
     */
    public function validate(array $package, array $authorization): array
    {
        $packageCore = $package;
        unset($packageCore['package_sha256']);
        $packageSha = $this->sha($packageCore);
        if (! hash_equals(self::PACKAGE_SHA256, $packageSha)
            || ! hash_equals(self::PACKAGE_SHA256, (string) ($package['package_sha256'] ?? ''))
        ) {
            throw new RuntimeException('MBTI-CROSS-APPROVAL-48 package SHA-256 mismatch.');
        }
        if (($package['schema_version'] ?? null) !== 'mbti.cross_type_comparison.approval.v1'
            || ($package['id'] ?? null) !== 'MBTI-CROSS-APPROVAL-48'
        ) {
            throw new RuntimeException('MBTI-CROSS-APPROVAL-48 package identity mismatch.');
        }

        $authorizationCore = $authorization;
        unset($authorizationCore['authorization_sha256']);
        $authorizationSha = $this->sha($authorizationCore);
        if (! hash_equals(self::AUTHORIZATION_SHA256, $authorizationSha)
            || ! hash_equals(self::AUTHORIZATION_SHA256, (string) ($authorization['authorization_sha256'] ?? ''))
        ) {
            throw new RuntimeException('MBTI-CROSS-APPROVAL-48 authorization SHA-256 mismatch.');
        }
        if (($authorization['decision'] ?? null) !== 'APPROVED_EXACT_THREE_EDITORIAL_CONTENT_NO_PRODUCTION_ACTION_AUTHORIZED'
            || ($authorization['permits_pr_49_implementation'] ?? false) !== true
            || ! hash_equals(self::PACKAGE_SHA256, (string) ($authorization['approved_package_sha256'] ?? ''))
        ) {
            throw new RuntimeException('Editorial authorization does not approve the exact PR49 package.');
        }
        foreach ([
            'production_content_write_authorized',
            'publication_or_indexability_change_authorized',
            'sitemap_or_llms_change_authorized',
            'search_submission_authorized',
        ] as $heldAuthorization) {
            if (($authorization[$heldAuthorization] ?? null) !== false) {
                throw new RuntimeException('Editorial authorization must not authorize production or discoverability actions.');
            }
        }

        $records = $package['records'] ?? null;
        if (! is_array($records) || ! array_is_list($records) || count($records) !== 3) {
            throw new RuntimeException('Package must contain exactly three records.');
        }
        $slugs = array_map(
            static fn (mixed $record): string => is_array($record) ? (string) ($record['slug'] ?? '') : '',
            $records,
        );
        if ($slugs !== self::EXACT_SLUGS
            || ($authorization['exact_slugs'] ?? null) !== self::EXACT_SLUGS
            || data_get($package, 'summary.exact_slugs') !== self::EXACT_SLUGS
            || (int) data_get($package, 'summary.record_count', 0) !== 3
        ) {
            throw new RuntimeException('Package, authorization, and summary must bind the exact three-slug set.');
        }

        $releaseCandidate = data_get($package, 'content_release_candidate.payload');
        if (! is_array($releaseCandidate)
            || ($releaseCandidate['exact_slugs'] ?? null) !== self::EXACT_SLUGS
            || data_get($releaseCandidate, 'invariants.atomic_exact_three') !== true
            || data_get($releaseCandidate, 'invariants.keep_noindex') !== true
            || data_get($releaseCandidate, 'invariants.keep_out_of_sitemap') !== true
            || data_get($releaseCandidate, 'invariants.keep_out_of_llms') !== true
            || data_get($releaseCandidate, 'invariants.keep_out_of_llms_full') !== true
            || data_get($releaseCandidate, 'invariants.no_indexability_mutation') !== true
        ) {
            throw new RuntimeException('Content release candidate does not preserve every discoverability hold.');
        }

        foreach ($records as $index => $record) {
            if (! is_array($record)) {
                throw new RuntimeException("Record {$index} must be an object.");
            }
            $slug = self::EXACT_SLUGS[$index];
            $payload = $record['candidate_payload'] ?? null;
            if (! is_array($payload)
                || ($record['locale'] ?? null) !== 'zh-CN'
                || ($record['comparison_type'] ?? null) !== 'mbti_cross_type'
                || ($payload['comparison_slug'] ?? null) !== $slug
                || ($payload['comparison_type'] ?? null) !== 'mbti_cross_type'
                || ($payload['locale'] ?? null) !== 'zh-CN'
                || ($payload['review_status'] ?? null) !== 'draft'
                || ($payload['publish_status'] ?? null) !== 'draft'
                || ($payload['indexability_status'] ?? null) !== 'pending_review'
                || ($payload['is_indexable'] ?? null) !== false
            ) {
                throw new RuntimeException("Record {$slug} identity or held-state contract mismatch.");
            }
            if (! hash_equals((string) ($record['content_sha256'] ?? ''), $this->sha($payload))) {
                throw new RuntimeException("Record {$slug} content SHA-256 mismatch.");
            }
            if (count((array) ($payload['sections'] ?? [])) !== 8
                || count((array) ($payload['faq'] ?? [])) !== 8
                || count((array) ($payload['internal_links'] ?? [])) !== 7
            ) {
                throw new RuntimeException("Record {$slug} content shape mismatch.");
            }
        }

        /** @var list<array<string,mixed>> $records */
        return $records;
    }

    public function sha(mixed $value): string
    {
        return hash('sha256', (string) json_encode(
            $this->stable($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function stable(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->stable($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->stable($item);
        }

        return $value;
    }
}
