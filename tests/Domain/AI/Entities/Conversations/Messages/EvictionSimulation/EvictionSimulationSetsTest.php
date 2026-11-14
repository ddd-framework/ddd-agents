<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationCatalog;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScenario;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScenarios;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationStep;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationSteps;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Tests\Agents\Support\UnitTestCase;
use InvalidArgumentException;

/**
 * The sets of the eviction simulator hold one element per key: a capability per name, a step per turn, a scenario per
 * name; they keep the order elements were added in.
 */
class EvictionSimulationSetsTest extends UnitTestCase
{
    public function testTheCatalogFindsACapabilityByNameAndReplacesOneOfTheSameNameInPlace(): void
    {
        $evictionSimulationCatalog = new EvictionSimulationCatalog();
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('Archive.Search', LoadedCapability::TYPE_SKILL, 1000, false, null));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('searchArchive', LoadedCapability::TYPE_TOOL, 400, false, 'Archive.Search'));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('Archive.Search', LoadedCapability::TYPE_SKILL, 1500, false, null));

        self::assertSame(['Archive.Search', 'searchArchive'], $evictionSimulationCatalog->getCapabilityNames());
        self::assertSame(1500, $evictionSimulationCatalog->getByName('Archive.Search')?->residentTokens);
        self::assertNull($evictionSimulationCatalog->getByName('readArchiveEntry'));
    }

    public function testTheToolsOfASkillAreASetInCatalogOrder(): void
    {
        $toolsOfSkill = EvictionSimulationCatalog::buildBuiltIn()->getToolsOfSkill('Documents.Search');

        self::assertSame(['searchDocuments', 'compareDocuments', 'listFolders', 'listTags'], $toolsOfSkill->getNames());
        self::assertSame('Documents.Search', $toolsOfSkill->getByName('listTags')?->parentSkillName);
        self::assertNull($toolsOfSkill->getByName('getEvents'), 'a tool of another skill is not in the set');
    }

    public function testAScenarioHasOneStepPerTurn(): void
    {
        $evictionSimulationSteps = new EvictionSimulationSteps();
        $evictionSimulationSteps->addStep(EvictionSimulationStep::forTurn(1, 1000, null, 'first'));
        $evictionSimulationSteps->addStep(EvictionSimulationStep::forTurn(2, 1100, null, 'second'));

        self::assertSame('second', $evictionSimulationSteps->getByTurnIndex(2)?->note);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Turn 2 has a step already; every turn has one step.');
        $evictionSimulationSteps->addStep(EvictionSimulationStep::forTurn(2, 1200, null, 'again'));
    }

    public function testTheBuiltInScenariosAreListedByNameInPlayingOrder(): void
    {
        $evictionSimulationScenarios = EvictionSimulationScenario::buildBuiltInScenarios();

        self::assertSame(['steady', 'switch', 'paste', 'flood', 'interleaved', 'revisit', 'longhaul', 'idle'], $evictionSimulationScenarios->getNames());
        self::assertSame('flood', $evictionSimulationScenarios->getByName('flood')?->name);
        self::assertSame(16, $evictionSimulationScenarios->getByName('flood')?->steps->count());
        self::assertNull($evictionSimulationScenarios->getByName('stedy'));
    }

    public function testAScenarioNameIsListedOnce(): void
    {
        $evictionSimulationScenarios = (new EvictionSimulationScenarios())->addScenario(EvictionSimulationScenario::buildIdleScenario());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A scenario named 'idle' is listed already.");
        $evictionSimulationScenarios->addScenario(EvictionSimulationScenario::buildIdleScenario());
    }
}
