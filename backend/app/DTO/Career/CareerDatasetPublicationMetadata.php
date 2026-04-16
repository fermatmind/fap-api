<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerDatasetPublicationMetadata
{
    /**
     * @param  array{name:string,url:string}  $publisher
     * @param  array{name:string,url:string,summary:string}  $license
     * @param  array{summary:string,allowed_for_public_display:bool,allowed_for_download:bool}  $usage
     * @param  array{
     *   access_mode:string,
     *   download_url:string,
     *   format:list<string>,
     *   methodology_url:string,
     *   documentation_url:string
     * }  $distribution
     */
    public function __construct(
        public readonly string $datasetKey,
        public readonly string $datasetScope,
        public readonly array $publisher,
        public readonly array $license,
        public readonly array $usage,
        public readonly array $distribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_kind' => 'career_dataset_publication_metadata',
            'authority_version' => 'career.dataset_publication.v1',
            'dataset_key' => $this->datasetKey,
            'dataset_scope' => $this->datasetScope,
            'publisher' => $this->publisher,
            'license' => $this->license,
            'usage' => $this->usage,
            'distribution' => $this->distribution,
        ];
    }
}
