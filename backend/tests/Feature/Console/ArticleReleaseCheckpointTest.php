<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleReleaseCheckpointTest extends TestCase
{
    public function test_resume_fails_closed_without_checkpoint_artifact(): void
    {
        $package = sys_get_temp_dir().'/fm-checkpoint-empty-package-'.Str::random(12);
        mkdir($package, 0777, true);

        $exitCode = Artisan::call('seo-agent:article-release', [
            '--package' => $package,
            '--translation-group-id' => 'tg_checkpoint_contract',
            '--locales' => 'zh-CN,en',
            '--stage' => 'package-qa',
            '--checkpoint' => $package.'/missing-checkpoint.json',
            '--resume' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(1, $exitCode);
        $this->assertSame('checkpoint_unreadable', $payload['errors'][0]['code'] ?? null);
        $this->assertFalse($payload['writes_attempted'] ?? true);
    }
}
