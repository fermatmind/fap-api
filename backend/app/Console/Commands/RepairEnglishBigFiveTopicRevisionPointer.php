<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\Audit\AuditLogger;
use App\Services\ContentPromotion\PublicContent53State;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @review-surface topic_profile */
final class RepairEnglishBigFiveTopicRevisionPointer extends Command
{
    protected $signature = 'topics:repair-english-big-five-revision-pointer
        {--execute : Repair the exact pointer pair}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Repair revision pointers for the already public English Big Five topic.';

    private const PUBLIC_COPY_HASH = '0214f17bb2ffdd3f7d0b10aa747a0500b71eb26c8cab4857c19ca97e63537c54';

    public function handle(AuditLogger $audit): int
    {
        $execute = (bool) $this->option('execute');
        try {
            $result = DB::transaction(fn (): string => $this->repair($audit, $execute));
        } catch (DomainException $exception) {
            return $this->report(false, $exception->getMessage());
        }

        return $this->report(true, $result);
    }

    private function repair(AuditLogger $audit, bool $execute): string
    {
        $topic = TopicProfile::withoutGlobalScopes()->whereKey(1)
            ->where('org_id', 0)->where('topic_code', 'big-five')->where('slug', 'big-five')
            ->where('locale', 'en')->where('status', TopicProfile::STATUS_PUBLISHED)->where('is_public', true)
            ->lockForUpdate()->first();
        if (! $topic) {
            throw new DomainException('Topic identity changed.');
        }

        $revisions = TopicProfileRevision::withoutGlobalScopes()
            ->whereIn('id', [4, 12])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $approved = $revisions->get(4);
        $foreign = $revisions->get(12);
        if (! $approved || ! $foreign || (int) $foreign->profile_id !== 8
            || (int) $approved->profile_id !== (int) $topic->id
            || $approved->workflow_state !== 'approved_exact_package'
            || $approved->source_package !== 'content_assets/public_content_53'
            || $approved->source_hash !== self::PUBLIC_COPY_HASH
            || ! is_array($approved->snapshot_json)) {
            throw new DomainException('Revision identity or approval changed.');
        }

        foreach (['topic_profile_sections', 'topic_profile_entries', 'topic_profile_seo_meta'] as $table) {
            DB::table($table)->where('profile_id', $topic->id)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $states = app(PublicContent53State::class);
        $candidate = [
            'kind' => 'profile',
            'identity' => ['slug' => $topic->slug, 'locale' => $topic->locale],
            'snapshot' => $approved->snapshot_json,
        ];
        if (! hash_equals(self::PUBLIC_COPY_HASH, $states->hash($approved->snapshot_json))
            || ! hash_equals(self::PUBLIC_COPY_HASH, $states->hash($states->projection($candidate)))) {
            throw new DomainException('The public topic no longer matches the approved snapshot.');
        }
        if ((int) $topic->working_revision_id === 4 && (int) $topic->published_revision_id === 4) {
            return 'already_repaired';
        }
        if ((int) $topic->working_revision_id !== 12 || $topic->published_revision_id !== null) {
            throw new DomainException('Topic pointer pair changed.');
        }
        if (! $execute) {
            return 'ready';
        }

        // The approved snapshot is already live. Repair only the mismatched pointers.
        TopicProfile::withoutTimestamps(fn () => $topic->forceFill([
            'working_revision_id' => $approved->id,
            'published_revision_id' => $approved->id,
        ])->saveQuietly());

        if (! hash_equals(self::PUBLIC_COPY_HASH, $states->hash($states->projection($candidate)))) {
            throw new DomainException('Topic projection changed during revision pointer repair.');
        }

        $audit->log(
            Request::create('/ops/topics/revisions/repair-approved-pointer', 'POST'),
            'topic_approved_revision_pointer_repaired',
            'topic_profile',
            (string) $topic->id,
            meta: ['actor' => 'controlled_ops_command', 'previous_working_revision_id' => 12, 'published_revision_id' => 4],
            reason: 'repair_existing_approved_topic_revision_identity',
        );

        return 'repaired';
    }

    private function report(bool $ok, string $result): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'result' => $result], JSON_THROW_ON_ERROR));
        } elseif ($ok) {
            $this->info($result);
        } else {
            $this->error($result);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
