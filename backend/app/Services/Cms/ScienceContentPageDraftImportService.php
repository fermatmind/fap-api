<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\ContentPage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

final class ScienceContentPageDraftImportService
{
    public const COMMAND = 'content-pages:science-import-drafts';

    public const APPROVAL_PHRASE = 'SCIENCE_CONTENTPAGE_NON_PUBLIC_DRAFT_IMPORT_APPROVED';

    /**
     * @return array<string, mixed>
     */
    public function dryRun(string $packagePath): array
    {
        return $this->buildPlan($packagePath, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $packagePath, string $approvalPhrase): array
    {
        if (trim($approvalPhrase) !== self::APPROVAL_PHRASE) {
            return [
                'ok' => false,
                'command' => self::COMMAND,
                'mode' => 'execute',
                'dry_run' => false,
                'writes_committed' => false,
                'errors' => [[
                    'field' => 'approval_phrase',
                    'code' => 'approval_phrase_mismatch',
                    'message' => '--approval-phrase must equal '.self::APPROVAL_PHRASE.' when --execute is used.',
                ]],
            ];
        }

        $plan = $this->buildPlan($packagePath, true);
        if (($plan['ok'] ?? false) !== true) {
            return $plan;
        }

        $created = DB::transaction(function () use ($plan): array {
            $createdRows = [];

            foreach ($plan['pages'] as $page) {
                if (($page['action'] ?? '') !== 'create_missing_non_public_draft') {
                    continue;
                }

                $attributes = $page['content_page_attributes'] ?? null;
                if (! is_array($attributes)) {
                    continue;
                }

                $createdRows[] = ContentPage::query()->withoutGlobalScopes()->create($attributes);
            }

            return $createdRows;
        });

        return array_merge($plan, [
            'mode' => 'execute',
            'dry_run' => false,
            'writes_committed' => count($created) > 0,
            'created_count' => count($created),
            'created_ids' => array_values(array_map(
                static fn (ContentPage $page): int => (int) $page->getKey(),
                $created,
            )),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(string $packagePath, bool $execute): array
    {
        $root = rtrim($packagePath, DIRECTORY_SEPARATOR);
        $dryRun = app(ScienceContentPageDraftDryRunService::class)->dryRun($root);
        $operator = app(ScienceContentPageOperatorReviewReadinessService::class)->review($root);
        $preImportQa = app(ScienceContentPagePreImportQaService::class)->check($root);

        $errors = [];
        if (($dryRun['status'] ?? '') !== 'pass_no_write_dry_run') {
            $errors[] = $this->error('dry_run', 'dry_run_not_passed', 'Science draft dry-run must pass before import command execution.');
        }
        if (($operator['operator_review_ready_for_non_public_draft'] ?? false) !== true) {
            $errors[] = $this->error('operator_review', 'operator_review_not_ready', 'Operator review fields must be ready for non-public draft import.');
        }
        if (($preImportQa['non_public_draft_import_qa_passed'] ?? false) !== true || (int) ($preImportQa['package_pre_import_qa_issue_count'] ?? 0) !== 0) {
            $errors[] = $this->error('pre_import_qa', 'pre_import_qa_not_passed', 'Pre-import QA must pass with zero package issues.');
        }

        $pages = [];
        foreach (($dryRun['pages'] ?? []) as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pages[] = $this->planPage($root, $page, $execute);
        }

        $blockedPages = array_values(array_filter(
            $pages,
            static fn (array $page): bool => ($page['blocked'] ?? false) === true,
        ));
        if ($blockedPages !== []) {
            $errors[] = $this->error('pages', 'blocked_pages_present', 'One or more page plans are blocked.');
        }

        $plannedCreates = array_values(array_filter(
            $pages,
            static fn (array $page): bool => ($page['action'] ?? '') === 'create_missing_non_public_draft',
        ));
        $skippedExisting = array_values(array_filter(
            $pages,
            static fn (array $page): bool => ($page['action'] ?? '') === 'skip_existing_content_page',
        ));
        $authorityRevisionOnly = array_values(array_filter(
            $pages,
            static fn (array $page): bool => ($page['action'] ?? '') === 'skip_existing_authority_revision_only',
        ));

        return [
            'ok' => $errors === [],
            'command' => self::COMMAND,
            'mode' => $execute ? 'execute_plan' : 'dry_run',
            'dry_run' => ! $execute,
            'writes_committed' => false,
            'approval_phrase_required' => self::APPROVAL_PHRASE,
            'package_path' => $root,
            'package_status' => $dryRun['package_status'] ?? 'Unknown',
            'pre_import_qa_decision' => $preImportQa['decision'] ?? 'Unknown',
            'non_public_draft_import_qa_passed' => $preImportQa['non_public_draft_import_qa_passed'] ?? false,
            'publish_allowed' => false,
            'discoverability_allowed' => false,
            'pages_seen' => count($pages),
            'planned_create_count' => count($plannedCreates),
            'skipped_existing_count' => count($skippedExisting),
            'authority_revision_only_count' => count($authorityRevisionOnly),
            'blocked_count' => count($blockedPages),
            'created_count' => 0,
            'pages' => $pages,
            'errors' => $errors,
            'hard_guards' => [
                'draft_only' => true,
                'is_public_false' => true,
                'is_indexable_false' => true,
                'publish_allowed_false' => true,
                'faq_schema_eligible_false' => true,
                'sitemap_llms_footer_unchanged' => true,
                'method_boundaries_write_blocked' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dryRunPage
     * @return array<string, mixed>
     */
    private function planPage(string $root, array $dryRunPage, bool $execute): array
    {
        $normalized = is_array($dryRunPage['normalized_content_page'] ?? null)
            ? $dryRunPage['normalized_content_page']
            : [];
        $slug = (string) ($normalized['slug'] ?? '');
        $locale = (string) ($normalized['locale'] ?? 'zh-CN');
        $pageKey = (string) ($dryRunPage['page_key'] ?? 'Unknown');

        if (($dryRunPage['planned_action'] ?? '') === 'preserve_existing_authority_revision_only') {
            return [
                'page_key' => $pageKey,
                'slug' => $slug,
                'locale' => $locale,
                'action' => 'skip_existing_authority_revision_only',
                'blocked' => false,
                'reason' => 'existing authority route must not be overwritten by this draft import command.',
            ];
        }

        $existing = null;
        $existingLookupStatus = 'available';
        try {
            $existing = ContentPage::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('slug', $slug)
                ->where('locale', $locale)
                ->first();
        } catch (\Throwable $throwable) {
            if ($execute) {
                throw $throwable;
            }

            $existingLookupStatus = 'Unknown';
        }

        if ($existing instanceof ContentPage) {
            return [
                'page_key' => $pageKey,
                'slug' => $slug,
                'locale' => $locale,
                'action' => 'skip_existing_content_page',
                'blocked' => false,
                'existing_content_page_id' => (int) $existing->getKey(),
                'existing_status' => (string) $existing->status,
                'existing_is_public' => (bool) $existing->is_public,
                'existing_is_indexable' => (bool) $existing->is_indexable,
            ];
        }

        $file = (string) ($dryRunPage['file'] ?? '');
        $filePath = $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        if ($file === '' || ! is_file($filePath)) {
            return [
                'page_key' => $pageKey,
                'slug' => $slug,
                'locale' => $locale,
                'action' => 'blocked_missing_page_file',
                'blocked' => true,
            ];
        }

        [$frontmatter, $body] = $this->readFrontmatter($filePath);
        $attributes = $this->contentPageAttributes($normalized, $frontmatter, $body, $file);

        return [
            'page_key' => $pageKey,
            'slug' => $slug,
            'locale' => $locale,
            'action' => 'create_missing_non_public_draft',
            'blocked' => false,
            'existing_lookup_status' => $existingLookupStatus,
            'content_page_attributes' => $attributes,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $frontmatter
     * @return array<string, mixed>
     */
    private function contentPageAttributes(array $normalized, array $frontmatter, string $body, string $file): array
    {
        $slug = (string) ($normalized['slug'] ?? '');
        $path = (string) ($normalized['path'] ?? ('/'.$slug));
        $title = $this->cleanString($frontmatter['zh_title'] ?? $frontmatter['h1'] ?? $slug);
        $metaDescription = $this->cleanString($frontmatter['meta_description_draft'] ?? '');
        $content = $this->cleanString($body);

        return [
            'org_id' => 0,
            'slug' => $slug,
            'path' => $path,
            'canonical_path' => (string) ($normalized['canonical_path'] ?? $path),
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => (string) ($normalized['page_type'] ?? 'methodology'),
            'title' => $title,
            'summary' => $metaDescription !== '' ? $metaDescription : null,
            'template' => 'policy',
            'animation_profile' => 'editorial',
            'locale' => (string) ($normalized['locale'] ?? 'zh-CN'),
            'translation_group_id' => 'science-contentpage-'.$slug.'-zh-CN',
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'source_doc' => 'science-contentpage-gpt55-review-draft-2026-06-08/'.$file,
            'is_public' => false,
            'is_indexable' => false,
            'review_state' => (string) ($normalized['review_state'] ?? 'science_review'),
            'owner' => 'Fermat Institute',
            'legal_review_required' => (bool) ($normalized['legal_review_required'] ?? true),
            'science_review_required' => (bool) ($normalized['science_review_required'] ?? true),
            'headings_json' => $this->headings($content),
            'content_md' => $content,
            'content_html' => '',
            'seo_title' => $this->nullableString($frontmatter['meta_title_draft'] ?? null),
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'seo_description' => $metaDescription !== '' ? $metaDescription : null,
            'faq_items' => $this->visibleFaqItems($content),
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => true,
            'operator_approved_at' => null,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => false,
            'schema_eligibility_reviewed_at' => null,
            'status' => ContentPage::STATUS_DRAFT,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function readFrontmatter(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (! preg_match('/\A---\R(?P<yaml>.*?)\R---\R(?P<body>.*)\z/s', $content, $matches)) {
            throw new \RuntimeException('Page file is missing YAML frontmatter: '.$path);
        }

        $frontmatter = Yaml::parse((string) $matches['yaml']);
        if (! is_array($frontmatter)) {
            throw new \RuntimeException('Page frontmatter must parse to an object: '.$path);
        }

        return [$frontmatter, (string) $matches['body']];
    }

    /**
     * @return list<string>
     */
    private function headings(string $body): array
    {
        preg_match_all('/^#{1,3}\s+(.+)$/m', $body, $matches);

        return array_values(array_map(
            fn (string $heading): string => $this->cleanString($heading),
            $matches[1] ?? [],
        ));
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function visibleFaqItems(string $body): array
    {
        $marker = "\nvisible_faq_items:\n";
        $position = strpos($body, $marker);
        if ($position === false) {
            return [];
        }

        $faqBody = trim(substr($body, $position + strlen($marker)));
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/', $faqBody) ?: [],
        ), static fn (string $line): bool => $line !== ''));

        $items = [];
        for ($index = 0; $index < count($lines) - 1; $index++) {
            $question = $this->cleanString($lines[$index]);
            if (! str_ends_with($question, '?') && ! str_ends_with($question, '？')) {
                continue;
            }

            $items[] = [
                'question' => $question,
                'answer' => $this->cleanString($lines[$index + 1]),
            ];
            $index++;
        }

        return $items;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->cleanString($value);

        return $string !== '' ? $string : null;
    }

    private function cleanString(mixed $value): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }

        if (preg_match('//u', $string) === 1) {
            return $string;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
            if (is_string($converted) && preg_match('//u', $converted) === 1) {
                return trim($converted);
            }
        }

        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $string);
        if (is_string($cleaned) && preg_match('//u', $cleaned) === 1) {
            return trim($cleaned);
        }

        $encoded = json_encode($string, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

        return is_string($decoded) ? trim($decoded) : '';
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function error(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
