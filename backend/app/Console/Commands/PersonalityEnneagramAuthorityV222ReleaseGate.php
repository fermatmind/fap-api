<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV222ReleaseGate;
use Illuminate\Console\Command;
use Throwable;

final class PersonalityEnneagramAuthorityV222ReleaseGate extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-release-gate
        {--manual-reviews=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/manual-review-register.json : Named human review evidence bound to exact asset hashes}
        {--json : Emit the complete deterministic release-gate report}';

    protected $description = 'Aggregate and validate the zero-write 116-page Enneagram Authority V2 release package.';

    public function handle(EnneagramPublicAuthorityV222ReleaseGate $gate): int
    {
        try {
            $report = $gate->evaluate(base_path(), (string) $this->option('manual-reviews'));
        } catch (Throwable $exception) {
            $report = [
                'artifact' => EnneagramPublicAuthorityV222ReleaseGate::ARTIFACT,
                'status' => 'fail_closed',
                'decision' => 'HOLD',
                'ok' => false,
                'automated_gate_passed' => false,
                'human_review_passed' => false,
                'release_eligible' => false,
                'errors' => [['code' => 'command_error', 'subject' => $exception->getMessage()]],
                'execution_boundaries' => [
                    'production_write_executed' => false,
                    'database_mutated' => false,
                    'cms_mutated' => false,
                    'revision_pointer_changed' => false,
                    'media_uploaded' => false,
                    'cache_revalidated' => false,
                    'indexability_changed' => false,
                    'sitemap_changed' => false,
                    'llms_changed' => false,
                    'search_submitted' => false,
                    'deployed' => false,
                ],
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        } else {
            foreach (['status', 'decision', 'automated_gate_passed', 'human_review_passed', 'media_rights_review_passed', 'release_eligible', 'package_sha256'] as $field) {
                $value = $report[$field] ?? null;
                $this->line($field.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
            }
            $this->line('asset_count='.(string) ($report['counts']['assets'] ?? 0));
            $this->line('missing_human_review_count='.(string) ($report['counts']['missing_human_reviews'] ?? 0));
            $this->line('writes_committed=0');
        }

        return ($report['release_eligible'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
