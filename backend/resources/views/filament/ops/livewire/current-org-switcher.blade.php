<div class="flex items-center gap-2">
    @if ($currentOrgId > 0)
        <span class="text-xs text-gray-600">{{ __('ops.topbar.current_org') }}: {{ $currentOrgName }} ({{ $currentOrgId }})</span>
        <select
            class="rounded-md border-gray-300 text-xs"
            wire:change="switchOrg($event.target.value)"
        >
            @foreach ($organizations as $organization)
                <option value="{{ $organization['id'] }}" @selected($organization['id'] === $currentOrgId)>
                    {{ $organization['name'] }} ({{ $organization['id'] }})
                </option>
            @endforeach
        </select>
    @else
        <span class="text-xs font-semibold text-warning-700">{{ __('ops.topbar.no_org_selected') }}</span>
        <x-filament::button size="xs" color="gray" wire:click="goSelectOrg">
            {{ __('ops.topbar.select_org') }}
        </x-filament::button>
    @endif
</div>
