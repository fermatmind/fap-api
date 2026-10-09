<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Services\Cms\ArticleBodyHeadingGuard;
use DomainException;

/** Reads reviewed public Article candidates; does not approve or publish CMS records. */
final class EqPublicArticlePackage
{
    public const PACKAGE = 'content_assets/eq_public/candidate/20261009-new-articles';

    private const SLUGS = [
        'EQ-03' => 'eq60-score-and-results-guide',
        'EQ-04' => 'emotional-intelligence-models-and-measures',
        'EQ-05' => 'emotional-awareness-regulation-and-relationship-practice',
    ];

    private const FIELDS = ['title', 'excerpt', 'seo_title', 'seo_description', 'content_md'];

    public function __construct(private readonly ArticleBodyHeadingGuard $headings) {}

    /** @return array{candidates:list<array<string,mixed>>,package_sha256:string} */
    public function read(string $backendRoot, string $expectedSha256): array
    {
        $root = realpath($backendRoot);
        if ($root === false || preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
            throw new DomainException('eq_public_package_identity_invalid');
        }
        $bytes = $this->bytes($root, self::PACKAGE.'/assets.json');
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (($package['schema'] ?? null) !== 'fermatmind.eq_public_article_candidates.v1'
            || ! is_array($package['candidates'] ?? null) || count($package['candidates']) !== 6) {
            throw new DomainException('eq_public_package_schema_invalid');
        }
        $seen = [];
        $proofs = [];
        foreach ($package['candidates'] as $row) {
            $id = $row['page_id'] ?? '';
            $identity = $row['identity'] ?? [];
            $locale = $identity['locale'] ?? '';
            $key = $id.':'.$locale;
            if (! isset(self::SLUGS[$id]) || ($identity['org_id'] ?? null) !== 0
                || ($identity['slug'] ?? null) !== self::SLUGS[$id]
                || ! in_array($locale, ['zh-CN', 'en'], true) || isset($seen[$key])) {
                throw new DomainException('eq_public_package_scope_invalid');
            }
            $seen[$key] = true;
            $snapshot = $row['snapshot'] ?? [];
            $fields = array_keys($snapshot);
            sort($fields);
            $expectedFields = self::FIELDS;
            sort($expectedFields);
            if ($fields !== $expectedFields) {
                throw new DomainException('eq_public_package_snapshot_invalid');
            }
            foreach (self::FIELDS as $field) {
                if (! is_string($snapshot[$field]) || trim($snapshot[$field]) === '') {
                    throw new DomainException('eq_public_package_snapshot_invalid');
                }
            }
            foreach (['title' => 255, 'seo_title' => 60, 'seo_description' => 160] as $field => $limit) {
                if (mb_strlen($snapshot[$field]) > $limit) {
                    throw new DomainException('eq_public_package_snapshot_invalid');
                }
            }
            if ($locale === 'en' && preg_match('/\p{Han}/u', implode("\n", $snapshot)) === 1) {
                throw new DomainException('eq_public_package_locale_invalid');
            }
            $this->headings->assertNoBodyH1($snapshot['content_md']);
            if (preg_match('~/(?:account|attempts?|checkout|history|orders?|payments?|pay|private|recovery|reports?|results?|shares?)(?:/|[?#\s]|$)|[?&](?:token|attempt_id|report_id|order_id|payment_id|user_id)=~i', $snapshot['content_md']) === 1) {
                throw new DomainException('eq_public_package_private_url');
            }
            $input = $this->proof($root, $row['independent_review_input'] ?? [], $proofs);
            $output = $this->proof($root, $row['independent_review_output'] ?? [], $proofs);
            // Original native replies have different prose formatting. Bind the
            // exact input bytes and page verdict, never infer an English pass
            // from a Chinese input or a neighbouring page's PASS.
            if (! str_contains($output, hash('sha256', $input))
                || preg_match('/'.preg_quote($id, '/').'(?:\s|[|｜*：:\/,，、-]|zh-CN|en){0,30}PASS/u', $output) !== 1) {
                throw new DomainException('eq_public_package_review_unbound');
            }
            $reviewed = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            $locales = array_unique(array_column((array) ($reviewed['candidates'] ?? []), 'locale'));
            if (array_values($locales) !== [$locale]) {
                throw new DomainException('eq_public_package_review_locale_invalid');
            }
            $matches = array_values(array_filter((array) ($reviewed['candidates'] ?? []), static fn (array $candidate): bool => ($candidate['id'] ?? null) === $id && ($candidate['locale'] ?? null) === $locale));
            if (count($matches) !== 1 || ($matches[0]['slug'] ?? null) !== self::SLUGS[$id]) {
                throw new DomainException('eq_public_package_review_unbound');
            }
            foreach (self::FIELDS as $field) {
                if (($matches[0][$field === 'content_md' ? 'body_md' : $field] ?? null) !== $snapshot[$field]) {
                    throw new DomainException('eq_public_package_review_payload_drift');
                }
            }
        }
        ksort($proofs, SORT_STRING);
        $chain = 'fermatmind.eq_public_article_candidates.v1'."\n".hash('sha256', $bytes)."\n";
        foreach ($proofs as $path => $sha256) {
            $chain .= $path."\n".$sha256."\n";
        }
        $actualSha256 = hash('sha256', $chain);
        if (! hash_equals($expectedSha256, $actualSha256)) {
            throw new DomainException('eq_public_package_digest_mismatch');
        }

        return ['candidates' => $package['candidates'], 'package_sha256' => $actualSha256];
    }

    /** @param array<string,mixed> $reference @param array<string,string> $proofs */
    private function proof(string $root, array $reference, array &$proofs): string
    {
        $path = $reference['path'] ?? '';
        $sha256 = $reference['sha256'] ?? '';
        if (! is_string($path) || ! str_starts_with($path, 'content_assets/eq_public/reviews/20261009-new-articles/')
            || ! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new DomainException('eq_public_package_review_reference_invalid');
        }
        $bytes = $this->bytes($root, $path);
        if (! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new DomainException('eq_public_package_review_digest_mismatch');
        }
        $proofs[$path] = $sha256;

        return $bytes;
    }

    private function bytes(string $root, string $relative): string
    {
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new DomainException('eq_public_package_path_invalid');
        }
        $path = $root.'/'.$relative;
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, $root.'/') || ! is_file($real) || is_link($path)) {
            throw new DomainException('eq_public_package_path_invalid');
        }
        $bytes = file_get_contents($real);
        if (! is_string($bytes)) {
            throw new DomainException('eq_public_package_unreadable');
        }

        return $bytes;
    }
}
