<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class UuidRouteParamsMiddlewareTest extends TestCase
{
    public function test_v03_share_click_with_invalid_share_id_returns_uniform_404_json(): void
    {
        $response = $this->postJson('/api/v0.3/shares/not-a-uuid/click');

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_v03_attempt_report_with_invalid_uuid_returns_uniform_404_json(): void
    {
        $response = $this->getJson('/api/v0.3/attempts/not-a-uuid/report');

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_v03_attempt_report_pdf_with_invalid_uuid_returns_uniform_404_json(): void
    {
        $response = $this->getJson('/api/v0.3/attempts/not-a-uuid/report.pdf');

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }
}
