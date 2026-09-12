<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiResultIntroductionPublisher;
use Illuminate\Console\Command;
use Throwable;

final class PublishMbtiResultIntroductions extends Command
{
    protected $signature = 'personality:publish-result-introductions {--expected-hash= : Exact SHA-bound editorial package hash} {--write : Publish the validated 64 assets atomically}';

    protected $description = 'Validate or publish the bilingual MBTI opening introductions without changing full-report or SEO publication.';

    public function handle(MbtiResultIntroductionPublisher $publisher): int
    {
        try {
            $result = $publisher->publish((string) $this->option('expected-hash'), (bool) $this->option('write'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
