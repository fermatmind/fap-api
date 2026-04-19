<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerValidateOccupationDirectoryReviewQueuesCommandTest extends TestCase
{
    #[Test]
    public function it_reports_pending_review_decisions_as_blocking_by_default(): void
    {
        $dir = $this->writeQueues([
            'translation' => [['review_decision' => '']],
            'alias' => [['review_decision' => '']],
            'child' => [['review_decision' => '']],
        ]);

        $this->artisan('career:validate-occupation-directory-review-queues', [
            '--queue-dir' => $dir,
        ])
            ->expectsOutputToContain('pending_total=3')
            ->expectsOutputToContain('ready_for_continuation=0')
            ->expectsOutputToContain('review queue validation failed')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_allows_pending_rows_when_explicitly_requested(): void
    {
        $dir = $this->writeQueues([
            'translation' => [['review_decision' => '']],
            'alias' => [['review_decision' => '']],
            'child' => [['review_decision' => '']],
        ]);

        $this->artisan('career:validate-occupation-directory-review-queues', [
            '--queue-dir' => $dir,
            '--allow-pending' => true,
        ])
            ->expectsOutputToContain('pending_total=3')
            ->expectsOutputToContain('ready_for_continuation=1')
            ->expectsOutputToContain('review queue validation complete')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_invalid_decisions_and_missing_required_fields(): void
    {
        $dir = $this->writeQueues([
            'translation' => [['review_decision' => 'edit']],
            'alias' => [['review_decision' => 'alias_existing']],
            'child' => [['review_decision' => 'not_a_decision']],
        ]);

        $this->artisan('career:validate-occupation-directory-review-queues', [
            '--queue-dir' => $dir,
            '--allow-pending' => true,
        ])
            ->expectsOutputToContain('invalid_total=1')
            ->expectsOutputToContain('missing_required_total=2')
            ->expectsOutputToContain('review queue validation failed')
            ->assertExitCode(1);
    }

    /**
     * @param  array{
     *   translation: list<array<string,string>>,
     *   alias: list<array<string,string>>,
     *   child: list<array<string,string>>
     * }  $rows
     */
    private function writeQueues(array $rows): string
    {
        $dir = sys_get_temp_dir().'/career-validate-review-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $this->writeCsv($dir.'/translation_review_queue.csv', [
            'review_decision',
            'approved_title_zh',
            'approved_title_en',
        ], $rows['translation']);
        $this->writeCsv($dir.'/alias_review_decisions.csv', [
            'review_decision',
            'approved_target_slug',
        ], $rows['alias']);
        $this->writeCsv($dir.'/child_role_review_decisions.csv', [
            'review_decision',
            'approved_parent_slug',
        ], $rows['child']);

        return $dir;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $key): string => $row[$key] ?? '',
                $header,
            ));
        }
        fclose($handle);
    }
}
