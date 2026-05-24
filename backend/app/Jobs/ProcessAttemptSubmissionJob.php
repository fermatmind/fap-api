<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Attempts\AttemptSubmissionService;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAttemptSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, int> */
    private const BASE_BACKOFF = [5, 15, 30];

    private const MAX_BACKOFF_JITTER_SECONDS = 5;

    private static ?Closure $backoffJitterResolver = null;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $submissionId,
    ) {
        $connection = config('fap.queue.attempt_connection');
        $this->onConnection((is_string($connection) && $connection !== '') ? $connection : (string) config('queue.default'));
        $this->onQueue(config('fap.queue.attempt_queue', 'attempts'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_values(array_map(
            fn (int $seconds, int $attemptIndex): int => $seconds + $this->backoffJitterSeconds($seconds, $attemptIndex),
            self::BASE_BACKOFF,
            array_keys(self::BASE_BACKOFF),
        ));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(int, int): int  $resolver
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withBackoffJitterResolverForTest(Closure $resolver, Closure $callback): mixed
    {
        $previous = self::$backoffJitterResolver;
        self::$backoffJitterResolver = $resolver;

        try {
            return $callback();
        } finally {
            self::$backoffJitterResolver = $previous;
        }
    }

    public function handle(AttemptSubmissionService $service): void
    {
        $service->process($this->submissionId);
    }

    public function failed(Throwable $exception): void
    {
        app(AttemptSubmissionService::class)->recordTerminalJobFailure(
            $this->submissionId,
            $exception,
            (int) $this->attempts(),
            (int) $this->tries,
            is_string($this->connection) ? $this->connection : null,
            is_string($this->queue) ? $this->queue : null,
        );
    }

    private function backoffJitterSeconds(int $baseSeconds, int $attemptIndex): int
    {
        $maxJitter = self::MAX_BACKOFF_JITTER_SECONDS;
        if ($maxJitter <= 0) {
            return 0;
        }

        $resolver = self::$backoffJitterResolver;
        if ($resolver instanceof Closure) {
            return max(0, min($maxJitter, (int) $resolver($baseSeconds, $attemptIndex)));
        }

        return random_int(0, $maxJitter);
    }
}
