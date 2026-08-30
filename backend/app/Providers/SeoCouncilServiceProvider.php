<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SeoAgentEvidence\Sources\SeoPlatformDependencyEvidenceAdapter;
use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\Policy\PolicyGatewayCouncilAdmissionGateway;
use App\Services\SeoCouncil\TechnicalDiagnosis\DenyOnlyTechnicalDiagnosisRuntimeGate;
use App\Services\SeoCouncil\TechnicalDiagnosis\ReadOnlyTechnicalDiagnosisEvidenceBundleLoader;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisActivityLedger;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisCoordinator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisDependencyBindingSource;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisEvidenceBundleLoader;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisRunner;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisRuntimeGate;
use Illuminate\Support\ServiceProvider;

final class SeoCouncilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CouncilAdmissionGateway::class, PolicyGatewayCouncilAdmissionGateway::class);
        $this->app->bind(TechnicalDiagnosisRunner::class, TechnicalDiagnosisCoordinator::class);
        $this->app->bind(TechnicalDiagnosisRuntimeGate::class, DenyOnlyTechnicalDiagnosisRuntimeGate::class);
        $this->app->bind(TechnicalDiagnosisEvidenceBundleLoader::class, ReadOnlyTechnicalDiagnosisEvidenceBundleLoader::class);
        $this->app->bind(TechnicalDiagnosisDependencyBindingSource::class, SeoPlatformDependencyEvidenceAdapter::class);
        $this->app->singleton(TechnicalDiagnosisActivityLedger::class);
    }
}
