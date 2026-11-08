<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentPages\ContentPagesControlledPublishService;
use App\Services\ContentPages\ReviewedSciencePageRevision;
use Illuminate\Console\Command;

final class ContentPagesPublishControlledCommand extends Command
{
    protected $signature = 'content-pages:publish-controlled
        {--scope=global-en-wave1 : Controlled scope: global-en-wave1, help-service, science-zh, reviewed-existing-science}
        {--locale=en : Required locale. Use en, all for help-service, or zh-CN for science-zh}
        {--keys= : Comma-separated exact page keys to publish}
        {--environment=production : Existing science baseline environment: staging or production}
        {--phase=publish : Existing science revision operation: stage, review, publish}
        {--candidate= : Frozen bilingual candidate JSON file for existing science revisions}
        {--candidate-sha256= : Exact candidate file SHA256}
        {--expected-state-sha256= : State SHA256 returned by the matching read-only preflight}
        {--actor-admin-id=0 : Configured owner executing an existing science revision operation}
        {--dry-run : Preview without writes}
        {--execute : Explicitly perform the controlled publish}
        {--json : Emit JSON output}';

    protected $description = 'Fail-closed controlled publish runtime for approved content_pages records.';

    public function handle(ContentPagesControlledPublishService $service, ReviewedSciencePageRevision $revisions): int
    {
        $execute = (bool) $this->option('execute');
        $dryRunOption = (bool) $this->option('dry-run');

        if ($execute && $dryRunOption) {
            $payload = [
                'ok' => false,
                'command' => ContentPagesControlledPublishService::COMMAND,
                'dry_run' => true,
                'execute' => false,
                'writes_committed' => false,
                'errors' => [[
                    'field' => 'options',
                    'code' => 'execute_dry_run_conflict',
                    'message' => '--execute and --dry-run cannot be combined.',
                    'context' => [],
                ]],
            ];
            $this->emit($payload);

            return self::FAILURE;
        }

        $scope = trim((string) $this->option('scope'));
        $locale = trim((string) $this->option('locale'));
        $keys = $this->keys();
        if ($scope === ReviewedSciencePageRevision::SCOPE) {
            $payload = $revisions->run(
                $locale,
                $keys,
                (string) $this->option('phase'),
                (string) $this->option('candidate'),
                (string) $this->option('candidate-sha256'),
                $execute,
                (string) $this->option('expected-state-sha256'),
                (int) $this->option('actor-admin-id'),
                (string) $this->option('environment'),
            );
            $this->emit($payload);

            return $payload['ok'] ? self::SUCCESS : self::FAILURE;
        }
        $payload = $execute
            ? $service->execute($scope, $locale, $keys)
            : $service->dryRun($scope, $locale, $keys);

        $this->emit($payload);

        return (bool) ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $raw = trim((string) $this->option('keys'));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            explode(',', $raw),
        ), static fn (string $key): bool => $key !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('ok='.(($payload['ok'] ?? false) ? '1' : '0'));
        $this->line('dry_run='.(($payload['dry_run'] ?? true) ? '1' : '0'));
        $this->line('writes_committed='.(($payload['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('target_count='.(string) ($payload['target_count'] ?? 0));
        $this->line('blocked_count='.(string) ($payload['blocked_count'] ?? count((array) ($payload['errors'] ?? []))));
    }
}
