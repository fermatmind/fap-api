<?php

declare(strict_types=1);

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12ActivationEvidence;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Fixed M1/M2 rollout under the A08 task's prior authorization. M3 is absent.
// Each transition is compare-and-set against the caller's last observed generation.
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $request = json_decode(stream_get_contents(STDIN, 8193), true, 16, JSON_THROW_ON_ERROR);
    $id = $request['mission_id'] ?? null;
    $mode = $request['mode'] ?? null;
    $expected = $request['expected_generation'] ?? null;
    if (! app()->environment('production') || ! in_array($id, array_slice(Platform12DailyMissionSet::IDS, 0, 2), true)
        || ! in_array($mode, ['controlled', 'enable'], true)
        || ! is_string($expected) || preg_match('/^[a-f0-9]{32}$/D', $expected) !== 1) {
        throw new RuntimeException('A08_ACCEPTANCE_SCOPE_HOLD');
    }
    $control = app(Platform12RuntimeControl::class);
    $before = $control->status();
    $manifest = app(Platform12ActivationEvidence::class)->inspect()['manifest'];
    $sha = trim(file_get_contents(config('seo_council.release_revision_path')));
    if ($before['generation'] !== $expected || ($request['sha'] ?? null) !== $sha
        || ! $control->businessGuardsClosed() || ! ($before['missions'][$id]['source_accepted'] ?? false)) {
        throw new RuntimeException('A08_ACCEPTANCE_PREREQUISITE_HOLD');
    }
    $selection = array_slice(Platform12DailyMissionSet::IDS, 0, $id === Platform12DailyMissionSet::IDS[0] ? 1 : 2);
    // The initial pause is the one observed before this task. A newer pause wins.
    if ($before['pause_intent'] === 'PAUSED'
        && ($expected !== '50bc7eec1b1d0aa3277d6237b9f37fa9' || $before['selected_missions'] !== [])) {
        throw new RuntimeException('NEW_OPERATOR_PAUSE_HOLD');
    }
    if ($id === Platform12DailyMissionSet::IDS[1]
        && ! in_array(Platform12DailyMissionSet::IDS[0], $before['effective_mission_ids'], true)) {
        throw new RuntimeException('M1_MUST_REMAIN_ENABLED');
    }
    if ($mode === 'enable' && ! ($before['missions'][$id]['end_to_end_accepted'] ?? false)) {
        throw new RuntimeException('END_TO_END_ACCEPTANCE_PENDING');
    }
    $state = $control->change(false, $selection, $expected);
    if (($state['state'] ?? null) !== 'ACTIVE_READ_ONLY') {
        throw new RuntimeException('A08_CONCURRENT_CONTROL_CHANGE');
    }
    if ($mode === 'enable') {
        if ($state['effective_mission_ids'] !== $selection) {
            throw new RuntimeException('A08_NATURAL_GATE_HOLD');
        }
        echo json_encode(['status' => 'ENABLED', 'sha' => $sha, 'mission_id' => $id,
            'generation' => $state['generation'], 'effective_mission_ids' => $selection,
            'first_enabled_at' => $state['missions'][$id]['first_enabled_at'],
            'business_write_enabled' => false], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (in_array($id, $state['effective_mission_ids'], true)) {
        throw new RuntimeException('CONTROLLED_PHASE_ALREADY_COMPLETE');
    }
    $exit = Artisan::call('seo:council-scheduled', ['--acceptance' => $id, '--json' => true]);
    $result = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
    if ($exit !== 0 || ($result['terminal_committed'] ?? null) !== true
        || $control->status()['generation'] !== $state['generation']) {
        throw new RuntimeException('A08_CONTROLLED_TERMINAL_HOLD');
    }
    $row = DB::connection(config('seo_council.connection', 'seo_intel'))
        ->table('seo_council_schedule_deliveries AS d')
        ->join('seo_council_run_receipts AS r', 'd.terminal_receipt_reference', '=', 'r.receipt_id')
        ->where('d.mission_id', $id)->where('d.terminal_receipt_hash', $result['receipt_hash'])
        ->whereIn('d.status', ['CLOSED', 'HELD'])->first(['r.receipt_json', 'd.mission_request_json']);
    $receipt = $row ? json_decode($row->receipt_json, true, 64, JSON_THROW_ON_ERROR) : null;
    $frozen = $row ? json_decode($row->mission_request_json, true, 64, JSON_THROW_ON_ERROR) : null;
    $hasher = app(SeoRegistryHasher::class);
    if (! is_array($receipt) || $hasher->hashWithout($receipt, 'receipt_hash') !== $result['receipt_hash']
        || data_get($frozen, 'slot.trigger_mode') !== 'controlled_acceptance'
        || data_get($frozen, 'evidence.source_gaps') !== []) {
        throw new RuntimeException('A08_TERMINAL_READBACK_HOLD');
    }
    $page = app(Platform12SystemHealthReadService::class)->snapshot();
    $item = $page['daily_missions']['items'][array_search($id, Platform12DailyMissionSet::IDS, true)] ?? [];
    $evaluation = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'daily_evaluation')['output'] ?? [];
    $expectedReason = $evaluation['reason_codes'][0] ?? $evaluation['state'] ?? null;
    $sourceHashes = array_column($frozen['evidence']['sources'], 'hash');
    $uiHashes = array_column($item['source_checks'] ?? [], 'hash');
    sort($sourceHashes);
    sort($uiHashes);
    $view = view('filament.ops.components.ops-system-health-workspace', ['snapshot' => $page])->render();
    if (($item['receipt_hash'] ?? null) !== $result['receipt_hash'] || empty($item['source_checks'])
        || ($item['reason_code'] ?? null) !== $expectedReason || $sourceHashes !== $uiHashes
        || empty($item['recommendation_key'])
        || ! str_contains($view, 'data-read-only="true"') || ! str_contains($view, substr($result['receipt_hash'], 0, 12))) {
        throw new RuntimeException('A08_RECEIPT_UI_HOLD');
    }
    $report = ['schema_version' => 'seo.a08_controlled_acceptance.v1', 'environment' => 'production',
        'sha' => $sha, 'mission_id' => $id, 'generation' => $state['generation'],
        'source_receipt_digest' => $manifest['missions'][$id]['source_acceptance']['receipt_digest'],
        'version_vector' => $manifest['runtime']['version_vector'],
        'fingerprint' => $manifest['missions'][$id]['checks']['fingerprint'],
        'terminal_committed' => true, 'receipt_hash' => $result['receipt_hash'],
        'observed_verdict' => $result['mission_verdict'], 'receipt_to_ui_verified' => true,
        'runtime_boundaries_verified' => $control->businessGuardsClosed()
            && $control->status()['generation'] === $state['generation'],
        'business_write_enabled' => false, 'completed_at' => now('UTC')->format('Y-m-d\TH:i:s\Z')];
    $report['receipt_digest'] = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    $reason = preg_match('/^(?:A08|STAGING|M1|NEW_OPERATOR|END_TO_END|CONTROLLED)_[A-Z_]{1,80}$/D', $error->getMessage()) === 1
        ? $error->getMessage() : 'A08_CONTROLLED_ACCEPTANCE_HOLD';
    fwrite(STDERR, $reason."\n");
    exit(1);
}
