<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFivePublicIntegrityGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PersonalityBigFivePublicIntegrityGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(PersonalityBigFivePublicIntegrityGate::class)
        );
    }

    public function test_gate_accepts_canonical_200_and_exact_reviewed_301_alias(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match ($url) {
                'https://fermatmind.com/en/personality/big-five' => Http::response(
                    '<html><head><link rel="canonical" href="https://fermatmind.com/en/personality/big-five"></head></html>',
                    200
                ),
                'https://fermatmind.com/en/personality/big-five/facets/order' => Http::response(
                    '<html><head><link rel="canonical" href="https://fermatmind.com/en/personality/big-five/facets/order"></head></html>',
                    200
                ),
                'https://fermatmind.com/zh/personality/big-five/high-openness' => Http::response(
                    '',
                    301,
                    ['Location' => '/zh/personality/big-five/openness-high']
                ),
                'https://fermatmind.com/zh/personality/big-five/openness-high' => Http::response(
                    '<html><head><link href="/zh/personality/big-five/openness-high" rel="canonical"></head></html>',
                    200
                ),
                default => Http::response('', 404),
            };
        });

        $source = $this->writePackage([
            '/en/personality/big-five',
            '/en/personality/big-five/facets/order',
            '/zh/personality/big-five/high-openness',
        ]);

        $exitCode = Artisan::call('personality-big-five:public-integrity-gate', [
            '--source' => $source,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(3, $payload['target_count']);
        $this->assertSame(2, $payload['canonical_200_count']);
        $this->assertSame(1, $payload['reviewed_301_alias_count']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
    }

    public function test_gate_rejects_404_unreviewed_redirect_private_cross_authority_and_canonical_mismatch(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://fermatmind.com/en/personality/big-five/missing' => Http::response('', 404),
                'https://fermatmind.com/en/personality/big-five/old' => Http::response(
                    '',
                    301,
                    ['Location' => '/en/personality/big-five']
                ),
                'https://fermatmind.com/en/personality/big-five/openness' => Http::response(
                    '<link rel="canonical" href="https://fermatmind.com/en/personality/big-five">',
                    200
                ),
                default => Http::response('', 500),
            };
        });

        $source = $this->writePackage([
            '/en/personality/big-five/missing',
            '/en/personality/big-five/old',
            '/en/personality/big-five/openness',
            '/en/results/private-report',
            'https://api.fermatmind.com/en/personality/big-five',
        ]);

        $exitCode = Artisan::call('personality-big-five:public-integrity-gate', [
            '--source' => $source,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertEqualsCanonicalizing([
            'canonical_mismatch',
            'private_target_rejected',
            'undeclared_external_or_invalid_target',
            'unexpected_http_status',
            'unreviewed_301_alias',
        ], array_column($payload['errors'], 'code'));
    }

    public function test_gate_detects_redirect_loop_before_following_it_again(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://fermatmind.com/zh/personality/big-five/high-openness' => Http::response(
                    '',
                    301,
                    ['Location' => '/zh/personality/big-five/openness-high']
                ),
                'https://fermatmind.com/zh/personality/big-five/openness-high' => Http::response(
                    '',
                    301,
                    ['Location' => '/zh/personality/big-five/high-openness']
                ),
                default => Http::response('', 404),
            };
        });

        $source = $this->writePackage(['/zh/personality/big-five/high-openness']);
        $exitCode = Artisan::call('personality-big-five:public-integrity-gate', [
            '--source' => $source,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('redirect_loop', $payload['errors'][0]['code']);
    }

    public function test_gate_rejects_cross_authority_base_url_without_requests(): void
    {
        Http::fake();
        $source = $this->writePackage(['/en/personality/big-five']);

        $exitCode = Artisan::call('personality-big-five:public-integrity-gate', [
            '--source' => $source,
            '--base-url' => 'https://example.com',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('command_error', $payload['errors'][0]['code']);
        Http::assertNothingSent();
    }

    /** @param list<string> $links */
    private function writePackage(array $links): string
    {
        $path = storage_path('framework/testing/big-five-authority-v2-integrity-gate-package.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode([
            'contract_version' => 'personality_public_asset.v1',
            'assets' => [[
                'framework' => 'big_five',
                'internal_links' => array_map(
                    static fn (string $href): array => ['label' => 'Target', 'href' => $href],
                    $links
                ),
                'sections' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
