<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationCatalog;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationRun;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScenario;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationStep;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationSteps;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationTurn;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Tests\Agents\Support\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The eviction simulator shows what the agent does: every turn of every built-in scenario evicts exactly what
 * {@see LoadedCapabilities::computeEviction()} names for the same set, and the hard ceiling stops as soon as the
 * carried load is under it.
 */
class EvictionSimulationRunTest extends UnitTestCase
{
    /** @return array<string, array{int, int, bool}> soft target, hard ceiling, tools loaded with their skill */
    public static function provideCeilings(): array
    {
        return [
            'defaults, lazy' => [LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS, LoadedCapability::DEFAULT_HARD_CAP_TOKENS, false],
            'ceiling 10k, lazy' => [6000, 10000, false],
            'ceiling 5k, lazy' => [3000, 5000, false],
            'defaults, eager' => [LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS, LoadedCapability::DEFAULT_HARD_CAP_TOKENS, true],
            'ceiling 10k, eager' => [6000, 10000, true],
        ];
    }

    #[DataProvider('provideCeilings')]
    public function testEveryTurnEvictsWhatTheAgentsPerToolPassNames(int $pressureBudgetTokens, int $hardCapTokens, bool $loadToolsOnSkillLoad): void
    {
        $settings = $this->buildSettings($pressureBudgetTokens, $hardCapTokens);
        $settings->loadToolsOnSkillLoad = $loadToolsOnSkillLoad;
        $evictionSimulationCatalog = EvictionSimulationCatalog::buildBuiltIn();
        foreach (EvictionSimulationScenario::buildBuiltInScenarios()->getElements() as $evictionSimulationScenario) {
            $scenarioName = $evictionSimulationScenario->name;
            $evictionSimulationRun = EvictionSimulationRun::forScenario($evictionSimulationCatalog, $evictionSimulationScenario, $settings);
            foreach ($evictionSimulationScenario->steps->getElements() as $evictionSimulationStep) {
                $expectedEvictedNames = $this->computeEvictionAfterStep($evictionSimulationRun->loadedCapabilities, $evictionSimulationCatalog, $evictionSimulationStep, $settings);
                $evictionSimulationTurn = $evictionSimulationRun->playStep($evictionSimulationStep);
                $evictedNames = $evictionSimulationTurn->getEvictedCapabilityNames();
                sort($expectedEvictedNames);
                sort($evictedNames);
                self::assertSame($expectedEvictedNames, $evictedNames, "{$scenarioName}, turn {$evictionSimulationStep->turnIndex}");
            }
        }
    }

