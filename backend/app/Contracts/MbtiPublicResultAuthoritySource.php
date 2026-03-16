<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Mbti\MbtiPublicTypeIdentity;

interface MbtiPublicResultAuthoritySource
{
    public function sourceKey(): string;

    /**
     * @return array{
     *   resolved_type_code:string,
     *   profile?:array<string,mixed>,
     *   sections?:array<string,array<string,mixed>>,
     *   premium_teaser?:array<string,array<string,mixed>>,
     *   seo_meta?:array<string,mixed>,
     *   meta?:array<string,mixed>
     * }
     */
    public function read(MbtiPublicTypeIdentity $identity): array;
}
