<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiTraitExplanations;
use Illuminate\Console\Command;
use Throwable;

final class PublishMbtiTraitContent extends Command
{
    protected $signature = 'personality:publish-trait-content {--expected-hash=} {--write}';

    protected $description = 'Validate or publish the exact Chinese MBTI trait and overview package.';

    public function handle(MbtiTraitExplanations $catalog): int
    {
        try {
            $this->line(json_encode($catalog->publish((string) $this->option('expected-hash'), (bool) $this->option('write')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
