<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Mbti\MbtiPublicTypeIdentity;

interface MbtiPublicResultPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(MbtiPublicTypeIdentity $identity, MbtiPublicResultAuthoritySource $source): array;
}
