<?php

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Content\Publisher\ContentPackPublisher;
use Illuminate\Http\Request;

class ContentReleaseController extends Controller
{
    private function requireAdmin(Request $request)
    {
        // ✅ 新 header：X-Admin-Token（与 publish-content.yml 保持一致）
        $expect = (string) config('admin.token', config('fap.admin_token'));
        $given = (string) $request->header('X-FAP-Admin-Token', '');
        if ($given === '') {
            $given = (string) $request->header('X-Admin-Token', '');
        }

        abort_unless($expect !== '' && hash_equals($expect, $given), 401, 'Unauthorized');

        return null;
    }

    public function upload(Request $request, ContentPackPublisher $publisher)
    {
        $this->requireAdmin($request);

        $file = $request->file('file');
        $s3Key = (string) $request->input('s3_key', '');
        if ($file === null && $s3Key === '') {
            return response()->json([
                'ok' => false,
                'error' => 'MISSING_SOURCE',
                'message' => 'file or s3_key is required.',
            ], 422);
        }

        $result = $publisher->ingest(
            $file,
            $s3Key !== '' ? $s3Key : null,
            [
                'created_by' => 'admin',
                'dir_alias' => (string) $request->input('dir_alias', ''),
                'region' => (string) $request->input('region', ''),
                'locale' => (string) $request->input('locale', ''),
                'pack_id' => (string) $request->input('pack_id', ''),
                'content_package_version' => (string) $request->input('content_package_version', ''),
                'self_check' => $request->boolean('self_check', false),
            ]
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function publish(Request $request, ContentPackPublisher $publisher)
    {
        $this->requireAdmin($request);

        $versionId = (string) $request->input('version_id', '');
        $region = (string) $request->input('region', '');
        $locale = (string) $request->input('locale', '');
        $dirAlias = (string) $request->input('dir_alias', '');
        $probe = $request->boolean('probe', false);

        if ($versionId === '' || $region === '' || $locale === '' || $dirAlias === '') {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PARAMS',
                'message' => 'version_id, region, locale, dir_alias are required.',
            ], 422);
        }

        $result = $publisher->publish(
            $versionId,
            $region,
            $locale,
            $dirAlias,
            $probe,
            $this->resolveBaseUrl()
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function rollback(Request $request, ContentPackPublisher $publisher)
    {
        $this->requireAdmin($request);

        $region = (string) $request->input('region', '');
        $locale = (string) $request->input('locale', '');
        $dirAlias = (string) $request->input('dir_alias', '');
        $probe = $request->boolean('probe', false);

        if ($region === '' || $locale === '' || $dirAlias === '') {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PARAMS',
                'message' => 'region, locale, dir_alias are required.',
            ], 422);
        }

        $result = $publisher->rollback(
            $region,
            $locale,
            $dirAlias,
            $probe,
            $this->resolveBaseUrl()
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    private function resolveBaseUrl(): string
    {
        $configured = trim((string) config('app.url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) url('/'), '/');
    }
}
