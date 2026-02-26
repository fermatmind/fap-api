<?php

namespace App\Services\Assessments;

use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Services\Scale\ScaleIdentityWriteProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AssessmentService
{
    public function __construct(
        private AssessmentSummaryService $summaryService,
        private ScaleIdentityWriteProjector $identityProjector,
    ) {}

    public function findForOrg(int $orgId, int $assessmentId): ?Assessment
    {
        return Assessment::query()
            ->where('org_id', $orgId)
            ->where('id', $assessmentId)
            ->first();
    }

    public function createAssessment(
        int $orgId,
        string $scaleCode,
        string $title,
        int $createdBy,
        ?\DateTimeInterface $dueAt
    ): Assessment {
        $now = now();

        $payload = [
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'title' => $title,
            'created_by' => $createdBy,
            'due_at' => $dueAt,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->shouldWriteScaleIdentityColumns() && $this->assessmentsTableHasIdentityColumns()) {
            $identity = $this->resolveScaleIdentityValues($scaleCode);
            $payload['scale_code_v2'] = $identity['scale_code_v2'];
            $payload['scale_uid'] = $identity['scale_uid'];
        }

        return Assessment::create($payload);
    }

    public function invite(Assessment $assessment, array $subjects): array
    {
        $invites = [];
        $rows = [];
        $now = now();

        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }

            $type = strtolower(trim((string) ($subject['subject_type'] ?? '')));
            $value = trim((string) ($subject['subject_value'] ?? ''));

            if ($type === '' || $value === '') {
                continue;
            }
            if (! in_array($type, ['user', 'email'], true)) {
                continue;
            }
            if ($type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($type === 'user' && ! preg_match('/^\d+$/', $value)) {
                continue;
            }

            $token = $this->generateInviteToken();

            $rows[] = [
                'org_id' => (int) $assessment->org_id,
                'assessment_id' => (int) $assessment->id,
                'subject_type' => $type,
                'subject_value' => $value,
                'invite_token' => $token,
                'started_at' => null,
                'completed_at' => null,
                'attempt_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $invites[] = [
                'subject_type' => $type,
                'subject_value' => $value,
                'invite_token' => $token,
            ];
        }

        if ($rows === []) {
            return [];
        }

        DB::transaction(function () use ($rows): void {
            DB::table('assessment_assignments')->insert($rows);
        });

        return $invites;
    }

    public function progress(Assessment $assessment): array
    {
        $baseQuery = AssessmentAssignment::query()
            ->where('org_id', (int) $assessment->org_id)
            ->where('assessment_id', (int) $assessment->id);

        $total = (clone $baseQuery)->count();
        $completed = (clone $baseQuery)->whereNotNull('completed_at')->count();
        $pending = $total - $completed;
        $listLimit = 50;

        $completedList = (clone $baseQuery)
            ->whereNotNull('completed_at')
            ->orderBy('id')
            ->limit($listLimit)
            ->get()
            ->map(fn (AssessmentAssignment $row): array => [
                'subject_type' => (string) $row->subject_type,
                'subject_value' => (string) $row->subject_value,
                'attempt_id' => $row->attempt_id !== null ? (string) $row->attempt_id : null,
                'completed_at' => $row->completed_at?->toISOString(),
            ])
            ->values()
            ->all();

        $pendingList = (clone $baseQuery)
            ->whereNull('completed_at')
            ->orderBy('id')
            ->limit($listLimit)
            ->get()
            ->map(fn (AssessmentAssignment $row): array => [
                'subject_type' => (string) $row->subject_type,
                'subject_value' => (string) $row->subject_value,
                'invite_token' => (string) $row->invite_token,
                'started_at' => $row->started_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'completed_list' => $completedList,
            'pending_list' => $pendingList,
            'completed_list_limit' => $listLimit,
            'pending_list_limit' => $listLimit,
            'completed_list_truncated' => $completed > $listLimit,
            'pending_list_truncated' => $pending > $listLimit,
        ];
    }

    public function summary(Assessment $assessment): array
    {
        return $this->summaryService->buildSummary($assessment);
    }

    public function attachAttemptByInviteToken(int $orgId, string $inviteToken, string $attemptId): ?AssessmentAssignment
    {
        $inviteToken = trim($inviteToken);
        if ($orgId <= 0 || $inviteToken === '') {
            return null;
        }

        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return null;
        }

        return DB::transaction(function () use ($orgId, $inviteToken, $attemptId) {
            $row = AssessmentAssignment::query()
                ->where('org_id', $orgId)
                ->where('invite_token', $inviteToken)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            $existingAttempt = trim((string) ($row->attempt_id ?? ''));
            if ($existingAttempt !== '' && $existingAttempt !== $attemptId) {
                return $row;
            }

            $now = now();
            $row->attempt_id = $attemptId;
            if ($row->started_at === null) {
                $row->started_at = $now;
            }
            $row->completed_at = $now;
            $row->updated_at = $now;
            $row->save();

            return $row;
        });
    }

    private function generateInviteToken(): string
    {
        return Str::uuid()->toString().Str::random(28);
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function assessmentsTableHasIdentityColumns(): bool
    {
        return Schema::hasTable('assessments')
            && Schema::hasColumn('assessments', 'scale_code_v2')
            && Schema::hasColumn('assessments', 'scale_uid');
    }

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    private function resolveScaleIdentityValues(string $scaleCode): array
    {
        return $this->identityProjector->projectFromCodes($scaleCode);
    }
}
