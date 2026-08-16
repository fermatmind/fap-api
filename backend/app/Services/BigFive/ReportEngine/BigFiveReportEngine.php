<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Registry\RegistryValidator;
use App\Services\BigFive\ReportEngine\Resolver\ActionMatrixResolver;
use App\Services\BigFive\ReportEngine\Resolver\AtomicBlockResolver;
use App\Services\BigFive\ReportEngine\Resolver\FacetPrecisionResolver;
use App\Services\BigFive\ReportEngine\Resolver\ModifierInjector;
use App\Services\BigFive\ReportEngine\Resolver\NormEvidenceResolver;
use App\Services\BigFive\ReportEngine\Resolver\QualityPolicyResolver;
use App\Services\BigFive\ReportEngine\Resolver\SynergyCandidateResolver;
use App\Services\BigFive\ReportEngine\Resolver\SynergyResolutionService;
use App\Services\Content\BigFivePrivateResultPackLoader;

final class BigFiveReportEngine
{
    public function __construct(
        private readonly BigFivePrivateResultPackLoader $privateResultPackLoader,
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
        private readonly QualityPolicyResolver $qualityPolicyResolver = new QualityPolicyResolver,
        private readonly NormEvidenceResolver $normEvidenceResolver = new NormEvidenceResolver,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array
    {
        $context = $this->contextBuilder->fromArray($input);
        $loaded = $this->privateResultPackLoader->load($context->locale);
        $payload = $this->generateWithRegistry($input, $loaded['registry']);
        $payload['_meta']['big5_private_result_authority'] = $loaded['authority'];

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    public function generateWithRegistry(array $input, array $registry): array
    {
        $context = $this->contextBuilder->fromArray($input);
        $this->registryValidator->assertValid($registry);
        $qualityPolicy = $this->qualityPolicyResolver->resolve($context, $registry);
        $normEvidence = $this->normEvidenceResolver->resolve($context, $qualityPolicy, $registry);
        $blocks = $this->atomicBlockResolver->resolve($context, $registry);
        $blocks = $this->modifierInjector->inject($context, $blocks, $registry);
        $synergies = $this->synergyResolutionService->resolve(
            $this->synergyCandidateResolver->collect($context, $registry),
            3,
        );
        $facetAnomalies = $this->facetPrecisionResolver->resolve($context, $registry, $qualityPolicy);
        $actionMatrix = $this->actionMatrixResolver->resolve($context, $registry, $qualityPolicy, $synergies, $facetAnomalies);
        $sections = $this->sectionInstructionAssembler->assemble($context, $blocks, $synergies, $facetAnomalies, $actionMatrix, $qualityPolicy, $normEvidence, $registry);

        return $this->runtimePayloadAssembler->assemble($context, $sections, $synergies, $facetAnomalies, $actionMatrix, $qualityPolicy, $normEvidence);
    }

    /**
     * @return array<string,mixed>
     */
    public function generateCanonicalNSlice(): array
    {
        $registry = (new RegistryLoader)->load('zh-CN');
        $fixture = $registry['fixtures']['canonical_n_slice_sensitive_independent'] ?? [];

        return $this->generateWithRegistry(is_array($fixture) ? $fixture : [], $registry);
    }
}
