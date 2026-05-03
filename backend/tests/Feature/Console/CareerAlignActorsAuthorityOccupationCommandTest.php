<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerAlignActorsAuthorityOccupationCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_dry_run_writes_zero_rows(): void
    {
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runAlign($file);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['would_write']);
        $this->assertFalse($report['did_write']);
        $this->assertSame('pass', $report['decision']);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function explicit_dry_run_writes_zero_rows(): void
    {
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runAlign($file, ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('dry_run', $report['mode']);
        $this->assertFalse($report['did_write']);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationCrosswalk::query()->count());
    }

    #[Test]
    public function force_creates_one_actors_occupation(): void
    {
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('force', $report['mode']);
        $this->assertTrue($report['did_write']);
        $this->assertNotEmpty($report['occupation_id']);
        $this->assertSame(1, Occupation::query()->count());
        $this->assertDatabaseHas('occupations', [
            'canonical_slug' => 'actors',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Actors',
            'canonical_title_zh' => '演员',
        ]);
    }

    #[Test]
    public function force_creates_exactly_two_required_crosswalk_rows(): void
    {
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertNotEmpty($report['soc_crosswalk_id']);
        $this->assertNotEmpty($report['onet_crosswalk_id']);
        $occupation = Occupation::query()->where('canonical_slug', 'actors')->firstOrFail();
        $this->assertDatabaseHas('occupation_crosswalks', [
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => '27-2011',
            'source_title' => 'Actors',
            'mapping_type' => 'direct_match',
        ]);
        $this->assertDatabaseHas('occupation_crosswalks', [
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => '27-2011.00',
            'source_title' => 'Actors',
            'mapping_type' => 'direct_match',
        ]);
    }

    #[Test]
    public function repeated_force_upserts_without_duplicates(): void
    {
        $first = $this->writeAsset();
        $second = $this->writeAsset(function (array &$payload): void {
            $payload['seo']['zh']['h1'] = '演员';
        });

        $this->runAlign($first, ['--force' => true]);
        [$exitCode, $report] = $this->runAlign($second, ['--force' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, Occupation::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertSame('pass', $report['decision']);
    }

    #[Test]
    public function bad_slug_is_rejected(): void
    {
        $file = $this->writeAsset(function (array &$payload): void {
            $payload['asset']['slug'] = 'directors';
        });

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('asset.slug must be actors.', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function bad_soc_is_rejected(): void
    {
        $file = $this->writeAsset(function (array &$payload): void {
            $payload['asset']['soc_code'] = '27-2012';
        });

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('asset.soc_code must be 27-2011.', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function bad_onet_is_rejected(): void
    {
        $file = $this->writeAsset(function (array &$payload): void {
            $payload['asset']['onet_code'] = '27-2012.00';
        });

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('asset.onet_code must be 27-2011.00.', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function missing_zh_title_is_rejected(): void
    {
        $file = $this->writeAsset(function (array &$payload): void {
            unset($payload['seo']['zh']['h1'], $payload['page']['zh']['hero']['h1']);
        });

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('seo.zh.h1 or page.zh.hero.h1 must exist.', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function missing_en_title_is_rejected(): void
    {
        $file = $this->writeAsset(function (array &$payload): void {
            unset($payload['seo']['en']['h1'], $payload['page']['en']['hero']['h1']);
        });

        [$exitCode, $report] = $this->runAlign($file, ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('seo.en.h1 or page.en.hero.h1 must exist.', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function dry_run_and_force_together_are_rejected(): void
    {
        $file = $this->writeAsset();

        [$exitCode, $report] = $this->runAlign($file, [
            '--dry-run' => true,
            '--force' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('invalid', $report['mode']);
        $this->assertStringContainsString('--dry-run and --force cannot be used together.', implode(' ', $report['errors']));
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function after_force_the_display_asset_import_dry_run_passes(): void
    {
        $file = $this->writeAsset();

        $this->runAlign($file, ['--force' => true]);
        [$exitCode, $report] = $this->runDisplayImportDryRun($file);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['occupation_found']);
        $this->assertTrue($report['soc_crosswalk_valid']);
        $this->assertTrue($report['onet_crosswalk_valid']);
        $this->assertFalse($report['did_write']);
    }

    #[Test]
    public function command_does_not_create_display_asset_rows(): void
    {
        $file = $this->writeAsset();

        $this->runAlign($file, ['--force' => true]);

        $this->assertSame(0, CareerJobDisplayAsset::query()->count());
    }

    #[Test]
    public function non_actors_are_not_affected(): void
    {
        $file = $this->writeAsset();

        $this->runAlign($file, ['--force' => true]);

        $this->assertDatabaseMissing('occupations', ['canonical_slug' => 'directors']);
        $this->assertDatabaseMissing('occupation_crosswalks', ['source_code' => '27-2012']);
        $this->assertSame(['actors'], Occupation::query()->orderBy('canonical_slug')->pluck('canonical_slug')->all());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0:int,1:array<string,mixed>}
     */
    private function runAlign(string $file, array $options = []): array
    {
        $exitCode = Artisan::call('career:align-actors-authority-occupation', array_merge([
            '--file' => $file,
            '--json' => true,
        ], $options));
        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, Artisan::output());

        return [$exitCode, $report];
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function runDisplayImportDryRun(string $file): array
    {
        $exitCode = Artisan::call('career:import-actors-display-asset', [
            '--file' => $file,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, Artisan::output());

        return [$exitCode, $report];
    }

    private function writeAsset(?callable $mutator = null): string
    {
        $payload = $this->assetPayload();
        if ($mutator !== null) {
            $mutator($payload);
        }

        $path = $this->tempDir().'/actors_v4_2_pilot_master.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(): array
    {
        return [
            'asset' => [
                'template_version' => 'v4.2',
                'asset_role' => 'formal_pilot_master',
                'asset_type' => 'career_job_public_display',
                'slug' => 'actors',
                'soc_code' => '27-2011',
                'onet_code' => '27-2011.00',
            ],
            'seo' => [
                'zh' => ['h1' => '演员'],
                'en' => ['h1' => 'Actors'],
            ],
            'component_order' => ['hero', 'market_signal_card'],
            'page' => [
                'zh' => [
                    'hero' => ['h1' => '演员'],
                    'sections' => [],
                ],
                'en' => [
                    'hero' => ['h1' => 'Actors'],
                    'sections' => [],
                ],
            ],
            'sources' => [
                ['label' => 'BLS', 'url' => 'https://www.bls.gov/ooh/entertainment-and-sports/actors.htm'],
            ],
            'structured_data_from_visible_content' => [
                'faq' => [
                    ['question' => 'Is acting stable?', 'answer' => 'It is project-based.'],
                ],
            ],
            'implementation_contract' => [
                'template_version' => 'v4.2',
            ],
        ];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/career-align-actors-authority-'.bin2hex(random_bytes(6));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
