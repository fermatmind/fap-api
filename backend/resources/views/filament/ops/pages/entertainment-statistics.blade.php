<x-filament-panels::page>
    <p class="text-sm text-slate-600">{{ __('entertainment-activity.note') }}</p>
    @if($catalogSource !== 'live')<p role="status" class="text-sm text-amber-800">{{ __('entertainment-activity.catalog_fallback') }}</p>@endif
    <form wire:submit.prevent="applyFilters" class="rounded-xl border bg-white p-5">
        <div class="grid gap-4 md:grid-cols-3">
            <label>{{ __('entertainment-activity.from') }}<input type="date" wire:model="fromDate" class="block w-full rounded-lg border-slate-300" /></label>
            <label>{{ __('entertainment-activity.to') }}<input type="date" wire:model="toDate" class="block w-full rounded-lg border-slate-300" /></label>
            <label>{{ __('entertainment-activity.test') }}<select wire:model="test" class="block w-full rounded-lg border-slate-300"><option value="*">{{ __('entertainment-activity.all') }}</option>@foreach($catalog as $item)<option value="{{ $item['slug'] }}">{{ $item['title'][app()->getLocale() === 'en' ? 'en' : 'zh'] }} · {{ $item['slug'] }}</option>@endforeach</select></label>
            <label>{{ __('entertainment-activity.language') }}<select wire:model="locale" class="block w-full rounded-lg border-slate-300"><option value="*">{{ __('entertainment-activity.all') }}</option><option value="en">English</option><option value="zh">中文</option></select></label>
            <label>{{ __('entertainment-activity.role') }}<select wire:model="role" class="block w-full rounded-lg border-slate-300"><option value="*">{{ __('entertainment-activity.all') }}</option>@foreach(['single','creator','friend','solo'] as $value)<option value="{{ $value }}">{{ __('entertainment-activity.roles.'.$value) }}</option>@endforeach</select></label>
        </div>
        @foreach($errors->all() as $error)<p class="mt-2 text-red-700">{{ $error }}</p>@endforeach
        <x-filament::button type="submit" class="mt-4">{{ __('entertainment-activity.apply') }}</x-filament::button>
    </form>
    @if($warning)<p role="alert">{{ $warning }}</p>@else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">@foreach($summary as $key => $value)<div class="rounded-xl border bg-white p-5"><p>{{ __('entertainment-activity.metrics.'.$key) }}</p><p class="text-2xl font-semibold">{{ number_format($value) }}</p></div>@endforeach</div>
        <p class="text-sm">{{ __('entertainment-activity.coverage', ['tests' => count($catalog), 'rows' => $totalRows]) }}</p>
        <div class="overflow-x-auto rounded-xl border bg-white"><table class="w-full text-left text-sm"><thead><tr>@foreach(['test','language','role','start','complete','view','share','methods','version','privacy'] as $key)<th class="px-3 py-3">{{ __('entertainment-activity.columns.'.$key) }}</th>@endforeach</tr></thead><tbody>
        @forelse($rows as $row)<tr class="border-t"><td class="px-3 py-3">{{ $row['title'] }}<br/><span class="text-xs text-slate-500">{{ $row['slug'] }}</span></td><td class="px-3">{{ $row['locale'] }}</td><td class="px-3">{{ __('entertainment-activity.roles.'.$row['role']) }}</td>@foreach($row['counts'] as $value)<td class="px-3">{{ $value }}</td>@endforeach<td class="px-3">@foreach($row['methods'] as $method => $count)<div>{{ __('entertainment-activity.methods.'.$method) }}: {{ $count }}</div>@endforeach</td><td class="px-3">{{ $row['versions'] ?: '—' }}</td><td class="px-3">{{ __('entertainment-activity.privacy.'.$row['privacy']) }}</td></tr>@empty<tr><td colspan="10" class="p-5">{{ __('entertainment-activity.no_rows') }}</td></tr>@endforelse
        </tbody></table></div>
        <div class="flex gap-4"><x-filament::button wire:click="movePage(-1)" :disabled="$offset === 0">{{ __('entertainment-activity.previous') }}</x-filament::button><span>{{ $totalRows ? $offset + 1 : 0 }}–{{ min($offset + 50, $totalRows) }} / {{ $totalRows }}</span><x-filament::button wire:click="movePage(1)" :disabled="$offset + 50 >= $totalRows">{{ __('entertainment-activity.next') }}</x-filament::button></div>
    @endif
</x-filament-panels::page>
