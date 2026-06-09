<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentPages\ContentPagesControlledPublishService;
use Illuminate\Console\Command;

final class ContentPagesPublishControlledCommand extends Command
{
    protected $signature = 'content-pages:publish-controlled
        {--scope=global-en-wave1 : Controlled publish scope. Supported: global-en-wave1, help-service, science-zh}
        {--locale=en : Required locale. Use en, all for help-service, or zh-CN for science-zh}
        {--keys= : Comma-separated exact page keys to publish}
        {--dry-run : Preview without writes}
        {--execute : Explicitly perform the controlled publish}
        {--json : Emit JSON output}';

    protected $description = 'Fail-closed controlled publish runtime for approved content_pages records.';

    public function handle(ContentPagesControlledPublishService $service): int
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
