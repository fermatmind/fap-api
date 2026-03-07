@php
    $localeLabel = $locales[$locale] ?? __('ops.locale.switcher_label');
    $localeCode = strtoupper(str_replace('_', '-', $locale));
@endphp

<div class="ops-topbar-chip ops-topbar-chip--locale">
    <span class="ops-topbar-chip__icon">
        <x-filament::icon
            icon="heroicon-m-language"
            class="h-4 w-4"
        />
    </span>

    <div class="ops-topbar-chip__stack">
        <span class="ops-topbar-chip__label">
            {{ __('ops.locale.switcher_label') }}
        </span>
        <span class="ops-topbar-chip__value">
            {{ $localeLabel }}
        </span>
    </div>

    <x-filament::dropdown placement="bottom-end">
        <x-slot name="trigger">
            <x-filament::button
                color="gray"
                size="sm"
                icon="heroicon-m-chevron-up-down"
                class="ops-topbar-chip__action"
            >
                {{ $localeCode }}
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($locales as $key => $label)
                <x-filament::dropdown.list.item
                    wire:click="setLocale('{{ $key }}')"
                    :disabled="$locale === $key"
                >
                    {{ $label }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
