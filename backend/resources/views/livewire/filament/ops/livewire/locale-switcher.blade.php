<div class="px-2">
    <x-filament::dropdown placement="bottom-end">
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" icon="heroicon-m-language">
                {{ $locales[$locale] ?? __('ops.locale.switcher_label') }}
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

