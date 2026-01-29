<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScalesListTest extends TestCase
{
    use RefreshDatabase;

    public function test_scales_list_contains_mbti(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $response = $this->getJson('/api/v0.3/scales');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
        ]);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $codes = array_map(function ($row) {
            return $row['code'] ?? null;
        }, $items);
        $this->assertContains('MBTI', $codes);
    }
}
