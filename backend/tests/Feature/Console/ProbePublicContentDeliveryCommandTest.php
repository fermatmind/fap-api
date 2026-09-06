<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Personality\Current\PersonalityCurrentPageReader;
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
        $mbti = $this->currentPayload('mbti', 'variant', 'intj-a');
        $bigFive = $this->currentPayload('big_five', 'hub', 'big-five');
        $headers = $this->currentAuthorityHeaders();
        $mbti['private_payload'] = 'must-not-persist';
        $bigFive['private_payload'] = 'must-not-persist';

        Http::fake(function (Request $request) use ($mbti, $bigFive, $headers) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('FermatMind-Public-Content-Probe/1.0', $request->header('User-Agent')[0] ?? null);
            $this->assertSame([], $request->header('Authorization'));

            return match (true) {
                str_contains($request->url(), '/personality/intj-a') => (function () use ($request, $mbti, $headers) {
                    $this->assertSame('MBTI', $request->data()['scale_code'] ?? null);

                    return Http::response($mbti, 200, $headers);
                })(),
                str_contains($request->url(), '/personality-content-assets/') => Http::response(
                    $bigFive,
                    200,
                    $headers,
                ),
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
        $this->assertSame(1048576, config('public_content_observability.probe.payload_budget_bytes'));
        $this->assertSame('personality.page.content.v1', $latest['items'][0]['content_authority']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $latest['items'][0]['content_aggregate_sha256'],
        );
    }

    public function test_all_mode_marks_stale_cache_and_missing_publication_fields_as_failed(): void
    {
        Http::fake([
            '*personality/intj-a*' => Http::response($this->mbtiPayload(), 200, [
                'X-Fermat-Public-Read-Cache' => 'stale',
                ...$this->currentAuthorityHeaders(),
            ]),
            '*personality-content-assets*' => Http::response(['ok' => true], 200, [
                ...$this->currentAuthorityHeaders(),
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
        config()->set('public_content_observability.probe.payload_budget_bytes', 1048576);
        Http::fakeSequence()
            ->push(str_repeat('x', 1048577), 200, $this->currentAuthorityHeaders())
            ->push(function (): never {
                throw new \Illuminate\Http\Client\ConnectionException('private upstream detail');
            });

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        $oversized = $this->jsonOutput()['items'][0];
        $this->assertSame(1048577, $oversized['bytes']);
        $this->assertSame('payload_budget_exceeded', $oversized['error_code']);
        $this->assertArrayNotHasKey('body', $oversized);

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        $network = $this->jsonOutput()['items'][0];
        $this->assertSame('connection_failed', $network['error_code']);
        $this->assertStringNotContainsString('private upstream detail', json_encode($network, JSON_THROW_ON_ERROR));
    }

    public function test_current_targets_fail_closed_on_missing_authority_or_invalid_aggregate(): void
    {
        Http::fake([
            '*personality/intj-a*' => Http::response($this->mbtiPayload()),
            '*personality-content-assets*' => Http::response($this->bigFivePayload(), 200, [
                'X-Fermat-Content-Authority' => 'personality.page.content.v1',
                'X-Fermat-Content-Aggregate' => 'invalid-private-value',
            ]),
            '*career/industries*' => Http::response($this->careerPayload()),
        ]);

        $this->assertSame(1, Artisan::call('public-content:probe-delivery', [
            '--all' => true,
            '--json' => true,
        ]));
        $items = collect($this->jsonOutput()['items'])->keyBy('target_id');

        $this->assertSame('content_authority_invalid', $items['l1_mbti_intj_a_en']['error_code']);
        $this->assertSame('unknown', $items['l1_mbti_intj_a_en']['content_authority']);
        $this->assertSame('content_aggregate_invalid', $items['l2_big_five_hub_en']['error_code']);
        $this->assertNull($items['l2_big_five_hub_en']['content_aggregate_sha256']);
        $this->assertStringNotContainsString(
            'invalid-private-value',
            json_encode($items->all(), JSON_THROW_ON_ERROR),
        );
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
                'schema_version' => 'v2',
                'slug' => 'intj',
                'locale' => 'en',
            ],
            'mbti_public_projection_v1' => ['display_type' => 'INTJ-A'],
        ];
    }

    /** @return array<string, mixed> */
    private function bigFivePayload(): array
    {
        return [
            'personality_public_content_asset_v1' => [
                'contract_version' => 'personality_public_asset.v1',
                'launch_state' => 'published',
                'source_hash' => str_repeat('b', 64),
                'locale' => 'en',
                'canonical_path' => '/en/personality/big-five',
            ],
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
    private function currentPayload(string $framework, string $pageKind, string $entityKey): array
    {
        return app(PersonalityCurrentPageReader::class)->payload($framework, $pageKind, $entityKey, 'en');
    }

    /** @return array<string, string> */
    private function currentAuthorityHeaders(): array
    {
        return [
            'X-Fermat-Content-Authority' => 'personality.page.content.v1',
            'X-Fermat-Content-Aggregate' => app(PersonalityCurrentPageReader::class)->aggregateSha256(),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }
}
