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

    public const SCOPE_GLOBAL_EN_WAVE1 = 'global-en-wave1';

    public const SCOPE_HELP_SERVICE = 'help-service';

    public const LOCALE = 'en';

    public const HELP_SERVICE_LOCALE_OPTION = 'all';

    public const CMS_DRAFT_UPDATE_SOURCE_MARKER = 'global-en-zh-content-pages-cms-draft-update-01';

    public const HELP_SERVICE_SOURCE_MARKER = 'HELP-SERVICE-CONTENT-DRAFTS-01';

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
    public const HELP_SERVICE_ALLOWED_KEYS = [
        'help-unlock-failure',
        'help-payment-refund',
        'help-result-recovery',
        'help-privacy-data',
        'help-use-boundaries',
        'help-data-deletion',
    ];

    /**
     * @var list<string>
     */
    public const HELP_SERVICE_LOCALES = [
        'zh-CN',
        'en',
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
    public function dryRun(string $scope, string $locale, array $keys): array
    {
        return $this->buildPlan($scope, $locale, $keys, false);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function execute(string $scope, string $locale, array $keys): array
    {
        return DB::transaction(function () use ($scope, $locale, $keys): array {
            $plan = $this->buildPlan($scope, $locale, $keys, true);
            if (! (bool) ($plan['ok'] ?? false)) {
                return $plan;
            }

            $published = [];
            $skipped = [];

            foreach ((array) ($plan['pages'] ?? []) as $pagePlan) {
                $key = (string) ($pagePlan['key'] ?? '');
                $targetLocale = (string) ($pagePlan['locale'] ?? self::LOCALE);
                $page = ContentPage::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('locale', $targetLocale)
                    ->where('slug', $key)
                    ->lockForUpdate()
                    ->first();

                if (! $page instanceof ContentPage) {
                    throw new RuntimeException('Target content page disappeared during controlled publish: '.$targetLocale.':'.$key);
                }

                if ($this->isPublishedTarget($page)) {
                    $skipped[] = (string) ($pagePlan['target_key'] ?? $key);

                    continue;
                }

                $publishedPage = $this->workspace->publishWorkingRevision('content_page', $page);
                $published[] = (string) ($pagePlan['target_key'] ?? $publishedPage->slug);
            }

            $afterPlan = $this->buildPlan($scope, $locale, $keys, true);

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
    private function buildPlan(string $scope, string $locale, array $keys, bool $forExecute): array
    {
        $scope = trim($scope) === '' ? self::SCOPE_GLOBAL_EN_WAVE1 : strtolower(trim($scope));
        $keys = array_values(array_map(static fn (string $key): string => strtolower(trim($key)), $keys));
        $errors = [];
        $allowedKeys = $this->allowedKeys($scope);
        $targetLocales = $this->targetLocales($scope, $locale);

        if (! in_array($scope, [self::SCOPE_GLOBAL_EN_WAVE1, self::SCOPE_HELP_SERVICE], true)) {
            $errors[] = $this->issue('scope', 'unsupported_scope', 'Unsupported content-pages controlled publish scope.', [
                'actual' => $scope,
                'supported' => [self::SCOPE_GLOBAL_EN_WAVE1, self::SCOPE_HELP_SERVICE],
            ]);
        }

        if ($scope === self::SCOPE_GLOBAL_EN_WAVE1 && $locale !== self::LOCALE) {
            $errors[] = $this->issue('locale', 'unsupported_locale', 'Controlled content-page publish only allows locale=en.', [
                'actual' => $locale,
            ]);
        }

        if ($scope === self::SCOPE_HELP_SERVICE && $locale !== self::HELP_SERVICE_LOCALE_OPTION) {
            $errors[] = $this->issue('locale', 'unsupported_locale', 'Help service controlled publish requires --locale=all so both zh-CN and en rows are in scope.', [
                'actual' => $locale,
            ]);
        }

        if ($keys === []) {
            $errors[] = $this->issue('keys', 'missing_keys', 'Exact --keys scope is required.');
        }

        if (count($keys) !== count(array_unique($keys))) {
            $errors[] = $this->issue('keys', 'duplicate_keys', 'Duplicate keys are not allowed.');
        }

        $extraKeys = array_values(array_diff($keys, $allowedKeys));
        if ($extraKeys !== []) {
            $errors[] = $this->issue('keys', 'extra_keys_not_allowed', 'Only the approved content page keys for this scope are allowed.', [
                'extra_keys' => $extraKeys,
                'scope' => $scope,
            ]);
        }

        $missingScopeKeys = array_values(array_diff($allowedKeys, $keys));
        if ($missingScopeKeys !== []) {
            $errors[] = $this->issue('keys', 'exact_scope_required', 'The controlled publish runtime requires the exact approved scope.', [
                'missing_scope_keys' => $missingScopeKeys,
                'scope' => $scope,
            ]);
        }

        $readiness = $this->readinessArtifactState($scope);
        if (! (bool) ($readiness['ready'] ?? false)) {
            $errors[] = $this->issue('readiness', 'readiness_not_ready', 'Merged readiness artifact must approve the controlled publish scope before publish.', [
                'artifact_state' => $readiness,
                'scope' => $scope,
            ]);
        }

        $records = ContentPage::query()
            ->withoutGlobalScopes()
            ->with('workingRevision')
            ->where('org_id', 0)
            ->whereIn('locale', $targetLocales)
            ->whereIn('slug', $allowedKeys)
            ->get()
            ->keyBy(static fn (ContentPage $page): string => (string) $page->locale.':'.(string) $page->slug);

        $pages = [];
        foreach ($targetLocales as $targetLocale) {
            foreach ($allowedKeys as $key) {
                $targetKey = $this->targetKey($scope, $targetLocale, $key);
                $page = $records->get($targetLocale.':'.$key);
                if (! $page instanceof ContentPage) {
                    $errors[] = $this->issue('content_pages.'.$targetKey, 'missing_target_record', 'Target content page record is missing.', [
                        'key' => $key,
                        'locale' => $targetLocale,
                        'target_key' => $targetKey,
                    ]);

                    continue;
                }

                $pageErrors = $this->preflightPage($scope, $page);
                array_push($errors, ...$pageErrors);

                $pages[] = [
                    'key' => $key,
                    'locale' => $targetLocale,
                    'target_key' => $targetKey,
                    'id' => (int) $page->id,
                    'before_state' => $this->state($page),
                    'after_state_preview' => $this->afterStatePreview($page),
                    'action' => $this->isPublishedTarget($page) ? 'skip_already_published' : 'publish',
                    'errors' => $pageErrors,
                ];
            }
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
            'scope' => $scope,
            'dry_run' => ! $forExecute,
            'execute' => $forExecute,
            'writes_committed' => false,
            'target_count' => count($allowedKeys) * count($targetLocales),
            'would_publish_count' => $ok ? $wouldPublishCount : 0,
            'would_update_count' => $ok ? $wouldPublishCount : 0,
            'would_create_count' => 0,
            'would_skip_count' => $ok ? $wouldSkipCount : 0,
            'blocked_count' => count($errors),
            'target_keys' => array_values(array_map(
                fn (array $page): string => (string) ($page['target_key'] ?? $page['key'] ?? ''),
                $pages,
            )),
            'allowed_keys' => $allowedKeys,
            'target_locales' => $targetLocales,
            'forbidden_keys' => $this->protectedKeys($scope),
            'before_state' => $this->indexByKey($pages, 'before_state'),
            'after_state_preview' => $this->indexByKey($pages, 'after_state_preview'),
            'foundation_fact_state' => self::FOUNDATION_FACT_STATE,
            'forbidden_foundation_claims_absent' => $scope === self::SCOPE_GLOBAL_EN_WAVE1
                ? ! $this->hasFoundationOverclaim($records->get(self::LOCALE.':foundation'))
                : true,
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
    private function preflightPage(string $scope, ContentPage $page): array
    {
        $errors = [];
        $key = (string) $page->slug;

        if (! in_array($key, $this->allowedKeys($scope), true)) {
            $errors[] = $this->issue('content_pages.'.$key, 'non_allowlisted_key', 'Content page key is not allowlisted.');
        }

        if (! in_array((string) $page->locale, $this->targetLocales($scope, $scope === self::SCOPE_HELP_SERVICE ? self::HELP_SERVICE_LOCALE_OPTION : self::LOCALE), true)) {
            $errors[] = $this->issue('content_pages.'.$key.'.locale', 'invalid_locale', 'Target content page locale is not allowed for this scope.', [
                'scope' => $scope,
                'locale' => (string) $page->locale,
            ]);
        }

        $sourceMarker = $scope === self::SCOPE_HELP_SERVICE ? self::HELP_SERVICE_SOURCE_MARKER : self::CMS_DRAFT_UPDATE_SOURCE_MARKER;
        if (! str_contains((string) $page->source_doc, $sourceMarker)) {
            $errors[] = $this->issue('content_pages.'.$key.'.source_doc', 'missing_cms_draft_update_marker', 'Target content page must reflect the approved CMS draft update source marker.', [
                'expected_marker' => $sourceMarker,
            ]);
        }

        if ((bool) $page->is_indexable) {
            $errors[] = $this->issue('content_pages.'.$key.'.is_indexable', 'discoverability_coupling_risk', 'Controlled publish must not make sitemap/llms eligibility inseparable; target drafts must remain non-indexable.');
        }

        if (! $this->isDraftTarget($page) && ! $this->isPublishedTarget($page)) {
            $errors[] = $this->issue('content_pages.'.$key.'.status', 'invalid_publish_state', 'Target content page must be an unpublished draft or an already-published idempotency match.');
        }

        if ($scope === self::SCOPE_HELP_SERVICE) {
            array_push($errors, ...$this->preflightHelpServicePage($page));
        }

        if ($scope === self::SCOPE_GLOBAL_EN_WAVE1 && $key === 'foundation') {
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

    /**
     * @return list<array<string, mixed>>
     */
    private function preflightHelpServicePage(ContentPage $page): array
    {
        $errors = [];
        $fieldPrefix = 'content_pages.'.(string) $page->locale.':'.(string) $page->slug;

        if ((string) $page->kind !== ContentPage::KIND_HELP) {
            $errors[] = $this->issue($fieldPrefix.'.kind', 'invalid_kind', 'Help service controlled publish only allows kind=help.');
        }

        if ((string) $page->review_state !== 'owner_review' && (string) $page->review_state !== 'approved') {
            $errors[] = $this->issue($fieldPrefix.'.review_state', 'invalid_review_state', 'Help service page must remain owner_review or approved before controlled publish.');
        }

        if ((string) $page->support_contact !== 'support@fermatmind.com') {
            $errors[] = $this->issue($fieldPrefix.'.support_contact', 'invalid_support_contact', 'Help service page must carry the approved support contact.');
        }

        if ((string) $page->policy_version !== 'help_service_policy.v1') {
            $errors[] = $this->issue($fieldPrefix.'.policy_version', 'invalid_policy_version', 'Help service page must carry the approved policy version.');
        }

        if (trim((string) $page->reviewer) === '') {
            $errors[] = $this->issue($fieldPrefix.'.reviewer', 'missing_reviewer', 'Help service page must carry a reviewer field, using Unknown when not yet assigned.');
        }

        $faqItems = is_array($page->faq_items) ? array_values($page->faq_items) : [];
        if (count($faqItems) !== 4) {
            $errors[] = $this->issue($fieldPrefix.'.faq_items', 'invalid_faq_item_count', 'Help service page must carry exactly four structured FAQ items before controlled publish.', [
                'actual_count' => count($faqItems),
            ]);
        }

        foreach ($faqItems as $index => $item) {
            if (! is_array($item) || trim((string) ($item['question'] ?? '')) === '' || trim((string) ($item['answer'] ?? '')) === '') {
                $errors[] = $this->issue($fieldPrefix.'.faq_items.'.$index, 'invalid_faq_item_shape', 'Each FAQ item must include non-empty question and answer fields.');
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
    private function readinessArtifactState(string $scope): array
    {
        if ($scope === self::SCOPE_HELP_SERVICE) {
            return [
                'ready' => true,
                'reason' => 'help_service_runtime_scope_authorized_by_manifest',
                'required_follow_up' => 'HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01 remains required before any production publish execution.',
            ];
        }

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
     * @return list<string>
     */
    private function allowedKeys(string $scope): array
    {
        return $scope === self::SCOPE_HELP_SERVICE ? self::HELP_SERVICE_ALLOWED_KEYS : self::ALLOWED_KEYS;
    }

    /**
     * @return list<string>
     */
    private function protectedKeys(string $scope): array
    {
        return $scope === self::SCOPE_HELP_SERVICE
            ? array_values(array_diff(self::PROTECTED_KEYS, self::HELP_SERVICE_ALLOWED_KEYS))
            : self::PROTECTED_KEYS;
    }

    /**
     * @return list<string>
     */
    private function targetLocales(string $scope, string $locale): array
    {
        if ($scope === self::SCOPE_HELP_SERVICE) {
            return self::HELP_SERVICE_LOCALES;
        }

        return [$locale];
    }

    private function targetKey(string $scope, string $locale, string $key): string
    {
        return $scope === self::SCOPE_HELP_SERVICE ? $locale.':'.$key : $key;
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
