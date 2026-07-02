<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ArticleReleaseCloseoutService;
use Illuminate\Console\Command;

final class ArticleReleaseCloseout extends Command
{
    protected $signature = 'articles:release-closeout
        {--article-id= : Exact article id to inspect}
        {--expected-slug= : Expected article slug lock}
        {--public-smoke-json= : Optional JSON file produced by the public article smoke verifier}
        {--gsc-manual-json= : Optional JSON file recording manual GSC Request Indexing evidence}
        {--observation-json= : Optional JSON file recording D1/D7/D14 observation readiness}
        {--json : Emit JSON}';

    protected $description = 'Read-only closeout report for one released article across CMS, discoverability, URL Truth, and Search Channel state.';

    public function handle(ArticleReleaseCloseoutService $closeout): int
    {
        $articleId = (int) $this->option('article-id');
        $expectedSlug = trim((string) $this->option('expected-slug'));
        $publicSmoke = $this->evidencePayload((string) $this->option('public-smoke-json'), 'PUBLIC_SMOKE_EVIDENCE');
        $gscManual = $this->evidencePayload((string) $this->option('gsc-manual-json'), 'GSC_MANUAL_EVIDENCE');
        $observation = $this->evidencePayload((string) $this->option('observation-json'), 'OBSERVATION_EVIDENCE');
        $payload = $closeout->inspect($articleId, $expectedSlug, $publicSmoke, $gscManual, $observation);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ((bool) ($payload['ok'] ?? false)) {
            $this->info((string) ($payload['decision'] ?? 'ok'));
        } else {
            $this->error((string) ($payload['decision'] ?? 'blocked'));
        }

        return (bool) ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function evidencePayload(string $path, string $label): ?array
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (! is_file($path)) {
            return [
                'ok' => false,
                'decision' => $label.'_FILE_MISSING',
                'path' => $path,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'decision' => $label.'_JSON_INVALID',
                'path' => $path,
            ];
        }

        return $decoded;
    }
}
