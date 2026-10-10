<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/** English candidates retain their own original review and the accepted source package. */
final class EqEnglishArticlePackage
{
    public const MARKER = 'en-publication.json';

    public const PACKAGE = EqPublicArticlePackage::PACKAGE;

    public function __construct(private readonly EqPublicArticlePackage $candidates) {}

    public function read(PromotionContext $context): array
    {
        $root = realpath(base_path(EqPublicArticlePackage::PACKAGE));
        if ($context->lane !== 'W3' || $context->subscope !== 'W3-ARTICLES'
            || $context->expectedRowCount !== 3 || $root === false
            || realpath($context->packageDirectory) !== $root) {
            throw new DomainException('eq_english_context_invalid');
        }
        $path = $root.'/'.self::MARKER;
        if (! is_file($path) || is_link($path)) {
            throw new DomainException('eq_english_marker_invalid');
        }
        $bytes = file_get_contents($path);
        $marker = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        $fields = array_keys($marker);
        sort($fields);
        if ($fields !== ['expected_row_count', 'locale', 'schema', 'source_commit', 'source_package_sha256', 'staging_source_commit']
            || ($marker['schema'] ?? null) !== 'fermatmind.eq_english_article_publication.v1'
            || ($marker['locale'] ?? null) !== 'en' || ($marker['expected_row_count'] ?? null) !== 3
            || preg_match('/\A[a-f0-9]{40}\z/', (string) ($marker['source_commit'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{40}\z/', (string) ($marker['staging_source_commit'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string) ($marker['source_package_sha256'] ?? '')) !== 1) {
            throw new DomainException('eq_english_marker_invalid');
        }
        $package = $this->candidates->read(base_path(), $marker['source_package_sha256']);
        $digest = hash('sha256', $marker['schema']."\n".hash('sha256', $bytes)."\n".$package['package_sha256']."\n");
        if (! hash_equals($context->packageSha256, $digest)) {
            throw new DomainException('eq_english_package_digest_mismatch');
        }

        return [
            'marker' => $marker, 'package_sha256' => $digest,
            'source_commit' => app()->environment('staging') ? $marker['staging_source_commit'] : $marker['source_commit'],
            'candidates' => array_values(array_filter($package['candidates'], static fn (array $row): bool => $row['identity']['locale'] === 'en')),
            'sources' => array_values(array_filter($package['candidates'], static fn (array $row): bool => $row['identity']['locale'] === 'zh-CN')),
        ];
    }
}
