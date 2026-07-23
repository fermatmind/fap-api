<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\Cms\MbtiCrossPublisher49ContentService;
use App\Services\Cms\MbtiCrossPublisher49IndexabilityService;
use App\Services\Cms\MbtiCrossPublisher49Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class PersonalityMbtiCrossPublisher49CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_command_defaults_to_exact_three_dry_run_without_writes(): void
    {
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-content', ['--json' => true]));
        $summary = $this->parsedOutput();

        self::assertTrue($summary['ok']);
        self::assertSame('dry_run', $summary['mode']);
        self::assertSame(3, $summary['record_count']);
        self::assertSame(MbtiCrossPublisher49Package::EXACT_SLUGS, $summary['exact_slugs']);
        self::assertSame(MbtiCrossPublisher49Package::PACKAGE_SHA256, $summary['package_sha256']);
        self::assertSame(MbtiCrossPublisher49Package::AUTHORIZATION_SHA256, $summary['editorial_authorization_sha256']);
        self::assertFalse($summary['writes_committed']);
        self::assertFalse($summary['indexability_mutated']);
        self::assertFalse($summary['sitemap_or_llms_mutated']);
        self::assertSame('mbti.cross.publisher49.rollback.v1', data_get($summary, 'rollback_manifest.contract'));
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_exact_authorized_content_write_is_atomic_and_remains_noindex(): void
    {
        $written = $this->publishContent();

        self::assertSame('published_noindex', $written['status']);
        self::assertTrue($written['writes_committed']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $written['content_readback_sha256']);
        self::assertSame(3, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());

        foreach (MbtiCrossPublisher49Package::EXACT_SLUGS as $slug) {
            $row = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
            self::assertSame('approved', $row->review_status);
            self::assertSame('published', $row->publish_status);
            self::assertTrue((bool) $row->is_public);
            self::assertFalse((bool) $row->is_indexable);
            self::assertFalse((bool) $row->sitemap_eligible);
            self::assertFalse((bool) $row->llms_eligible);
            self::assertFalse((bool) $row->search_submission_eligible);
            self::assertSame('noindex,follow', data_get($row->content_payload_json, 'robots'));
            self::assertSame(MbtiCrossPublisher49Package::PACKAGE_SHA256, data_get($row->content_payload_json, 'package_sha256'));
        }
    }

    public function test_missing_or_extra_slug_and_package_tamper_fail_closed(): void
    {
        $package = $this->package();
        array_pop($package['records']);
        $missingPath = $this->writeJson($package, 'missing');
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--package' => $missingPath,
            '--json' => true,
        ]));
        self::assertStringContainsString('package SHA-256 mismatch', Artisan::output());

        $package = $this->package();
        $package['records'][] = $package['records'][0];
        $package['records'][3]['slug'] = 'intj-vs-entp';
        $extraPath = $this->writeJson($package, 'extra');
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--package' => $extraPath,
            '--json' => true,
        ]));
        self::assertStringContainsString('package SHA-256 mismatch', Artisan::output());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_package_and_authorization_hash_mismatches_fail_before_any_write(): void
    {
        $package = $this->package();
        data_set($package, 'records.0.candidate_payload.title', 'tampered');
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--package' => $this->writeJson($package, 'package-tamper'),
            '--json' => true,
        ]));
        self::assertStringContainsString('package SHA-256 mismatch', Artisan::output());

        $authorization = $this->authorization();
        $authorization['permits_pr_49_implementation'] = false;
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--authorization' => $this->writeJson($authorization, 'authorization-tamper'),
            '--json' => true,
        ]));
        self::assertStringContainsString('authorization SHA-256 mismatch', Artisan::output());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_production_current_state_precondition_mismatch_fails_closed(): void
    {
        $wrongState = str_repeat('a', 64);
        $service = app(MbtiCrossPublisher49ContentService::class);
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--execute' => true,
            '--expected-current-state-sha256' => $wrongState,
            '--production-authorization' => $service->expectedProductionAuthorization($wrongState),
            '--json' => true,
        ]));

        self::assertStringContainsString('current-state SHA-256 precondition mismatch', Artisan::output());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_partial_failure_rolls_back_all_three_records(): void
    {
        MbtiCrossTypeComparisonAuthority::saving(static function (MbtiCrossTypeComparisonAuthority $row): void {
            if ($row->slug === 'isfp-vs-infp') {
                throw new RuntimeException('injected third-row failure');
            }
        });

        $plan = $this->contentPlan();
        $service = app(MbtiCrossPublisher49ContentService::class);
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--execute' => true,
            '--expected-current-state-sha256' => $plan['current_state_sha256'],
            '--production-authorization' => $service->expectedProductionAuthorization($plan['current_state_sha256']),
            '--json' => true,
        ]));

        self::assertStringContainsString('injected third-row failure', Artisan::output());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_content_write_is_idempotent_without_creating_partial_state(): void
    {
        $plan = $this->contentPlan();
        $service = app(MbtiCrossPublisher49ContentService::class);
        $options = [
            '--execute' => true,
            '--expected-current-state-sha256' => $plan['current_state_sha256'],
            '--production-authorization' => $service->expectedProductionAuthorization($plan['current_state_sha256']),
            '--json' => true,
        ];
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-content', $options));
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-content', $options));
        $second = $this->parsedOutput();

        self::assertSame('already_applied', $second['status']);
        self::assertFalse($second['writes_committed']);
        self::assertTrue($second['already_applied']);
        self::assertSame(3, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_indexability_stage_requires_independent_exact_authorization(): void
    {
        $written = $this->publishContent();

        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-indexability', [
            '--execute' => true,
            '--content-readback-sha256' => $written['content_readback_sha256'],
            '--json' => true,
        ]));
        $failure = $this->parsedOutput();
        self::assertFalse($failure['ok']);
        self::assertStringContainsString('Independent exact production indexability authorization', $failure['error']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('is_indexable', true)->count());
    }

    public function test_indexability_dry_run_and_test_database_execute_never_change_body_or_search_state(): void
    {
        $written = $this->publishContent();
        $beforeSections = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->orderBy('slug')
            ->get()
            ->map(fn (MbtiCrossTypeComparisonAuthority $row): array => (array) data_get($row->content_payload_json, 'sections'))
            ->all();

        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-indexability', ['--json' => true]));
        $plan = $this->parsedOutput();
        self::assertSame('dry_run', $plan['mode']);
        self::assertSame('held', $plan['discoverability_state']);
        self::assertFalse($plan['indexability_write_committed']);
        self::assertSame($written['content_readback_sha256'], $plan['required_content_readback_sha256']);

        $service = app(MbtiCrossPublisher49IndexabilityService::class);
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-indexability', [
            '--execute' => true,
            '--content-readback-sha256' => $written['content_readback_sha256'],
            '--production-authorization' => $service->expectedProductionAuthorization($written['content_readback_sha256']),
            '--json' => true,
        ]));
        $released = $this->parsedOutput();
        self::assertTrue($released['ok']);
        self::assertTrue($released['indexability_write_committed']);
        self::assertFalse($released['body_mutated']);
        self::assertFalse($released['search_submission_executed']);
        self::assertSame('mbti.cross.publisher49.indexability-rollback.v1', data_get($released, 'rollback_manifest.contract'));

        $after = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->orderBy('slug')->get();
        self::assertSame($beforeSections, $after->map(
            fn (MbtiCrossTypeComparisonAuthority $row): array => (array) data_get($row->content_payload_json, 'sections')
        )->all());
        self::assertSame(3, $after->where('is_indexable', true)->count());
        self::assertSame(3, $after->where('sitemap_eligible', true)->count());
        self::assertSame(3, $after->where('llms_eligible', true)->count());
        self::assertSame(0, $after->where('search_submission_eligible', true)->count());

        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-indexability', [
            '--execute' => true,
            '--content-readback-sha256' => $written['content_readback_sha256'],
            '--production-authorization' => $service->expectedProductionAuthorization($written['content_readback_sha256']),
            '--json' => true,
        ]));
        self::assertSame('already_released', $this->parsedOutput()['status']);
    }

    public function test_indexability_release_rejects_live_content_drift_even_when_stored_hashes_are_unchanged(): void
    {
        $written = $this->publishContent();
        $row = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('slug', 'enfp-vs-entp')
            ->firstOrFail();
        $row->forceFill(['title' => 'tampered after content publication'])->save();

        $service = app(MbtiCrossPublisher49IndexabilityService::class);
        self::assertSame(1, Artisan::call('personality:mbti-cross-publisher49-indexability', [
            '--execute' => true,
            '--content-readback-sha256' => $written['content_readback_sha256'],
            '--production-authorization' => $service->expectedProductionAuthorization($written['content_readback_sha256']),
            '--json' => true,
        ]));

        self::assertStringContainsString('does not match the exact approved package', Artisan::output());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('is_indexable', true)
            ->count());
    }

    public function test_exact_content_readback_accepts_json_object_key_reordering(): void
    {
        $written = $this->publishContent();
        $row = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('slug', 'enfp-vs-entp')
            ->firstOrFail();
        $row->forceFill([
            'content_payload_json' => array_reverse((array) $row->content_payload_json, true),
        ])->save();

        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-indexability', ['--json' => true]));
        $plan = $this->parsedOutput();

        self::assertTrue($plan['ok']);
        self::assertSame('held', $plan['discoverability_state']);
        self::assertSame($written['content_readback_sha256'], $plan['required_content_readback_sha256']);
    }

    public function test_content_phase_rerun_preserves_an_already_released_exact_package(): void
    {
        $written = $this->publishContent();
        $indexability = app(MbtiCrossPublisher49IndexabilityService::class);
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-indexability', [
            '--execute' => true,
            '--content-readback-sha256' => $written['content_readback_sha256'],
            '--production-authorization' => $indexability->expectedProductionAuthorization($written['content_readback_sha256']),
            '--json' => true,
        ]));

        $plan = $this->contentPlan();
        self::assertSame('already_released', $plan['status']);
        self::assertTrue($plan['already_applied']);
        self::assertSame($written['content_readback_sha256'], $plan['content_readback_sha256']);

        $content = app(MbtiCrossPublisher49ContentService::class);
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--execute' => true,
            '--expected-current-state-sha256' => $plan['current_state_sha256'],
            '--production-authorization' => $content->expectedProductionAuthorization($plan['current_state_sha256']),
            '--json' => true,
        ]));
        $rerun = $this->parsedOutput();

        self::assertSame('already_released', $rerun['status']);
        self::assertFalse($rerun['writes_committed']);
        $rows = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->get();
        self::assertSame(3, $rows->where('is_indexable', true)->count());
        self::assertSame(3, $rows->where('sitemap_eligible', true)->count());
        self::assertSame(3, $rows->where('llms_eligible', true)->count());
        self::assertSame(0, $rows->where('search_submission_eligible', true)->count());
        self::assertSame(
            ['index,follow'],
            $rows->pluck('content_payload_json')->map(
                static fn (array $payload): string => (string) ($payload['robots'] ?? '')
            )->unique()->values()->all(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function publishContent(): array
    {
        $plan = $this->contentPlan();
        $service = app(MbtiCrossPublisher49ContentService::class);
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-content', [
            '--execute' => true,
            '--expected-current-state-sha256' => $plan['current_state_sha256'],
            '--production-authorization' => $service->expectedProductionAuthorization($plan['current_state_sha256']),
            '--json' => true,
        ]));

        return $this->parsedOutput();
    }

    /**
     * @return array<string,mixed>
     */
    private function contentPlan(): array
    {
        self::assertSame(0, Artisan::call('personality:mbti-cross-publisher49-content', ['--json' => true]));

        return $this->parsedOutput();
    }

    /**
     * @return array<string,mixed>
     */
    private function package(): array
    {
        return json_decode((string) File::get(base_path(MbtiCrossPublisher49Package::PACKAGE_PATH)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function authorization(): array
    {
        return json_decode((string) File::get(base_path(MbtiCrossPublisher49Package::AUTHORIZATION_PATH)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(array $payload, string $name): string
    {
        $path = storage_path("framework/testing/mbti-cross-publisher49-{$name}-".bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function parsedOutput(): array
    {
        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
