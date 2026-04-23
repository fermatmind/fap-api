<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Registry\RegistryValidator;
use App\Services\BigFive\ReportEngine\Resolver\ActionMatrixResolver;
use App\Services\BigFive\ReportEngine\Resolver\AtomicBlockResolver;
use App\Services\BigFive\ReportEngine\Resolver\FacetPrecisionResolver;
use App\Services\BigFive\ReportEngine\Resolver\ModifierInjector;
use App\Services\BigFive\ReportEngine\Resolver\SynergyCandidateResolver;
use App\Services\BigFive\ReportEngine\Resolver\SynergyResolutionService;

final class BigFiveReportEngine
{
    public function __construct(
        private readonly RegistryLoader $registryLoader = new RegistryLoader,
        private readonly RegistryValidator $registryValidator = new RegistryValidator,
        private readonly ReportContextBuilder $contextBuilder = new ReportContextBuilder,
        private readonly AtomicBlockResolver $atomicBlockResolver = new AtomicBlockResolver,
        private readonly ModifierInjector $modifierInjector = new ModifierInjector,
        private readonly SynergyCandidateResolver $synergyCandidateResolver = new SynergyCandidateResolver,
        private readonly SynergyResolutionService $synergyResolutionService = new SynergyResolutionService,
        private readonly FacetPrecisionResolver $facetPrecisionResolver = new FacetPrecisionResolver,
        private readonly ActionMatrixResolver $actionMatrixResolver = new ActionMatrixResolver,
        private readonly SectionInstructionAssembler $sectionInstructionAssembler = new SectionInstructionAssembler,
        private readonly RuntimePayloadAssembler $runtimePayloadAssembler = new RuntimePayloadAssembler,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array
    {
        $registry = $this->registryLoader->load();
        $this->registryValidator->assertValid($registry);

        $context = $this->contextBuilder->fromArray($input);
        $blocks = $this->atomicBlockResolver->resolve($context, $registry);
        $blocks = $this->modifierInjector->inject($context, $blocks, $registry);
        $synergies = $this->synergyResolutionService->resolve(
            $this->synergyCandidateResolver->collect($context, $registry),
            2,
        );
        $facetAnomalies = $this->facetPrecisionResolver->resolve($context, $registry);
        $actionMatrix = $this->actionMatrixResolver->resolve($context, $registry);
        $sections = $this->sectionInstructionAssembler->assemble($context, $blocks, $synergies, $facetAnomalies, $registry);

        return $this->runtimePayloadAssembler->assemble($context, $sections, $synergies, $facetAnomalies, $actionMatrix);
    }

    /**
     * @return array<string,mixed>
     */
    public function generateCanonicalNSlice(): array
    {
        $registry = $this->registryLoader->load();
        $fixture = $registry['fixtures']['canonical_n_slice_sensitive_independent'] ?? [];

        return $this->generate(is_array($fixture) ? $fixture : []);
    }
}
