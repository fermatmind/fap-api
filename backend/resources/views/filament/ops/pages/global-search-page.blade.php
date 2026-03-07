<x-filament-panels::page>
    @php
        $hasQuery = trim($query) !== '';
        $emptyTitle = $hasQuery ? 'No matching records' : 'Start with a search';
        $emptyDescription = $hasQuery
            ? 'Try another order number, attempt id, share id, or user email to widen the search.'
            : 'Search by order number, attempt id, share id, or user email to open the right workflow quickly.';
    @endphp

    <div class="ops-shell-page">
        <x-filament::section>
            <div class="ops-workbench-toolbar ops-workbench-toolbar--split">
                <div class="ops-workbench-toolbar__main">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">Support workspace</span>
                        <p class="ops-shell-inline-intro__meta">
                            Jump directly to orders, attempts, shares, or user records without leaving the current ops context.
                        </p>
                    </div>

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
                </div>

                <div class="ops-workbench-toolbar__actions">
                    <x-filament::button color="primary" wire:click="runSearch">
                        Search
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Results</h3>
                    <p class="ops-results-header__meta">Shared result cards keep support actions readable even when records come from different domains.</p>
                </div>
                <span class="ops-results-header__meta">{{ $elapsedMs }} ms</span>
            </div>

            <div class="ops-card-list mt-4">
                @forelse ($items as $item)
                    <div class="ops-result-card">
                        <div class="ops-result-card__header">
                            <div>
                                <p class="ops-result-card__title">{{ (string) ($item['label'] ?? '-') }}</p>
                                <p class="ops-result-card__meta">
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
                    <x-filament.ops.shared.empty-state
                        eyebrow="Support search"
                        icon="heroicon-o-magnifying-glass"
                        :title="$emptyTitle"
                        :description="$emptyDescription"
                    />
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
