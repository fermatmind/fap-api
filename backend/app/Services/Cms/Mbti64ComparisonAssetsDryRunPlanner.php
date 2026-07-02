<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class Mbti64ComparisonAssetsDryRunPlanner
{
    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~/(?:[a-z]{2}(?:-[A-Z]{2})?/)?(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

    private const FORBIDDEN_QUERY_PATTERN =
        '/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i';

    /**
     * @return array<string,mixed>
     */
    public function planSourceDir(string $sourceDir): array
    {
        $resolvedSourceDir = $this->resolveSourceDir($sourceDir);
        $files = $this->comparisonFiles($resolvedSourceDir);
        $errors = [];
        $warnings = [];
        $rows = [];

        if ($files === []) {
            $errors[] = $this->issue('source_dir', 'comparison_assets_missing', 'No comparisons/*_CMS_READY.json files were found.');
        }

        foreach ($files as $file) {
            $raw = (string) File::get($file);
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $errors[] = $this->issue($this->relativePath($file), 'invalid_json_object', 'Comparison CMS_READY file must be a JSON object.');

                continue;
            }

            $assetErrors = [];
            $this->validateAsset($decoded, $this->relativePath($file), $assetErrors, $warnings);

            if ($assetErrors === []) {
                $rows[] = $this->rowPlan($decoded, $file, $raw);
            }

            $errors = array_merge($errors, $assetErrors);
        }

        return [
            'artifact' => 'MBTI64-COMPARISON-ASSETS-DRY-RUN-01',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'dry_run' => true,
            'write' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'queue_enqueue_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'canonical_hreflang_jsonld_release_attempted' => false,
            'source_dir' => $resolvedSourceDir,
            'assets_found' => count($files),
            'valid_count' => count($rows),
            'errors_count' => count($errors),
            'comparison_count' => count($rows),
            'rows_would_stage' => count($rows),
            'target_contract' => $this->targetContract(),
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function resolveSourceDir(string $sourceDir): string
    {
        $sourceDir = trim($sourceDir);
        if ($sourceDir === '') {
            throw new RuntimeException('--source-dir is required.');
        }

        $resolved = str_starts_with($sourceDir, '/')
            ? $sourceDir
            : base_path($sourceDir);

        if (! File::isDirectory($resolved)) {
            throw new RuntimeException('Source directory not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function comparisonFiles(string $sourceDir): array
    {
        $files = glob($sourceDir.'/comparisons/*_CMS_READY.json') ?: [];
        sort($files);

        return array_values(array_map('strval', $files));
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     */
    private function validateAsset(array $asset, string $path, array &$errors, array &$warnings): void
    {
        $slug = $this->stringValue($asset['slug'] ?? null);
        if ($slug === null || preg_match('/^([a-z]{4})-a-vs-\1-t$/i', $slug) !== 1) {
            $errors[] = $this->issue($path.'.slug', 'invalid_mbti_at_comparison_slug', 'Slug must match {type}-a-vs-{type}-t.');
        }

        foreach (['title', 'seo_title', 'seo_description', 'summary'] as $field) {
            if ($this->stringValue($asset[$field] ?? null) === null) {
                $errors[] = $this->issue($path.'.'.$field, 'required_string_missing', $field.' is required.');
            }
        }

        if ($this->stringValue($asset['review_status'] ?? null) !== 'draft') {
            $errors[] = $this->issue($path.'.review_status', 'review_status_must_be_draft', 'Review status must remain draft for this package.');
        }

        if ($this->stringValue($asset['publish_status'] ?? null) !== 'draft') {
            $errors[] = $this->issue($path.'.publish_status', 'publish_status_must_be_draft', 'Publish status must remain draft.');
        }

        if ($this->stringValue($asset['indexability_status'] ?? null) === 'indexable') {
            $errors[] = $this->issue($path.'.indexability_status', 'indexability_must_not_be_indexable', 'Draft comparison assets must not request indexability.');
        }

        $sections = is_array($asset['sections'] ?? null) ? array_values((array) $asset['sections']) : [];
        if (count($sections) < 5) {
            $errors[] = $this->issue($path.'.sections', 'sections_under_minimum', 'Expected at least five comparison sections.');
        }

        $faq = is_array($asset['faq'] ?? null) ? array_values((array) $asset['faq']) : [];
        if (count($faq) < 4) {
            $errors[] = $this->issue($path.'.faq', 'faq_under_minimum', 'Expected at least four FAQ entries.');
        }

        $internalLinks = is_array($asset['internal_links'] ?? null) ? array_values((array) $asset['internal_links']) : [];
        if (count($internalLinks) < 3) {
            $errors[] = $this->issue($path.'.internal_links', 'internal_links_under_minimum', 'Expected at least three safe internal links.');
        }

        if ($this->stringValue($asset['claim_boundary'] ?? null) === null) {
            $errors[] = $this->issue($path.'.claim_boundary', 'claim_boundary_missing', 'Claim boundary is required.');
        }

        if (! is_array($asset['source_notes'] ?? null)) {
            $errors[] = $this->issue($path.'.source_notes', 'source_notes_missing', 'Source notes are required.');
        }

        $this->validateForbiddenRoutes($asset, $path, $errors);
        $this->validateSourceNotes($asset, $path, $warnings);
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<array<string,string>>  $errors
     */
    private function validateForbiddenRoutes(array $asset, string $path, array &$errors): void
    {
        $json = json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            $errors[] = $this->issue($path, 'json_encode_failed', 'Asset could not be normalized for route safety scanning.');

            return;
        }

        if (preg_match(self::FORBIDDEN_PUBLIC_ROUTE_PATTERN, $json) === 1) {
            $errors[] = $this->issue($path, 'forbidden_public_route_pattern_present', 'Active payload must not contain result/order/share/payment/history/private/account routes.');
        }

        if (preg_match(self::FORBIDDEN_QUERY_PATTERN, $json) === 1) {
            $errors[] = $this->issue($path, 'forbidden_query_pattern_present', 'Active payload must not contain sensitive query keys.');
        }
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<array<string,string>>  $warnings
     */
    private function validateSourceNotes(array $asset, string $path, array &$warnings): void
    {
        $sourceNotes = is_array($asset['source_notes'] ?? null) ? $asset['source_notes'] : [];
        if (($sourceNotes['16p_reference_used_for_structure_only'] ?? null) !== true) {
            $warnings[] = $this->issue($path.'.source_notes', 'structure_reference_boundary_not_confirmed', 'Source notes should confirm 16P was used for structure only, not copied text.');
        }
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function rowPlan(array $asset, string $file, string $raw): array
    {
        $slug = (string) $asset['slug'];
        $baseTypeCode = strtoupper(substr($slug, 0, 4));

        return [
            'slug' => $slug,
            'base_type_code' => $baseTypeCode,
            'locale' => 'zh-CN',
            'source_file' => $this->relativePath($file),
            'source_sha256' => hash('sha256', $raw),
            'target' => [
                'table' => 'personality_profile_sections',
                'section_key' => 'mbti64_comparison_a_vs_t',
                'profile_lookup' => [
                    'type_code' => $baseTypeCode,
                    'locale' => 'zh-CN',
                    'scale_code' => 'mbti',
                ],
                'write_mode' => 'draft_overlay_plan_only',
            ],
            'draft_overlay' => $this->draftOverlay($asset),
            'draft_state_after_import' => [
                'review_status' => 'draft',
                'publish_status' => 'draft',
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_eligible' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function draftOverlay(array $asset): array
    {
        return [
            'source' => 'mbti64_comparison_gpt_asset_draft_v1',
            'snapshot_key' => 'mbti64_comparison_gpt_asset_draft_v1',
            'seo' => [
                'title' => $asset['title'] ?? null,
                'h1' => $asset['title'] ?? null,
                'seo_title' => $asset['seo_title'] ?? null,
                'seo_description' => $asset['seo_description'] ?? null,
                'quick_answer_summary' => $asset['summary'] ?? null,
            ],
            'content' => $this->contentOverlay($asset),
            'faq' => $this->faq((array) ($asset['faq'] ?? [])),
            'internal_links' => $this->internalLinks((array) ($asset['internal_links'] ?? [])),
            'governance' => [
                'claim_boundary' => $asset['claim_boundary'] ?? null,
                'source_notes' => $asset['source_notes'] ?? [],
                'review_status' => $asset['review_status'] ?? null,
                'publish_status' => $asset['publish_status'] ?? null,
                'indexability_status' => $asset['indexability_status'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function contentOverlay(array $asset): array
    {
        $content = [
            'quick_answer' => $asset['summary'] ?? null,
            'side_by_side_summary' => [
                'h2' => 'INTP-A 与 INTP-T 速览',
                'rows' => [
                    [
                        'dimension' => '自我确认与模型校准',
                        'a_variant' => 'INTP-A 更容易在形成可用模型后推进。',
                        't_variant' => 'INTP-T 更容易继续检查模型是否完整。',
                    ],
                ],
            ],
        ];

        foreach ((array) ($asset['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $id = $this->blockKey((string) ($section['id'] ?? 'section'));
            $body = $this->bodyMarkdown($section);
            if ($id === '' || $body === '') {
                continue;
            }

            $content[$id] = [
                'h2' => $this->stringValue($section['title'] ?? null) ?? str_replace('_', ' ', $id),
                'body' => $body,
            ];
        }

        return $content;
    }

    /**
     * @param  array<int|string,mixed>  $items
     * @return list<array<string,string|null>>
     */
    private function faq(array $items): array
    {
        $faq = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->stringValue($item['question'] ?? null);
            $answer = $this->stringValue($item['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $faq[] = [
                'id' => 'mbti-comparison-faq-'.((string) ($index + 1)),
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $faq;
    }

    /**
     * @param  array<int|string,mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function internalLinks(array $items): array
    {
        $links = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $href = $this->stringValue($item['href'] ?? null);
            $anchor = $this->stringValue($item['anchor_text'] ?? null)
                ?? $this->stringValue($item['label'] ?? null);
            if ($href === null || $anchor === null || ! str_starts_with($href, '/zh/')) {
                continue;
            }

            $links[] = [
                'href' => $href,
                'anchor_text' => $anchor,
                'role' => $this->blockKey($this->stringValue($item['link_intent'] ?? null) ?? 'internal_link'),
                'safe_public_route' => true,
            ];
        }

        return $links;
    }

    /**
     * @param  array<string,mixed>  $section
     */
    private function bodyMarkdown(array $section): string
    {
        $lines = [];
        $this->appendParagraphValue($lines, $section['body'] ?? null);
        $this->appendBodyValue($lines, $section['groups'] ?? null);
        $this->appendBodyValue($lines, $section['items'] ?? null);

        return trim(implode("\n\n", array_values(array_filter($lines))));
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendParagraphValue(array &$lines, mixed $value): void
    {
        if (is_string($value)) {
            $text = $this->stringValue($value);
            if ($text !== null) {
                $lines[] = $text;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            if (is_string($item)) {
                $text = $this->stringValue($item);
                if ($text !== null) {
                    $lines[] = $text;
                }

                continue;
            }

            $this->appendBodyValue($lines, $item);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendBodyValue(array &$lines, mixed $value): void
    {
        if (is_string($value)) {
            $text = $this->stringValue($value);
            if ($text !== null) {
                $lines[] = $text;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $associative = array_keys($value) !== range(0, count($value) - 1);
        if ($associative) {
            $title = $this->stringValue($value['title'] ?? null)
                ?? $this->stringValue($value['goal'] ?? null);
            if ($title !== null) {
                $lines[] = '### '.$title;
            }

            foreach (['practice', 'reflection', 'body', 'items', 'groups'] as $field) {
                $this->appendBodyValue($lines, $value[$field] ?? null);
            }

            return;
        }

        foreach ($value as $item) {
            if (is_string($item)) {
                $text = $this->stringValue($item);
                if ($text !== null) {
                    $lines[] = '- '.$text;
                }

                continue;
            }

            $this->appendBodyValue($lines, $item);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function targetContract(): array
    {
        return [
            'storage' => 'personality_profile_sections.mbti64_comparison_a_vs_t',
            'profile_lookup' => 'type_code + locale + scale_code',
            'overlay_contract' => [
                'seo',
                'content.quick_answer',
                'content.side_by_side_summary.rows',
                'content.{section_id}.h2',
                'content.{section_id}.body',
                'faq',
                'internal_links',
                'source',
                'snapshot_key',
                'governance',
            ],
        ];
    }

    private function blockKey(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $value), '_'));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
