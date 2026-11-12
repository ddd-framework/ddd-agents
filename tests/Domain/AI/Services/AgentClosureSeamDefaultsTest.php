<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services;

use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Models\CustomerSelection\AgentCustomerSelectableModel;
use DDD\Domain\AI\Entities\Models\CustomerSelection\AgentCustomerSelectableModels;
use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModel;
use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModels;
use DDD\Domain\AI\Services\Eval\AgentEvalOutboundGuardHooksService;
use DDD\Domain\AI\Services\Mandates\AgentMandateRevocationEffectsService;
use DDD\Domain\Base\Entities\Entity;
use PHPUnit\Framework\TestCase;

/**
 * The "no application" defaults of the application seams (mandate revocation effects, the eval outbound guard's
 * application part, the customer-selectable models): an application without an override runs the core unchanged.
 */
class AgentClosureSeamDefaultsTest extends TestCase
{
    public function testARevokedMandateChangesNothingBeyondItsRowByDefault(): void
    {
        $agentMandate = new AgentMandate();
        $agentMandate->actionClass = AgentMandate::ACTION_STANDING_AUTOMATION;
        $agentMandate->accountId = 7;
        (new AgentMandateRevocationEffectsService())->onMandateRevoked($agentMandate);
        self::assertSame(AgentMandate::ACTION_STANDING_AUTOMATION, $agentMandate->actionClass);
    }

    public function testTheGuardKnowsNoEvalAccountNoScopeOwnerAndNoThrowawayScopeByDefault(): void
    {
        $agentEvalOutboundGuardHooksService = new AgentEvalOutboundGuardHooksService();
        $scope = new class extends Entity {
        };
        $scope->id = 21;
        self::assertSame([], $agentEvalOutboundGuardHooksService->findConfiguredEvalAccountIds());
        self::assertNull($agentEvalOutboundGuardHooksService->findScopeAccountId($scope));
        self::assertFalse($agentEvalOutboundGuardHooksService->isThrowawayScope($scope));
        self::assertSame([], $agentEvalOutboundGuardHooksService->findAdditionalAllowedPhoneNumbers());
    }

    public function testThePhoneNumberIsNormalizedToDigitsAndPlusByDefault(): void
    {
        self::assertSame('+4930123456', (new AgentEvalOutboundGuardHooksService())->normalizePhoneNumber(' +49 (30) 123-456 '));
        self::assertSame('', (new AgentEvalOutboundGuardHooksService())->normalizePhoneNumber('no number'));
    }

    public function testTheSelectableModelsAreTheCuratedEligibleModelsInDisplayOrder(): void
    {
        $agentEligibleModels = new AgentEligibleModels();
        foreach ([['M.CHEAP', 50, 120.0, 1.0], ['M.STRONG', 95, 30.0, 4.0], ['M.UNCURATED', 99, 200.0, 0.5]] as [$name, $agenticScore, $tokensPerSecond, $blendedCost]) {
            $agentEligibleModel = new AgentEligibleModel();
            $agentEligibleModel->name = $name;
            $agentEligibleModel->agenticScore = $agenticScore;
            $agentEligibleModel->tokensPerSecond = $tokensPerSecond;
            $agentEligibleModel->blendedCostPer1MUsd = $blendedCost;
            $agentEligibleModels->add($agentEligibleModel);
        }
        $modelsConfig = [
            'M.CHEAP' => ['agentCustomerSelection' => ['displayOrder' => 2, 'displayName' => 'Cheap']],
            'M.STRONG' => ['agentCustomerSelection' => ['displayOrder' => 1, 'displayName' => 'Strong']],
        ];

        $agentCustomerSelectableModels = AgentCustomerSelectableModels::fromAgentEligibleModels($agentEligibleModels, $modelsConfig);

        $names = array_map(static fn (AgentCustomerSelectableModel $model): string => $model->name, $agentCustomerSelectableModels->getElements());
        self::assertSame(['M.STRONG', 'M.CHEAP'], $names);
        self::assertSame(4.0, $agentCustomerSelectableModels->getElements()[0]->creditsMultiplier);
        self::assertSame('M.CHEAP', $agentCustomerSelectableModels->getModelNameIfSelectable('M.CHEAP'));
        self::assertNull($agentCustomerSelectableModels->getModelNameIfSelectable('M.UNCURATED'));
    }
}
