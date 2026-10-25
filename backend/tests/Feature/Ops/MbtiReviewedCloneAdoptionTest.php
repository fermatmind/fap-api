<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages\CreatePersonalityVariantCloneContent;
use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages\EditPersonalityVariantCloneContent;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityResultIntroduction;
use App\Models\Role;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineNormalizer;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineReader;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class MbtiReviewedCloneAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_action_preserves_reviewed_types_and_publishes_independent_intro_atomically(): void
    {
        [$profile, $variant, $intro, $input, $clone] = $this->fixture();
        $originalIntro = $intro->getRawOriginal();
        $profileBefore = $profile->getRawOriginal();
        $variantBefore = $variant->getRawOriginal();
        Livewire::withQueryParams(['profile' => $profile->id])->test(CreatePersonalityVariantCloneContent::class)
            ->callAction('adoptReviewedEnglishClone', ['variant_id' => $variant->id, ...$input])->assertHasNoActionErrors();

        $record = PersonalityProfileVariantCloneContent::sole();
        $this->assertSame($clone['content_json'], $record->content_json);
        $this->assertSame($clone['asset_slots_json'], $record->asset_slots_json);
        $this->assertSame($clone['meta_json'], $record->meta_json);
        Livewire::test(EditPersonalityVariantCloneContent::class, ['record' => $record->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($clone['asset_slots_json'], $record->fresh()->asset_slots_json);
        $this->assertSame(['Independent reviewed first paragraph.', 'Independent reviewed second paragraph.'], $intro->fresh()->paragraphs);
        $this->assertNotSame($clone['content_json']['intro']['paragraphs'], $intro->fresh()->paragraphs);
        $this->assertSame(2, $intro->fresh()->revision);
        $this->assertSame($profileBefore, $profile->fresh()->getRawOriginal());
        $this->assertSame($variantBefore, $variant->fresh()->getRawOriginal());
        $revision = PersonalityProfileVariantRevision::sole();
        $this->assertSame($originalIntro['content_hash'], data_get($revision->snapshot_json, 'mbti_reviewed_clone_adoption_v1.intro_before.content_hash'));
        $this->assertSame($clone['content_json'], data_get($revision->snapshot_json, 'mbti_reviewed_clone_adoption_v1.clone_candidate.content_json'));
        $this->getJson('/api/v0.5/personality/intj-a/result-intro?locale=en')->assertOk()->assertJsonPath('revision', 2)
            ->assertJsonPath('paragraphs', $intro->fresh()->paragraphs);
    }

    public function test_changed_sha_identity_or_owner_is_rejected_without_writes(): void
    {
        [$profile, $variant, $intro, $input, $clone] = $this->fixture();
        $before = $intro->getRawOriginal();
        $badSha = [...$input, 'clone_sha256' => str_repeat('0', 64)];
        $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $badSha));
        foreach (['locale' => 'zh-CN', 'full_code' => 'INTJ-T', 'template_key' => 'other', 'schema_version' => 'v9'] as $key => $value) {
            $changed = [...$clone, $key => $value];
            $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, [...$input, ...$this->fileInput('clone', $changed)]));
        }
        $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id + 100, $variant->id, $input));
        $variant->update(['org_id' => 77]);
        $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input));
        $variant->update(['org_id' => 0]);
        $profile->update(['type_code' => 'ENTP', 'canonical_type_code' => 'ENTP']);
        $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input));
        $this->assertSame($before, $intro->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personality_profile_variant_clone_contents', 0);
        $this->assertDatabaseCount('personality_profile_variant_revisions', 0);
    }

    public function test_stale_intro_and_new_clone_collision_are_not_upserted(): void
    {
        [$profile, $variant, $intro, $input, $clone] = $this->fixture();
        $intro->update(['revision' => 9]);
        $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input));
        $intro->update(['revision' => 1]);
        $collision = PersonalityProfileVariantCloneContent::create(['personality_profile_variant_id' => $variant->id,
            'template_key' => $clone['template_key'], 'status' => 'draft', 'content_json' => $clone['content_json'],
            'asset_slots_json' => $clone['asset_slots_json'], 'meta_json' => ['keep' => 'Independent existing draft']]);
        $before = $collision->fresh()->getRawOriginal();
        $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input));
        $this->assertSame($before, $collision->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personality_profile_variant_revisions', 0);
    }

    public function test_readback_failure_after_both_writes_rolls_back_the_entire_transaction(): void
    {
        [$profile, $variant, $intro, $input] = $this->fixture();
        $before = $intro->getRawOriginal();
        $event = 'eloquent.updated: '.PersonalityResultIntroduction::class;
        Event::listen($event, function (PersonalityResultIntroduction $model): void {
            DB::table($model->getTable())->where('id', $model->id)->update(['paragraphs' => json_encode(['Unexpected persisted text', 'Another unexpected paragraph'])]);
        });
        try {
            $this->assertRejected(fn () => $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input));
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $intro->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personality_profile_variant_clone_contents', 0);
        $this->assertDatabaseCount('personality_profile_variant_revisions', 0);
    }

    public function test_idempotent_adoption_and_guarded_native_restore_keep_immutable_history(): void
    {
        [$profile, $variant, $intro, $input] = $this->fixture();
        $originalParagraphs = $intro->paragraphs;
        $record = $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input);
        $revision = PersonalityProfileVariantRevision::sole();
        $frozen = $revision->getRawOriginal();
        $this->assertSame($record->id, $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input)->id);
        $this->assertDatabaseCount('personality_profile_variant_revisions', 1);
        $hash = $this->adoption()->reviewedCloneRecoveryHash($record);
        $intro->refresh()->update(['revision' => 3]);
        $this->assertRejected(fn () => $this->adoption()->restoreReviewedClone($record, $hash));
        $this->assertSame('published', $record->fresh()->status);
        $intro->update(['revision' => 2]);
        Livewire::test(EditPersonalityVariantCloneContent::class, ['record' => $record->id])
            ->callAction('restoreReviewedEnglishClone', ['expected_hash' => $hash])->assertHasNoActionErrors();
        $this->assertSame('draft', $record->fresh()->status);
        $this->assertNull($record->fresh()->published_at);
        $this->assertSame($originalParagraphs, $intro->fresh()->paragraphs);
        $this->assertSame(3, $intro->fresh()->revision);
        $this->assertSame($frozen, $revision->fresh()->getRawOriginal());
        $this->assertDatabaseCount('personality_profile_variant_revisions', 2);
        $this->assertDatabaseCount('personality_profile_variant_clone_contents', 1);
    }

    public function test_content_reader_cannot_call_the_publisher_or_native_adoption_action(): void
    {
        [$profile, $variant, $intro, $input] = $this->fixture(false);
        Livewire::withQueryParams(['profile' => $profile->id])->test(CreatePersonalityVariantCloneContent::class)->assertForbidden();
        try {
            $this->adoption()->adoptReviewedClone($profile->id, $variant->id, $input);
            $this->fail('Content read alone must not publish.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $intro->fresh()->revision);
        $this->assertDatabaseCount('personality_profile_variant_clone_contents', 0);
    }

    private function adoption(): PersonalityVariantCloneContentResource
    {
        return app(PersonalityVariantCloneContentResource::class);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Changed or conflicting state must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function fileInput(string $kind, array $document): array
    {
        $bytes = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        return [$kind.'_package_base64' => base64_encode($bytes), $kind.'_sha256' => hash('sha256', $bytes)];
    }

    private function fixture(bool $publish = true): array
    {
        $admin = AdminUser::create(['name' => 'Package test', 'email' => 'package@example.test', 'password' => bcrypt('test-only'), 'is_active' => 1]);
        $role = Role::create(['name' => 'package-role']);
        foreach ([PermissionNames::ADMIN_CONTENT_READ, ...($publish ? [PermissionNames::ADMIN_CONTENT_PUBLISH] : [])] as $permission) {
            $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['name' => $permission])->id]);
        }
        $admin->roles()->attach($role);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app(OrgContext::class)->set(77, $admin->id, 'admin');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $profile = PersonalityProfile::create(['org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => 'INTJ', 'slug' => 'intj',
            'locale' => 'en', 'title' => 'Synthetic profile', 'status' => 'published', 'is_public' => true, 'published_at' => now()->subDay()]);
        $variant = PersonalityProfileVariant::create(['org_id' => 0, 'personality_profile_id' => $profile->id,
            'canonical_type_code' => 'INTJ', 'variant_code' => 'A', 'runtime_type_code' => 'INTJ-A', 'is_published' => true, 'published_at' => now()->subDay()]);
        static $source;
        if ($source === null) {
            $reader = app(PersonalityDesktopCloneBaselineReader::class);
            $rows = app(PersonalityDesktopCloneBaselineNormalizer::class)->normalizeDocuments($reader->read($reader->resolveSourceDir(), ['en']), ['INTJ-A']);
            $source = $rows[0];
        }
        $clone = array_intersect_key($source, array_flip(['locale', 'template_key', 'schema_version', 'full_code', 'base_code', 'content_json', 'asset_slots_json', 'meta_json']));
        $clone['base_code'] = 'INTJ';
        $clone['asset_slots_json'][0]['meta'] = ['enabled' => false, 'count' => 2, 'ordered' => ['second', 'first']];
        $clone['meta_json'] = ['reviewed' => true, 'revision' => 7, 'ordered' => [2, 1]];
        $paragraphs = ['Original independent first paragraph.', 'Original independent second paragraph.'];
        $hash = hash('sha256', json_encode($paragraphs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $intro = PersonalityResultIntroduction::create(['full_code' => 'INTJ-A', 'locale' => 'en', 'paragraphs' => $paragraphs,
            'content_hash' => $hash, 'revision' => 1, 'status' => 'published', 'published_at' => now()->subDay()]);
        $introDocument = ['schema' => 'mbti_result_introduction.candidate.v1', 'source' => ['revision' => 1, 'content_hash' => $hash],
            'candidate' => ['full_code' => 'INTJ-A', 'locale' => 'en', 'paragraphs' => ['Independent reviewed first paragraph.', 'Independent reviewed second paragraph.']]];

        return [$profile->fresh(), $variant->fresh(), $intro->fresh(), [...$this->fileInput('clone', $clone), ...$this->fileInput('intro', $introDocument)], $clone];
    }
}
