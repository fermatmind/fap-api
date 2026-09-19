@props([
    'actions' => [
        'primary' => [],
        'secondary' => [],
        'disabled' => [],
    ],
])

<div {{ $attributes->class(['ops-translation-actions']) }}>
    @foreach (($actions['primary'] ?? []) as $action)
        @if (($action['url'] ?? null) && ($action['enabled'] ?? false))
            <x-filament::button size="xs" color="primary" tag="a" href="{{ $action['url'] }}">
                {{ $action['label'] }}
            </x-filament::button>
        @elseif (($action['wire_action'] ?? null) && ($action['enabled'] ?? false))
            @php
                $wireExpression = sprintf(
                    '%s(%s, %d, %s)',
                    (string) $action['wire_action'],
                    var_export((string) ($action['content_type'] ?? ''), true),
                    (int) ($action['record_id'] ?? 0),
                    var_export((string) ($action['target_locale'] ?? ''), true),
                );
            @endphp
            <x-filament::button
                size="xs"
                color="primary"
                type="button"
                wire:click="{{ $wireExpression }}"
            >
                {{ $action['label'] }}
            </x-filament::button>
        @endif
    @endforeach

    @foreach (($actions['secondary'] ?? []) as $action)
        @if (($action['url'] ?? null) && ($action['enabled'] ?? false))
            <x-filament::button size="xs" color="gray" tag="a" href="{{ $action['url'] }}">
                {{ $action['label'] }}
            </x-filament::button>
        @elseif (($action['wire_action'] ?? null) && ($action['enabled'] ?? false))
            @php
                $wireExpression = sprintf(
                    '%s(%s, %d, %s)',
                    (string) $action['wire_action'],
                    var_export((string) ($action['content_type'] ?? ''), true),
                    (int) ($action['record_id'] ?? 0),
                    var_export((string) ($action['target_locale'] ?? ''), true),
                );
            @endphp
            <x-filament::button
                size="xs"
                color="gray"
                type="button"
                wire:click="{{ $wireExpression }}"
            >
                {{ $action['label'] }}
            </x-filament::button>
        @endif
    @endforeach

    @foreach (($actions['disabled'] ?? []) as $action)
        <span class="ops-translation-actions__disabled">
            {{ __('ops.translation_ops.actions.disabled', ['action' => $action['label'], 'reason' => $action['reason'] ?? __('ops.translation_ops.reasons.action_unavailable')]) }}
        </span>
    @endforeach
</div>
