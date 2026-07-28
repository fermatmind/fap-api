<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Seo13ArticleReleaseCloseoutService;
use Illuminate\Console\Command;

final class ArticleSeo13ReleaseCloseout extends Command
{
    protected $signature = 'articles:seo13-release-closeout
        {--json : Emit a sanitized JSON closeout artifact}';

    protected $description = 'Read-only SEO 13 batch closeout with publication, schema, discoverability, search-hold, and cannibalization evidence.';

    public function handle(Seo13ArticleReleaseCloseoutService $service): int
    {
        $payload = $service->inspect();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } elseif (($payload['ok'] ?? false) === true) {
            $this->info((string) $payload['decision']);
        } else {
            $this->error((string) $payload['decision']);
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
