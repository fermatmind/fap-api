<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScalesSitemapSourceController extends Controller
{
    /**
     * GET /api/v0.3/scales/sitemap-source?locale=en|zh
     */
    public function index(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);

        $rows = DB::table('scales_registry')
            ->select(['primary_slug', 'slugs_json', 'view_policy_json', 'is_indexable', 'updated_at'])
            ->where('org_id', 0)
            ->where('is_public', 1)
            ->where('is_active', 1)
            ->orderBy('primary_slug')
            ->get();

        /** @var array<string,array{slug:string,lastmod:string,is_indexable:bool}> $itemsBySlug */
        $itemsBySlug = [];

        foreach ($rows as $row) {
            $isIndexable = $this->resolveIsIndexable($row->is_indexable ?? null, $row->view_policy_json ?? null);
            $lastmod = $this->resolveLastmod($row->updated_at ?? null);

            foreach ($this->collectSlugs($row->primary_slug ?? null, $row->slugs_json ?? null) as $slug) {
                if ($slug === '') {
                    continue;
                }

                $existing = $itemsBySlug[$slug] ?? null;
                if (!$existing) {
                    $itemsBySlug[$slug] = [
                        'slug' => $slug,
                        'lastmod' => $lastmod,
                        'is_indexable' => $isIndexable,
                    ];
                    continue;
                }

                $itemsBySlug[$slug]['is_indexable'] = $existing['is_indexable'] && $isIndexable;
                if ($lastmod > $existing['lastmod']) {
                    $itemsBySlug[$slug]['lastmod'] = $lastmod;
                }
            }
        }

        $items = array_values($itemsBySlug);
        usort($items, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return response()->json([
            'ok' => true,
            'locale' => $locale,
            'items' => $items,
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $raw = trim((string) ($request->query('locale') ?? $request->header('X-FAP-Locale', 'en')));
        if ($raw === '') {
            return 'en';
        }

        $lang = strtolower((string) explode('-', str_replace('_', '-', $raw))[0]);
        return $lang === 'zh' ? 'zh' : 'en';
    }

    /**
     * @return array<int,string>
     */
    private function collectSlugs(mixed $primarySlug, mixed $slugsJson): array
    {
        $out = [];

        $primary = trim((string) $primarySlug);
        if ($primary !== '') {
            $out[] = $primary;
        }

        if (is_array($slugsJson)) {
            foreach ($slugsJson as $slug) {
                $slug = trim((string) $slug);
                if ($slug !== '') {
                    $out[] = $slug;
                }
            }

            return array_values(array_unique($out));
        }

        if (is_string($slugsJson) && trim($slugsJson) !== '') {
            $decoded = json_decode($slugsJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug !== '') {
                        $out[] = $slug;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function resolveLastmod(mixed $raw): string
    {
        try {
            if ($raw === null || $raw === '') {
                return '1970-01-01';
            }

            return Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            return '1970-01-01';
        }
    }

    private function resolveIsIndexable(mixed $rawIsIndexable, mixed $viewPolicy): bool
    {
        if ($rawIsIndexable !== null) {
            return (bool) $rawIsIndexable;
        }

        $policy = [];
        if (is_array($viewPolicy)) {
            $policy = $viewPolicy;
        } elseif (is_string($viewPolicy) && trim($viewPolicy) !== '') {
            $decoded = json_decode($viewPolicy, true);
            if (is_array($decoded)) {
                $policy = $decoded;
            }
        }

        if (array_key_exists('indexable', $policy)) {
            return (bool) $policy['indexable'];
        }

        $robots = strtolower(trim((string) ($policy['robots'] ?? '')));
        if ($robots !== '' && str_contains($robots, 'noindex')) {
            return false;
        }

        return true;
    }
}
