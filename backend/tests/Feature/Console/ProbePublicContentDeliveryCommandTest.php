<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ops\PublicContentDeliveryProbeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProbePublicContentDeliveryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('public_content_observability.probe.cache_store', 'array');
        config()->set('public_content_observability.probe.base_url', 'https://probe.example.test');
        config()->set('public_content_observability.probe.enabled', true);
        Cache::store('array')->flush();
        CarbonImmutable::setTestNow('2026-07-14 09:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_rotates_the_fixed_l1_l2_l3_allowlist_and_persists_only_safe_readback_fields(): void
    {
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('FermatMind-Public-Content-Probe/1.0', $request->header('User-Agent')[0] ?? null);
            $this->assertSame([], $request->header('Authorization'));

            return match (true) {
                str_contains($request->url(), '/personality/intj-a') => (function () use ($request) {
                    $this->assertSame('MBTI', $request->data()['scale_code'] ?? null);

                    return Http::response([
                        'profile' => [
                            'published_at' => '2026-07-01T00:00:00Z',
                            'updated_at' => '2026-07-13T00:00:00Z',
                            'private_marker' => 'must-not-persist',
                        ],
                        'mbti_public_projection_v1' => ['display_type' => 'INTJ-A'],
                        'body' => 'must-not-persist',
                    ], 200, ['X-Fermat-Public-Read-Cache' => 'fresh']);
                })(),
                str_contains($request->url(), '/personality-content-assets/') => Http::response([
                    'personality_public_content_asset_v1' => [
                        'contract_version' => 'personality.public_content_asset.v1',
                        'launch_state' => 'published',
                        'review_state' => 'approved',
                        'published_at' => '2026-07-01T00:00:00Z',
                        'updated_at' => '2026-07-13T00:00:00Z',
                        'sections' => ['must-not-persist'],
                    ],
                ], 200, ['X-Fermat-Public-Read-Cache' => 'miss']),
                default => Http::response([
                    'authority_version' => 'career.industry_directory.v1',
                    'bundle_version' => 'career.industry_directory.v1',
                    'locale' => 'en',
                    'public_detail_indexable_count' => 1048,
                    'industry_count' => 23,
                    'industries' => [['body' => 'must-not-persist']],
                ]),
            };
        });

        $targetIds = [];
        foreach (range(1, 3) as $_) {
            $this->assertSame(0, Artisan::call('public-content:probe-delivery', ['--json' => true]));
            $report = $this->jsonOutput();
            $this->assertTrue($report['ok']);
            $targetIds[] = $report['items'][0]['target_id'];
        }

        $this->assertSame([
            'l1_mbti_intj_a_en',
            'l2_big_five_hub_en',
            'l3_career_industries_en',
        ], $targetIds);

        $latest = app(PublicContentDeliveryProbeService::class)->latest();
        $encoded = json_encode($latest, JSON_THROW_ON_ERROR);
        $this->assertTrue($latest['ok']);
        $this->assertCount(3, $latest['items']);
        $this->assertStringNotContainsString('must-not-persist', $encoded);
        $this->assertStringNotContainsString('probe.example.test', $encoded);
        $this->assertStringNotContainsString('/api/', $encoded);
        $this->assertArrayNotHasKey('response_body', $latest['items'][0]);
    }

    public function test_all_mode_marks_stale_cache_and_missing_publication_fields_as_failed(): void
    {
        Http::fake([
            '*personality/intj-a*' => Http::response($this->mbtiPayload(), 200, [
                'X-Fermat-Public-Read-Cache' => 'stale',
            ]),
            '*personality-content-assets*' => Http::response(['ok' => true], 200, [
                'X-Fermat-Public-Read-Cache' => 'fresh',
            ]),
            '*career/industries*' => Http::response($this->careerPayload()),
        ]);

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', [
            '--all' => true,
            '--json' => true,
        ]));
        $items = collect($this->jsonOutput()['items'])->keyBy('target_id');

        $this->assertSame('cache_state_degraded', $items['l1_mbti_intj_a_en']['error_code']);
        $this->assertSame('publication_readback_failed', $items['l2_big_five_hub_en']['error_code']);
        $this->assertTrue($items['l3_career_industries_en']['ok']);
    }

    public function test_payload_budget_and_connection_failures_are_bounded_without_exception_details(): void
    {
        config()->set('public_content_observability.probe.payload_budget_bytes', 1024);
        Http::fakeSequence()
            ->push(str_repeat('x', 2048), 200, ['X-Fermat-Public-Read-Cache' => 'fresh'])
            ->push(function (): never {
                throw new \Illuminate\Http\Client\ConnectionException('private upstream detail');
            });

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        $oversized = $this->jsonOutput()['items'][0];
        $this->assertSame(1025, $oversized['bytes']);
        $this->assertSame('payload_budget_exceeded', $oversized['error_code']);
        $this->assertArrayNotHasKey('body', $oversized);

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        $network = $this->jsonOutput()['items'][0];
        $this->assertSame('connection_failed', $network['error_code']);
        $this->assertStringNotContainsString('private upstream detail', json_encode($network, JSON_THROW_ON_ERROR));
    }

    public function test_private_or_tenant_scoped_config_fails_before_any_request(): void
    {
        $targets = (array) config('public_content_observability.probe.targets');
        $targets[0]['path'] = '/api/v0.5/attempts/private';
        $targets[0]['query']['org_id'] = 9;
        config()->set('public_content_observability.probe.targets', $targets);
        Http::fake();

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        $this->assertSame(
            'probe_configuration_or_storage_unavailable',
            $this->jsonOutput()['error_code'],
        );
        Http::assertNothingSent();
    }

    public function test_probe_rejects_unallowlisted_query_keys_and_credentialed_base_urls(): void
    {
        $targets = (array) config('public_content_observability.probe.targets');
        $targets[0]['query']['token'] = 'must-never-be-sent';
        config()->set('public_content_observability.probe.targets', $targets);
        Http::fake();

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        Http::assertNothingSent();

        unset($targets[0]['query']['token']);
        config()->set('public_content_observability.probe.targets', $targets);
        config()->set('public_content_observability.probe.base_url', 'https://user:secret@probe.example.test/path');

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        Http::assertNothingSent();

        config()->set('public_content_observability.probe.base_url', 'https://probe.example.test');
        $targets[0]['query']['scale_code'] = 'mbti';
        config()->set('public_content_observability.probe.targets', $targets);

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        Http::assertNothingSent();
    }

    public function test_schedule_registers_one_bounded_rotation_without_warm_or_purge_flags(): void
    {
        $output = Artisan::call('schedule:list', ['--no-ansi' => true]);
        $schedule = Artisan::output();

        $this->assertSame(0, $output);
        $this->assertStringContainsString('public-content:probe-delivery --json', $schedule);
        $this->assertStringNotContainsString('public-content:probe-delivery --all', $schedule);
        $this->assertStringNotContainsString('public-content:probe-delivery --warm', $schedule);
        $this->assertStringNotContainsString('public-content:probe-delivery --purge', $schedule);
    }

    public function test_schedule_does_not_register_probe_when_it_is_disabled(): void
    {
        config()->set('public_content_observability.probe.enabled', false);

        $output = Artisan::call('schedule:list', ['--no-ansi' => true]);
        $schedule = Artisan::output();

        $this->assertSame(0, $output);
        $this->assertStringNotContainsString('public-content:probe-delivery', $schedule);
    }

    /** @return array<string, mixed> */
    private function mbtiPayload(): array
    {
        return [
            'profile' => [
                'published_at' => '2026-07-01T00:00:00Z',
                'updated_at' => '2026-07-13T00:00:00Z',
            ],
            'mbti_public_projection_v1' => ['display_type' => 'INTJ-A'],
        ];
    }

    /** @return array<string, mixed> */
    private function careerPayload(): array
    {
        return [
            'authority_version' => 'career.industry_directory.v1',
            'bundle_version' => 'career.industry_directory.v1',
            'locale' => 'en',
            'public_detail_indexable_count' => 1048,
            'industry_count' => 23,
        ];
    }

    /** @return array<string, mixed> */
    private function jsonOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }
}
