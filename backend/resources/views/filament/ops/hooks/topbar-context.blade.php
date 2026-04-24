@php
    $routeName = (string) request()->route()?->getName();

    $sectionLabel = match (true) {
        str_contains($routeName, 'mbti-insights') => __('ops.group.insights'),
        str_contains($routeName, 'content-pack-release'),
        str_contains($routeName, 'content-pack-version') => __('ops.group.operations'),
        str_contains($routeName, 'editorial-review'),
        str_contains($routeName, 'post-release-observability'),
        str_contains($routeName, 'content-release') => __('ops.group.operations'),
        str_contains($routeName, 'content-metrics'),
        str_contains($routeName, 'content-growth-attribution'),
        str_contains($routeName, 'seo-operations'),
        str_contains($routeName, 'content-search'),
        str_contains($routeName, 'content-overview') => __('ops.group.insights'),
        str_contains($routeName, 'content-workspace') => __('ops.group.content'),
        str_contains($routeName, 'editorial-operations') => __('ops.group.content'),
        str_contains($routeName, 'article-translation-ops') => __('ops.group.translation'),
        str_contains($routeName, 'article-category'),
        str_contains($routeName, 'article-tag') => __('ops.group.content'),
        str_contains($routeName, 'article'),
        str_contains($routeName, 'career-job'),
        str_contains($routeName, 'career-guide') => __('ops.group.content'),
        str_contains($routeName, 'content-pack'),
        str_contains($routeName, 'personality'),
        str_contains($routeName, 'scale-registry'),
        str_contains($routeName, 'scale-slug'),
        str_contains($routeName, 'topic') => __('ops.group.content'),
        str_contains($routeName, 'order'),
        str_contains($routeName, 'payment'),
        str_contains($routeName, 'benefit'),
        str_contains($routeName, 'sku') => __('ops.group.operations'),
        str_contains($routeName, 'organization'),
        str_contains($routeName, 'role'),
        str_contains($routeName, 'permission'),
        str_contains($routeName, 'admin-user') => __('ops.group.governance'),
        str_contains($routeName, 'approval'),
        str_contains($routeName, 'go-live') => __('ops.group.governance'),
        str_contains($routeName, 'queue'),
        str_contains($routeName, 'health'),
        str_contains($routeName, 'webhook') => __('ops.group.operations'),
        str_contains($routeName, 'audit'),
        str_contains($routeName, 'deploy') => __('ops.group.operations'),
        str_contains($routeName, 'global-search'),
        str_contains($routeName, 'order-lookup'),
        str_contains($routeName, 'delivery-tools'),
        str_contains($routeName, 'secure-link') => __('ops.group.operations'),
        default => __('ops.nav.dashboard'),
    };
@endphp

<div class="ops-topbar-start">
    <x-filament-ops::ops-context-bar
        class="hidden xl:flex"
        :eyebrow="$sectionLabel"
        :meta="__('ops.topbar.operations_shell')"
        title="Fermat Ops"
    />
</div>
