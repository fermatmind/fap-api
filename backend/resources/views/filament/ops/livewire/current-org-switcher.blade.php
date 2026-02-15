<div class="flex items-center gap-2">
    @if ($currentOrgId > 0)
        <span class="text-xs text-gray-600">Current Org: {{ $currentOrgName }} ({{ $currentOrgId }})</span>
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
        <span class="text-xs font-semibold text-warning-700">No Org Selected</span>
        <x-filament::button size="xs" color="gray" wire:click="goSelectOrg">
            Select Org
        </x-filament::button>
    @endif
</div>
