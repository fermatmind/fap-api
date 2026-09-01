<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use Illuminate\Console\Command;

final class SeoCompetitiveEvidenceCloseoutCommand extends Command
{
    protected $signature = 'seo:competitive-evidence-closeout
        {--expected-sha= : Exact candidate SHA}
        {--environment=ci_candidate : ci_candidate only}
        {--json : Emit machine-readable output}';

    protected $description = 'Emit the offline SEO Platform 11G exact-SHA closeout';

    public function handle(CompetitiveCloseoutBuilder $builder): int
    {
        $sha = trim((string) $this->option('expected-sha'));
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1 || $this->option('environment') !== 'ci_candidate') {
            return self::FAILURE;
        }
        $receipt = $builder->build($sha);
        if (! $builder->verify($receipt, $sha)) {
            return self::FAILURE;
        }
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line((bool) $this->option('json') ? $encoded : 'SEO-PLATFORM-11G=OFFLINE_EVAL_READY');

        return self::SUCCESS;
    }
}
