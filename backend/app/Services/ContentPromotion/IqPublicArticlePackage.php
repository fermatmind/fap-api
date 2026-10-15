<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Services\Cms\ArticleBodyHeadingGuard;
use DomainException;

/** Reads the fixed reviewed IQ Article cohort; it never writes CMS records. */
final class IqPublicArticlePackage
{
    public const PACKAGE = 'content_assets/iq_public/articles/20261010-v1';

    public const SHA256 = 'ca6612bf60a63e2f8f253658587e0299997e82a3dfecad11994ee7f21d79fcad';

    public const SEO_PACKAGE = 'content_assets/iq_public/articles/20261015-seo-v1';

    public const SEO_SHA256 = 'b9755f9564f3ff6b39a5c987cac2c49d0fda4c00b3ed2713b6612fb5d361c041';

    public const PARITY_PACKAGE = 'content_assets/iq_public/articles/20261015-en-parity';

    public const PARITY_SHA256 = '1a0ad2975484f667e3e6da3c529c31a87697283552207a8945a743e95880262a';

    // Keep already-published FAQ projections readable while the new package is promoted.
    public const PREVIOUS_PARITY_SHA256 = 'a2ae9ba665b88d612cea7d84f8a499325a214b5f1b2490fc727167b7bf889fb2';

    public const MENSA_PACKAGE = 'content_assets/iq_public/articles/20261015-mensa-v1';

    public const MENSA_SHA256 = '32230e74353bab576906b97d58c171579163557354abca3b87888edaecd9811c';

    public const MENSA_SLUG = 'mensa-iq-test-free-practice-vs-admission';

    private const SCHEMA = 'fermatmind.iq_public_article_package.v1';

    private const SLUGS = [
        'IQ-02' => 'iq-test-score-and-limits-explained',
        'IQ-03' => 'iq-test-tool-guide',
        'IQ-04' => 'iq-test-narrative-portrait',
        'IQ-05' => 'iq-test-growth-guide',
        'IQ-06' => 'what-is-iq-and-how-it-is-measured',
    ];

    private const SNAPSHOT_FIELDS = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description', 'faq_items'];

    private const REVIEW_FILES = ['iq-all-source-fresh-result.json', 'iq-publication-three-fresh-result.json', 'iq-article-exact-payload-review.json'];

    public function __construct(private readonly ArticleBodyHeadingGuard $headings) {}

    public static function pathForSha(string $sha): string
    {
        return match ($sha) {
            self::SHA256 => self::PACKAGE,
            self::SEO_SHA256 => self::SEO_PACKAGE,
            self::PARITY_SHA256, self::PREVIOUS_PARITY_SHA256 => self::PARITY_PACKAGE,
            self::MENSA_SHA256 => self::MENSA_PACKAGE,
            default => throw new DomainException('iq_article_package_digest_mismatch'),
        };
    }

    public static function rowCountForSha(string $sha): int
    {
        self::pathForSha($sha);

        return match ($sha) {
            self::SEO_SHA256, self::MENSA_SHA256 => 2, self::PARITY_SHA256, self::PREVIOUS_PARITY_SHA256 => 6, default => 10
        };
    }

    public static function supportsPublishedDigest(?string $sha, string $slug): bool
    {
        return ($sha === self::SHA256 && in_array($slug, self::SLUGS, true))
            || ($sha === self::MENSA_SHA256 && $slug === self::MENSA_SLUG)
            || ($sha === self::SEO_SHA256 && $slug === self::SLUGS['IQ-03'])
            || (in_array($sha, [self::PARITY_SHA256, self::PREVIOUS_PARITY_SHA256], true) && in_array($slug, array_intersect_key(self::SLUGS, array_flip(['IQ-02', 'IQ-03', 'IQ-05'])), true));
    }

