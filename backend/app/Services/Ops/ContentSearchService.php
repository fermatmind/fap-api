<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;

class ContentSearchService
{
    /**
     * @param  array<int, int>  $currentOrgIds
     * @return array{query:string,items:list<array<string,mixed>>,elapsed_ms:int}
     */
    public function search(string $query, array $currentOrgIds, string $typeFilter = 'all'): array
    {
        $startedAt = microtime(true);
        $needle = trim($query);

        if ($needle === '') {
            return [
                'query' => '',
                'items' => [],
                'elapsed_ms' => 0,
            ];
        }

        $items = [];

        if (in_array($typeFilter, ['all', 'article'], true)) {
            $articles = Article::query()
                ->whereIn('org_id', $currentOrgIds)
                ->where(function ($query) use ($needle): void {
                    $query->where('title', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%')
                        ->orWhere('excerpt', 'like', '%'.$needle.'%');
                })
                ->latest('updated_at')
                ->limit(8)
                ->get();

            foreach ($articles as $record) {
                $items[] = [
                    'type' => 'article',
                    'label' => (string) $record->title,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Current org',
                    'status' => (string) $record->status,
                    'url' => ArticleResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if (in_array($typeFilter, ['all', 'category'], true)) {
            $categories = ArticleCategory::query()
                ->whereIn('org_id', $currentOrgIds)
                ->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%')
                        ->orWhere('description', 'like', '%'.$needle.'%');
                })
                ->latest('updated_at')
                ->limit(8)
                ->get();

            foreach ($categories as $record) {
                $items[] = [
                    'type' => 'category',
                    'label' => (string) $record->name,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Current org',
                    'status' => $record->is_active ? 'active' : 'inactive',
                    'url' => ArticleCategoryResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if (in_array($typeFilter, ['all', 'tag'], true)) {
            $tags = ArticleTag::query()
                ->whereIn('org_id', $currentOrgIds)
                ->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%');
                })
                ->latest('updated_at')
                ->limit(8)
                ->get();

            foreach ($tags as $record) {
                $items[] = [
                    'type' => 'tag',
                    'label' => (string) $record->name,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Current org',
                    'status' => $record->is_active ? 'active' : 'inactive',
                    'url' => ArticleTagResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if (in_array($typeFilter, ['all', 'guide'], true)) {
            $guides = CareerGuide::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where(function ($query) use ($needle): void {
                    $query->where('title', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%')
                        ->orWhere('excerpt', 'like', '%'.$needle.'%');
                })
                ->latest('updated_at')
                ->limit(8)
                ->get();

            foreach ($guides as $record) {
                $items[] = [
                    'type' => 'guide',
                    'label' => (string) $record->title,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Global content',
                    'status' => (string) $record->status,
                    'url' => CareerGuideResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if (in_array($typeFilter, ['all', 'job'], true)) {
            $jobs = CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where(function ($query) use ($needle): void {
                    $query->where('title', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%')
                        ->orWhere('excerpt', 'like', '%'.$needle.'%');
                })
                ->latest('updated_at')
                ->limit(8)
                ->get();

            foreach ($jobs as $record) {
                $items[] = [
                    'type' => 'job',
                    'label' => (string) $record->title,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Global content',
                    'status' => (string) $record->status,
                    'url' => CareerJobResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'query' => $needle,
            'items' => array_slice($items, 0, 40),
            'elapsed_ms' => $elapsedMs,
        ];
    }
}
