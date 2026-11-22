<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\MainstreamReportPurchase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DiscReport
{
    public function source(): array
    {
        return json_decode(file_get_contents(base_path('content_assets/web_report/disc.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function score(array $answers): array
    {
        $bank = $this->source();
        if (count($answers) !== count($bank['questions'])) {
            throw ValidationException::withMessages(['answers' => 'Complete all 24 choices.']);
        }
        $counts = array_fill_keys(array_column($bank['directions'], 'id'), 0);
        foreach ($bank['questions'] as $question) {
            $choice = $answers[$question['id']] ?? null;
            $option = collect($question['options'])->first(fn ($row) => is_string($choice) && $row['id'] === $choice);
            if (! $option) {
                throw ValidationException::withMessages(['answers' => 'Invalid answer.']);
            }
            $counts[$option['directionId']]++;
        }
        $leaders = array_keys(array_filter($counts, fn ($value) => $value === max($counts)));
        $sorted = $leaders;
        sort($sorted);
        $profile = collect($bank['profiles'])->first(function ($row) use ($sorted) {
            $ids = $row['directionIds'];
            sort($ids);

            return $ids === $sorted;
        });
        abort_unless(is_array($profile), 503, 'Report is unavailable.');

        return ['counts' => $counts, 'leaders' => $leaders, 'profile_id' => $profile['id']];
    }

    public function present(Request $request, Attempt $attempt, array $purchase): array
    {
        $result = app(MainstreamReportPurchase::class)->result($attempt);
        abort_unless($result instanceof Result, 503, 'Report is unavailable.');

        return $this->report($attempt, $result) + ['report_purchase' => $purchase];
    }

    public function report(Attempt $attempt, Result $result): array
    {
        $profile = collect($this->source()['profiles'])->firstWhere('id', $result->type_code);
        abort_unless(is_array($profile), 503, 'Report is unavailable.');

        return ['ok' => true, 'attempt_id' => $attempt->id, 'scale_code' => 'DISC_BEHAVIOR',
            'meta' => ['scale_code' => 'DISC_BEHAVIOR'],
            'report' => ['schema_version' => 'disc.report.v1', 'disc' => ['counts' => $result->scores_pct, 'profile' => $profile]],
            'locked' => false, 'variant' => 'full', 'access_level' => 'full'];
    }
}