    /** @return list<array<string,mixed>> */
    public function read(string $backendRoot, string $expectedSha256): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
            throw new DomainException('iq_article_package_digest_mismatch');
        }
        $package = self::pathForSha($expectedSha256);
        $slugs = match ($expectedSha256) {
            self::SEO_SHA256 => ['IQ-03' => self::SLUGS['IQ-03']],
            self::MENSA_SHA256 => ['IQ-07' => self::MENSA_SLUG],
            self::PARITY_SHA256, self::PREVIOUS_PARITY_SHA256 => array_intersect_key(self::SLUGS, array_flip(['IQ-02', 'IQ-03', 'IQ-05'])),
            default => self::SLUGS,
        };
        $rowCount = self::rowCountForSha($expectedSha256);
        $root = realpath($backendRoot);
        if ($root === false) {
            throw new DomainException('iq_article_package_path_invalid');
        }
        $directory = $root;
        foreach (explode('/', $package) as $segment) {
            $directory .= '/'.$segment;
            if (! is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
                throw new DomainException('iq_article_package_path_invalid');
            }
        }
        $bytes = $this->bytes($directory, 'manifest.json');
        $manifest = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $expectedFiles = $expectedSha256 === self::SEO_SHA256
            ? ['iq-all-source-fresh-result.json', 'iq-article-exact-payload-review.json']
            : self::REVIEW_FILES;
        foreach (array_keys($slugs) as $id) {
            foreach (['zh-CN', 'en'] as $locale) {
                foreach (['md', 'json', 'snapshot.json'] as $extension) {
                    $expectedFiles[] = $id.'-'.$locale.'.'.$extension;
                }
            }
        }
        sort($expectedFiles, SORT_STRING);
        $boundaries = [
            'preserve_existing_indexability' => true,
            'preserve_foreign_working_revisions' => true,
            'new_source_pair_indexable' => $expectedSha256 === self::MENSA_SHA256,
            'search_submission' => false,
            'private_payload_write' => false,
            'eq_write' => false,
        ];
        if (($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['boundaries'] ?? null) !== $boundaries
            || ! is_array($manifest['targets'] ?? null) || count($manifest['targets']) !== $rowCount
            || array_keys((array) ($manifest['files'] ?? [])) !== $expectedFiles) {
            throw new DomainException('iq_article_package_scope_invalid');
        }
        $physicalFiles = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        $expectedPhysicalFiles = [...$expectedFiles, 'manifest.json'];
        sort($expectedPhysicalFiles, SORT_STRING);
        if ($physicalFiles !== $expectedPhysicalFiles) {
            throw new DomainException('iq_article_package_inventory_invalid');
        }
        $files = [];
        $chain = self::SCHEMA."\n".hash('sha256', $bytes)."\n";
        foreach ($manifest['files'] as $name => $sha) {
            $fileBytes = $this->bytes($directory, $name);
            if (! is_string($sha) || ! hash_equals($sha, hash('sha256', $fileBytes))) {
                throw new DomainException('iq_article_file_digest_mismatch');
            }
            $files[$name] = $fileBytes;
            $chain .= $name."\n".$sha."\n";
        }
        if (! hash_equals($expectedSha256, hash('sha256', $chain))) {
            throw new DomainException('iq_article_package_digest_mismatch');
        }
        $exactReview = json_decode($files['iq-article-exact-payload-review.json'], true, 32, JSON_THROW_ON_ERROR);
        if (($exactReview['schema'] ?? null) !== 'iq.article_exact_payload_review.v1'
            || ! is_array($exactReview['targets'] ?? null) || count($exactReview['targets']) !== $rowCount) {
            throw new DomainException('iq_article_review_unbound');
        }
        $targets = [];
        $seen = [];
        foreach ($manifest['targets'] as $target) {
            $id = $target['page_id'] ?? '';
            $locale = $target['locale'] ?? '';
            $stem = $id.'-'.$locale;
            if (! isset($slugs[$id]) || ! in_array($locale, ['zh-CN', 'en'], true) || isset($seen[$stem])
                || ($target['slug'] ?? null) !== $slugs[$id]
                || ($target['operation'] ?? null) !== (in_array($id, ['IQ-06', 'IQ-07'], true) ? 'new_source_pair' : 'existing_public_update')) {
                throw new DomainException('iq_article_package_scope_invalid');
            }
            $seen[$stem] = true;
            $input = json_decode($files[$stem.'.json'], true, 32, JSON_THROW_ON_ERROR);
            $payload = json_decode($files[$stem.'.snapshot.json'], true, 32, JSON_THROW_ON_ERROR);
            $identity = ['org_id' => 0, 'slug' => $slugs[$id], 'locale' => $locale];
            if (($input['page_id'] ?? null) !== $id || ($input['locale'] ?? null) !== $locale
                || ($payload['page_id'] ?? null) !== $id || ($payload['identity'] ?? null) !== $identity
                || ($input['candidate_sha256'] ?? null) !== hash('sha256', $files[$stem.'.md'])) {
                throw new DomainException('iq_article_payload_identity_invalid');
            }
            $snapshot = $payload['snapshot'] ?? [];
            $this->assertSnapshot($snapshot, $input, $files[$stem.'.md'], $locale);
            $verdicts = array_values(array_filter($exactReview['targets'], static fn (array $row): bool => ($row['page_id'] ?? null) === $id && ($row['locale'] ?? null) === $locale));
            if (count($verdicts) !== 1 || ($verdicts[0]['decision'] ?? null) !== 'PASS'
                || ($verdicts[0]['body_sha256'] ?? null) !== hash('sha256', $files[$stem.'.md'])
                || ($verdicts[0]['candidate_json_sha256'] ?? null) !== hash('sha256', $files[$stem.'.json'])
                || ($verdicts[0]['snapshot_json_sha256'] ?? null) !== hash('sha256', $files[$stem.'.snapshot.json'])) {
                throw new DomainException('iq_article_review_unbound');
            }
            $sourceFile = in_array($id, ['IQ-06', 'IQ-07'], true) ? 'iq-publication-three-fresh-result.json' : 'iq-all-source-fresh-result.json';
            $sourceReview = json_decode($files[$sourceFile], true, 32, JSON_THROW_ON_ERROR);
            $sourceVerdicts = [];
            foreach ((array) ($sourceReview['pages'] ?? []) as $page) {
                if (($page['page_id'] ?? null) === $id) {
                    foreach ((array) ($page['locales'] ?? []) as $verdict) {
                        if (($verdict['locale'] ?? null) === $locale) {
                            $sourceVerdicts[] = $verdict;
                        }
                    }
                }
            }
            if (count($sourceVerdicts) !== 1 || ($sourceVerdicts[0]['decision'] ?? null) !== 'PASS'
                || ($sourceVerdicts[0]['body_sha256'] ?? null) !== hash('sha256', $files[$stem.'.md'])) {
                throw new DomainException('iq_article_source_review_unbound');
            }
            $targets[] = [
                'page_id' => $id, 'identity' => $identity, 'operation' => $target['operation'], 'snapshot' => $snapshot,
                'body_sha256' => hash('sha256', $files[$stem.'.md']), 'metadata_sha256' => hash('sha256', $files[$stem.'.json']),
                'snapshot_sha256' => hash('sha256', $files[$stem.'.snapshot.json']),
            ];
        }

        return $targets;
    }

    private function assertSnapshot(mixed $snapshot, array $input, string $body, string $locale): void
    {
        if (! is_array($snapshot) || array_keys($snapshot) !== self::SNAPSHOT_FIELDS) {
            throw new DomainException('iq_article_snapshot_invalid');
        }
        foreach (array_diff(self::SNAPSHOT_FIELDS, ['faq_items']) as $field) {
            if (! is_string($snapshot[$field]) || trim($snapshot[$field]) === '') {
                throw new DomainException('iq_article_snapshot_invalid');
            }
        }
        if ($snapshot['content_md'] !== $body || $snapshot['seo_title'] !== ($input['metadata']['title'] ?? null)
            || $snapshot['seo_description'] !== ($input['metadata']['description'] ?? null)
            || $snapshot['faq_items'] !== ($input['faq_items'] ?? null)
            || ! is_array($snapshot['faq_items']) || ! array_is_list($snapshot['faq_items']) || $snapshot['faq_items'] === []) {
            throw new DomainException('iq_article_projection_mismatch');
        }
        foreach (['title' => 255, 'seo_title' => 60, 'seo_description' => 160] as $field => $limit) {
            if (mb_strlen($snapshot[$field]) > $limit) {
                throw new DomainException('iq_article_snapshot_invalid');
            }
        }
        foreach ($snapshot['faq_items'] as $faq) {
            if (! is_array($faq) || array_keys($faq) !== ['question', 'answer']
                || ! is_string($faq['question']) || trim($faq['question']) === ''
                || ! is_string($faq['answer']) || trim($faq['answer']) === '') {
                throw new DomainException('iq_article_faq_invalid');
            }
        }
        $this->headings->assertNoBodyH1($body);
        if ($locale === 'en' && preg_match('/\p{sc:Han}/u', PromotionContextFactory::canonicalJson($snapshot)) === 1) {
            throw new DomainException('iq_article_locale_invalid');
        }
        if (preg_match('~/(?:account|attempts?|checkout|history|orders?|payments?|private|recovery|reports?|results?|shares?)(?:/|[?#\s]|$)|[?&](?:token|attempt_id|report_id|user_id)=~i', $body) === 1) {
            throw new DomainException('iq_article_private_url');
        }
    }

    private function bytes(string $directory, string $name): string
    {
        if (basename($name) !== $name) {
            throw new DomainException('iq_article_package_path_invalid');
        }
        $path = $directory.'/'.$name;
        $stat = @lstat($path);
        if ($stat === false || ! is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1 || realpath($path) !== $path) {
            throw new DomainException('iq_article_package_path_invalid');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new DomainException('iq_article_package_unreadable');
        }

        return $bytes;
    }
}
