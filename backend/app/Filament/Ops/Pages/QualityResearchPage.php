<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Analytics\QualityResearchInsightsSupport;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Actions\Action;
use Filament\Pages\Page;

class QualityResearchPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'quality-research';

    protected static string $view = 'filament.ops.pages.quality-research-page';

    public string $activeTab = 'quality';

    public string $fromDate = '';

    public string $toDate = '';

    public string $scaleCode = 'all';

    public string $locale = 'all';

    public string $region = 'all';

    public string $contentPackageVersion = 'all';

    public string $scoringSpecVersion = 'all';

    public string $normVersion = 'all';

    public string $qualityLevel = 'all';

    public bool $onlyCrisis = false;

    public bool $onlyInvalid = false;

    public bool $onlyWarnings = false;

    /** @var array<string,string> */
    public array $scaleOptions = [];

    /** @var array<string,string> */
    public array $localeOptions = [];

    /** @var array<string,string> */
    public array $regionOptions = [];

    /** @var array<string,string> */
    public array $contentPackageVersionOptions = [];

    /** @var array<string,string> */
    public array $scoringSpecVersionOptions = [];

    /** @var array<string,string> */
    public array $normVersionOptions = [];

    /** @var list<array<string,mixed>> */
    public array $qualityKpis = [];

    /** @var list<array<string,mixed>> */
    public array $qualityDailyRows = [];

    /** @var list<array<string,mixed>> */
    public array $qualityScaleRows = [];

    /** @var list<array<string,mixed>> */
    public array $qualityFlagRows = [];

    /** @var list<array<string,mixed>> */
    public array $psychometricRows = [];

    /** @var list<array<string,mixed>> */
    public array $normCoverageRows = [];

    /** @var list<array<string,mixed>> */
    public array $rolloutRows = [];

    /** @var list<array<string,mixed>> */
    public array $driftRows = [];

    /** @var list<string> */
    public array $scopeNotes = [];

    /** @var list<string> */
    public array $qualityNotes = [];

    /** @var list<string> */
    public array $psychometricNotes = [];

    /** @var list<string> */
    public array $normNotes = [];

    /** @var list<string> */
    public array $warnings = [];

    public bool $hasQualityData = false;

    public bool $hasPsychometricsData = false;

    public bool $hasNormsData = false;

    public function mount(): void
    {
        $this->fromDate = now()->subDays(13)->toDateString();
        $this->toDate = now()->toDateString();
        $this->refreshPage();
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && $user->hasPermission(PermissionNames::ADMIN_OWNER);
    }

    public function getTitle(): string
    {
        return __('ops.nav.quality_research');
    }

    public function getSubheading(): ?string
    {
        return __('ops.pages.quality_research.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.quality_research');
    }

    public function applyFilters(): void
    {
        $this->refreshPage();
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['quality', 'psychometrics', 'norms-drift'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function formatInt(int $value): string
    {
        return number_format($value);
    }

    public function formatRate(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value * 100, 1).'%';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('attempts')
                ->label(__('ops.nav.attempts_explorer'))
                ->url('/ops/attempts'),
            Action::make('results')
                ->label(__('ops.nav.results_explorer'))
                ->url('/ops/results'),
        ];
    }

    private function refreshPage(): void
    {
        $this->warnings = [];
        $this->scopeNotes = [];
        $this->qualityNotes = [];
        $this->psychometricNotes = [];
        $this->normNotes = [];
        $this->qualityKpis = [];
        $this->qualityDailyRows = [];
        $this->qualityScaleRows = [];
        $this->qualityFlagRows = [];
        $this->psychometricRows = [];
        $this->normCoverageRows = [];
        $this->rolloutRows = [];
        $this->driftRows = [];
        $this->hasQualityData = false;
        $this->hasPsychometricsData = false;
        $this->hasNormsData = false;

        $orgId = $this->selectedOrgId();
        $support = app(QualityResearchInsightsSupport::class);
        $this->loadFilterOptions($support, $orgId);
        $this->scopeNotes = $support->pageScopeNotes();

        if ($orgId <= 0) {
            $this->warnings[] = 'Select an org before loading Quality / Psychometrics / Norms & Drift.';

            return;
        }

        $quality = $support->qualityPayload($orgId, $this->sharedFilters(), $this->qualityLocalFilters());
        $psychometrics = $support->psychometricsPayload($this->sharedFilters());
        $norms = $support->normsPayload($orgId, $this->sharedFilters());

        $this->qualityKpis = $quality['kpis'];
        $this->qualityDailyRows = $quality['daily_rows'];
        $this->qualityScaleRows = $quality['scale_rows'];
        $this->qualityFlagRows = $quality['flag_rows'];
        $this->qualityNotes = $quality['notes'];
        $this->hasQualityData = (bool) $quality['has_data'];

        $this->psychometricRows = $psychometrics['rows'];
        $this->psychometricNotes = $psychometrics['notes'];
        $this->hasPsychometricsData = (bool) $psychometrics['has_data'];

        $this->normCoverageRows = $norms['coverage_rows'];
        $this->rolloutRows = $norms['rollout_rows'];
        $this->driftRows = $norms['drift_rows'];
        $this->normNotes = $norms['notes'];
        $this->hasNormsData = (bool) $norms['has_data'];

        $this->warnings = array_values(array_unique(array_merge(
            $quality['warnings'],
            $psychometrics['warnings'],
            $norms['warnings']
        )));
    }

    private function loadFilterOptions(QualityResearchInsightsSupport $support, int $orgId): void
    {
        $options = $support->filterOptions($orgId);

        $this->scaleOptions = $options['scaleOptions'];
        $this->localeOptions = $options['localeOptions'];
        $this->regionOptions = $options['regionOptions'];
        $this->contentPackageVersionOptions = $options['contentPackageVersionOptions'];
        $this->scoringSpecVersionOptions = $options['scoringSpecVersionOptions'];
        $this->normVersionOptions = $options['normVersionOptions'];
    }

    /**
     * @return array<string,string>
     */
    private function sharedFilters(): array
    {
        return [
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'scale_code' => $this->scaleCode,
            'locale' => $this->locale,
            'region' => $this->region,
            'content_package_version' => $this->contentPackageVersion,
            'scoring_spec_version' => $this->scoringSpecVersion,
            'norm_version' => $this->normVersion,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityLocalFilters(): array
    {
        return [
            'quality_level' => $this->qualityLevel,
            'only_crisis' => $this->onlyCrisis,
            'only_invalid' => $this->onlyInvalid,
            'only_warnings' => $this->onlyWarnings,
        ];
    }

    private function selectedOrgId(): int
    {
        $sessionOrgId = max(0, (int) session('ops_org_id', 0));
        if ($sessionOrgId > 0) {
            return $sessionOrgId;
        }

        return max(0, (int) app(OrgContext::class)->orgId());
    }
}
