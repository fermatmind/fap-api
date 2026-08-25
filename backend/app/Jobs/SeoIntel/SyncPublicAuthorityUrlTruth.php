<?php

declare(strict_types=1);

namespace App\Jobs\SeoIntel;

use App\Services\SeoIntel\UrlTruth\IncrementalUrlTruthSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncPublicAuthorityUrlTruth implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $pageEntityType,
        public readonly string $entityIdentity,
        public readonly string $locale,
        public readonly string $revision,
        public readonly string $change,
    ) {}

    public function uniqueId(): string
    {
        return hash('sha256', implode('|', [
            $this->pageEntityType,
            $this->entityIdentity,
            $this->locale,
            $this->revision,
        ]));
    }

    public function handle(IncrementalUrlTruthSyncService $service): void
    {
        $service->sync(
            $this->pageEntityType,
            $this->entityIdentity,
            $this->locale,
            $this->revision,
            $this->change,
        );
    }
}
