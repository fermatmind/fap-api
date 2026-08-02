<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

final class CareerGuideCmsPromotionAdapter extends AbstractCareerCmsPromotionAdapter
{
    public function id(): string
    {
        return 'w3_career_guides_career_cms_v2';
    }

    protected function lane(): string
    {
        return 'W3';
    }

    protected function subscope(): string
    {
        return 'career-guides';
    }

    protected function revisionStore(): string
    {
        return 'career-guide-cms';
    }
}
