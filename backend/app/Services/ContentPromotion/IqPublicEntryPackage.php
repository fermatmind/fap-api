<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/** Reads two reviewed entry candidates; CMS authority begins at publication. */
final class IqPublicEntryPackage
{
    public const PACKAGE = 'content_assets/iq_public/entry/20261009-v1';

    public const SHA256 = 'f6e170de25839e081342c8953d17a17a85938c2c9ebb2b07d9cf8ff48351cb7f';

    public const CODE = 'IQ_RAVEN';

    public const SLUG = 'iq-test-intelligence-quotient-assessment';

    private const FILES = [
        'IQ-01-en.json', 'IQ-01-en.md', 'IQ-01-zh-CN.json', 'IQ-01-zh-CN.md',
        'iq-all-source-fresh-result.json', 'iq-publication-three-fresh-result.json',
    ];

    /** @return list<array{identity:array<string,int|string>,key:string,patch:array<string,mixed>}> */
    public function read(string $backendRoot, string $expectedSha256): array
    {
        if (! hash_equals(self::SHA256, $expectedSha256)) {
            throw new DomainException('iq_public_entry_package_digest_mismatch');
        }
        $directory = realpath($backendRoot.'/'.self::PACKAGE);
        $root = realpath($backendRoot);
        if ($directory === false || $root === false || ! str_starts_with($directory, $root.'/')
            || is_link($backendRoot.'/'.self::PACKAGE)) {
            throw new DomainException('iq_public_entry_package_path_invalid');
        }
        $manifestBytes = $this->bytes($directory, 'manifest.json');
        $manifest = json_decode($manifestBytes, true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== 'fermatmind.iq_public_entry_package.v1'
            || ($manifest['org_id'] ?? null) !== 0 || ($manifest['code'] ?? null) !== self::CODE
            || ($manifest['primary_slug'] ?? null) !== self::SLUG
            || ! is_array($manifest['targets'] ?? null) || count($manifest['targets']) !== 2
            || array_keys((array) ($manifest['files'] ?? [])) !== self::FILES) {
            throw new DomainException('iq_public_entry_package_scope_invalid');
        }
        $files = [];
        $chain = $manifest['schema']."\n".hash('sha256', $manifestBytes)."\n";
        foreach ($manifest['files'] as $name => $digest) {
            $bytes = $this->bytes($directory, $name);
            if (! is_string($digest) || ! hash_equals($digest, hash('sha256', $bytes))) {
                throw new DomainException('iq_public_entry_file_digest_mismatch');
            }
            $files[$name] = $bytes;
            $chain .= $name."\n".$digest."\n";
        }
        if (! hash_equals($expectedSha256, hash('sha256', $chain))) {
            throw new DomainException('iq_public_entry_package_digest_mismatch');
        }
        $rows = [];
        $seen = [];
        foreach ($manifest['targets'] as $target) {
            $locale = $target['locale'] ?? '';
            $key = $locale === 'zh-CN' ? 'zh' : 'en';
            $stem = 'IQ-01-'.$locale;
            if (! in_array($locale, ['zh-CN', 'en'], true) || isset($seen[$locale])
                || ($target['page_id'] ?? null) !== 'IQ-01' || ($target['content_locale_key'] ?? null) !== $key
                || ($target['body_file'] ?? null) !== $stem.'.md' || ($target['input_file'] ?? null) !== $stem.'.json'
                || ! isset($files[$target['review_file'] ?? ''])) {
                throw new DomainException('iq_public_entry_package_scope_invalid');
            }
            $seen[$locale] = true;
            $body = $files[$stem.'.md'];
            $inputBytes = $files[$stem.'.json'];
            $input = json_decode($inputBytes, true, 32, JSON_THROW_ON_ERROR);
            if (($input['page_id'] ?? null) !== 'IQ-01' || ($input['locale'] ?? null) !== $locale
                || ($input['candidate_sha256'] ?? null) !== hash('sha256', $body)
                || ! is_array($input['faq_items'] ?? null) || count($input['faq_items']) !== 11) {
                throw new DomainException('iq_public_entry_candidate_invalid');
            }
            $report = json_decode($files[$target['review_file']], true, 64, JSON_THROW_ON_ERROR);
            $verdicts = [];
            foreach ((array) ($report['pages'] ?? []) as $page) {
                if (($page['page_id'] ?? null) === 'IQ-01') {
                    foreach ((array) ($page['locales'] ?? []) as $verdict) {
                        if (($verdict['locale'] ?? null) === $locale) {
                            $verdicts[] = $verdict;
                        }
                    }
                }
            }
            if (count($verdicts) !== 1 || ($verdicts[0]['decision'] ?? null) !== 'PASS'
                || ($verdicts[0]['body_sha256'] ?? null) !== hash('sha256', $body)
                || ($verdicts[0]['candidate_json_sha256'] ?? null) !== hash('sha256', $inputBytes)) {
                throw new DomainException('iq_public_entry_review_unbound');
            }
            $patch = $this->project($body, $input['faq_items'], $key);
            if ($locale === 'en' && preg_match('/\p{Han}/u', PromotionContextFactory::canonicalJson($patch)) === 1) {
                throw new DomainException('iq_public_entry_locale_invalid');
            }
            if (preg_match('~/(?:account|attempts?|checkout|history|orders?|payments?|private|recovery|reports?|results?|shares?)(?:/|[?#\s]|$)|[?&](?:token|attempt_id|report_id|user_id)=~i', $body) === 1) {
                throw new DomainException('iq_public_entry_private_url');
            }
            $rows[] = ['identity' => ['org_id' => 0, 'code' => self::CODE, 'slug' => self::SLUG, 'locale' => $locale], 'key' => $key, 'patch' => $patch];
        }

        return $rows;
    }

