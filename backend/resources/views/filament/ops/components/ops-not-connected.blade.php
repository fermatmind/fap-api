@props(['title', 'description', 'source' => null])

<div {{ $attributes->class(['ops-not-connected']) }} role="status">
    <span class="ops-not-connected__signal" aria-hidden="true"></span>
    <div>
        <strong>{{ $title }}</strong>
        <p>{{ $description }}</p>
        @if ($source)<small>{{ $source }}</small>@endif
    </div>
</div>
