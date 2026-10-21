<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\PersonalityProfileResource\Pages\EditPersonalityProfile;
use App\Filament\Ops\Support\MbtiEnglishResultGuidanceWorkspace;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\Role;
use App\Services\Cms\MbtiEnglishVariantSectionEnrichmentService as Copy;
use App\Services\Mbti\Adapters\MbtiPersonalityProfileAuthoritySourceAdapter;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class MbtiEnglishResultGuidanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_action_changes_only_legacy_english_prompts_and_keeps_chinese_and_drafts(): void
    {
        $this->login();
        $profile = $this->profile();
        $chinese = $this->profile('zh-CN');
        $before = $this->allSections();
        $profileBefore = $profile->fresh()->getRawOriginal();
        $variantBefore = $profile->variants()->get()->map->getRawOriginal()->all();
        $draft = PersonalityProfileVariantRevision::create([
            'personality_profile_variant_id' => $profile->variants()->first()->id,
            'revision_no' => 1, 'snapshot_json' => ['draft' => 'Keep this separate working draft'], 'note' => 'Existing draft', 'created_at' => now(),
        ]);
        $draftBefore = $draft->fresh()->getRawOriginal();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $data = $workspace->prepare($profile);
        $this->assertStringContainsString('search intent', $data['preview']);
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditPersonalityProfile::class, ['record' => $profile->id])
            ->callAction('updateEnglishResultGuidance', $data)->assertHasNoActionErrors();

        $after = $this->allSections();
        $changed = 0;
        foreach ($before as $id => $row) {
            if ($after[$id] === $row) {
                continue;
            }
            $changed++;
            $this->assertSame(Arr::except($row, ['body_md', 'payload_json', 'updated_at']), Arr::except($after[$id], ['body_md', 'payload_json', 'updated_at']));
            if ($row['section_key'] === 'overview') {
                $this->assertStringStartsWith('Keep this type-specific opening.\n\n', str_replace("\n", '\n', $after[$id]['body_md']));
                $this->assertStringContainsString(Copy::OVERVIEW_READER_GUIDANCE, $after[$id]['body_md']);
            } else {
                $this->assertSame($row['body_md'], $after[$id]['body_md']);
                $payload = json_decode($after[$id]['payload_json'], true);
                $original = json_decode($row['payload_json'], true);
                foreach ($payload['dimensions'] as $i => $dimension) {
                    $this->assertSame(Arr::except($original['dimensions'][$i], 'description'), Arr::except($dimension, 'description'));
                    $this->assertSame($i === 4 ? 'Preserve the A/T explanation.' : Copy::PREFERENCE_READER_GUIDANCE, $dimension['description']);
                }
                $this->assertSame($original['unknown_future_key'], $payload['unknown_future_key']);
            }
        }
        $this->assertSame(4, $changed);
        $this->assertSame($profileBefore, $profile->fresh()->getRawOriginal());
        $this->assertSame($variantBefore, $profile->variants()->get()->map->getRawOriginal()->all());
        $this->assertSame($draftBefore, $draft->fresh()->getRawOriginal());
        $this->assertSame(3, PersonalityProfileVariantRevision::count());
        $this->assertSame(0, $workspace->commit($profile, $workspace->prepare($profile)['target_hash'])['updated_sections']);
        $this->assertSame(3, PersonalityProfileVariantRevision::count());
        $this->assertSame('zh-CN', $chinese->fresh()->locale);
        $variant = $profile->variants()->with('sections')->first();
        $adapter = app(MbtiPersonalityProfileAuthoritySourceAdapter::class);
        $authority = $adapter->overlayVariant($adapter->fromBaseProfile($profile->fresh()), $variant);
        $this->assertStringContainsString(Copy::OVERVIEW_READER_GUIDANCE, $authority['sections']['overview']['body_md']);
    }

    public function test_stale_preview_rejects_whole_update_without_creating_history(): void
    {
        $this->login();
        $profile = $this->profile();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $data = $workspace->prepare($profile);
        $row = PersonalityProfileVariantSection::where('section_key', 'overview')->first();
        $row->forceFill(['body_md' => 'Concurrent editorial content'])->save();
        $before = $this->allSections();
        try {
            $workspace->commit($profile, $data['target_hash']);
            $this->fail('A stale preview must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
        }
        $this->assertSame($before, $this->allSections());
        $this->assertSame(0, PersonalityProfileVariantRevision::count());
    }

    public function test_mid_update_failure_rolls_back_all_sections_and_history(): void
    {
        $this->login();
        $profile = $this->profile();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $data = $workspace->prepare($profile);
        $before = $this->allSections();
        $saves = 0;
        $fired = false;
        $dispatcher = PersonalityProfileVariantSection::getEventDispatcher();
        PersonalityProfileVariantSection::setEventDispatcher(clone $dispatcher);
        PersonalityProfileVariantSection::saving(function () use (&$saves, &$fired): void {
            if (++$saves === 3) {
                $fired = true;
                throw new RuntimeException('Injected third-row failure');
            }
        });
        try {
            $workspace->commit($profile, $data['target_hash']);
            $this->fail('The injected update failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected third-row failure', $exception->getMessage());
        } finally {
            PersonalityProfileVariantSection::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($fired);
        $this->assertSame(3, $saves);
        $this->assertSame($before, $this->allSections());
        $this->assertSame(0, PersonalityProfileVariantRevision::count());
    }

    public function test_restore_and_another_owner_publication_keep_immutable_history(): void
    {
        $this->login();
        $profile = $this->profile();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $before = $this->allSections();
        $workspace->commit($profile, $workspace->prepare($profile)['target_hash']);
        $history = PersonalityProfileVariantRevision::get()->map->getRawOriginal()->all();
        $workspace->commit($profile, $workspace->prepare($profile, true)['target_hash'], true);
        foreach ($before as $id => $row) {
            $this->assertSame(Arr::except($row, 'updated_at'), Arr::except($this->allSections()[$id], 'updated_at'));
        }
        $this->login();
        $this->assertSame(4, $workspace->commit($profile, $workspace->prepare($profile)['target_hash'])['updated_sections']);
        $this->assertSame($history, PersonalityProfileVariantRevision::orderBy('id')->limit(2)->get()->map->getRawOriginal()->all());
        $this->assertSame(6, PersonalityProfileVariantRevision::count());
    }

    public function test_one_sided_update_restores_and_republishes_without_touching_custom_copy(): void
    {
        $this->login();
        $profile = $this->profile();
        $custom = $profile->variants()->where('variant_code', 'T')->first();
        $custom->sections()->where('section_key', 'overview')->update(['body_md' => 'Independently reviewed opening.']);
        $custom->sections()->where('section_key', 'trait_overview')->update(['payload_json' => '{"dimensions":[],"custom":{}}']);
        $before = $this->allSections();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $this->assertSame(2, $workspace->commit($profile, $workspace->prepare($profile)['target_hash'])['updated_sections']);
        $history = PersonalityProfileVariantRevision::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->assertCount(2, $history);
        $this->assertSame(2, $workspace->commit($profile, $workspace->prepare($profile, true)['target_hash'], true)['updated_sections']);
        foreach ($before as $id => $row) {
            $this->assertSame(Arr::except($row, 'updated_at'), Arr::except($this->allSections()[$id], 'updated_at'));
        }
        $this->login();
        $this->assertSame(2, $workspace->commit($profile, $workspace->prepare($profile)['target_hash'])['updated_sections']);
        $this->assertSame($history, PersonalityProfileVariantRevision::orderBy('id')->limit(2)->get()->map->getRawOriginal()->all());
        foreach ($custom->sections()->get() as $row) {
            $this->assertSame($before[$row->id], $row->getRawOriginal());
        }
    }

    public function test_json_types_and_list_order_survive_update_and_restore(): void
    {
        $this->login();
        $profile = $this->profile();
        foreach ($profile->variants()->get() as $variant) {
            $row = $variant->sections()->where('section_key', 'trait_overview')->first();
            $payload = json_decode($row->getRawOriginal('payload_json'));
            $payload->unknown_future_key = (object) ['empty' => (object) [], 'numeric' => (object) ['0' => 'keep'],
                'integer' => 1, 'fraction' => 1.0, 'boolean' => true, 'null' => null, 'list' => [3, 1, 2]];
            $raw = $row->getAttributes();
            $raw['payload_json'] = substr(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 0, -1).',"large_integer":9223372036854775809,"precise_decimal":0.123456789012345678901}';
            $row->setRawAttributes($raw)->save();
        }
        $before = $this->allSections();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $workspace->commit($profile, $workspace->prepare($profile)['target_hash']);
        foreach ($this->allSections() as $id => $row) {
            $expected = $row['section_key'] === 'trait_overview'
                ? str_replace('This preference shapes search intent around traits, relationships, career fit, and daily decisions.', Copy::PREFERENCE_READER_GUIDANCE, $before[$id]['payload_json'])
                : $before[$id]['payload_json'];
            $this->assertSame($expected, $row['payload_json']);
            $this->assertSame(json_encode(json_decode($before[$id]['payload_json'])->unknown_future_key, JSON_PRESERVE_ZERO_FRACTION),
                json_encode(json_decode($row['payload_json'])->unknown_future_key, JSON_PRESERVE_ZERO_FRACTION));
        }
        $workspace->commit($profile, $workspace->prepare($profile, true)['target_hash'], true);
        foreach ($before as $id => $row) {
            $this->assertEquals(json_decode($row['payload_json']), json_decode($this->allSections()[$id]['payload_json']));
            $this->assertSame(json_encode(json_decode($row['payload_json'])->unknown_future_key, JSON_PRESERVE_ZERO_FRACTION),
                json_encode(json_decode($this->allSections()[$id]['payload_json'])->unknown_future_key, JSON_PRESERVE_ZERO_FRACTION));
        }
    }

    public function test_ambiguous_description_tokens_reject_the_entire_update(): void
    {
        $this->login();
        $profile = $this->profile();
        $row = $profile->variants()->first()->sections()->where('section_key', 'trait_overview')->first();
        $payload = $row->payload_json;
        $payload['unknown_future_key']['description'] = 'This preference shapes search intent around traits, relationships, career fit, and daily decisions.';
        $row->fill(['payload_json' => $payload])->save();
        $before = $this->allSections();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $this->expectException(ValidationException::class);
        try {
            $workspace->commit($profile, $workspace->prepare($profile)['target_hash']);
        } finally {
            $this->assertSame($before, $this->allSections());
            $this->assertSame(0, PersonalityProfileVariantRevision::count());
        }
    }

    public function test_escaped_target_and_matching_unknown_description_cannot_swap_paths(): void
    {
        $this->login();
        $profile = $this->profile();
        $row = $profile->variants()->first()->sections()->where('section_key', 'trait_overview')->first();
        $legacy = 'This preference shapes search intent around traits, relationships, career fit, and daily decisions.';
        $raw = $row->getAttributes();
        $raw['payload_json'] = '{"dimensions":[{"id":"EI","description":"'.'\\u0054'.substr($legacy, 1).'"}],"unknown_future_key":{"description":"'.$legacy.'"}}';
        $row->setRawAttributes($raw)->save();
        $before = $this->allSections();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $this->expectException(ValidationException::class);
        try {
            $workspace->commit($profile, $workspace->prepare($profile)['target_hash']);
        } finally {
            $this->assertSame($before, $this->allSections());
            $this->assertSame(0, PersonalityProfileVariantRevision::count());
        }
    }

    public function test_restore_rejects_subsequent_editorial_changes(): void
    {
        $this->login();
        $profile = $this->profile();
        $workspace = app(MbtiEnglishResultGuidanceWorkspace::class);
        $workspace->commit($profile, $workspace->prepare($profile)['target_hash']);
        PersonalityProfileVariantSection::where('section_key', 'overview')->first()->forceFill(['body_md' => 'Newly reviewed content'])->save();
        $before = $this->allSections();
        $this->expectException(ValidationException::class);
        try {
            $workspace->prepare($profile, true);
        } finally {
            $this->assertSame($before, $this->allSections());
            $this->assertSame(2, PersonalityProfileVariantRevision::count());
        }
    }

    #[DataProvider('rejectedScopes')]
    public function test_unsupported_scope_permissions_or_mfa_cannot_write(string $case): void
    {
        $this->login();
        $profile = $this->profile($case === 'chinese' ? 'zh-CN' : 'en');
        if ($case === 'read_only') {
            $this->login(false);
        } elseif ($case === 'mfa') {
            config(['admin.totp.enabled' => true]);
            session()->forget('ops_admin_totp_verified_user_id');
        } elseif ($case === 'missing_variant') {
            $profile->variants()->first()->delete();
        }
        $before = $this->allSections();
        try {
            app(MbtiEnglishResultGuidanceWorkspace::class)->commit($profile, str_repeat('a', 64));
            $this->fail('Unsupported scope must fail before writing.');
        } catch (ValidationException|HttpException $exception) {
            if ($exception instanceof HttpException) {
                $this->assertSame(403, $exception->getStatusCode());
            } else {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertSame($before, $this->allSections());
        $this->assertSame(0, PersonalityProfileVariantRevision::count());
    }

    public static function rejectedScopes(): array
    {
        return array_map(fn ($case): array => [$case], ['chinese', 'read_only', 'mfa', 'missing_variant']);
    }

    public function test_generator_keeps_all_32_type_and_at_identities_without_search_operations_copy(): void
    {
        $copy = app(Copy::class);
        $this->assertCount(32, $copy->supportedRuntimeTypeCodes());
        foreach ($copy->supportedRuntimeTypeCodes() as $type) {
            $overview = $copy->build($type, null, 'overview');
            $traits = $copy->build($type, null, 'trait_overview');
            $this->assertStringContainsString($type, $overview['body_md']);
            $this->assertStringContainsString(Copy::OVERVIEW_READER_GUIDANCE, $overview['body_md']);
            $this->assertStringNotContainsString('search intent', json_encode([$overview, $traits]));
            $this->assertSame('AT', $traits['payload_json']['dimensions'][4]['id']);
            $this->assertSame(substr($type, -1), $traits['payload_json']['dimensions'][4]['side']);
        }
    }

    private function login(bool $publisher = true): AdminUser
    {
        $admin = AdminUser::create(['name' => 'Reader-copy editor', 'email' => uniqid('qa', true).'@example.test', 'password' => bcrypt('test-only'), 'is_active' => 1]);
        $role = Role::create(['name' => uniqid('reader-copy-', true)]);
        foreach ([PermissionNames::ADMIN_CONTENT_READ, ...($publisher ? [PermissionNames::ADMIN_CONTENT_PUBLISH] : [])] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['name' => $name])->id]);
        }
        $admin->roles()->attach($role);
        $this->actingAs($admin->fresh(), (string) config('admin.guard', 'admin'));
        $this->withSession(['ops_admin_totp_verified_user_id' => (int) $admin->id]);

        return $admin;
    }

    private function profile(string $locale = 'en'): PersonalityProfile
    {
        $profile = PersonalityProfile::create(['org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => 'INTJ', 'canonical_type_code' => 'INTJ',
            'slug' => 'intj', 'locale' => $locale, 'title' => 'INTJ', 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2]);
        foreach (['A', 'T'] as $code) {
            $variant = PersonalityProfileVariant::create(['org_id' => 0, 'personality_profile_id' => $profile->id, 'canonical_type_code' => 'INTJ',
                'variant_code' => $code, 'runtime_type_code' => 'INTJ-'.$code, 'is_published' => true]);
            foreach (['overview', 'trait_overview', 'career.summary'] as $key) {
                $old = "This page is meant to answer practical search intent: what this type is, how the {$code} side changes the pattern, what relationships tend to need, which work environments fit, and when taking the test can clarify your next move.";
                $payload = ['unknown_future_key' => ['keep' => [3, 1, 2]], 'dimensions' => array_map(fn ($id): array => [
                    'id' => $id, 'side' => 'keep', 'summary' => 'Keep this preference summary.',
                    'description' => $id === 'AT' ? 'Preserve the A/T explanation.' : 'This preference shapes search intent around traits, relationships, career fit, and daily decisions.',
                ], ['EI', 'SN', 'TF', 'JP', 'AT'])];
                PersonalityProfileVariantSection::create(['org_id' => 0, 'personality_profile_variant_id' => $variant->id, 'section_key' => $key,
                    'render_variant' => 'rich_text', 'body_md' => $key === 'overview' ? "Keep this type-specific opening.\n\n".$old : 'Keep this section.',
                    'body_html' => '<p>Preserve existing HTML.</p>', 'payload_json' => $payload, 'sort_order' => 20, 'is_enabled' => true]);
            }
        }

        return $profile;
    }

    private function allSections(): array
    {
        return PersonalityProfileVariantSection::withoutGlobalScopes()->orderBy('id')->get()->mapWithKeys(fn ($row): array => [$row->id => $row->getRawOriginal()])->all();
    }
}