    public function testTheHardCeilingStopsAfterTheOneEvictionThatBringsTheLoadUnderIt(): void
    {
        $evictionSimulationCatalog = new EvictionSimulationCatalog();
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('Archive.Search', LoadedCapability::TYPE_SKILL, 1000, false, null));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('searchArchive', LoadedCapability::TYPE_TOOL, 2500, false, 'Archive.Search'));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('listArchiveFolders', LoadedCapability::TYPE_TOOL, 200, false, 'Archive.Search'));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('readArchiveEntry', LoadedCapability::TYPE_TOOL, 500, false, 'Archive.Search'));
        $evictionSimulationScenario = EvictionSimulationScenario::forSteps('one eviction', $this->buildSteps(
            EvictionSimulationStep::forTurn(1, 1000, 'searchArchive', 'load + use the large tool', ['Archive.Search']),
            EvictionSimulationStep::forTurn(2, 1100, 'listArchiveFolders', 'use the small tool'),
            EvictionSimulationStep::forTurn(3, 1200, 'readArchiveEntry', 'use the third tool: the load passes the ceiling'),
        ));
        // no soft eviction: the horizons are far away; the ceiling sits between the load with and without the large tool
        $settings = $this->buildSettings(1000000, 4000);
        $settings->unloadToolAfterUnusedTokens = 1000000;
        $settings->unloadToolAfterUnusedTurns = 1000;

        $evictionSimulationRun = EvictionSimulationRun::forScenario($evictionSimulationCatalog, $evictionSimulationScenario, $settings)->play();
        $lastTurn = $evictionSimulationRun->turns->getByTurnIndex(3);

        self::assertSame(['searchArchive'], $lastTurn->getEvictedCapabilityNames());
        self::assertStringStartsWith(EvictionSimulationTurn::REASON_PREFIX_HARD_CEILING, $lastTurn->evictionReasonsByName['searchArchive']);
        self::assertSame(1700, $lastTurn->getCarriedTokens());
        self::assertFalse($evictionSimulationRun->wasEverAboveHardCeiling());
    }

    public function testALazyRunLoadsAToolOnItsFirstUseOnly(): void
    {
        $evictionSimulationRun = EvictionSimulationRun::forScenario(
            EvictionSimulationCatalog::buildBuiltIn(),
            EvictionSimulationScenario::buildSteadyScenario(),
            $this->buildSettings(LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS, LoadedCapability::DEFAULT_HARD_CAP_TOKENS)
        )->play();

        self::assertSame(['load_skills', 'load_tool', 'delegate', 'Documents.Search', 'searchDocuments'], $evictionSimulationRun->turns->getByTurnIndex(1)->loadedCapabilityNames);
        self::assertSame(['compareDocuments'], $evictionSimulationRun->turns->getByTurnIndex(2)->loadedCapabilityNames);
    }

    public function testAnEagerRunLoadsEveryToolOfASkillWithTheSkill(): void
    {
        $settings = $this->buildSettings(LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS, LoadedCapability::DEFAULT_HARD_CAP_TOKENS);
        $settings->loadToolsOnSkillLoad = true;

        $evictionSimulationRun = EvictionSimulationRun::forScenario(EvictionSimulationCatalog::buildBuiltIn(), EvictionSimulationScenario::buildSteadyScenario(), $settings)->play();

        self::assertSame(
            ['load_skills', 'load_tool', 'delegate', 'Documents.Search', 'searchDocuments', 'compareDocuments', 'listFolders', 'listTags'],
            $evictionSimulationRun->turns->getByTurnIndex(1)->loadedCapabilityNames
        );
        self::assertSame([], $evictionSimulationRun->turns->getByTurnIndex(2)->loadedCapabilityNames, 'the used tool is already loaded');
    }

    public function testALazyRunLoadsAnAlwaysLoadedToolWithItsSkill(): void
    {
        $evictionSimulationCatalog = new EvictionSimulationCatalog();
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('Archive.Search', LoadedCapability::TYPE_SKILL, 1000, false, null));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('searchArchive', LoadedCapability::TYPE_TOOL, 400, true, 'Archive.Search'));
        $evictionSimulationCatalog->addCapability(EvictionSimulationCapability::forEntry('readArchiveEntry', LoadedCapability::TYPE_TOOL, 300, false, 'Archive.Search'));
        $evictionSimulationScenario = EvictionSimulationScenario::forSteps('always loaded', $this->buildSteps(
            EvictionSimulationStep::forTurn(1, 1000, null, 'load the skill only', ['Archive.Search']),
            EvictionSimulationStep::forTurn(2, 1100, null, 'list the always-loaded tool again', ['searchArchive', 'readArchiveEntry']),
        ));

        $evictionSimulationRun = EvictionSimulationRun::forScenario(
            $evictionSimulationCatalog,
            $evictionSimulationScenario,
            $this->buildSettings(LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS, LoadedCapability::DEFAULT_HARD_CAP_TOKENS)
        )->play();

        self::assertSame(['Archive.Search', 'searchArchive'], $evictionSimulationRun->turns->getByTurnIndex(1)->loadedCapabilityNames);
        self::assertSame(['searchArchive'], $evictionSimulationRun->turns->getByTurnIndex(2)->loadedCapabilityNames, 'a listed tool of a skill loads only when always-loaded');
    }

    public function testAStepThatNamesACapabilityTheCatalogLacksIsRefused(): void
    {
        $evictionSimulationScenario = EvictionSimulationScenario::forSteps('typo', $this->buildSteps(
            EvictionSimulationStep::forTurn(1, 1000, 'searchDocument', 'a tool name the catalog does not have'),
        ));
        $evictionSimulationRun = EvictionSimulationRun::forScenario(
            EvictionSimulationCatalog::buildBuiltIn(),
            $evictionSimulationScenario,
            $this->buildSettings(LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS, LoadedCapability::DEFAULT_HARD_CAP_TOKENS)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Scenario 'typo', turn 1 names 'searchDocument', which the catalog lacks.");
        $evictionSimulationRun->play();
    }

    protected function buildSteps(EvictionSimulationStep ...$evictionSimulationSteps): EvictionSimulationSteps
    {
        $steps = new EvictionSimulationSteps();
        foreach ($evictionSimulationSteps as $evictionSimulationStep) {
            $steps->addStep($evictionSimulationStep);
        }
        return $steps;
    }

    protected function buildSettings(int $pressureBudgetTokens, int $hardCapTokens): ContextRetentionSettings
    {
        $settings = new ContextRetentionSettings();
        $settings->pressureBudgetTokens = $pressureBudgetTokens;
        $settings->hardCapTokens = $hardCapTokens;
        $settings->referenceResidentTokens = LoadedCapability::DEFAULT_REFERENCE_RESIDENT_TOKENS;
        $settings->unloadToolAfterUnusedTokens = LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS;
        $settings->unloadToolAfterUnusedTurns = LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS;
        $settings->unloadSkillAfterUnusedTokens = LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS;
        $settings->unloadSkillAfterUnusedTurns = LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS;
        $settings->minResidencyTurns = LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS;
        $settings->loadToolsOnSkillLoad = false;
        return $settings;
    }

    /**
     * The oracle: a copy of the run's set before the step, the step's loads and use applied the way the agent applies
     * them, then the agent's pass.
     *
     * @return string[]
     */
    protected function computeEvictionAfterStep(LoadedCapabilities $loadedCapabilitiesBeforeStep, EvictionSimulationCatalog $evictionSimulationCatalog, EvictionSimulationStep $evictionSimulationStep, ContextRetentionSettings $settings): array
    {
        $loadedCapabilities = new LoadedCapabilities();
        foreach ($loadedCapabilitiesBeforeStep->getElements() as $loadedCapability) {
            $copiedLoadedCapability = clone $loadedCapability;
            $loadedCapabilities->add($copiedLoadedCapability);
        }
        $contextInputTokens = $evictionSimulationStep->contextInputTokens;
        $turnIndex = $evictionSimulationStep->turnIndex;
        foreach ($evictionSimulationStep->capabilityNamesToLoad as $capabilityName) {
            $evictionSimulationCapability = $evictionSimulationCatalog->getByName($capabilityName);
            if ($evictionSimulationCapability === null || !$this->loadsWithoutUse($evictionSimulationCapability, $settings)) {
                continue;
            }
            $loadedCapabilities->removeByNames($capabilityName);
            $loadedCapability = $evictionSimulationCapability->buildLoadedCapability($contextInputTokens, $turnIndex);
            $loadedCapabilities->add($loadedCapability);
            if ($evictionSimulationCapability->type !== LoadedCapability::TYPE_SKILL) {
                continue;
            }
            foreach ($evictionSimulationCatalog->getToolsOfSkill($capabilityName)->getElements() as $toolOfSkill) {
                if ($this->loadsWithoutUse($toolOfSkill, $settings) && $loadedCapabilities->getByName($toolOfSkill->name) === null) {
                    $loadedToolOfSkill = $toolOfSkill->buildLoadedCapability($contextInputTokens, $turnIndex);
                    $loadedCapabilities->add($loadedToolOfSkill);
                }
            }
        }
        $usedToolName = $evictionSimulationStep->usedToolName;
        if ($usedToolName !== null && $loadedCapabilities->getByName($usedToolName) === null) {
            $loadedUsedTool = $evictionSimulationCatalog->getByName($usedToolName)->buildLoadedCapability($contextInputTokens, $turnIndex);
            $loadedCapabilities->add($loadedUsedTool);
        }
        if ($evictionSimulationStep->usedToolName !== null) {
            $loadedCapabilities->getByName($evictionSimulationStep->usedToolName)?->markUsedAt($contextInputTokens, $turnIndex);
            $parentSkillName = $evictionSimulationCatalog->getByName($evictionSimulationStep->usedToolName)?->parentSkillName;
            if ($parentSkillName !== null) {
                $loadedCapabilities->getByName($parentSkillName)?->markUsedAt($contextInputTokens, $turnIndex);
            }
        }
        return $loadedCapabilities->computeEviction($contextInputTokens, $turnIndex, $settings);
    }

    /** The oracle's loading rule: a skill and a meta tool always load; a tool of a skill when eager or always-loaded. */
    protected function loadsWithoutUse(EvictionSimulationCapability $evictionSimulationCapability, ContextRetentionSettings $settings): bool
    {
        return !$evictionSimulationCapability->isToolOfSkill()
            || $settings->loadToolsOnSkillLoad === true
            || $evictionSimulationCapability->isAlwaysLoaded;
    }
}
