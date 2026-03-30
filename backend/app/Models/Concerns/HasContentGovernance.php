<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ContentGovernance;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasContentGovernance
{
    public function governance(): MorphOne
    {
        return $this->morphOne(ContentGovernance::class, 'governable')
            ->withoutGlobalScopes();
    }
}
