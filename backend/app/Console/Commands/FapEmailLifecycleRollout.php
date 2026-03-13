<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\EmailLifecycleRolloutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class FapEmailLifecycleRollout extends Command
{
    protected $signature = 'email:lifecycle-rollout {--dry-run : Scan eligible subscribers without enqueueing outbox rows.}';

    protected $description = 'Scan lifecycle confirmation candidates and enqueue pending outbox rows.';

    public function __construct(
        private readonly EmailLifecycleRolloutService $rollout,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('email_subscribers') || ! Schema::hasTable('email_outbox')) {
            $this->warn('Lifecycle rollout tables are missing.');

            return Command::SUCCESS;
        }

        $result = $this->rollout->rollout((bool) $this->option('dry-run'));

        $this->info('Candidates: '.(int) ($result['candidates'] ?? 0));
        $this->info('Enqueued: '.(int) ($result['enqueued'] ?? 0));
        $this->line(sprintf(
            'preferences_updated => candidates %d, enqueued %d',
            (int) data_get($result, 'templates.preferences_updated.candidates', 0),
            (int) data_get($result, 'templates.preferences_updated.enqueued', 0),
        ));
        $this->line(sprintf(
            'unsubscribe_confirmation => candidates %d, enqueued %d',
            (int) data_get($result, 'templates.unsubscribe_confirmation.candidates', 0),
            (int) data_get($result, 'templates.unsubscribe_confirmation.enqueued', 0),
        ));

        if ((bool) ($result['dry_run'] ?? false)) {
            $this->comment('Dry run: no outbox rows were enqueued.');
        }

        return Command::SUCCESS;
    }
}
