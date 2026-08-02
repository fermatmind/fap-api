<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPackRelease;
use App\Services\Riasec\RiasecResultPageV2ProductionSmokeVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RiasecResultPageV2ProductionSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_verifies_both_forms_and_fail_closed_paths_without_writes(): void
    {
        $before = ContentPackRelease::query()->count();

        $this->artisan('riasec:result-page-v2-production-smoke', ['--json' => true])
            ->expectsOutputToContain('"decision": "pass"')
            ->assertExitCode(0);

        $summary = app(RiasecResultPageV2ProductionSmokeVerifier::class)->verify();
        $this->assertSame('pass', $summary['decision'] ?? null);
        $this->assertSame(60, data_get($summary, 'forms.riasec_60.question_count'));
        $this->assertSame(140, data_get($summary, 'forms.riasec_140.question_count'));
        $this->assertTrue((bool) data_get($summary, 'checks.locked_payload_hidden'));
        $this->assertTrue((bool) data_get($summary, 'checks.private_fields_filtered'));
        $this->assertTrue((bool) data_get($summary, 'checks.legacy_fallback'));

        $this->assertSame($before, ContentPackRelease::query()->count());
    }
}
