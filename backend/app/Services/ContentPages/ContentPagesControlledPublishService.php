<?php

declare(strict_types=1);

namespace App\Services\ContentPages;

use App\Models\ContentPage;
use App\Services\Cms\RowBackedRevisionWorkspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ContentPagesControlledPublishService
{
    public const COMMAND = 'content-pages:publish-controlled';

    public const LOCALE = 'en';

    public const CMS_DRAFT_UPDATE_SOURCE_MARKER = 'global-en-zh-content-pages-cms-draft-update-01';

    public const FOUNDATION_FACT_STATE = 'planned_public_benefit_shareholding';

    public const R2_READINESS_DECISION = 'content_pages_publish_readiness_r2_completed_ready_for_controlled_publish';

    /**
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'brand',
        'charter',
        'foundation',
        'careers',
        'policies',
    ];

    /**
     * @var list<string>
     */
    public const PROTECTED_KEYS = [
        'about',
        'help-about',
        'help-contact',
        'help-faq',
        'help-for-business-and-research',
        'method-boundaries',
        'privacy',
        'terms',
    ];

    public function __construct(
        private readonly RowBackedRevisionWorkspace $workspace,
    ) {}

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function dryRun(string $locale, array $keys): array
    {
        return $this->buildPlan($locale, $keys, false);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function execute(string $locale, array $keys): array
    {
        return DB::transaction(function () use ($locale, $keys): array {
            $plan = $this->buildPlan($locale, $keys, true);
            if (! (bool) ($plan['ok'] ?? false)) {
                return $plan;
            }

            $published = [];
            $skipped = [];

            foreach ((array) ($plan['pages'] ?? []) as $pagePlan) {
                $key = (string) ($pagePlan['key'] ?? '');
                $page = ContentPage::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('locale', self::LOCALE)
                    ->where('slug', $key)
                    ->lockForUpdate()
                    ->first();

                if (! $page instanceof ContentPage) {
                    throw new RuntimeException('Target content page disappeared during controlled publish: '.$key);
                }

                if ($this->isPublishedTarget($page)) {
                    $skipped[] = $key;

                    continue;
                }

                $publishedPage = $this->workspace->publishWorkingRevision('content_page', $page);
                $published[] = (string) $publishedPage->slug;
            }

            $afterPlan = $this->buildPlan($locale, $keys, true);

            return [
                ...$afterPlan,
                'dry_run' => false,
                'execute' => true,
                'writes_committed' => (bool) ($afterPlan['ok'] ?? false),
                'published_keys' => $published,
                'skipped_keys' => $skipped,
            ];
        });
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function buildPlan(string $locale, array $keys, bool $forExecute): array
    {
        $keys = array_values(array_map(static fn (string $key): string => strtolower(trim($key)), $keys));
        $errors = [];

        if ($locale !== self::LOCALE) {
            $errors[] = $this->issue('locale', 'unsupported_locale', 'Controlled content-page publish only allows locale=en.', [
                'actual' => $locale,
            ]);
        }

        if ($keys === []) {
            $errors[] = $this->issue('keys', 'missing_keys', 'Exact --keys scope is required.');
        }

        if (count($keys) !== count(array_unique($keys))) {
            $errors[] = $this->issue('keys', 'duplicate_keys', 'Duplicate keys are not allowed.');
        }

        $extraKeys = array_values(array_diff($keys, self::ALLOWED_KEYS));
        if ($extraKeys !== []) {
            $errors[] = $this->issue('keys', 'extra_keys_not_allowed', 'Only the Wave 1 approved English content page keys are allowed.', [
                'extra_keys' => $extraKeys,
            ]);
        }

        $missingScopeKeys = array_values(array_diff(self::ALLOWED_KEYS, $keys));
        if ($missingScopeKeys !== []) {
            $errors[] = $this->issue('keys', 'exact_scope_required', 'The controlled publish runtime requires the exact five-page Wave 1 scope.', [
                'missing_scope_keys' => $missingScopeKeys,
            ]);
        }

        $readiness = $this->readinessArtifactState();
        if (! (bool) ($readiness['ready'] ?? false)) {
            $errors[] = $this->issue('readiness', 'r2_readiness_not_ready', 'Merged R2 readiness artifact must approve all five pages before controlled publish.', [
                'artifact_state' => $readiness,
            ]);
        }

        $records = ContentPage::query()
            ->withoutGlobalScopes()
            ->with('workingRevision')
            ->where('org_id', 0)
            ->where('locale', self::LOCALE)
            ->whereIn('slug', self::ALLOWED_KEYS)
            ->get()
            ->keyBy(static fn (ContentPage $page): string => (string) $page->slug);

        $pages = [];
        foreach (self::ALLOWED_KEYS as $key) {
            $page = $records->get($key);
            if (! $page instanceof ContentPage) {
                $errors[] = $this->issue('content_pages.'.$key, 'missing_target_record', 'Target content page record is missing.', [
                    'key' => $key,
                ]);

                continue;
            }

            $pageErrors = $this->preflightPage($page);
            array_push($errors, ...$pageErrors);

            $pages[] = [
                'key' => $key,
                'id' => (int) $page->id,
                'before_state' => $this->state($page),
                'after_state_preview' => $this->afterStatePreview($page),
                'action' => $this->isPublishedTarget($page) ? 'skip_already_published' : 'publish',
                'errors' => $pageErrors,
            ];
        }

        $wouldPublishCount = count(array_filter(
            $pages,
            static fn (array $page): bool => ($page['action'] ?? null) === 'publish'
        ));
        $wouldSkipCount = count($pages) - $wouldPublishCount;
        $ok = $errors === [];

        return [
            'ok' => $ok,
            'command' => self::COMMAND,
            'dry_run' => ! $forExecute,
            'execute' => $forExecute,
            'writes_committed' => false,
            'target_count' => count(self::ALLOWED_KEYS),
            'would_publish_count' => $ok ? $wouldPublishCount : 0,
            'would_update_count' => $ok ? $wouldPublishCount : 0,
            'would_create_count' => 0,
            'would_skip_count' => $ok ? $wouldSkipCount : 0,
            'blocked_count' => count($errors),
            'target_keys' => self::ALLOWED_KEYS,
            'allowed_keys' => self::ALLOWED_KEYS,
            'forbidden_keys' => self::PROTECTED_KEYS,
            'before_state' => $this->indexByKey($pages, 'before_state'),
            'after_state_preview' => $this->indexByKey($pages, 'after_state_preview'),
            'foundation_fact_state' => self::FOUNDATION_FACT_STATE,
            'forbidden_foundation_claims_absent' => ! $this->hasFoundationOverclaim($records->get('foundation')),
            'discoverability_coupled' => false,
            'discoverability_coupling_policy' => 'The runtime preserves is_indexable=false and fails if any target is already indexable before publish; sitemap/llms/footer/nav exposure remains out of scope.',
            'search_channel_action_attempted' => false,
            'url_submission_attempted' => false,
            'external_search_api_call_attempted' => false,
            'deploy_attempted' => false,
            'sitemap_llms_footer_explicit_enablement' => false,
            'no_record_creation' => true,
            'no_upsert_missing' => true,
            'no_out_of_scope_cms_write' => true,
            'pages' => $pages,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function preflightPage(ContentPage $page): array
    {
        $errors = [];
        $key = (string) $page->slug;

        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            $errors[] = $this->issue('content_pages.'.$key, 'non_allowlisted_key', 'Content page key is not allowlisted.');
        }

        if ((string) $page->locale !== self::LOCALE) {
            $errors[] = $this->issue('content_pages.'.$key.'.locale', 'invalid_locale', 'Target content page must be locale=en.');
        }

        if (! str_contains((string) $page->source_doc, self::CMS_DRAFT_UPDATE_SOURCE_MARKER)) {
            $errors[] = $this->issue('content_pages.'.$key.'.source_doc', 'missing_cms_draft_update_marker', 'Target content page must reflect the approved CMS draft update source marker.');
        }

        if ((bool) $page->is_indexable) {
            $errors[] = $this->issue('content_pages.'.$key.'.is_indexable', 'discoverability_coupling_risk', 'Controlled publish must not make sitemap/llms eligibility inseparable; target drafts must remain non-indexable.');
        }

        if (! $this->isDraftTarget($page) && ! $this->isPublishedTarget($page)) {
            $errors[] = $this->issue('content_pages.'.$key.'.status', 'invalid_publish_state', 'Target content page must be an unpublished draft or an already-published idempotency match.');
        }

        if ($key === 'foundation') {
            $text = $this->pageText($page);
            if (! str_contains($text, 'planned public-benefit shareholding')) {
                $errors[] = $this->issue('content_pages.foundation', 'foundation_fact_state_missing', 'Foundation page must retain planned public-benefit shareholding language.');
            }

            if (! str_contains($text, 'public-benefit mission and governance')) {
                $errors[] = $this->issue('content_pages.foundation', 'foundation_governance_framing_missing', 'Foundation page must retain Public-Benefit Mission and Governance framing.');
            }

            if ($this->hasFoundationOverclaim($page)) {
                $errors[] = $this->issue('content_pages.foundation', 'foundation_overclaim_detected', 'Foundation page contains a forbidden legal/foundation overclaim.');
            }
        }

        return $errors;
    }

    private function isDraftTarget(ContentPage $page): bool
    {
        return (string) $page->status === ContentPage::STATUS_DRAFT
            && ! (bool) $page->is_public
            && $page->published_at === null;
    }

    private function isPublishedTarget(ContentPage $page): bool
    {
        return (string) $page->status === ContentPage::STATUS_PUBLISHED
            && (bool) $page->is_public
            && $page->published_at !== null;
    }

    private function hasFoundationOverclaim(mixed $page): bool
    {
        if (! $page instanceof ContentPage) {
            return false;
        }

        $text = $this->pageText($page);
        $patterns = [
            '/\\b(is|are|as|operates as|registered as|has registered as|became|becomes)\\s+(a\\s+)?registered foundation\\b/i',
            '/\\b(is|are|as|operates as|registered as|has registered as|became|becomes)\\s+(a\\s+)?nonprofit\\b/i',
            '/\\b(is|are|as|operates as|registered as|has registered as|became|becomes)\\s+(a\\s+)?charit(y|able registration)\\b/i',
            '/\\b(accepts|handles|runs|offers|provides|launched)\\s+(public\\s+)?(donations?|grants?)\\b/i',
            '/\\b(formal board governance|legal fiduciary duty|fiduciary duty)\\b/i',
            '/\\b\\d+(?:\\.\\d+)?%\\s+(ownership|shareholding|equity)\\b/i',
            '/\\b(completed|finalized|transferred|has transferred|now holds)\\s+(the\\s+)?(equity transfer|foundation holding|shares?|equity)\\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function pageText(ContentPage $page): string
    {
        return strtolower(implode("\n", array_filter([
            (string) $page->title,
            (string) $page->summary,
            (string) $page->content_md,
            (string) $page->content_html,
            (string) $page->seo_title,
            (string) $page->seo_description,
            (string) $page->meta_description,
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(ContentPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'slug' => (string) $page->slug,
            'locale' => (string) $page->locale,
            'status' => (string) $page->status,
            'is_public' => (bool) $page->is_public,
            'is_indexable' => (bool) $page->is_indexable,
            'published_at' => $page->published_at?->toDateString(),
            'source_doc' => $page->source_doc,
            'working_revision_id' => $page->working_revision_id ? (int) $page->working_revision_id : null,
            'published_revision_id' => $page->published_revision_id ? (int) $page->published_revision_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function afterStatePreview(ContentPage $page): array
    {
        return [
            ...$this->state($page),
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => (bool) $page->is_indexable,
            'published_at' => $page->published_at?->toDateString() ?? 'set_at_execute_time',
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'footer_eligible' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function indexByKey(array $pages, string $field): array
    {
        $indexed = [];
        foreach ($pages as $page) {
            $indexed[(string) ($page['key'] ?? '')] = $page[$field] ?? null;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessArtifactState(): array
    {
        $path = base_path('docs/seo/generated/global-en-zh-content-pages-publish-readiness-r2.v1.json');
        if (! is_file($path)) {
            return [
                'ready' => false,
                'reason' => 'artifact_missing',
                'path' => $path,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'ready' => false,
                'reason' => 'artifact_invalid_json',
                'path' => $path,
            ];
        }

        return [
            'ready' => ($decoded['final_decision'] ?? null) === self::R2_READINESS_DECISION
                && ($decoded['publish_scope_recommendation'] ?? null) === 'all_five_pages'
                && ($decoded['target_pages'] ?? null) === self::ALLOWED_KEYS,
            'final_decision' => $decoded['final_decision'] ?? null,
            'publish_scope_recommendation' => $decoded['publish_scope_recommendation'] ?? null,
            'target_pages' => $decoded['target_pages'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function issue(string $field, string $code, string $message, array $context = []): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }
}
