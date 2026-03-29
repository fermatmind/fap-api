<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Content workspace"
            title="Content workspace"
            description="Operate article and career CMS workspaces through one unified Ops shell entry instead of hopping between disconnected resources."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Permission boundary</span>
                    <p class="ops-control-hint">Read opens the workspace, write enables authoring, and release stays isolated in the content release surface.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            Release Surface
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Editorial"
            description="Primary content workspaces for long-form CMS authoring, structured career content, and release-ready editorial review."
        >
            <div class="ops-card-list">
                @foreach ($editorialCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $card['index_url'] }}">
                                Open
                            </x-filament::button>
                            @if ($card['can_write'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    Create
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Taxonomy"
            description="Supporting data structures that keep editorial taxonomies consistent and keep the workspace discoverable."
        >
            <div class="ops-card-list">
                @foreach ($dataCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $card['index_url'] }}">
                                Open
                            </x-filament::button>
                            @if ($card['can_write'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    Create
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Access model"
            description="The CMS product layer shares the same Ops security model but makes the content boundary explicit."
        >
            <x-filament-ops::ops-field-grid :fields="$permissionFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
