<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Models\CustomerSelection;

use DDD\Domain\AI\Entities\Models\CustomerSelection\AgentCustomerCuratedModel;
use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModel;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A curated model pairs an eligible model with its `agentCustomerSelection` block and names its display order; a
 * block without one sorts last.
 */
class AgentCustomerCuratedModelTest extends UnitTestCase
{
    public function testTheDisplayOrderComesFromTheBlockAndAMissingOneSortsLast(): void
    {
        $agentEligibleModel = new AgentEligibleModel();
        $agentEligibleModel->name = 'M.ONE';

        $orderedModel = AgentCustomerCuratedModel::forSelectionConfigAndEligibleModel(['displayOrder' => '3', 'displayName' => 'One'], $agentEligibleModel);
        self::assertSame(3, $orderedModel->getDisplayOrder());
        self::assertSame($agentEligibleModel, $orderedModel->eligibleModel);
        self::assertSame(['displayOrder' => '3', 'displayName' => 'One'], $orderedModel->customerSelectionConfig);

        $unorderedModel = AgentCustomerCuratedModel::forSelectionConfigAndEligibleModel([], $agentEligibleModel);
        self::assertSame(PHP_INT_MAX, $unorderedModel->getDisplayOrder());
    }
}
