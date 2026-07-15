<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\TopicAuthority\BigFiveTopicAuthorityDraftPreflight;
use Illuminate\Console\Command;
use Throwable;

final class PersonalityBigFiveAuthorityV246TopicDraftPreflight extends Command
{
    protected $signature = 'personality:big-five-authority-v2-topic-draft-preflight
        {--package=../generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/topic-draft-revision-package.json : PR46 Topic draft-revision package}
        {--package-only : Validate committed source locks without reading scales_registry}
        {--json : Emit the full JSON preflight result}';

    protected $description = 'Validate the Big Five Topic working-revision package with zero writes and no promotion.';

    public function handle(BigFiveTopicAuthorityDraftPreflight $preflight): int
    {
        try {
            $result = $preflight->preflight(
                (string) $this->option('package'),
                ! (bool) $this->option('package-only'),
            );
        } catch (Throwable $throwable) {
            $result = [
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'mode' => 'working_revision_candidates_zero_write',
                'actions' => $this->zeroWriteActions(),
                'error' => $throwable->getMessage(),
            ];
        }

        $this->emitResult($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed> $result */
    private function emitResult(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
        $this->line('ok='.(($result['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($result['status'] ?? 'FAIL_CLOSED'));
        $this->line('mode='.(string) ($result['mode'] ?? 'working_revision_candidates_zero_write'));
        $this->line('topic_candidates='.(string) ($counts['topic_candidates'] ?? 0));
        $this->line('working_revision_candidates='.(string) ($counts['working_revision_candidates'] ?? 0));
        $this->line('promotion_eligible='.(string) ($counts['promotion_eligible'] ?? 0));
        $this->line('database_writes='.(string) ($actions['database_writes'] ?? 0));
        $this->line('cms_writes='.(string) ($actions['cms_writes'] ?? 0));
        $this->line('revision_writes='.(string) ($actions['revision_writes'] ?? 0));
        $this->line('package_sha256='.(string) ($result['package_sha256'] ?? ''));
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }

    /** @return array<string,int> */
    private function zeroWriteActions(): array
    {
        return [
            'database_reads' => 0,
            'database_writes' => 0,
            'cms_writes' => 0,
            'revision_writes' => 0,
            'promotion_changes' => 0,
            'public_release_changes' => 0,
            'indexability_changes' => 0,
            'sitemap_changes' => 0,
            'llms_changes' => 0,
            'search_submissions' => 0,
            'cache_operations' => 0,
            'deployments' => 0,
        ];
    }
}
