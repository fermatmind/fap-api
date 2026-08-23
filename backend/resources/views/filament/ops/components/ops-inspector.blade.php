@props(['title', 'description' => null])

<aside {{ $attributes->class(['ops-inspector']) }}>
    <header class="ops-inspector__header">
        <h2>{{ $title }}</h2>
        @if ($description)<p>{{ $description }}</p>@endif
    </header>
    <div class="ops-inspector__body">{{ $slot }}</div>
</aside>
