<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use Filament\Support\Assets\Theme;

final class OpsTheme extends Theme
{
    private ?string $resolvedVersion = null;

    public function getVersion(): string
    {
        if ($this->resolvedVersion !== null) {
            return $this->resolvedVersion;
        }

        $path = $this->getPath();
        $hash = is_string($path) && is_file($path) && is_readable($path)
            ? @hash_file('sha256', $path)
            : false;

        return $this->resolvedVersion = is_string($hash) ? $hash : parent::getVersion();
    }
}
