<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_220px_220px_auto]">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">From</span>
                    <input
                        type="date"
                        wire:model.defer="fromDate"
                        class="block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">To</span>
                    <input
                        type="date"
                        wire:model.defer="toDate"
                        class="block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Scale</span>
                    <select wire:model.defer="scaleCode" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All scales</option>
                        @foreach ($scaleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Locale</span>
                    <select wire:model.defer="locale" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All locales</option>
                        @foreach ($localeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end gap-3">
                    <x-filament::button type="submit">
                        Apply
                    </x-filament::button>

                    <a href="/ops/attempts" class="inline-flex items-center rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                        Drill into attempts
                    </a>
                </div>
            </div>
        </form>

        @if ($warnings !== [])
            <div class="space-y-3">
                @foreach ($warnings as $warning)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        {{ $warning }}
                    </div>
                @endforeach
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">KPI Cards</h2>
                    <p class="text-sm text-slate-500">First-phase attempt-led funnel totals for the selected scope.</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs text-slate-500">
                    <a href="/ops/orders" class="rounded-full border border-slate-200 px-3 py-1.5 hover:border-slate-300 hover:text-slate-900">Orders</a>
                    <a href="/ops/payment-events" class="rounded-full border border-slate-200 px-3 py-1.5 hover:border-slate-300 hover:text-slate-900">Payment Events</a>
                    <a href="/ops/order-lookup" class="rounded-full border border-slate-200 px-3 py-1.5 hover:border-slate-300 hover:text-slate-900">Order Lookup</a>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($kpis as $card)
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $card['label'] }}</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                            @if (($card['currency'] ?? false) === true)
                                {{ $this->formatCurrencyCents((int) $card['value']) }}
                            @else
                                {{ $this->formatInt((int) $card['value']) }}
                            @endif
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $card['description'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-950">Daily Funnel Trend</h2>
                <p class="text-sm text-slate-500">Operational daily counts for the core commerce funnel. Bars are scaled within each metric column.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Day</th>
                            @foreach ($dailyTrend[0] ?? [] as $key => $value)
                                @if (str_ends_with((string) $key, '_attempts'))
                                    <th class="px-3 py-3">{{ $value !== null ? ($dailyTrend[0][$key . '_label'] ?? ucwords(str_replace('_', ' ', $key))) : '' }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($dailyTrend as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                @foreach ($row as $key => $value)
                                    @if (str_ends_with((string) $key, '_attempts'))
                                        <td class="px-3 py-3">
                                            <div class="flex min-w-[120px] flex-col gap-1">
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="font-medium text-slate-900">{{ $this->formatInt((int) $value) }}</span>
                                                </div>
                                                <div class="h-1.5 rounded-full bg-slate-100">
                                                    <div
                                                        class="h-1.5 rounded-full bg-sky-500"
                                                        style="width: {{ $row[$key . '_pct'] ?? 0 }}%;"
                                                    ></div>
                                                </div>
                                            </div>
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
            <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Step Conversion Table</h2>
                    <p class="text-sm text-slate-500">Main funnel stages only. paywall_view and attribution-only events stay out of this authority table.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Stage</th>
                                <th class="px-3 py-3">Distinct attempts</th>
                                <th class="px-3 py-3">Previous-step conversion</th>
                                <th class="px-3 py-3">Cumulative progression</th>
                                <th class="px-3 py-3">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($conversionRows as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['label'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['value']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatRate($row['previous_step_rate']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatRate($row['cumulative_rate']) }}</td>
                                    <td class="px-3 py-3 text-slate-600">{{ $row['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Locale Comparison</h2>
                    <p class="text-sm text-slate-500">Compare the current date and scale scope by locale. Keep locale = All to preserve the cross-locale view.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Locale</th>
                                <th class="px-3 py-3">Started</th>
                                <th class="px-3 py-3">Submitted</th>
                                <th class="px-3 py-3">Paid</th>
                                <th class="px-3 py-3">Unlocked</th>
                                <th class="px-3 py-3">Report ready</th>
                                <th class="px-3 py-3">Submit/start</th>
                                <th class="px-3 py-3">Paid/submit</th>
                                <th class="px-3 py-3">Ready/unlock</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($localeComparison as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['locale'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['started_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['submitted_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['paid_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['unlocked_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['report_ready_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatRate($row['submit_rate']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatRate($row['paid_rate']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatRate($row['ready_rate']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-4 text-center text-slate-500">No locale rows for the current scope.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">PDF Panel</h2>
                    <p class="text-sm text-slate-500">Trailing delivery health only. PDF does not become a main-funnel authority stage in v1.</p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">PDF download attempts</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatInt((int) ($pdfPanel['downloads'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">PDF readiness gap</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatInt((int) ($pdfPanel['readiness_gap'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Download / ready</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatRate($pdfPanel['download_rate'] ?? null) }}</div>
                    </article>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Day</th>
                                <th class="px-3 py-3">Report ready</th>
                                <th class="px-3 py-3">PDF downloads</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($pdfPanel['trend'] ?? [] as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['report_ready_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['pdf_download_attempts']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Share Panel</h2>
                    <p class="text-sm text-slate-500">Operational share health only. share-to-purchase attribution stays explicitly non-authoritative in v1.</p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Share generated attempts</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatInt((int) ($sharePanel['generated'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Share click attempts</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatInt((int) ($sharePanel['clicks'] ?? 0)) }}</div>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Click / generated</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatRate($sharePanel['click_rate'] ?? null) }}</div>
                    </article>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Day</th>
                                <th class="px-3 py-3">Share generated</th>
                                <th class="px-3 py-3">Share clicks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($sharePanel['trend'] ?? [] as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['share_generated_attempts']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['share_click_attempts']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-950">Stage Definition Note</h2>
                <p class="text-sm text-slate-500">Keep these caveats visible: the page is operationally authoritative for the core stage chain, but not every metric here is a finance-grade truth table.</p>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <div class="space-y-3 text-sm leading-6 text-slate-700">
                    <p><strong>Hard facts in v1:</strong> <code>test_start</code> from <code>attempts.created_at</code>, <code>order_created</code> from <code>orders.created_at</code>, <code>payment_success</code> from <code>orders.paid_at</code> with a payment-event fallback, <code>unlock_success</code> from active <code>benefit_grants</code>, and <code>report_ready</code> from ready/readable <code>report_snapshots</code>.</p>
                    <p><strong>Behavioral / approximate stages:</strong> <code>first_result_or_report_view</code> comes from normalized view events. It is intentionally a single merged stage in v1 and stays downstream of submit in the authority chain.</p>
                    <p><strong>Explicitly non-authoritative in v1:</strong> PDF downloads, share generated, share clicks, paywall views, landing views, channel attribution, region comparisons, and any share-to-purchase revenue story.</p>
                </div>

                <ul class="space-y-2 text-sm leading-6 text-slate-700">
                    <li><strong>Main stages:</strong> <code>test_start</code>, <code>test_submit_success</code>, <code>first_result_or_report_view</code>, <code>order_created</code>, <code>payment_success</code>, <code>unlock_success</code>, <code>report_ready</code>.</li>
                    <li><strong>Trailing panels only:</strong> <code>pdf_download</code>, <code>share_generate</code>, <code>share_click</code>.</li>
                    <li><strong>Why paywall_view stays out:</strong> event taxonomy and attribution around paywall exposure are still too unstable to serve as the first authority stage.</li>
                    <li><strong>Why locale/scale only:</strong> they are the stable operating cuts today; region, channel, and attribution dimensions remain exploratory.</li>
                </ul>
            </div>
        </section>
    </div>
</x-filament-panels::page>
