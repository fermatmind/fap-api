<x-filament-panels::page>
    @php
        $hasQuery = trim($query) !== '';
        $emptyTitle = $hasQuery ? 'No matching records' : 'Start with a search';
        $emptyDescription = $hasQuery
            ? 'Try another order number, attempt id, share id, or user email to widen the search.'
            : 'Search by order number, attempt id, share id, or user email to open the right workflow quickly.';
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Support workspace"
            title="Global search"
            description="Jump directly to orders, attempts, shares, or user records without leaving the current Ops shell."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <label class="ops-control-label" for="ops-global-search-input">Search by order_no / attempt_id / share_id / user_email</label>
                    <input
                        id="ops-global-search-input"
                        type="text"
                        wire:model.defer="query"
                        placeholder="ord_..., attempt..., share..., email"
                        class="ops-input"
                    />
                    <p class="ops-control-hint">The search stays read-only here. Open a result to continue in its native workspace.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="primary" wire:click="runSearch">
                        Search
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Results"
            description="Shared result cards keep support actions readable even when records come from different domains."
        >
            <x-slot name="actions">
                <span class="ops-results-header__meta">{{ $elapsedMs }} ms</span>
            </x-slot>

            <div class="ops-card-list">
                @forelse ($items as $item)
                    <x-filament-ops::ops-result-card
                        :title="(string) ($item['label'] ?? '-')"
                        :meta="(string) ($item['type'] ?? '-') . (((int) ($item['org_id'] ?? 0) > 0) ? ' | org='.(int) ($item['org_id'] ?? 0) : '') . (((string) ($item['subtitle'] ?? '')) !== '' ? ' | '.(string) ($item['subtitle'] ?? '') : '')"
                    >
                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ (string) ($item['url'] ?? '/ops') }}">
                                Open
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow="Support search"
                        icon="heroicon-o-magnifying-glass"
                        :title="$emptyTitle"
                        :description="$emptyDescription"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
