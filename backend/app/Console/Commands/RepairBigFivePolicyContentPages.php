<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BigFivePolicyContentRepair;
use App\Services\Cms\CmsTranslationWorkflowException;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** @review-surface content_page */
final class RepairBigFivePolicyContentPages extends Command
{
    protected $signature = 'content-pages:repair-reviewed-big-five-policy
        {id : Existing ContentPage ID}
        {--stage : Save the exact private review candidate}
        {--publish : Publish a separately approved revision}
        {--revision= : Exact approved working revision ID}
        {--payload-sha256= : Exact approved working payload SHA-256}';

    protected $description = 'Stage or publish a reviewed correction for the exact Big Five policy pages.';

    public function handle(BigFivePolicyContentRepair $repair): int
    {
        try {
            $id = (int) $this->argument('id');
            if ($this->option('stage') && $this->option('publish')) {
                throw new DomainException('Stage and publish are separate operations.');
            }
            if ($this->option('stage')) {
                $repair->stage($id);
            }
            if ($this->option('publish')) {
                $repair->publish($id, (int) $this->option('revision'), (string) $this->option('payload-sha256'));
            }
            $this->line(json_encode($repair->inspect($id), JSON_THROW_ON_ERROR));
        } catch (DomainException|CmsTranslationWorkflowException|ReviewAttestationValidationException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
