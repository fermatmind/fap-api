<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Memory\MemoryRedactor;
use App\Services\Memory\MemoryRetriever;
use App\Services\Memory\MemoryService;
use App\Services\Analytics\EventRecorder;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    /**
     * POST /api/v0.2/memory/propose
     */
    public function propose(Request $request)
    {
        if (!(bool) config('memory.enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'MEMORY_DISABLED',
                'message' => 'Memory is currently disabled.',
            ], 503);
        }

        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            return response()->json([
                'ok' => false,
                'error' => 'CONTENT_REQUIRED',
                'message' => 'content is required.',
            ], 422);
        }

        if ((bool) config('memory.redaction_enabled', true)) {
            $redactor = app(MemoryRedactor::class);
            $redacted = $redactor->redact($content);
            if (!empty($redacted['flags'])) {
                return response()->json([
                    'ok' => false,
                    'error' => 'CONTENT_REDACTED',
                    'message' => 'Memory content contains sensitive data.',
                ], 422);
            }
            $content = (string) ($redacted['content'] ?? $content);
        }

        $service = app(MemoryService::class);
        $result = $service->propose($userId, [
            'content' => $content,
            'title' => $request->input('title', null),
            'kind' => $request->input('kind', 'note'),
            'tags' => $request->input('tags', []),
            'evidence' => $request->input('evidence', []),
            'source_refs' => $request->input('source_refs', []),
            'consent_version' => $request->input('consent_version', null),
        ]);

        if (!($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'MEMORY_PROPOSE_FAILED',
            ], 500);
        }

        app(EventRecorder::class)->recordFromRequest($request, 'memory_proposed', $userId, [
            'memory_id' => $result['id'] ?? null,
            'kind' => $request->input('kind', 'note'),
        ]);

        return response()->json([
            'ok' => true,
            'id' => $result['id'],
        ]);
    }

    /**
     * POST /api/v0.2/memory/{id}/confirm
     */
    public function confirm(Request $request, string $id)
    {
        if (!(bool) config('memory.enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'MEMORY_DISABLED',
            ], 503);
        }

        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        $service = app(MemoryService::class);
        $result = $service->confirm($userId, $id);

        if (!($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'MEMORY_CONFIRM_FAILED',
            ], 404);
        }

        app(EventRecorder::class)->recordFromRequest($request, 'memory_confirmed', $userId, [
            'memory_id' => $id,
            'embedded' => (bool) ($result['embedded'] ?? false),
        ]);

        return response()->json([
            'ok' => true,
            'id' => $id,
            'embedded' => $result['embedded'] ?? false,
        ]);
    }

    /**
     * DELETE /api/v0.2/memory/{id}
     */
    public function delete(Request $request, string $id)
    {
        if (!(bool) config('memory.enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'MEMORY_DISABLED',
            ], 503);
        }

        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        $service = app(MemoryService::class);
        $result = $service->delete($userId, $id);

        if (($result['deleted'] ?? 0) > 0) {
            app(EventRecorder::class)->recordFromRequest($request, 'memory_deleted', $userId, [
                'memory_id' => $id,
            ]);
        }

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'deleted' => (int) ($result['deleted'] ?? 0),
        ]);
    }

    /**
     * GET /api/v0.2/memory/search
     */
    public function search(Request $request)
    {
        if (!(bool) config('memory.enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'MEMORY_DISABLED',
            ], 503);
        }

        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        $query = trim((string) $request->input('q', ''));
        $retriever = app(MemoryRetriever::class);
        $result = $retriever->search($userId, $query, []);

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'items' => $result['items'] ?? [],
            'matches' => $result['matches'] ?? [],
        ]);
    }

    /**
     * GET /api/v0.2/memory/export
     */
    public function export(Request $request)
    {
        if (!(bool) config('memory.enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'MEMORY_DISABLED',
            ], 503);
        }

        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        $service = app(MemoryService::class);
        $download = filter_var($request->query('download', false), FILTER_VALIDATE_BOOL);
        $limit = (int) $request->query('limit', 200);
        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) ? $cursor : null;

        if ($download) {
            $probe = $service->exportConfirmedPage($userId, 1, null);
            if (!($probe['ok'] ?? false)) {
                return response()->json([
                    'ok' => false,
                    'error' => $probe['error'] ?? 'MEMORY_EXPORT_FAILED',
                ], 500);
            }

            $filename = sprintf('memory_export_user_%d_%s.ndjson', $userId, now()->format('Ymd_His'));

            return response()->streamDownload(function () use ($service, $userId): void {
                foreach ($service->exportConfirmedCursor($userId) as $row) {
                    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false) {
                        continue;
                    }

                    echo $json . "\n";
                }
            }, $filename, [
                'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            ]);
        }

        $result = $service->exportConfirmedPage($userId, $limit, $cursor);
        if (!($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? 'MEMORY_EXPORT_FAILED');
            $status = $error === 'invalid_cursor' ? 422 : 500;

            return response()->json([
                'ok' => false,
                'error' => $error,
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'items' => $result['items'] ?? [],
            'next_cursor' => $result['next_cursor'] ?? null,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ]);
    }

    private function requireUserId(Request $request): ?int
    {
        $userId = trim((string) $request->attributes->get('fm_user_id', ''));
        if ($userId === '') {
            return null;
        }

        return (int) $userId;
    }

    private function userIdError()
    {
        return response()->json([
            'ok' => false,
            'error' => 'USER_ID_REQUIRED',
            'message' => 'user_id is required for memory operations.',
        ], 401);
    }
}
