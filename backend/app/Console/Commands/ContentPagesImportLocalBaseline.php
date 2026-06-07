<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ContentPagesImportLocalBaseline extends Command
{
    protected $signature = 'content-pages:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--upsert : Update existing records instead of create-missing only}
        {--status=published : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed company and policy page baseline content into ContentPage CMS tables.';

    public function handle(): int
    {
        try {
            $dryRun = (bool) $this->option('dry-run');
            $upsert = (bool) $this->option('upsert');
            $status = trim((string) $this->option('status'));
            if (! in_array($status, [ContentPage::STATUS_DRAFT, ContentPage::STATUS_PUBLISHED], true)) {
                throw new RuntimeException('Unsupported --status value: '.$status);
            }

            $sourceDir = $this->resolveSourceDir((string) ($this->option('source-dir') ?? ''));
            $files = glob($sourceDir.'/*.json') ?: [];
            sort($files);

            $pages = [];
            foreach ($files as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Invalid content page baseline JSON: '.$file);
                }

                foreach ($decoded as $row) {
                    if (is_array($row)) {
                        $pages[] = $this->normalizePage($row, $status);
                    }
                }
            }

            $summary = [
                'files_found' => count($files),
                'pages_found' => count($pages),
                'will_create' => 0,
                'will_update' => 0,
                'will_skip' => 0,
            ];

            foreach ($pages as $page) {
                $existing = ContentPage::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('slug', $page['slug'])
                    ->where('locale', $page['locale'])
                    ->first();

                if (! $existing instanceof ContentPage) {
                    $summary['will_create']++;
                    if (! $dryRun) {
                        ContentPage::query()->withoutGlobalScopes()->create($page);
                    }

                    continue;
                }

                if (! $upsert) {
                    $summary['will_skip']++;

                    continue;
                }

                $current = $existing->only(array_keys($page));
                if ($this->normalizeComparable($current) === $this->normalizeComparable($page)) {
                    $summary['will_skip']++;

                    continue;
                }

                $summary['will_update']++;
                if (! $dryRun) {
                    DB::transaction(static function () use ($existing, $page): void {
                        $existing->fill($page);
                        $existing->save();
                    });
                }
            }

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('dry_run='.($dryRun ? '1' : '0'));
            $this->line('upsert='.($upsert ? '1' : '0'));
            $this->line('status_mode='.$status);
            foreach ($summary as $key => $value) {
                $this->line($key.'='.(string) $value);
            }
            $this->info($dryRun ? 'dry-run complete' : 'import complete');

            return 0;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return 1;
        }
    }

    private function resolveSourceDir(string $override): string
    {
        $override = trim($override);
        $candidate = $override !== ''
            ? ($this->isAbsolutePath($override) ? $override : base_path($override))
            : base_path('../content_baselines/content_pages');

        $real = realpath($candidate);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Content page baseline source directory not found: '.$candidate);
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizePage(array $row, string $status): array
    {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        $locale = $this->normalizeLocale((string) ($row['locale'] ?? 'en'));
        $contentMd = trim((string) ($row['contentMd'] ?? $row['content_md'] ?? ''));
        $contentHtml = trim((string) ($row['contentHtml'] ?? $row['content_html'] ?? ''));

        if ($slug === '' || $locale === '' || trim((string) ($row['title'] ?? '')) === '') {
            throw new RuntimeException('Content page baseline row is missing slug, locale, or title.');
        }

        if ($contentMd === '' && $contentHtml === '') {
            throw new RuntimeException('Content page baseline row is missing content: '.$slug);
        }

        return [
            'org_id' => 0,
            'slug' => $slug,
            'path' => trim((string) ($row['path'] ?? '')) ?: '/'.$slug,
            'kind' => $this->normalizeKind($row['kind'] ?? null),
            'page_type' => $this->normalizePageType($row['pageType'] ?? $row['page_type'] ?? $slug),
            'title' => trim((string) $row['title']),
            'kicker' => $this->nullableString($row['kicker'] ?? null),
            'summary' => $this->nullableString($row['summary'] ?? null),
            'template' => $this->normalizeTemplate($row['template'] ?? null),
            'animation_profile' => $this->normalizeAnimationProfile($row['animationProfile'] ?? $row['animation_profile'] ?? null),
            'locale' => $locale,
            'translation_group_id' => $this->nullableString($row['translationGroupId'] ?? $row['translation_group_id'] ?? null)
                ?? $this->defaultTranslationGroupId($slug),
            'source_locale' => $this->nullableString($row['sourceLocale'] ?? $row['source_locale'] ?? null)
                ?? 'zh-CN',
            'translation_status' => $this->normalizeTranslationStatus(
                $row['translationStatus'] ?? $row['translation_status'] ?? null,
                $locale,
                $status,
            ),
            'published_at' => $this->nullableString($row['publishedAt'] ?? $row['published_at'] ?? null),
            'source_updated_at' => $this->nullableString($row['updatedAt'] ?? $row['updated_at'] ?? null),
            'effective_at' => $this->nullableString($row['effectiveAt'] ?? $row['effective_at'] ?? null),
            'source_doc' => $this->nullableString($row['sourceDoc'] ?? $row['source_doc'] ?? null),
            'is_public' => (bool) ($row['isPublic'] ?? $row['is_public'] ?? true),
            'is_indexable' => (bool) ($row['isIndexable'] ?? $row['is_indexable'] ?? true),
            'review_state' => $this->normalizeReviewState($row['reviewState'] ?? $row['review_state'] ?? null, $status),
            'headings_json' => array_values(array_filter(array_map(
                static fn (mixed $heading): string => trim((string) $heading),
                is_array($row['headings'] ?? null) ? $row['headings'] : $this->extractHeadings($contentMd),
            ))),
            'content_md' => $contentMd,
            'content_html' => $contentHtml,
            'seo_title' => $this->nullableString($row['seoTitle'] ?? $row['seo_title'] ?? $row['title'] ?? null),
            'meta_description' => $this->nullableString($row['metaDescription'] ?? $row['meta_description'] ?? $row['summary'] ?? null),
            'seo_description' => $this->nullableString($row['seoDescription'] ?? $row['seo_description'] ?? $row['metaDescription'] ?? $row['meta_description'] ?? $row['summary'] ?? null),
            'canonical_path' => $this->nullableString($row['canonicalPath'] ?? $row['canonical_path'] ?? null),
            'support_contact' => $this->nullableString($row['support_contact'] ?? $row['supportContact'] ?? null),
            'policy_version' => $this->nullableString($row['policy_version'] ?? $row['policyVersion'] ?? null),
            'reviewer' => $this->nullableString($row['reviewer'] ?? null),
            'faq_items' => $this->normalizeFaqItems($row['faq_items'] ?? $row['faqItems'] ?? []),
            'schema_enabled' => (bool) ($row['schema_enabled'] ?? $row['schemaEnabled'] ?? false),
            'status' => $status,
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : 'en';
    }

    private function normalizeKind(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, [ContentPage::KIND_COMPANY, ContentPage::KIND_POLICY, ContentPage::KIND_HELP], true)
            ? $normalized
            : ContentPage::KIND_COMPANY;
    }

    private function normalizeTemplate(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['company', 'charter', 'foundation', 'careers', 'brand', 'policy', 'help'], true)
            ? $normalized
            : 'company';
    }

    private function normalizePageType(mixed $value): string
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', trim((string) $value)));
        $aliases = [
            'method-boundaries' => 'boundary',
            'methodology-boundaries' => 'boundary',
            'careers' => 'company',
            'brand' => 'company',
            'charter' => 'company',
            'foundation' => 'company',
            'policies' => 'policy',
            'faq' => 'support_static',
            'help-faq' => 'support_static',
            'help-about' => 'about',
            'help-contact' => 'support_static',
            'help-team' => 'company',
            'help-used-and-mentioned' => 'company',
            'help-for-business-and-research' => 'company',
        ];

        $normalized = $aliases[$normalized] ?? $normalized;

        return in_array($normalized, ContentPage::PAGE_TYPES, true)
            ? $normalized
            : ContentPage::PAGE_TYPES[7];
    }

    private function normalizeTranslationStatus(mixed $value, string $locale, string $status): string
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, [
            ContentPage::TRANSLATION_STATUS_SOURCE,
            ContentPage::TRANSLATION_STATUS_DRAFT,
            ContentPage::TRANSLATION_STATUS_MACHINE_DRAFT,
            ContentPage::TRANSLATION_STATUS_HUMAN_REVIEW,
            ContentPage::TRANSLATION_STATUS_APPROVED,
            ContentPage::TRANSLATION_STATUS_PUBLISHED,
            ContentPage::TRANSLATION_STATUS_STALE,
            ContentPage::TRANSLATION_STATUS_ARCHIVED,
        ], true)) {
            return $normalized;
        }

        if ($locale === 'zh-CN') {
            return ContentPage::TRANSLATION_STATUS_SOURCE;
        }

        return $status === ContentPage::STATUS_PUBLISHED
            ? ContentPage::TRANSLATION_STATUS_PUBLISHED
            : ContentPage::TRANSLATION_STATUS_DRAFT;
    }

    private function normalizeReviewState(mixed $value, string $status): string
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ContentPage::REVIEW_STATES, true)) {
            return $normalized;
        }

        return $status === ContentPage::STATUS_PUBLISHED ? 'approved' : 'draft';
    }

    private function defaultTranslationGroupId(string $slug): string
    {
        return 'content-page-'.preg_replace('/[^a-z0-9]+/', '-', $slug);
    }

    private function normalizeAnimationProfile(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['mission', 'principles', 'editorial', 'brand', 'policy', 'none'], true)
            ? $normalized
            : 'none';
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return list<array{question:string,answer:string}>
     */
    private function normalizeFaqItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function extractHeadings(string $contentMd): array
    {
        preg_match_all('/^#{2,3}\s+(.+)$/m', $contentMd, $matches);

        return array_values(array_filter(array_map(
            static fn (string $heading): string => trim($heading),
            $matches[1] ?? []
        )));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeComparable(array $payload): array
    {
        $payload['headings_json'] = is_array($payload['headings_json'] ?? null)
            ? array_values($payload['headings_json'])
            : [];

        return $payload;
    }
}
