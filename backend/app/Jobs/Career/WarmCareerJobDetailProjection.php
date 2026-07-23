<?php

declare(strict_types=1);

namespace App\Jobs\Career;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class WarmCareerJobDetailProjection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return strtolower(trim($this->slug)).':'.strtolower(trim($this->locale));
    }

    public function handle(PublicCareerAuthorityResponseCache $cache): void
    {
        $cache->warmJobDetailPayload($this->slug, $this->locale);
    }
}
