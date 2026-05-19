<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SEO Intelligence"
            title="SEO Dash access"
            description="Authenticated Ops entry for the private SEO Intelligence MVP dashboard. This page is status and runbook only; it does not embed, proxy, or call Metabase."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Dashboard</span>
                    <p class="ops-control-hint">SEO Intelligence MVP - URL Truth &amp; Issue Queue</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                        CMS SEO Ops
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Verified MVP state"
            description="Static closeout summary from the verified SEO Dash MVP. No live database or Metabase API calls are performed by this page."
        >
            <x-filament-ops::ops-field-grid :fields="$this->statusCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Access boundary"
            description="Metabase remains private and read-only over seo_intel. This route is an Ops entry point, not a public dashboard endpoint."
        >
            <x-filament-ops::ops-field-grid :fields="$this->boundaryCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Private access runbook"
            description="Use approved private access only. Do not create public links, anonymous links, public embeds, reverse proxies, or iframe exposure."
        >
            <div class="ops-card-list">
                @foreach ($this->accessSteps() as $step)
                    <x-filament-ops::ops-result-card
                        :title="$step['title']"
                        :meta="$step['body']"
                    />
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Hard stops"
            description="Stop and revoke access if any forbidden exposure or source appears."
        >
            <div class="ops-card-list">
                <x-filament-ops::ops-result-card
                    title="Forbidden exposure"
                    meta="No public Metabase, no 0.0.0.0 binding, no public port, no security group change, no DNS/CDN/OpenResty/Nginx change."
                />
                <x-filament-ops::ops-result-card
                    title="Forbidden sources"
                    meta="No business DB, Tencent RDS, Node2 local DB, CMS write tables, raw orders, raw payments, raw events, raw email, raw reports, or raw crawler logs."
                />
                <x-filament-ops::ops-result-card
                    title="Forbidden operator access"
                    meta="No unrestricted SQL, no datasource management, no default exports, no public sharing, no anonymous links, and no public embeds for normal operators."
                />
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
