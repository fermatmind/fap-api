<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

final class CareerStructuredDataOutputPolicy
{
    public const SCHEMA_OCCUPATION = 'occupation';

    public const SCHEMA_COLLECTION_PAGE = 'collection_page';

    public const SCHEMA_ITEM_LIST = 'item_list';

    public const SCHEMA_BREADCRUMB_LIST = 'breadcrumb_list';

    public const SCHEMA_ARTICLE = 'article';

    public const SCHEMA_DATASET = 'dataset';

    /**
     * @return list<string>
     */
    public function allowedSchemaFamiliesFor(string $routeKind): array
    {
        return match (trim($routeKind)) {
            'career_job_detail' => [
                self::SCHEMA_OCCUPATION,
                self::SCHEMA_BREADCRUMB_LIST,
            ],
            'career_family_hub' => [
                self::SCHEMA_COLLECTION_PAGE,
                self::SCHEMA_ITEM_LIST,
                self::SCHEMA_BREADCRUMB_LIST,
            ],
            'career_guide_public_detail', 'article_public_detail' => [
                self::SCHEMA_ARTICLE,
                self::SCHEMA_BREADCRUMB_LIST,
            ],
            'career_dataset_hub' => [
                self::SCHEMA_DATASET,
                self::SCHEMA_BREADCRUMB_LIST,
            ],
            'career_dataset_method' => [
                self::SCHEMA_ARTICLE,
                self::SCHEMA_BREADCRUMB_LIST,
            ],
            default => [],
        };
    }

    public function allows(string $routeKind, string $schemaFamily): bool
    {
        return in_array(
            $schemaFamily,
            $this->allowedSchemaFamiliesFor($routeKind),
            true,
        );
    }
}
