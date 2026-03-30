<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\IntentRegistry;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasIntentRegistry
{
    public function intentRegistry(): MorphOne
    {
        return $this->morphOne(IntentRegistry::class, 'governable')
            ->withoutGlobalScopes();
    }
}
