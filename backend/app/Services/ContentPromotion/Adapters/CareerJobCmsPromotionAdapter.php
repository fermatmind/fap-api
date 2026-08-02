<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

final class CareerJobCmsPromotionAdapter extends AbstractCareerCmsPromotionAdapter
{
    public function id(): string
    {
        return 'w8_career_jobs_career_cms_v2';
    }

    protected function lane(): string
    {
        return 'W8';
    }

    protected function subscope(): string
    {
        return 'career-jobs';
    }

    protected function revisionStore(): string
    {
        return 'career-job-cms';
    }
}
