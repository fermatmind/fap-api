@props([
    'columns' => [],
    'rows' => [],
])

<div {{ $attributes->class(['ops-coverage-matrix']) }}>
    @if ($rows === [])
        <x-filament-ops::ops-empty-state
            :title="__('ops.translation_ops.empty_title')"
            :description="__('ops.translation_ops.empty_description')"
        />
    @else
        <div class="ops-coverage-matrix__scroller">
            <table class="ops-coverage-matrix__table">
                <thead>
                    <tr>
                        <th class="ops-coverage-matrix__group-col">{{ __('ops.translation_ops.matrix.group') }}</th>
                        <th>{{ __('ops.translation_ops.matrix.health') }}</th>
                        @foreach ($columns as $locale)
                            <th>{{ $locale }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="coverage-matrix-row-{{ $row['group_key'] }}">
                            <td class="ops-coverage-matrix__group-col">
                                <div class="ops-coverage-matrix__group">
                                    <div>
                                        <p class="ops-coverage-matrix__type">{{ $row['content_type_label'] }}</p>
                                        <p class="ops-coverage-matrix__slug">{{ $row['slug'] }}</p>
                                        <p class="ops-control-hint">{{ $row['translation_group_id'] }}</p>
                                    </div>
                                    <div class="ops-toolbar-inline">
                                        <x-filament::button size="xs" color="gray" type="button" wire:click="inspectGroup('{{ $row['group_key'] }}')">
                                            {{ __('ops.translation_ops.actions.inspect') }}
                                        </x-filament::button>
                                        @if ($row['source_edit_url'])
                                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $row['source_edit_url'] }}">
                                                {{ __('ops.translation_ops.actions.source') }}
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <x-filament.ops.shared.status-pill :state="$row['health_state']" :label="$row['health_label']" />
                                @foreach (($row['alerts'] ?? []) as $alert)
                                    <p class="ops-control-hint">{{ $alert['label'] }}</p>
                                @endforeach
                            </td>
                            @foreach ($columns as $locale)
                                @php($cell = $row['cells'][$locale])
                                <td class="ops-coverage-matrix__cell ops-coverage-matrix__cell--{{ $cell['state'] }}">
                                    <div class="ops-coverage-matrix__cell-body">
                                        <div class="ops-coverage-matrix__cell-head">
                                            <x-filament.ops.shared.status-pill :state="$cell['status_state']" :label="$cell['status_label']" />
                                            <span class="ops-control-hint">{{ $cell['record_label'] }}</span>
                                        </div>

                                        <div class="ops-coverage-matrix__cell-pills">
                                            <x-filament.ops.shared.status-pill :state="$cell['freshness_state']" :label="$cell['freshness_label']" />
                                            <x-filament.ops.shared.status-pill :state="$cell['publish_state']" :label="$cell['publish_label']" />
                                        </div>

                                        <p class="ops-control-hint">{{ $cell['workflow_label'] }}</p>

                                        @if (($cell['ownership_blockers'] ?? []) !== [])
                                            <p class="ops-control-hint">{{ __('ops.translation_ops.table.ownership_preflight') }}:</p>
                                            @foreach (($cell['ownership_blockers'] ?? []) as $blocker)
                                                <p class="ops-coverage-matrix__blocker">{{ $blocker }}</p>
                                            @endforeach
                                        @endif

                                        @if (($cell['readiness_blockers'] ?? []) !== [])
                                            <p class="ops-control-hint">{{ __('ops.translation_ops.health.preflight_blocked') }}:</p>
                                            @foreach (($cell['readiness_blockers'] ?? []) as $blocker)
                                                <p class="ops-coverage-matrix__blocker">{{ $blocker }}</p>
                                            @endforeach
                                        @endif

                                        <x-filament-ops::ops-translation-action-list :actions="$cell['actions']" />
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
