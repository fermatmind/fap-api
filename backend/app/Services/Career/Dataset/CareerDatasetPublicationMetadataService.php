<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\DTO\Career\CareerDatasetPublicationMetadata;

final class CareerDatasetPublicationMetadataService
{
    public const DATASET_KEY = CareerFullDatasetAuthorityBuilder::DATASET_KEY;

    public const DATASET_SCOPE = CareerFullDatasetAuthorityBuilder::DATASET_SCOPE;

    public function build(): CareerDatasetPublicationMetadata
    {
        return new CareerDatasetPublicationMetadata(
            datasetKey: self::DATASET_KEY,
            datasetScope: self::DATASET_SCOPE,
            publisher: [
                'name' => 'FermatMind',
                'url' => 'https://www.fermatmind.com',
            ],
            license: [
                'name' => 'Proprietary Dataset License',
                'url' => 'https://www.fermatmind.com/datasets/occupations/license',
                'summary' => 'Public display allowed. Redistribution and mirrored republication require explicit permission.',
            ],
            usage: [
                'summary' => 'Public viewing and on-site exploration allowed; redistribution, bulk extraction, and mirrored republication require explicit permission.',
                'allowed_for_public_display' => true,
                'allowed_for_download' => true,
            ],
            distribution: [
                'access_mode' => 'landing_page_and_download',
                'download_url' => 'https://www.fermatmind.com/datasets/occupations/download',
                'format' => ['json', 'csv'],
                'methodology_url' => 'https://www.fermatmind.com/datasets/occupations/method',
                'documentation_url' => 'https://www.fermatmind.com/datasets/occupations',
            ],
        );
    }
}
