<x-filament-panels::page>
    <div class="space-y-4">
        <x-filament::section>
            <div class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-3">
                    <label class="block text-sm font-medium text-gray-700" for="ops-global-search-input">Search by order_no / attempt_id / share_id / user_email</label>
                    <input
                        id="ops-global-search-input"
                        type="text"
                        wire:model.defer="query"
                        placeholder="ord_..., attempt..., share..., email"
                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>
                <div class="flex items-end">
                    <x-filament::button color="primary" wire:click="runSearch">
                        Search
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Results</h3>
                <span class="text-xs text-gray-500">{{ $elapsedMs }} ms</span>
            </div>

            <div class="mt-3 space-y-2">
                @forelse ($items as $item)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ (string) ($item['label'] ?? '-') }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ (string) ($item['type'] ?? '-') }}
                                    @if ((int) ($item['org_id'] ?? 0) > 0)
                                        | org={{ (int) ($item['org_id'] ?? 0) }}
                                    @endif
                                    | {{ (string) ($item['subtitle'] ?? '') }}
                                </p>
                            </div>
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ (string) ($item['url'] ?? '/ops') }}">
                                Open
                            </x-filament::button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                        No results yet.
                    </div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
