@php
    use App\Services\SeoCouncil\Platform12\Operations\Platform12DecisionExperimentReadService;

    $snapshot = app(Platform12DecisionExperimentReadService::class)->snapshot();
    $copy = 'ops.custom_pages.seo_operations.decision_experiment';
@endphp

<section
    class="ops-decision-experiment"
    aria-labelledby="decision-experiment-title"
    data-read-only="{{ $snapshot['read_only'] ? 'true' : 'false' }}"
    data-navigation-only="{{ $snapshot['navigation_only'] ? 'true' : 'false' }}"
    data-cards-state="{{ $snapshot['cards']['state'] }}"
    data-experiments-state="{{ $snapshot['experiments']['state'] }}"
>
    <div class="ops-seo-section-heading">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span>
            <h2 id="decision-experiment-title">{{ __($copy.'.title') }}</h2>
            <p>{{ __($copy.'.scope_note') }}</p>
        </div>
        <span class="ops-tag">#12 · {{ __($copy.'.read_only') }}</span>
    </div>

    <div class="ops-decision-experiment__status" aria-label="{{ __($copy.'.status_label') }}">
        <span><strong>{{ __($copy.'.cards.title') }}</strong> {{ $snapshot['cards']['state'] }}</span>
        <span><strong>{{ __($copy.'.experiments.title') }}</strong> {{ $snapshot['experiments']['state'] }}</span>
        <span><strong>{{ __($copy.'.cms_authority') }}</strong> {{ $snapshot['cms_authority'] }}</span>
    </div>

    <div class="ops-decision-experiment__grid">
        <section aria-labelledby="decision-cards-title">
            <span class="ops-shell-eyebrow">{{ $snapshot['cards']['iso_week'] ?? '—' }}</span>
            <h3 id="decision-cards-title">{{ __($copy.'.cards.title') }}</h3>
            <div class="ops-decision-experiment__rows">
                @forelse ($snapshot['cards']['items'] as $card)
                    <article>
                        <div><strong>{{ $card['detector'] }}</strong><span>{{ $card['status'] }}</span></div>
                        <p>{{ $card['family'] }} · {{ $card['locale'] }} · {{ $card['owner'] }}</p>
                        <dl><dt>{{ __($copy.'.cards.expiry') }}</dt><dd>{{ $card['expires_at'] ?? 'unavailable' }}</dd></dl>
                    </article>
                @empty
                    <x-filament-ops::ops-state-message
                        :state="$snapshot['cards']['state']"
                        :title="__($copy.'.states.'.$snapshot['cards']['state'])"
                        :description="__($copy.'.cards.empty')"
                    />
                @endforelse
            </div>
        </section>

        <section aria-labelledby="active-experiments-title">
            <span class="ops-shell-eyebrow">{{ __($copy.'.experiments.eyebrow') }}</span>
            <h3 id="active-experiments-title">{{ __($copy.'.experiments.title') }}</h3>
            <div class="ops-decision-experiment__rows">
                @forelse ($snapshot['experiments']['items'] as $experiment)
                    <article data-canary-state="{{ $experiment['canary_state'] }}">
                        <div><strong>{{ $experiment['family'] ?? 'unavailable' }} · {{ $experiment['locale'] ?? 'unavailable' }}</strong><span>{{ $experiment['status'] }} / {{ $experiment['canary_state'] }}</span></div>
                        <p>{{ __($copy.'.experiments.owner') }}: {{ $experiment['owner'] }} · {{ __($copy.'.experiments.sample') }}: {{ $experiment['sample_size'] ?? 'unavailable' }} · {{ __($copy.'.experiments.window') }}: {{ $experiment['window_days'] ?? 'unavailable' }}</p>
                        <dl><dt>readback / rollback</dt><dd>{{ $experiment['readback'] }} / {{ $experiment['rollback'] }}</dd></dl>
                    </article>
                @empty
                    <x-filament-ops::ops-state-message
                        :state="$snapshot['experiments']['state']"
                        :title="__($copy.'.states.'.$snapshot['experiments']['state'])"
                        :description="__($copy.'.experiments.empty')"
                    />
                @endforelse
            </div>
        </section>
    </div>

    <p class="ops-control-hint">{{ __($copy.'.boundary') }}</p>
</section>
