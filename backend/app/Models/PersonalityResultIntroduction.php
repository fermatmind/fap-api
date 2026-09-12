<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A published result introduction is independent of full-report and SEO publication. */
final class PersonalityResultIntroduction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paragraphs' => 'array',
            'revision' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public static function publishedFor(string $fullCode, string $locale): ?self
    {
        return self::query()
            ->where('full_code', strtoupper(trim($fullCode)))
            ->where('locale', $locale)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->first();
    }
}
