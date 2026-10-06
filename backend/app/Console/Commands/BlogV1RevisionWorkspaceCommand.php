<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Services\Cms\BlogV1RevisionWorkspace;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class BlogV1RevisionWorkspaceCommand extends Command
{
    protected $signature = 'articles:blog-v1-workspace
        {--phase=plan : snapshot|plan|stage|surface-plan|surface-stage|surface-publish}
        {--file= : B4 frozen package or exact two-locale native surface package}
        {--sha256= : Exact package bytes SHA-256}
        {--expected-state-sha256= : Complete native state hash from a fresh read-only plan}
        {--execute : Explicitly execute the selected bounded write transition}
        {--admin-user-id= : Existing active configured solo owner with content publish permission}
        {--deployed-sha= : Exact active production release SHA}
        {--preview-approved : Actual authenticated preview QA for this exact surface candidate}
        {--confirm= : Exact phase, package and complete-state confirmation}';

    protected $description = 'Plan or stage the fixed blog V1 cohort; articles are never published by this command.';

    public static function confirmation(string $phase, string $sha, string $state): string
    {
        return 'blog-v1:'.$phase.':'.$sha.':'.$state;
    }

    public static function executionActor(string $confirmation, string $supplied, int $id, string $deployedSha): int
    {
        if (! hash_equals($confirmation, $supplied) || ! SchemaBaseline::hasTable('audit_logs')) {
            throw new RuntimeException('blog_confirmation_or_audit_missing');
        }
        if (app()->environment('production')) {
            $revision = dirname(base_path()).'/REVISION';
            if (preg_match('/\A[a-f0-9]{40}\z/', $deployedSha) !== 1 || ! is_file($revision)
                || ! hash_equals($deployedSha, trim((string) file_get_contents($revision)))) {
                throw new RuntimeException('blog_production_release_drift');
            }
        }
        $actor = AdminUser::query()->find($id);
        if (! $actor instanceof AdminUser || (int) $actor->is_active !== 1 || $actor->locked_until?->isFuture()
            || ! ($actor->hasPermission(PermissionNames::ADMIN_OWNER) || $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH))
            || ! app(CmsEditorialReviewAttestationService::class)->isConfiguredSoloOwner($id)) {
            throw new RuntimeException('blog_configured_operator_required');
        }

        return $id;
    }

    public function handle(BlogV1RevisionWorkspace $workspace): int
    {
        $phase = (string) $this->option('phase');
        $execute = (bool) $this->option('execute');
        try {
            if (! in_array($phase, ['snapshot', 'plan', 'stage', 'surface-plan', 'surface-stage', 'surface-publish'], true)
                || ($execute && ! in_array($phase, ['stage', 'surface-stage', 'surface-publish'], true))) {
                throw new RuntimeException('blog_phase_invalid');
            }
            $file = (string) $this->option('file');
            $sha = (string) $this->option('sha256');
            $surface = str_starts_with($phase, 'surface-');
            if ($surface) {
                if (! is_file($file) || is_link($file) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1
                    || ! hash_equals($sha, (string) hash_file('sha256', $file))) {
                    throw new RuntimeException('blog_surface_package_bytes_drift');
                }
                $package = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            } else {
                $package = $workspace->loadSource($file, $sha);
            }
            $state = (string) $this->option('expected-state-sha256');
            $confirm = self::confirmation($phase, $sha, $state);
            $actor = $execute ? self::executionActor($confirm, (string) $this->option('confirm'),
                (int) $this->option('admin-user-id'), (string) $this->option('deployed-sha')) : 0;
            if ($execute && $phase === 'surface-publish' && ! $this->option('preview-approved')) {
                throw new RuntimeException('blog_authenticated_surface_preview_required');
            }
            $result = $surface ? $workspace->surface($package, $phase, $execute, $state, $actor, $sha)
                : ($execute ? $workspace->stage($package, $state, $actor) : $workspace->plan($package));
            $result['expected_confirmation'] = self::confirmation($phase, $sha, $result['state_sha256']);
            $this->line(json_encode(['phase' => $phase, ...$result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = $error instanceof RuntimeException && preg_match('/\A[a-z_]+\z/', $error->getMessage()) === 1
                ? $error->getMessage() : 'blog_native_operation_failed';
            $this->line(json_encode(['ok' => false, 'phase' => $phase, 'readonly' => ! $execute, 'error' => $code], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
