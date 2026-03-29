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
    public function search(
        string $query,
        array $currentOrgIds,
        string $typeFilter = 'all',
        string $lifecycleFilter = 'all',
        string $staleFilter = 'all'
    ): array {
        $startedAt = microtime(true);
        $needle = trim($query);
        $staleThreshold = now()->subDays(14);

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
                ->when($lifecycleFilter !== 'all', fn ($query) => $query->where('lifecycle_state', $lifecycleFilter))
                ->when($staleFilter === 'only_stale', fn ($query) => $query->where('updated_at', '<', $staleThreshold))
                ->when($staleFilter === 'only_fresh', fn ($query) => $query->where('updated_at', '>=', $staleThreshold))
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
                    'selection_key' => 'article:'.(int) $record->id,
                    'type' => 'article',
                    'label' => (string) $record->title,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Current org',
                    'status' => (string) $record->status,
                    'lifecycle_state' => (string) ($record->lifecycle_state ?: ContentLifecycleService::STATE_ACTIVE),
                    'is_stale' => optional($record->updated_at)?->lt($staleThreshold) ?? false,
                    'actionable' => true,
                    'url' => ArticleResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if ($lifecycleFilter === 'all' && in_array($typeFilter, ['all', 'category'], true)) {
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
                    'selection_key' => null,
                    'type' => 'category',
                    'label' => (string) $record->name,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Current org',
                    'status' => $record->is_active ? 'active' : 'inactive',
                    'lifecycle_state' => $record->is_active ? 'active' : 'inactive',
                    'is_stale' => optional($record->updated_at)?->lt($staleThreshold) ?? false,
                    'actionable' => false,
                    'url' => ArticleCategoryResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if ($lifecycleFilter === 'all' && in_array($typeFilter, ['all', 'tag'], true)) {
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
                    'selection_key' => null,
                    'type' => 'tag',
                    'label' => (string) $record->name,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Current org',
                    'status' => $record->is_active ? 'active' : 'inactive',
                    'lifecycle_state' => $record->is_active ? 'active' : 'inactive',
                    'is_stale' => optional($record->updated_at)?->lt($staleThreshold) ?? false,
                    'actionable' => false,
                    'url' => ArticleTagResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if (in_array($typeFilter, ['all', 'guide'], true)) {
            $guides = CareerGuide::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->when($lifecycleFilter !== 'all', fn ($query) => $query->where('lifecycle_state', $lifecycleFilter))
                ->when($staleFilter === 'only_stale', fn ($query) => $query->where('updated_at', '<', $staleThreshold))
                ->when($staleFilter === 'only_fresh', fn ($query) => $query->where('updated_at', '>=', $staleThreshold))
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
                    'selection_key' => 'guide:'.(int) $record->id,
                    'type' => 'guide',
                    'label' => (string) $record->title,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Global content',
                    'status' => (string) $record->status,
                    'lifecycle_state' => (string) ($record->lifecycle_state ?: ContentLifecycleService::STATE_ACTIVE),
                    'is_stale' => optional($record->updated_at)?->lt($staleThreshold) ?? false,
                    'actionable' => true,
                    'url' => CareerGuideResource::getUrl('edit', ['record' => $record]),
                ];
            }
        }

        if (in_array($typeFilter, ['all', 'job'], true)) {
            $jobs = CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->when($lifecycleFilter !== 'all', fn ($query) => $query->where('lifecycle_state', $lifecycleFilter))
                ->when($staleFilter === 'only_stale', fn ($query) => $query->where('updated_at', '<', $staleThreshold))
                ->when($staleFilter === 'only_fresh', fn ($query) => $query->where('updated_at', '>=', $staleThreshold))
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
                    'selection_key' => 'job:'.(int) $record->id,
                    'type' => 'job',
                    'label' => (string) $record->title,
                    'subtitle' => 'slug='.(string) $record->slug,
                    'scope' => 'Global content',
                    'status' => (string) $record->status,
                    'lifecycle_state' => (string) ($record->lifecycle_state ?: ContentLifecycleService::STATE_ACTIVE),
                    'is_stale' => optional($record->updated_at)?->lt($staleThreshold) ?? false,
                    'actionable' => true,
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
