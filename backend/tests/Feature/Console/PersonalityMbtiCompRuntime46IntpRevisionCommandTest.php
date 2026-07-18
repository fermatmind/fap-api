<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiCompRuntime46IntpRevisionCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE = 'docs/seo/import-packages/mbti-comp-runtime-46-intp-revision-2026-07-19.json';

    private const SOURCE_SHA = '5fcf54132504ef85978a5424e428fb56763ffbaa7c60f50b94b9c91fc3e85dc8';

    private const AUTHORIZATION_SHA = '7a84cda503b6f328f0659ee5bd41c85f51c1eca44ac9aa7cfa721d59ab6197e2';

    public function test_command_is_registered_and_exact_dry_run_is_read_only(): void
    {
        self::assertArrayHasKey('personality:mbti-comp-runtime46-intp-revision', Artisan::all());
        $profile = $this->seedIntpTarget();

        $exitCode = Artisan::call('personality:mbti-comp-runtime46-intp-revision', $this->commandOptions(true));
        $summary = $this->summary();

        self::assertSame(0, $exitCode);
        self::assertTrue($summary['ok']);
        self::assertTrue($summary['dry_run']);
        self::assertFalse($summary['cms_write_attempted']);
        self::assertFalse($summary['writes_committed']);
        self::assertSame(1, $summary['row_count']);
        self::assertSame(1, $summary['at_comparison_row_count']);
        self::assertSame('intp-a-vs-intp-t', $summary['rows'][0]['slug']);
        self::assertSame('would_stage_draft_revision', $summary['rows'][0]['action']);
        self::assertSame(0, PersonalityProfileRevision::query()->where('profile_id', $profile->id)->count());
    }

    public function test_exact_authorized_write_stages_one_idempotent_draft_without_publication_or_indexability_changes(): void
    {
        $profile = $this->seedIntpTarget();
        $statusBefore = $profile->status;
        $isPublicBefore = $profile->is_public;
        $isIndexableBefore = $profile->is_indexable;

        self::assertSame(0, Artisan::call('personality:mbti-comp-runtime46-intp-revision', $this->commandOptions(false)));
        $first = $this->summary();
        self::assertTrue($first['writes_committed']);
        self::assertSame(1, $first['staged_draft_count']);
        self::assertSame(0, $first['published_content_count']);
        self::assertFalse($first['index_attempted']);
        self::assertFalse($first['sitemap_llms_release_attempted']);
        self::assertFalse($first['search_release_attempted']);

        $revision = PersonalityProfileRevision::query()->where('profile_id', $profile->id)->sole();
        self::assertSame('draft_only', data_get($revision->snapshot_json, 'mbti_comp_runtime_46_intp_revision_draft_v1.visibility'));
        self::assertFalse((bool) data_get($revision->snapshot_json, 'mbti_comp_runtime_46_intp_revision_draft_v1.public_projection_promoted'));
        $profile->refresh();
        self::assertSame($statusBefore, $profile->status);
        self::assertSame($isPublicBefore, $profile->is_public);
        self::assertSame($isIndexableBefore, $profile->is_indexable);

        self::assertSame(0, Artisan::call('personality:mbti-comp-runtime46-intp-revision', $this->commandOptions(false)));
        $second = $this->summary();
        self::assertSame(0, $second['staged_draft_count']);
        self::assertSame(1, $second['skipped_existing_count']);
        self::assertSame(1, PersonalityProfileRevision::query()->where('profile_id', $profile->id)->count());
    }

    public function test_hash_scope_and_slug_tampering_fail_closed_without_writes(): void
    {
        $this->seedIntpTarget();
        $package = $this->package();
        $cases = [
            'payload' => function (array $value): array {
                $value['repair_records'][0]['import_payload']['seo']['seo_title'] = 'tampered';

                return $value;
            },
            'authorization' => function (array $value): array {
                $value['authorization_payload']['record_count'] = 2;

                return $value;
            },
            'slug' => function (array $value): array {
                $value['repair_records'][0]['slug'] = 'intj-a-vs-intj-t';

                return $value;
            },
            'promotion' => function (array $value): array {
                $value['exact_package']['public_promotion_authorized'] = true;

                return $value;
            },
        ];

        foreach ($cases as $name => $mutate) {
            $path = $this->writePackage($mutate($package), $name);
            $options = $this->commandOptions(false);
            $options['--package'] = $path;
            self::assertSame(1, Artisan::call('personality:mbti-comp-runtime46-intp-revision', $options), $name);
            self::assertFalse($this->summary()['ok'], $name);
            self::assertSame(0, PersonalityProfileRevision::query()->count(), $name);
        }
    }

    public function test_write_requires_all_explicit_safety_guards_before_reading_package(): void
    {
        $exitCode = Artisan::call('personality:mbti-comp-runtime46-intp-revision', [
            '--package' => '/tmp/not-read.json',
            '--write' => true,
            '--json' => true,
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--production-write-authorized is required', (string) data_get($this->summary(), 'errors.0.message'));
    }

    private function seedIntpTarget(): PersonalityProfile
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTP',
            'canonical_type_code' => 'INTP',
            'slug' => 'intp',
            'locale' => 'zh-CN',
            'title' => 'INTP',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        foreach (['A', 'T'] as $variant) {
            PersonalityProfileVariant::query()->create([
                'org_id' => 0,
                'personality_profile_id' => $profile->id,
                'canonical_type_code' => 'INTP',
                'variant_code' => $variant,
                'runtime_type_code' => 'INTP-'.$variant,
                'type_name' => 'INTP '.$variant,
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                'is_published' => true,
                'published_at' => now()->subMinute(),
            ]);
        }

        return $profile;
    }

    /** @return array<string,mixed> */
    private function commandOptions(bool $dryRun): array
    {
        $options = [
            '--package' => self::PACKAGE,
            '--source-package-sha256' => self::SOURCE_SHA,
            '--authorization-payload-sha256' => self::AUTHORIZATION_SHA,
            '--import-scope-mode' => 'single_intp_at_content_revision_only',
            '--record-count' => '1',
            $dryRun ? '--dry-run' : '--write' => true,
            '--json' => true,
        ];
        if (! $dryRun) {
            $options += [
                '--production-write-authorized' => true,
                '--no-publication-change' => true,
                '--no-indexability-change' => true,
                '--no-sitemap' => true,
                '--no-llms' => true,
                '--no-search-release' => true,
            ];
        }

        return $options;
    }

    /** @return array<string,mixed> */
    private function package(): array
    {
        $value = json_decode((string) File::get(base_path(self::PACKAGE)), true);
        self::assertIsArray($value);

        return $value;
    }

    /** @param array<string,mixed> $package */
    private function writePackage(array $package, string $suffix): string
    {
        $path = storage_path('framework/testing/mbti-runtime46-intp-'.$suffix.'-'.uniqid('', true).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        $value = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($value);

        return $value;
    }
}
