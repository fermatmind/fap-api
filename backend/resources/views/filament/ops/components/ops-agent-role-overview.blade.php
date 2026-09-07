@props(['snapshot', 'runtime'])

@php
    $copy = 'seo-agent-roles';
    $globalState = \App\Filament\Ops\Support\SeoAgentRolePresentation::globalState($runtime);
@endphp

<section class="ops-agent-roles" aria-labelledby="agent-roles-title" data-registry-available="{{ $snapshot['available'] ? 'true' : 'false' }}">
    <div class="ops-seo-section-heading">
        <h3 id="agent-roles-title">{{ __($copy.'.title') }}</h3>
        <span class="ops-tag" data-council-state="{{ $globalState }}">{{ __($copy.'.global.'.$globalState) }}</span>
    </div>
    <p class="ops-control-hint">{{ __($copy.'.disclaimer') }}</p>

    @if (! $snapshot['available'])
        <p role="status">{{ __($copy.'.registry_unavailable') }}</p>
    @elseif ($snapshot['roles'] === [])
        <p role="status">{{ __($copy.'.empty') }}</p>
    @else
        <ul class="ops-agent-roles__grid" role="list">
            @foreach ($snapshot['roles'] as $role)
                <li class="ops-agent-roles__card" data-role-id="{{ $role['role_id'] }}" data-role-state="{{ $role['state'] }}">
                    <span class="ops-agent-roles__avatar" data-tone="{{ $role['tone'] }}" aria-hidden="true">
                        <x-filament::icon :icon="'heroicon-o-'.$role['icon']" />
                    </span>
                    <div class="ops-agent-roles__body">
                        <h4>{{ $role['copy'] ? __($copy.'.roles.'.$role['copy'].'.name') : $role['role_id'] }}</h4>
                        <p>{{ $role['copy'] ? __($copy.'.roles.'.$role['copy'].'.duty') : __($copy.'.unknown_duty') }}</p>
                        <span class="ops-agent-roles__state">{{ __($copy.'.states.'.$role['state']) }}</span>
                        <code>{{ $role['role_id'] }}</code>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
