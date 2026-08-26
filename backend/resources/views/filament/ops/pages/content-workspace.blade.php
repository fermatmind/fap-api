<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar class="ops-toolbar--center-actions">
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
        >
            <div class="ops-card-list">
                @foreach ($advancedContentCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                    >
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
        >
            <x-filament-ops::ops-field-grid :fields="$snapshotFields" :show-hints="false" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_workspace.editorial_title')"
        >
            <div class="ops-card-list">
                @foreach ($editorialCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
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
        >
            <div class="ops-card-list">
                @foreach ($dataCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
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
        >
            <div class="ops-card-list">
                @foreach ($optionalContentCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
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
        >
            <x-filament-ops::ops-field-grid class="ops-field-grid--centered-head" :fields="$permissionFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
