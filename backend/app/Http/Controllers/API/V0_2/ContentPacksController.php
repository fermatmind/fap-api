<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentPacksIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ContentPacksController extends Controller
{
    /**
     * GET /api/v0.2/content-packs
     */
    public function index(Request $request, ContentPacksIndex $index)
    {
        $refresh = (string) $request->query('refresh', '') === '1';
        return response()->json($index->getIndex($refresh));
    }

    /**
     * GET /api/v0.2/content-packs/{pack_id}/{dir_version}/manifest
     */
    public function manifest(string $pack_id, string $dir_version, ContentPacksIndex $index)
    {
        $found = $index->find($pack_id, $dir_version);
        if (!($found['ok'] ?? false)) {
            return $this->notFound();
        }

        $item = $found['item'] ?? [];
        $path = (string) ($item['manifest_path'] ?? '');
        $read = $this->readJsonFile($path);
        if (!($read['ok'] ?? false)) {
            return $this->readFailed((string) ($read['error'] ?? 'READ_FAILED'), (string) ($read['message'] ?? ''));
        }

        return response()->json([
            'ok' => true,
            'pack_id' => $pack_id,
            'dir_version' => $dir_version,
            'manifest' => $read['data'],
        ]);
    }

    /**
     * GET /api/v0.2/content-packs/{pack_id}/{dir_version}/questions
     */
    public function questions(string $pack_id, string $dir_version, ContentPacksIndex $index)
    {
        $found = $index->find($pack_id, $dir_version);
        if (!($found['ok'] ?? false)) {
            return $this->notFound();
        }

        $item = $found['item'] ?? [];
        $path = (string) ($item['questions_path'] ?? '');
        $read = $this->readJsonFile($path);
        if (!($read['ok'] ?? false)) {
            return $this->readFailed((string) ($read['error'] ?? 'READ_FAILED'), (string) ($read['message'] ?? ''));
        }

        return response()->json([
            'ok' => true,
            'pack_id' => $pack_id,
            'dir_version' => $dir_version,
            'questions' => $read['data'],
        ]);
    }

    private function readJsonFile(string $path): array
    {
        if ($path === '' || !File::exists($path) || !File::isFile($path)) {
            return [
                'ok' => false,
                'error_code' => 'READ_FAILED',
                'message' => $path === '' ? 'missing file path' : "file not found: {$path}",
            ];
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_code' => 'READ_FAILED',
                'message' => "failed to read: {$path}",
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error_code' => 'INVALID_JSON',
                'message' => "invalid json: {$path}",
            ];
        }

        return [
            'ok' => true,
            'data' => $decoded,
        ];
    }

    private function notFound()
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
            'message' => 'pack not found',
        ], 404);
    }

    private function readFailed(string $error, string $message)
    {
        return response()->json([
            'ok' => false,
            'error_code' => $error,
            'message' => $message,
        ], 500);
    }
}
