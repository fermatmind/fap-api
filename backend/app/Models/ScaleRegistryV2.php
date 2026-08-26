<?php

declare(strict_types=1);

namespace App\Models;

final class ScaleRegistryV2 extends ScaleRegistry
{
    protected $table = 'scales_registry_v2';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';
}
