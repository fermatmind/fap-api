<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerAuditCanonicalEligibilityCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:audit-canonical-eligibility', Artisan::all());
    }

    public function test_slugs_mode_returns_read_only_json_schema(): void
    {
        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('slugs', $payload['scope']);
        $this->assertSame(1, $payload['audited_occupations']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame('actuaries', data_get($payload, 'rows.0.slug'));
        $this->assertSame('unverified', data_get($payload, 'rows.0.runtime_status.status'));
    }

    public function test_missing_public_resolution_plan_reports_structured_error(): void
    {
        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'all',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['public_resolution_plan_missing' => 1], $payload['by_reason']);
        $this->assertTrue($payload['read_only']);
    }

    public function test_include_live_html_without_base_url_reports_unverified_context_issue(): void
    {
        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--include-surfaces' => true,
            '--include-live-html' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('unverified', data_get($payload, 'rows.0.surface_status.status'));
        $this->assertContains('validator_context_missing', data_get($payload, 'rows.0.surface_status.reasons'));
        $this->assertSame(1, $payload['by_reason']['validator_context_missing']);
    }

    public function test_json_schema_is_stable(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'status',
            'scope',
            'expected_occupations',
            'audited_occupations',
            'eligible_count',
            'blocked_count',
            'by_reason',
            'rows',
            'sidecars',
            'read_only',
            'writes_database',
            'audit_command',
        ], array_keys($payload));
        $this->assertSame([
            'slug',
            'locale',
            'source_scope',
            'entity_status',
            'baseline_status',
            'index_status',
            'runtime_status',
            'seo_geo_status',
            'surface_status',
            'safety_status',
            'overall_status',
            'severity',
            'reasons',
            'evidence',
            'sidecars',
        ], array_keys($payload['rows'][0]));
    }
}
