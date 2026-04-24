@php
    $returnUrl = request()->fullUrl();
    $segments = [
        'en' => __('ops.locale.english_short'),
        'zh_CN' => __('ops.locale.chinese_short'),
    ];
@endphp

<div
    class="ops-language-switcher"
    role="group"
    aria-label="{{ __('ops.locale.switcher_label') }}"
>
    @foreach ($segments as $key => $label)
        <button
            type="button"
            @class([
                'ops-language-switcher__option',
                'ops-language-switcher__option--active' => $locale === $key,
            ])
            wire:key="ops-locale-{{ $key }}"
            wire:click="setLocale({{ \Illuminate\Support\Js::from($key) }}, {{ \Illuminate\Support\Js::from($returnUrl) }})"
            @disabled($locale === $key)
            aria-pressed="{{ $locale === $key ? 'true' : 'false' }}"
        >
            {{ $label }}
        </button>
    @endforeach
</div>
