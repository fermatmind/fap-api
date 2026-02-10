<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EventsGuardedCreateRollbackDoesNotDropTableTest extends TestCase
{
    #[Test]
    public function rollback_one_step_after_two_create_events_migrations_keeps_events_table(): void
    {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2025_12_14_091106_create_events_table.php',
            '--force' => true,
        ]);

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2025_12_17_165938_create_events_table.php',
            '--force' => true,
        ]);

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2025_12_17_165938_create_events_table.php',
            '--step' => 1,
            '--force' => true,
        ]);

        $this->assertTrue(
            Schema::hasTable('events'),
            'events table must still exist after rolling back one guarded create migration step.'
        );
    }
}
