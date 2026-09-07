<?php

declare(strict_types=1);

// Invoked through the existing deploy transport, under the application identity.
// Reads state only. Does not execute a command, Mission, collector or notification.
use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $runtime = app(Platform12RuntimeControl::class);
    $activation = app(App\Services\SeoCouncil\Platform12\Platform12ActivationEvidence::class)->inspect();
    $status = $runtime->status();
    if (($status['state'] ?? null) === 'SHARED_CACHE_HOLD') {
        throw new RuntimeException('A08_SHARED_CACHE_HOLD');
    }
    $raw = Cache::store(config('seo_council.runtime_cache_store'))->get(Platform12RuntimeControl::CACHE_KEY);
    $raw = is_array($raw) ? $raw : [];
    $selected = $raw['selected_missions'] ?? [];
    if (! $runtime->businessGuardsClosed()) {
        throw new RuntimeException('A08_PAUSE_OR_WRITE_GUARD_HOLD');
    }
    $connection = DB::connection(config('seo_council.connection', 'seo_intel'));
    $counts = [];
    foreach (['seo_council_schedule_deliveries', 'seo_council_runs', 'seo_council_run_receipts', 'seo_council_notification_outbox'] as $table) {
        $counts[$table] = $connection->table($table)->count();
    }
    $counts['sent'] = $connection->table('seo_council_notification_outbox')->where('status', 'sent')->count();
    $page = app(Platform12SystemHealthReadService::class)->snapshot();
    $view = view('filament.ops.components.ops-system-health-workspace', ['snapshot' => $page])->render();
    if (($page['status'] ?? null) === 'UNAVAILABLE' || ! str_contains($view, 'data-read-only="true"')) {
        throw new RuntimeException('A08_OPERATIONS_READ_HOLD');
    }
    $capability = app(App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder::class)->snapshot();
    echo json_encode(['schema_version' => 'seo.a08_readonly_state.v2', 'environment' => app()->environment(),
        'sha' => trim(file_get_contents(dirname(getcwd()).'/REVISION')), 'paused' => $raw['paused'] ?? true,
        'gate_only' => getenv('A08_GATE_ONLY') === 'true' && ($raw['paused'] ?? true) && $selected === [],
        'generation' => $raw['generation'] ?? null, 'selected_missions' => $selected, 'counts' => $counts,
        'business_guards_closed' => true, 'operations_readonly' => true,
        'model_runtime_enabled' => false, 'tool_broker_enabled' => false, 'business_write_enabled' => false,
        'post12_agent_write_enabled' => false, '28_day_clock' => 'NOT_STARTED',
        'runtime_status' => array_intersect_key($status, array_flip(['a08_gate_policy', 'gate_software_delivery', 'public_gate', 'effective_enabled_missions'])),
        'activation' => $activation['manifest'] ?? null,
        'version_vector' => $capability['version_vector'], 'version_vector_hash' => $capability['version_vector_hash']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    fwrite(STDERR, "A08_READONLY_STATE_HOLD\n");
    exit(1);
}
