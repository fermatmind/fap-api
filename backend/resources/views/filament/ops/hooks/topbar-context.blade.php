@php
    $routeName = (string) request()->route()?->getName();

    $section = match (true) {
        str_contains($routeName, 'global-search'),
        str_contains($routeName, 'attempt'),
        str_contains($routeName, 'result'),
        str_contains($routeName, 'report-pdf'),
        str_contains($routeName, 'order-lookup'),
        str_contains($routeName, 'delivery-tools'),
        str_contains($routeName, 'secure-link') => 'support',
        str_contains($routeName, 'test-kpi'),
        str_contains($routeName, 'question-analytics'),
        str_contains($routeName, 'mbti-insights'),
        str_contains($routeName, 'quality-research') => 'psychometrics',
        str_contains($routeName, 'content-pack-release'),
        str_contains($routeName, 'content-pack-version'),
        str_contains($routeName, 'editorial-review'),
        str_contains($routeName, 'post-release-observability'),
        str_contains($routeName, 'article-publishing-ops'),
        str_contains($routeName, 'content-release') => 'content_release',
        str_contains($routeName, 'content-metrics'),
        str_contains($routeName, 'content-growth-attribution'),
        str_contains($routeName, 'seo-operations'),
        str_contains($routeName, 'seo-dashboard'),
        str_contains($routeName, 'public-content-health'),
        str_contains($routeName, 'content-search'),
        str_contains($routeName, 'content-overview') => 'content_overview',
        str_contains($routeName, 'article-translation-ops') => 'translation',
        str_contains($routeName, 'daily-giving') => 'legacy_content',
        str_contains($routeName, 'content-workspace'),
        str_contains($routeName, 'editorial-operations'),
        str_contains($routeName, 'article-category'),
        str_contains($routeName, 'article-tag'),
        str_contains($routeName, 'article'),
        str_contains($routeName, 'career-job'),
        str_contains($routeName, 'career-guide'),
        str_contains($routeName, 'content-pack'),
        str_contains($routeName, 'personality'),
        str_contains($routeName, 'scale-registry'),
        str_contains($routeName, 'scale-slug'),
        str_contains($routeName, 'topic') => 'content',
        str_contains($routeName, 'funnel-conversion'),
        str_contains($routeName, 'order'),
        str_contains($routeName, 'payment'),
        str_contains($routeName, 'benefit'),
        str_contains($routeName, 'sku') => 'commerce',
        str_contains($routeName, 'organization'),
        str_contains($routeName, 'role'),
        str_contains($routeName, 'permission'),
        str_contains($routeName, 'admin-user'),
        str_contains($routeName, 'approval'),
        str_contains($routeName, 'go-live') => 'governance',
        str_contains($routeName, 'queue'),
        str_contains($routeName, 'health'),
        str_contains($routeName, 'webhook'),
        str_contains($routeName, 'audit'),
        str_contains($routeName, 'deploy') => 'operations',
        default => 'workspace',
    };

    $sectionLabel = __('ops.group.'.$section);
@endphp

<div class="ops-topbar-start" data-ops-domain="{{ $section }}">
    <x-filament-ops::ops-context-bar
        class="hidden xl:flex"
        :eyebrow="$sectionLabel"
        :meta="__('ops.topbar.operations_shell')"
        title="Fermat Ops"
    />
</div>
