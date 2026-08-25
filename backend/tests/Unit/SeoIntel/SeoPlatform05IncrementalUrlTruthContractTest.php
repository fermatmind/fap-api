<?php

declare(strict_types=1);

namespace Tests\Unit\SeoIntel;

use App\Events\PublicAuthorityChanged;
use App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth;
use App\Listeners\QueueUrlTruthIncrementalSync;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeoPlatform05IncrementalUrlTruthContractTest extends TestCase
{
    public function test_domain_event_is_post_commit_and_listener_dispatches_one_unified_unique_job(): void
    {
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        Queue::fake();
        $event = $this->event('revision-a');

        self::assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        (new QueueUrlTruthIncrementalSync)->handle($event);

        Queue::assertPushed(SyncPublicAuthorityUrlTruth::class, function (SyncPublicAuthorityUrlTruth $job): bool {
            self::assertInstanceOf(ShouldQueue::class, $job);
            self::assertInstanceOf(ShouldBeUnique::class, $job);
            self::assertSame(3, $job->tries);

            return $job->uniqueId() === $this->job('revision-a')->uniqueId();
        });
    }

    public function test_authority_locale_revision_form_the_idempotency_key(): void
    {
        self::assertSame($this->job('revision-a')->uniqueId(), $this->job('revision-a')->uniqueId());
        self::assertNotSame($this->job('revision-a')->uniqueId(), $this->job('revision-b')->uniqueId());

        $otherLocale = new SyncPublicAuthorityUrlTruth('article', '42', 'zh-CN', 'revision-a', 'publish');
        self::assertNotSame($this->job('revision-a')->uniqueId(), $otherLocale->uniqueId());
    }

    public function test_disabled_url_truth_lane_does_not_queue_or_block_cms_publication(): void
    {
        config(['seo_intel.enabled' => false, 'seo_intel.write_enabled' => false]);
        Queue::fake();

        (new QueueUrlTruthIncrementalSync)->handle($this->event('revision-a'));

        Queue::assertNothingPushed();
    }

    public function test_real_cms_publication_and_scheduler_are_wired_to_the_incremental_and_bounded_paths(): void
    {
        $root = dirname(__DIR__, 3);
        $publisher = file_get_contents($root.'/app/Services/Cms/ArticlePublishService.php');
        $provider = file_get_contents($root.'/app/Providers/AppServiceProvider.php');
        $scheduler = file_get_contents($root.'/bootstrap/app.php');
        $deploy = file_get_contents(dirname($root).'/deploy.php');

        self::assertStringContainsString("dispatchUrlTruthChange(\$article, 'publish')", $publisher);
        self::assertStringContainsString("dispatchUrlTruthChange(\$article, 'unpublish')", $publisher);
        self::assertStringContainsString("dispatchUrlTruthChange(\$article, 'authority_revision')", $publisher);
        self::assertStringNotContainsString('Event::listen(PublicAuthorityChanged::class, QueueUrlTruthIncrementalSync::class)', $provider);
        self::assertStringContainsString('seo-intel:url-truth-controlled-reconcile --execute --no-http --max-records=5000 --batch-size=250', $scheduler);
        self::assertStringContainsString('->onOneServer()', $scheduler);
        self::assertStringContainsString("task('seo:url-truth-incremental-cms-canary'", $deploy);
        self::assertStringContainsString("after('seo:url-truth-controlled-reconcile', 'seo:url-truth-incremental-cms-canary')", $deploy);
        $canary = file_get_contents($root.'/app/Console/Commands/SeoPlatformUrlTruthCmsCanaryCommand.php');
        self::assertStringContainsString("option('allow-measurement-hold')", $canary);
        self::assertStringContainsString("'status' => 'measurement_hold'", $canary);
        self::assertStringContainsString("'reason' => 'write_lane_disabled'", $canary);
        self::assertStringContainsString("'seo_intel.incremental_sync_inline' => true", $canary);
        self::assertStringContainsString("'blocked_stage' => \$stage", $canary);
        self::assertStringContainsString("'blocked_reason' => \$this->blockedReason(\$exception)", $canary);
        self::assertStringContainsString("'sql_emitted' => false", $canary);
        self::assertStringContainsString("'bindings_emitted' => false", $canary);
        self::assertStringContainsString("'url_truth_binding'", $canary);
        self::assertStringContainsString("'cms_article'", $canary);
        self::assertStringContainsString('$job->handle(app(IncrementalUrlTruthSyncService::class))', file_get_contents($root.'/app/Listeners/QueueUrlTruthIncrementalSync.php'));
    }

    private function event(string $revision): PublicAuthorityChanged
    {
        return new PublicAuthorityChanged('article', '42', 'en', $revision, 'publish');
    }

    private function job(string $revision): SyncPublicAuthorityUrlTruth
    {
        return new SyncPublicAuthorityUrlTruth('article', '42', 'en', $revision, 'publish');
    }
}
