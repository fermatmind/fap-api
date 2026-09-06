<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SeoAgentEvidence\Sources\SeoPlatformDependencyEvidenceAdapter;
use App\Services\SeoCouncil\Competitive\CompetitiveActivityLedger;
use App\Services\SeoCouncil\Competitive\CompetitiveCoordinator;
use App\Services\SeoCouncil\Competitive\CompetitiveEvidenceBundleLoader;
use App\Services\SeoCouncil\Competitive\CompetitiveRunner;
use App\Services\SeoCouncil\Competitive\CompetitiveRuntimeGate;
use App\Services\SeoCouncil\Competitive\DenyOnlyCompetitiveRuntimeGate;
use App\Services\SeoCouncil\Competitive\ReadOnlyCompetitiveEvidenceBundleLoader;
use App\Services\SeoCouncil\Measurement\DenyOnlyMeasurementRuntimeGate;
use App\Services\SeoCouncil\Measurement\MeasurementActivityLedger;
use App\Services\SeoCouncil\Measurement\MeasurementCoordinator;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceBundleLoader;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceDiagnosticLoader;
use App\Services\SeoCouncil\Measurement\MeasurementRunner;
use App\Services\SeoCouncil\Measurement\MeasurementRuntimeGate;
use App\Services\SeoCouncil\Measurement\ReadOnlyMeasurementEvidenceBundleLoader;
use App\Services\SeoCouncil\Platform12\Model\DisabledSeoCouncilModelClient;
use App\Services\SeoCouncil\Platform12\Model\FakeSeoCouncilModelClient;
use App\Services\SeoCouncil\Platform12\Model\HttpSeoCouncilModelClient;
use App\Services\SeoCouncil\Platform12\Model\SeoCouncilModelClient;
use App\Services\SeoCouncil\Platform12\Notification\OpsAlertNotificationTransport;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationTransport;
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
        $this->app->bind(\App\Services\SeoCouncil\Platform12\Platform12EvidenceReader::class,
            \App\Services\SeoCouncil\Platform12\Platform12ProductionEvidenceReader::class);
        $this->app->bind(CouncilAdmissionGateway::class, PolicyGatewayCouncilAdmissionGateway::class);
        $this->app->bind(CompetitiveRunner::class, CompetitiveCoordinator::class);
        $this->app->bind(CompetitiveRuntimeGate::class, DenyOnlyCompetitiveRuntimeGate::class);
        $this->app->bind(CompetitiveEvidenceBundleLoader::class, ReadOnlyCompetitiveEvidenceBundleLoader::class);
        $this->app->singleton(CompetitiveActivityLedger::class);
        $this->app->bind(MeasurementRunner::class, MeasurementCoordinator::class);
        $this->app->bind(MeasurementRuntimeGate::class, DenyOnlyMeasurementRuntimeGate::class);
        $this->app->singleton(ReadOnlyMeasurementEvidenceBundleLoader::class);
        $this->app->bind(
            MeasurementEvidenceBundleLoader::class,
            fn ($app) => $app->make(ReadOnlyMeasurementEvidenceBundleLoader::class),
        );
        $this->app->bind(
            MeasurementEvidenceDiagnosticLoader::class,
            fn ($app) => $app->make(ReadOnlyMeasurementEvidenceBundleLoader::class),
        );
        $this->app->singleton(MeasurementActivityLedger::class);
        $this->app->bind(TechnicalDiagnosisRunner::class, TechnicalDiagnosisCoordinator::class);
        $this->app->bind(TechnicalDiagnosisRuntimeGate::class, DenyOnlyTechnicalDiagnosisRuntimeGate::class);
        $this->app->bind(TechnicalDiagnosisEvidenceBundleLoader::class, ReadOnlyTechnicalDiagnosisEvidenceBundleLoader::class);
        $this->app->bind(TechnicalDiagnosisDependencyBindingSource::class, SeoPlatformDependencyEvidenceAdapter::class);
        $this->app->singleton(TechnicalDiagnosisActivityLedger::class);
        $this->app->singleton(DisabledSeoCouncilModelClient::class);
        $this->app->singleton(HttpSeoCouncilModelClient::class);
        $this->app->singleton(FakeSeoCouncilModelClient::class);
        $this->app->bind(Platform12NotificationTransport::class, OpsAlertNotificationTransport::class);
        $this->app->singleton(
            SeoCouncilModelClient::class,
            static function ($app): SeoCouncilModelClient {
                $provider = strtolower(trim((string) config('seo_council.model_provider', 'disabled')));

                return match ($provider) {
                    'http' => $app->make(HttpSeoCouncilModelClient::class),
                    'fake' => $app->environment('testing')
                        ? $app->make(FakeSeoCouncilModelClient::class)
                        : $app->make(DisabledSeoCouncilModelClient::class),
                    default => $app->make(DisabledSeoCouncilModelClient::class),
                };
            },
        );
    }
}
