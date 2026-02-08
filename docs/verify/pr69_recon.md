# PR69 Recon

- Keywords: config/queue.php|SensitiveDataRedactor|EventController

## 扫描范围
- backend/config/queue.php（failed / retry_after）
- backend/app/Providers/AppServiceProvider.php（日志 processor）
- backend/app/Support/SensitiveDataRedactor.php（脱敏 keys）
- backend/.env.example（APP_DEBUG / FAP_ADMIN_TOKEN / EVENT_INGEST_TOKEN）
- backend/app/Http/Controllers/EventController.php（token 校验）
- backend/config/fap.php（ingest_token 配置）
- backend/database/migrations（failed_jobs 迁移）

## 扫描输出（粘贴命令输出）
- queue failed/retry_after：
- AppServiceProvider processor：
- .env.example baseline：
- EventController token gate：
- failed_jobs migration：

## queue failed/retry_after
- 47:            'retry_after' => 90,
- 55:            'retry_after' => 90,
- 76:            'retry_after' => 90,
- 120:    | These options configure the behavior of failed queue job logging so you
- 121:    | can control how and where failed jobs are stored. Laravel ships with
- 122:    | support for storing failed jobs in a simple file or in a database.
- 124:    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
- 128:    'failed' => [
- 129:        'driver' => 'database-uuids',
- 131:        'table' => 'failed_jobs',

## AppServiceProvider processor
- 10:use App\Support\SensitiveDataRedactor;
- 16:use Monolog\LogRecord;
- 136:            $extractVersion = function (string $raw): string {
- 160:                $contentPackageVersion = $extractVersion($dirVersion);
- 163:                $contentPackageVersion = $extractVersion($packId);
- 226:            $redactor = new SensitiveDataRedactor();
- 228:            Log::getLogger()->pushProcessor(function (array|LogRecord $record) use ($redactor): array|LogRecord {
- 230:                    $context = is_array($record->context) ? $redactor->redact($record->context) : $record->context;
- 231:                    $extra = is_array($record->extra) ? $redactor->redact($record->extra) : $record->extra;
- 233:                    return $record->with(context: $context, extra: $extra);
- 236:                if (isset($record['context']) && is_array($record['context'])) {
- 237:                    $record['context'] = $redactor->redact($record['context']);
- 240:                if (isset($record['extra']) && is_array($record['extra'])) {
- 241:                    $record['extra'] = $redactor->redact($record['extra']);

## .env.example baseline
- 4:APP_DEBUG=false
- 112:FAP_ADMIN_TOKEN=
- 113:EVENT_INGEST_TOKEN=

## EventController token gate
- backend/app/Http/Controllers/EventController.php:26:                'message' => 'Missing Authorization Bearer token.',
- backend/app/Http/Controllers/EventController.php:30:        $ingestToken = trim((string) config('fap.events.ingest_token', ''));
- backend/app/Http/Controllers/EventController.php:33:            if (!hash_equals($ingestToken, $token)) {
- backend/app/Http/Controllers/EventController.php:44:        if (!Schema::hasTable('fm_tokens') || !Schema::hasColumn('fm_tokens', 'token')) {
- backend/app/Http/Controllers/EventController.php:52:        $exists = DB::table('fm_tokens')->where('token', $token)->exists();
- backend/config/fap.php:56:        'ingest_token' => env('EVENT_INGEST_TOKEN', ''),

## failed_jobs migration
- backend/database/migrations/2026_02_08_060000_make_failed_jobs_uuid_nullable.php
