<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.content_workspace.eyebrow')"
            :title="__('ops.custom_pages.content_workspace.title')"
            :description="__('ops.custom_pages.content_workspace.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.content_workspace.permission_boundary') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.content_workspace.permission_boundary_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            {{ __('ops.custom_pages.content_workspace.release_surface') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.advanced_tools_title')"
            :description="__('ops.custom_pages.content_workspace.advanced_tools_desc')"
        >
            <div class="ops-card-list">
                @foreach ($advancedContentCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $card['index_url'] }}">
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                            @if ($card['can_create'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    {{ __('ops.custom_pages.common.actions.create') }}
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.snapshot_title')"
            :description="__('ops.custom_pages.content_workspace.snapshot_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$snapshotFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.editorial_title')"
            :description="__('ops.custom_pages.content_workspace.editorial_desc')"
        >
            <div class="ops-card-list">
                @foreach ($editorialCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">{{ $card['status_meta'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $card['index_url'] }}">
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                            @if ($card['can_write'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    {{ __('ops.custom_pages.common.actions.create') }}
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.taxonomy_title')"
            :description="__('ops.custom_pages.content_workspace.taxonomy_desc')"
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
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                            @if ($card['can_write'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    {{ __('ops.custom_pages.common.actions.create') }}
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.optional_types_title')"
            :description="__('ops.custom_pages.content_workspace.optional_types_desc')"
        >
            <div class="ops-card-list">
                @foreach ($optionalContentCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $card['index_url'] }}">
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                            @if ($card['can_create'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    {{ $card['count'] === 0 ? __('ops.custom_pages.content_workspace.create_first') : __('ops.custom_pages.common.actions.create') }}
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.access_model_title')"
            :description="__('ops.custom_pages.content_workspace.access_model_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$permissionFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