    /** @param list<array{question:string,answer:string}> $faq @return array<string,mixed> */
    private function project(string $body, array $faq, string $key): array
    {
        $parts = preg_split('/^## (.+)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        $intro = trim(explode("\n\n", $parts[0], 2)[1] ?? '');
        $paragraphs = explode("\n\n", $intro);
        $items = [['id' => 'iq-introduction', 'title' => $key === 'zh' ? '使用范围' : 'Scope', 'body' => implode("\n\n", array_slice($paragraphs, 1))]];
        for ($i = 1; $i < count($parts); $i += 2) {
            $items[] = ['id' => 'iq-section-'.(intdiv($i, 2) + 1), 'title' => $parts[$i], 'body' => trim($parts[$i + 1])];
        }
        $questions = [];
        foreach ($faq as $i => $item) {
            if (! is_string($item['question'] ?? null) || trim($item['question']) === ''
                || ! is_string($item['answer'] ?? null) || trim($item['answer']) === '') {
                throw new DomainException('iq_public_entry_faq_invalid');
            }
            $questions[] = ['id' => 'faq-iq-candidate-'.($i + 1), 'q' => $item['question'], 'a' => $item['answer']];
        }

        return [
            'landing_copy' => $paragraphs[0],
            'why_choose' => ['title' => $key === 'zh' ? '了解这份视觉推理练习' : 'Understand this visual reasoning exercise', 'intro' => $paragraphs[0], 'items' => $items],
            'faq' => $questions,
        ];
    }

    private function bytes(string $directory, string $name): string
    {
        if ($name !== 'manifest.json' && ! in_array($name, self::FILES, true)) {
            throw new DomainException('iq_public_entry_package_path_invalid');
        }
        $path = $directory.'/'.$name;
        $stat = @lstat($path);
        if ($stat === false || ! is_file($path) || is_link($path) || ($stat['nlink'] ?? 0) !== 1
            || realpath($path) !== $path) {
            throw new DomainException('iq_public_entry_package_path_invalid');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new DomainException('iq_public_entry_package_unreadable');
        }

        return $bytes;
    }
}
