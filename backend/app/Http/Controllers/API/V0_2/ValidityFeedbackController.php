<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesOrgId;
use App\Models\Attempt;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ValidityFeedbackController extends Controller
{
    use ResolvesOrgId;

    /**
     * POST /api/v0.2/attempts/{attempt_id}/feedback
     */
    public function store(Request $request, string $attemptId): JsonResponse
    {
        $enabled = filter_var(\App\Support\RuntimeConfig::value('FEEDBACK_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_ENABLED',
                'message' => 'feedback is disabled.',
            ], 200);
        }

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'reason_tags' => ['sometimes', 'array', 'max:10'],
            'reason_tags.*' => ['string', 'max:32'],
            'free_text' => ['sometimes', 'string', 'max:200'],
        ]);

        $orgId = $this->resolveOrgId($request);

        /** @var Attempt|null $attempt */
        $attempt = Attempt::where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $fmUserId = $this->normalizeId($request->attributes->get('fm_user_id'));
        $anonId = $this->normalizeId($request->attributes->get('anon_id'));

        if ($fmUserId !== null && \App\Support\SchemaBaseline::hasColumn('attempts', 'user_id')) {
            if ((string) ($attempt->user_id ?? '') !== $fmUserId) {
                return $this->forbiddenResponse();
            }
        } else {
            if ($anonId === null) {
                return $this->forbiddenResponse();
            }
            if (!$this->checkAnonOwnership($attemptId, $anonId)) {
                return $this->forbiddenResponse();
            }
        }

        $createdAt = now();
        $createdYmd = $createdAt->format('Y-m-d');

        $existing = DB::table('validity_feedbacks')
            ->where('attempt_id', $attemptId)
            ->where('created_ymd', $createdYmd)
            ->first();

        if ($existing) {
            return response()->json([
                'ok' => true,
                'existing' => true,
                'feedback_id' => $existing->id,
                'created_at' => (string) ($existing->created_at ?? ''),
            ], 200);
        }

        $packId = $this->resolvePackId($attempt);
        $packVersion = $this->parsePackVersion($packId);
        $reportVersion = (string) \App\Support\RuntimeConfig::value('REPORT_VERSION', (string) config('app.version', ''));
        $typeCode = '';
        if (\App\Support\SchemaBaseline::hasColumn('attempts', 'type_code')) {
            $typeCode = (string) ($attempt->type_code ?? '');
        }

        $freeText = $this->sanitizeFreeText($data['free_text'] ?? null);
        $reasonTags = $this->normalizeReasonTags($data['reason_tags'] ?? []);
        $reasonTagsJson = json_encode($reasonTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($reasonTagsJson === false) {
            $reasonTagsJson = '[]';
        }

        $ip = (string) ($request->ip() ?? '');
        $salt = (string) \App\Support\RuntimeConfig::value('IP_HASH_SALT', 'local_salt');
        $ipHash = hash('sha256', $ip . $salt);

        $row = [
            'attempt_id' => $attemptId,
            'fm_user_id' => $fmUserId,
            'anon_id' => $anonId,
            'ip_hash' => $ipHash,
            'score' => (int) $data['score'],
            'reason_tags_json' => $reasonTagsJson,
            'free_text' => $freeText,
            'pack_id' => $packId,
            'pack_version' => $packVersion,
            'report_version' => $reportVersion,
            'type_code' => $typeCode,
            'created_at' => $createdAt,
            'created_ymd' => $createdYmd,
        ];

        try {
            $feedbackId = DB::table('validity_feedbacks')->insertGetId($row);
        } catch (QueryException $e) {
            $dupe = DB::table('validity_feedbacks')
                ->where('attempt_id', $attemptId)
                ->where('created_ymd', $createdYmd)
                ->first();
            if ($dupe) {
                return response()->json([
                    'ok' => true,
                    'existing' => true,
                    'feedback_id' => $dupe->id,
                    'created_at' => (string) ($dupe->created_at ?? ''),
                ], 200);
            }
            throw $e;
        }

        return response()->json([
            'ok' => true,
            'feedback_id' => $feedbackId,
            'created_at' => $createdAt->toISOString(),
        ], 200);
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
            'message' => 'attempt not found.',
        ], 404);
    }

    private function normalizeId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function normalizeReasonTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $clean = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $tag);
            $clean = trim((string) $clean);
            if ($clean === '') {
                continue;
            }
            $out[] = $clean;
            if (count($out) >= 10) {
                break;
            }
        }
        return $out;
    }

    private function sanitizeFreeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $clean = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $text);
        $clean = trim((string) $clean);
        if ($clean === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($clean, 0, 200, 'UTF-8');
        }
        return (string) substr($clean, 0, 200);
    }

    private function checkAnonOwnership(string $attemptId, string $anonId): bool
    {
        if (!Schema::hasTable('identities')) {
            return $this->matchAttemptAnonId($attemptId, $anonId);
        }
        if (!Schema::hasColumn('identities', 'attempt_id') || !Schema::hasColumn('identities', 'anon_id')) {
            return $this->matchAttemptAnonId($attemptId, $anonId);
        }

        try {
            $row = DB::table('identities')
                ->where('attempt_id', $attemptId)
                ->first();
        } catch (QueryException) {
            return $this->matchAttemptAnonId($attemptId, $anonId);
        }

        if (!$row) {
            return $this->matchAttemptAnonId($attemptId, $anonId);
        }

        return trim((string) ($row->anon_id ?? '')) === $anonId;
    }

    private function matchAttemptAnonId(string $attemptId, string $anonId): bool
    {
        if (!Schema::hasTable('attempts') || !Schema::hasColumn('attempts', 'anon_id')) {
            return false;
        }

        try {
            $attemptAnonId = DB::table('attempts')
                ->where('id', $attemptId)
                ->value('anon_id');
        } catch (QueryException) {
            return false;
        }
        $attemptAnonId = trim((string) $attemptAnonId);

        if ($attemptAnonId === '' || $anonId === '') {
            return false;
        }

        return hash_equals($attemptAnonId, $anonId);
    }

    private function resolvePackId(Attempt $attempt): string
    {
        if (\App\Support\SchemaBaseline::hasColumn('attempts', 'pack_id')) {
            $packId = trim((string) ($attempt->pack_id ?? ''));
            if ($packId !== '') {
                return $packId;
            }
        }

        $fromResult = $this->contentPackIdFromResult($attempt);
        if ($fromResult !== '') {
            return $fromResult;
        }

        return $this->contentPackIdFromReport((string) $attempt->id);
    }

    private function contentPackIdFromResult(Attempt $attempt): string
    {
        if (!\App\Support\SchemaBaseline::hasColumn('attempts', 'result_json')) {
            return '';
        }

        $raw = $attempt->result_json ?? null;
        if (empty($raw)) {
            return '';
        }

        $decoded = null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        if (!is_array($decoded)) {
            return '';
        }

        $packId = (string) (
            data_get($decoded, 'content_pack_id')
            ?? data_get($decoded, 'versions.content_pack_id')
            ?? data_get($decoded, 'report.versions.content_pack_id')
            ?? ''
        );

        return trim($packId);
    }

    private function contentPackIdFromReport(string $attemptId): string
    {
        $disk = array_key_exists('private', config('filesystems.disks', []))
            ? Storage::disk('private')
            : Storage::disk(config('filesystems.default', 'local'));

        $path = "reports/{$attemptId}/report.json";
        try {
            if (!$disk->exists($path)) {
                return '';
            }
            $json = $disk->get($path);
        } catch (\Throwable $e) {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        $packId = (string) (data_get($decoded, 'versions.content_pack_id') ?? '');
        return trim($packId);
    }

    private function parsePackVersion(string $packId): string
    {
        $pos = strrpos($packId, '.v');
        if ($pos === false) {
            return '';
        }
        return substr($packId, $pos + 1);
    }
}
