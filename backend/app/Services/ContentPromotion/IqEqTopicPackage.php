<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;
use FilesystemIterator;

/** Reads only the reviewed, fixed bilingual SH-01 CMS copy package. */
final class IqEqTopicPackage
{
    public const PACKAGE = 'content_assets/iq_public/topics/20261010-v1';

    public const SHA256 = 'ad0e2d75cdee2f969582620fb6157267dc661c688122900a12ca4a4f7359f2af';

    public function read(string $backendRoot, string $expectedSha256): array
    {
        if ($expectedSha256 !== self::SHA256) {
            throw new DomainException('iq_eq_topic_package_sha_invalid');
        }
        $root = rtrim((string) realpath($backendRoot), '/').'/'.self::PACKAGE;
        if (realpath($root) !== $root || is_link($root)) {
            throw new DomainException('iq_eq_topic_package_path_invalid');
        }
        $manifest = $this->json($root, 'manifest.json');
        $unsigned = $manifest;
        unset($unsigned['package_sha256']);
        if (($manifest['schema'] ?? null) !== 'fermatmind.iq_eq_topic_package.v1'
            || ($manifest['package'] ?? null) !== self::PACKAGE || ($manifest['scope_count'] ?? null) !== 2
            || ($manifest['package_sha256'] ?? null) !== self::SHA256
            || hash('sha256', PromotionContextFactory::canonicalJson($unsigned)) !== self::SHA256) {
            throw new DomainException('iq_eq_topic_manifest_invalid');
        }
        $inventory = ['manifest.json'];
        foreach ($manifest['files'] ?? [] as $file) {
            $bytes = $this->bytes($root, $file['path'] ?? '');
            if (strlen($bytes) !== ($file['bytes'] ?? null) || hash('sha256', $bytes) !== ($file['sha256'] ?? null)) {
                throw new DomainException('iq_eq_topic_file_digest_invalid');
            }
            $inventory[] = $file['path'];
        }
        $actual = [];
        foreach (new FilesystemIterator($root) as $file) {
            if (! $file->isFile() || $file->isLink() || ($file->getFileInfo()->getType() !== 'file')) {
                throw new DomainException('iq_eq_topic_inventory_invalid');
            }
            $actual[] = $file->getFilename();
        }
        sort($actual);
        sort($inventory);
        if ($actual !== $inventory || count($actual) !== 8) {
            throw new DomainException('iq_eq_topic_inventory_invalid');
        }
        $review = $this->json($root, 'exact-payload-review.json');
        if (($review['schema'] ?? null) !== 'fermatmind.iq_eq_topic_exact_review.v1'
            || ($review['approval_kind'] ?? null) !== 'verified_native_professional_candidate_review'
            || ($review['reviewer_role'] ?? null) !== 'fm_independent_reviewer'
            || preg_match('/\A[a-f0-9]{64}\z/', (string) ($review['source_native_result_sha256'] ?? '')) !== 1) {
            throw new DomainException('iq_eq_topic_review_invalid');
        }
        $rows = $manifest['rows'] ?? [];
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 2 || count($review['rows'] ?? []) !== 2) {
            throw new DomainException('iq_eq_topic_scope_invalid');
        }
        foreach ($rows as $index => &$row) {
            $locale = ['zh-CN', 'en'][$index];
            if (($row['page_id'] ?? null) !== 'SH-01'
                || ! $this->same($row['identity'] ?? null, ['org_id' => 0, 'slug' => 'iq-eq', 'locale' => $locale])) {
                throw new DomainException('iq_eq_topic_identity_invalid');
            }
            foreach (['body' => 'md', 'metadata' => 'json', 'snapshot' => 'snapshot.json'] as $kind => $suffix) {
                $path = 'SH-01-'.$locale.'.'.$suffix;
                if (($row[$kind.'_file'] ?? null) !== $path || hash('sha256', $this->bytes($root, $path)) !== ($row[$kind.'_sha256'] ?? null)
                    || ($review['rows'][$index][$kind.'_sha256'] ?? null) !== $row[$kind.'_sha256']) {
                    throw new DomainException('iq_eq_topic_review_binding_invalid');
                }
            }
            if (($review['rows'][$index]['decision'] ?? null) !== 'PASS'
                || ! $this->same($review['rows'][$index]['identity'] ?? null, $row['identity'])) {
                throw new DomainException('iq_eq_topic_review_binding_invalid');
            }
            $body = $this->bytes($root, $row['body_file']);
            $metadata = $this->json($root, $row['metadata_file']);
            $snapshot = $this->json($root, $row['snapshot_file']);
            $overview = trim((string) preg_replace('/\A# [^\n]+\n/', '', $body, 1));
            $title = $metadata['metadata']['title'] ?? null;
            $description = $metadata['metadata']['description'] ?? null;
            if (($metadata['page_id'] ?? null) !== 'SH-01' || ($metadata['locale'] ?? null) !== $locale
                || ($metadata['candidate_sha256'] ?? null) !== $row['body_sha256']
                || ! is_string($title) || $title === '' || mb_strlen($title) > 255 || ! is_string($description) || $description === ''
                || ! $this->same($snapshot['profile_patch'] ?? null, ['title' => $title, 'subtitle' => $description, 'excerpt' => ''])
                || ! $this->same($snapshot['seo_text_candidate'] ?? null, ['title' => $title, 'description' => $description])
                || count($snapshot['section_candidates'] ?? []) !== 2
                || ($snapshot['section_candidates'][0]['section_key'] ?? null) !== 'overview'
                || ($snapshot['section_candidates'][0]['render_variant'] ?? null) !== 'rich_text'
                || ($snapshot['section_candidates'][0]['body_md'] ?? null) !== $overview
                || preg_match('/^#\s/m', $overview) === 1
                || ($snapshot['section_candidates'][1]['section_key'] ?? null) !== 'faq'
                || ($snapshot['section_candidates'][1]['render_variant'] ?? null) !== 'faq') {
                throw new DomainException('iq_eq_topic_snapshot_invalid');
            }
            $faqs = $metadata['faq_items'] ?? null;
            if (! is_array($faqs) || ! array_is_list($faqs) || count($faqs) !== 7
                || ! $this->same($snapshot['section_candidates'][1]['payload_json']['items'] ?? null, $faqs)) {
                throw new DomainException('iq_eq_topic_faq_invalid');
            }
            $entries = $snapshot['entry_excerpt_overrides'] ?? [];
            foreach (['IQ_RAVEN' => ['featured', 'iq-test-intelligence-quotient-assessment'], 'EQ_60' => ['tests', 'eq-test-emotional-intelligence-assessment']] as $code => [$group, $slug]) {
                $matches = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['target_key'] ?? null) === $code));
                if (count($entries) !== 2 || count($matches) !== 1 || ($matches[0]['group_key'] ?? null) !== $group
                    || ($matches[0]['entry_type'] ?? null) !== 'scale'
                    || ($matches[0]['expected_url'] ?? null) !== '/'.($locale === 'en' ? 'en' : 'zh').'/tests/'.$slug
                    || ! is_string($matches[0]['excerpt_override'] ?? null) || $matches[0]['excerpt_override'] === '') {
                    throw new DomainException('iq_eq_topic_entry_scope_invalid');
                }
            }
            if ($locale === 'en' && preg_match('/[\x{3400}-\x{9fff}]/u', $overview.$title.$description.json_encode($faqs, JSON_UNESCAPED_UNICODE)) === 1) {
                throw new DomainException('iq_eq_topic_locale_invalid');
            }
            $row['snapshot'] = $snapshot;
        }
        unset($row);

        return $rows;
    }

    private function bytes(string $root, string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9-]+(?:\.[a-z]+){1,2}\z/', $name) !== 1
            || realpath($root.'/'.$name) !== $root.'/'.$name || is_link($root.'/'.$name)
            || ! is_file($root.'/'.$name) || (stat($root.'/'.$name)['nlink'] ?? 0) !== 1) {
            throw new DomainException('iq_eq_topic_file_path_invalid');
        }

        return (string) file_get_contents($root.'/'.$name);
    }

    private function json(string $root, string $name): array
    {
        $value = json_decode($this->bytes($root, $name), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new DomainException('iq_eq_topic_json_invalid');
        }

        return $value;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return is_array($left) && is_array($right)
            ? PromotionContextFactory::canonicalJson($left) === PromotionContextFactory::canonicalJson($right)
            : $left === $right;
    }
}
