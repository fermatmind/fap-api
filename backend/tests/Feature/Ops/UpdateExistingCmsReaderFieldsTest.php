<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\UpdateExistingCmsReaderFields as Updater;
use App\Filament\Ops\Resources\TopicProfileResource\Support\TopicWorkspace;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileSection;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class UpdateExistingCmsReaderFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_dry_read_is_stable_and_native_apply_preserves_links_seo_and_old_history(): void
    {
        $topic = $this->topic();
        $before = Updater::state($topic);
        $package = $this->topicPackage($topic);
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame($before, Updater::state($topic->fresh()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        $actor = $this->actor();
        $this->assertSame(0, $this->runPackage($package, $actor));
        $after = Updater::state($topic->fresh());
        $this->assertSame('Editorial context, not a prediction.', $topic->fresh()->excerpt);
        $this->assertSame('New explanation', $after['sections'][0]['body_md']);
        $this->assertSame('Discussion prompt', $after['entries'][0]['excerpt_override']);
        $this->assertSame($before['entries'][0]['target_key'], $after['entries'][0]['target_key']);
        $this->assertSame($before['seo'], $after['seo']);
        $this->assertSame($before['revisions'], array_slice($after['revisions'], 1));
        $audit = AuditLog::withoutGlobalScopes()->sole();
        $this->assertNull($audit->actor_admin_id);
        $this->assertSame($actor->id, $audit->meta_json['authorized_operator_id']);
        $this->assertFalse($audit->meta_json['editorial_attestation_created']);
    }

    public function test_category_updates_existing_payload_fields_and_keeps_all_blocks_and_eligibility(): void
    {
        $surface = $this->category();
        $before = Updater::state($surface);
        $package = $this->package($surface, [['pointer' => '/payload_json/featured/items/0/durationLabel', 'current' => 'Old duration', 'proposed' => 'About 15 minutes']]);
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame($before, Updater::state($surface->fresh()));
        $this->assertSame(0, $this->runPackage($package, $this->actor()));
        $after = Updater::state($surface->fresh());
        $this->assertSame('About 15 minutes', data_get($surface->fresh()->payload_json, 'featured.items.0.durationLabel'));
        $this->assertSame($before['blocks'], $after['blocks']);
        $this->assertFalse($surface->fresh()->is_indexable);
        $this->assertSame('untouched', data_get($surface->fresh()->payload_json, 'other.private_marker'));
    }

    public function test_child_drift_identity_edits_and_external_links_are_rejected_without_writes(): void
    {
        $topic = $this->topic();
        $package = $this->topicPackage($topic);
        $section = $topic->sections()->first();
        $section->body_md = 'Concurrent body';
        $section->save();
        $this->assertSame(1, $this->runPackage($package, $this->actor()));
        $package = $this->topicPackage($topic->fresh());
        $package['operations'][0]['field'] = 'is_indexable';
        $this->assertSame(1, $this->runPackage($package));
        $surface = $this->category();
        $package = $this->package($surface, [['pointer' => '/payload_json/featured/items/0/href', 'current' => '/en/tests/mbti', 'proposed' => 'https://external.test/']]);
        $this->assertSame(1, $this->runPackage($package, $this->actor()));
        $this->assertSame('/en/tests/mbti', data_get($surface->fresh()->payload_json, 'featured.items.0.href'));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_audit_failure_rolls_back_native_fields_and_new_topic_revision(): void
    {
        $topic = $this->topic();
        $before = Updater::state($topic);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->assertSame(1, $this->runPackage($this->topicPackage($topic), $this->actor()));
        $this->assertSame($before, Updater::state($topic->fresh()));
    }

    public function test_unauthorized_operator_duplicate_operations_and_missing_fields_fail_closed(): void
    {
        $surface = $this->category();
        $package = $this->package($surface, [['pointer' => '/payload_json/featured/items/0/durationLabel', 'current' => 'Old duration', 'proposed' => 'New duration']]);
        $this->assertSame(1, $this->runPackage($package, $this->actor(false)));
        $package['operations'][] = $package['operations'][0];
        $this->assertSame(1, $this->runPackage($package));
        $package['operations'] = [['pointer' => '/payload_json/featured/items/1/durationLabel', 'current' => null, 'proposed' => 'Invented item']];
        $this->assertSame(1, $this->runPackage($package));
        $this->assertSame('Old duration', data_get($surface->fresh()->payload_json, 'featured.items.0.durationLabel'));
    }

    public function test_noop_html_shadow_and_arbitrary_form_query_never_create_revision_or_audit(): void
    {
        $topic = $this->topic();
        $section = $topic->sections()->first();
        $section->body_html = '<p>Native HTML takes precedence</p>';
        $section->save();
        $this->assertSame(1, $this->runPackage($this->topicPackage($topic->fresh()), $this->actor()));
        $this->assertSame(1, $topic->revisions()->count());
        $surface = $this->category();
        $this->assertSame(1, $this->runPackage($this->package($surface, [['pointer' => '/payload_json/featured/items/0/durationLabel', 'current' => 'Old duration', 'proposed' => 'Old duration']])));
        $this->assertSame(1, $this->runPackage($this->package($surface, [['pointer' => '/payload_json/featured/items/0/href', 'current' => '/en/tests/mbti',
            'proposed' => '/en/tests/big-five-personality-test-ocean-model/take?form=big5_90&redirect=external']])));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_non_global_record_and_missing_production_release_lock_are_rejected(): void
    {
        $surface = $this->category();
        $surface->org_id = 42;
        $surface->save();
        $operations = [['pointer' => '/payload_json/featured/items/0/durationLabel', 'current' => 'Old duration', 'proposed' => 'New duration']];
        $this->assertSame(1, $this->runPackage($this->package($surface->fresh(), $operations), $this->actor()));
        $surface->org_id = 0;
        $surface->save();
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame(1, $this->runPackage($this->package($surface->fresh(), $operations), $this->actor()));
        $this->assertSame('Old duration', data_get($surface->fresh()->payload_json, 'featured.items.0.durationLabel'));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    private function topic(): TopicProfile
    {
        $topic = TopicProfile::query()->create(['org_id' => 0, 'topic_code' => 'mbti', 'slug' => 'mbti', 'locale' => 'zh-CN', 'title' => 'Topic',
            'excerpt' => 'Old context', 'status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        TopicProfileSection::query()->create(['profile_id' => $topic->id, 'section_key' => 'overview', 'render_variant' => 'rich_text', 'body_md' => 'Old explanation', 'sort_order' => 0, 'is_enabled' => true]);
        TopicProfileEntry::query()->create(['profile_id' => $topic->id, 'entry_type' => 'personality_profile', 'group_key' => 'personalities', 'target_key' => 'INTJ', 'excerpt_override' => null, 'sort_order' => 0, 'is_enabled' => true]);
        TopicWorkspace::createRevision($topic, 'Original');

        return $topic->fresh();
    }

    private function category(): LandingSurface
    {
        $surface = LandingSurface::query()->create(['org_id' => 0, 'surface_key' => 'tests_category_personality', 'locale' => 'en', 'title' => 'Tests',
            'payload_json' => ['featured' => ['items' => [['durationLabel' => 'Old duration', 'href' => '/en/tests/mbti']]], 'other' => ['private_marker' => 'untouched']],
            'status' => 'published', 'is_public' => true, 'is_indexable' => false]);
        PageBlock::query()->create(['landing_surface_id' => $surface->id, 'block_key' => 'featured', 'block_type' => 'cards', 'payload_json' => ['held' => true], 'sort_order' => 1, 'is_enabled' => true]);

        return $surface->fresh();
    }

    private function topicPackage(TopicProfile $topic): array
    {
        $state = Updater::state($topic);

        return $this->package($topic, [
            ['entity' => 'profile', 'id' => $topic->id, 'field' => 'excerpt', 'native_before' => $topic->excerpt, 'after' => 'Editorial context, not a prediction.'],
            ['entity' => 'section', 'id' => $state['sections'][0]['id'], 'field' => 'body_md', 'native_before' => $state['sections'][0]['body_md'], 'after' => 'New explanation'],
            ['entity' => 'entry', 'id' => $state['entries'][0]['id'], 'field' => 'excerpt_override', 'native_before' => $state['entries'][0]['excerpt_override'], 'after' => 'Discussion prompt'],
        ]);
    }

    private function package(Model $record, array $operations): array
    {
        $topic = $record instanceof TopicProfile;

        return ['schema' => 'existing_cms_reader_fields.v1', 'kind' => $topic ? 'topic' : 'tests_category',
            'identity' => array_intersect_key($record->getAttributes(), array_flip($topic ? ['id', 'org_id', 'slug', 'locale', 'topic_code'] : ['id', 'org_id', 'surface_key', 'locale'])),
            'before_sha256' => Hash::hash(Updater::state($record)), 'operations' => $operations];
    }

    private function actor(bool $allowed = true): AdminUser
    {
        $actor = AdminUser::query()->create(['name' => 'Operator', 'email' => uniqid().'@example.test', 'password' => 'test-secret', 'is_active' => 1]);
        if ($allowed) {
            $role = Role::query()->create(['name' => uniqid('reader-')]);
            $permission = Permission::query()->firstOrCreate(['name' => PermissionNames::ADMIN_CONTENT_PUBLISH]);
            $role->permissions()->attach($permission);
            $actor->roles()->attach($role);
        }

        return $actor;
    }

    private function runPackage(array $package, ?AdminUser $actor = null): int
    {
        $path = tempnam(sys_get_temp_dir(), 'native-reader-');
        $bytes = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);
        try {
            return Artisan::call('cms:update-existing-reader-fields', ['--file' => $path, '--sha256' => $sha,
                ...($actor ? ['--execute' => true, '--admin-user-id' => $actor->id, '--confirm' => $sha] : [])]);
        } finally {
            unlink($path);
        }
    }
}
