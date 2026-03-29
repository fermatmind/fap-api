@props([
    'split' => true,
])

<div {{ $attributes->class([
    'ops-workbench-toolbar',
    'ops-workbench-toolbar--split' => $split,
]) }}>
    <div class="ops-workbench-toolbar__main">
        {{ $slot }}
    </div>

    @isset($actions)
        <div class="ops-workbench-toolbar__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
