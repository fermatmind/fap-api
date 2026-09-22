<article data-decision-revision="{{ $decision['decision_revision_id'] }}" data-executable="{{ $decision['executable'] ? 'true' : 'false' }}">
    @php($brief = $decision['brief'] ?? null)
    <h3>{{ $brief['target']['canonical_path'] ?? (app()->getLocale() === 'en' ? 'Details not provided' : '详情未提供') }}</h3>
    <p>{{ $decision['locale'] }} · {{ $decision['page_family'] }} · {{ $decision['status'] }} · {{ $decision['hold_reason'] ?? 'eligible_for_human_review' }}</p>
    <p>{{ $decision['decision_card_id'] }} · v{{ $decision['revision_number'] }} · ledger {{ $decision['ledger_id'] }}</p>
    <p>expires_at: {{ $decision['expires_at'] }} · SHA: {{ $brief['generated_sha'] ?? '—' }}</p>
    @if ($brief)
        <p>{{ $brief['evidence']['start_date'] }} — {{ $brief['evidence']['end_date'] }} · {{ app()->getLocale() === 'en' ? 'Candidate query-set metrics, not page totals' : '候选查询集合指标，非页面总流量' }} · {{ $brief['evidence']['query_count'] }} queries · {{ $brief['evidence']['search_type'] }}</p>
        <dl>
            @foreach ($brief['evidence']['metrics'] as $name => $value)
                <dt>{{ $name }}</dt><dd>{{ $value }}</dd>
            @endforeach
        </dl>
        @foreach ($brief['actions'] as $action)
            <p><strong>{{ $action['signal'] }}</strong> {{ $action['action'] }}</p>
        @endforeach
        <p>priority: {{ $brief['priority']['score'] }} · {{ $brief['priority']['assumptions'] }} · {{ $brief['priority']['sort_reason'] }}</p>
        <p>{{ json_encode($brief['priority']['inputs'], JSON_UNESCAPED_UNICODE) }}</p>
        <p>{{ $brief['acceptance']['recommendation'] }}</p>
        <p>{{ $brief['acceptance']['future_change'] }}</p>
        <details><summary>{{ app()->getLocale() === 'en' ? 'Evidence and source references' : '证据与来源引用' }}</summary>
            <p>authority: {{ $brief['target']['authority_revision'] }} · evidence: {{ $brief['evidence_hash'] }} · query set: {{ $brief['evidence']['query_set_hash'] }}</p>
            <p>{{ implode(' / ', $brief['evidence']['dimensions']) }}</p>
            @foreach ($brief['evidence']['candidate_refs'] as $reference)<p>candidate: {{ $reference }}</p>@endforeach
            @foreach ($brief['evidence']['sources'] as $source)
                <p>seo_gsc_daily #{{ $source['row_id'] }} · sync {{ $source['sync_run_uid'] }} · {{ $source['sync_receipt_hash'] }}</p>
            @endforeach
        </details>
    @endif
</article>
