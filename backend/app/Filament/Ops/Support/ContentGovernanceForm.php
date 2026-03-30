<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\Cms\ContentGovernanceService;
use Filament\Forms;
use Filament\Forms\Components\Section;

final class ContentGovernanceForm
{
    public static function section(string $defaultPageType, string $railClass): Section
    {
        return Forms\Components\Section::make('Governance')
            ->description('Bind page model, canonical target, hub/test/method references, and editorial owners in one SEO governance rail.')
            ->extraAttributes(['class' => $railClass])
            ->schema([
                Forms\Components\Select::make('workspace_governance.page_type')
                    ->label('Page type')
                    ->required()
                    ->native(false)
                    ->options(ContentGovernanceService::pageTypeOptions())
                    ->default($defaultPageType)
                    ->helperText('Required SEO page model used by later schema and release gate policies.'),
                Forms\Components\Select::make('workspace_governance.publish_gate_state')
                    ->label('Publish gate state')
                    ->native(false)
                    ->options(ContentGovernanceService::publishGateStateOptions())
                    ->default(ContentGovernanceService::PUBLISH_GATE_DRAFT)
                    ->helperText('Tracks governance readiness separately from the public status toggle.'),
                Forms\Components\Select::make('workspace_governance.cta_stage')
                    ->label('CTA stage')
                    ->required()
                    ->native(false)
                    ->options(ContentGovernanceService::ctaStageOptions())
                    ->helperText('Required funnel stage for release gating and later CTA rendering policies.'),
                Forms\Components\TextInput::make('workspace_governance.primary_query')
                    ->label('Primary query')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Single canonical search intent this page is expected to own.'),
                Forms\Components\Toggle::make('workspace_governance.intent_exception_requested')
                    ->label('Request intent exception')
                    ->default(false)
                    ->helperText('Only use when a near-duplicate page must stay separate for a documented reason.'),
                Forms\Components\Textarea::make('workspace_governance.intent_exception_reason')
                    ->label('Exception reason')
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('Required when requesting an intent exception. Explain why this cannot be merged or converted into an H2.'),
                Forms\Components\TextInput::make('workspace_governance.canonical_target')
                    ->label('Canonical target')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Canonical URL or stable route target that this content should consolidate into.'),
                Forms\Components\TextInput::make('workspace_governance.hub_ref')
                    ->label('Hub binding')
                    ->maxLength(255)
                    ->helperText('Topic/Hub reference required for future topology gates.'),
                Forms\Components\TextInput::make('workspace_governance.test_binding')
                    ->label('Test binding')
                    ->maxLength(255)
                    ->helperText('Target test entry or funnel destination for this page.'),
                Forms\Components\TextInput::make('workspace_governance.method_binding')
                    ->label('Method binding')
                    ->maxLength(255)
                    ->helperText('Method page or method key this page must be anchored to.'),
                Forms\Components\Select::make('workspace_governance.author_admin_user_id')
                    ->label('Author')
                    ->native(false)
                    ->default(function (): ?int {
                        $guard = (string) config('admin.guard', 'admin');
                        $user = auth($guard)->user();

                        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
                            return null;
                        }

                        return (int) $user->getAuthIdentifier();
                    })
                    ->options(fn (): array => ContentGovernanceService::adminUserOptions())
                    ->searchable()
                    ->preload()
                    ->helperText('Real author record for governance and later structured-data mapping.'),
                Forms\Components\Select::make('workspace_governance.reviewer_admin_user_id')
                    ->label('Reviewer')
                    ->native(false)
                    ->options(fn (): array => ContentGovernanceService::adminUserOptions())
                    ->searchable()
                    ->preload()
                    ->helperText('Assigned reviewer for editorial and release workflows.'),
            ])
            ->columns(2);
    }
}
