<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationCatalog;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationRun;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScenario;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScoreRow;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScoreRows;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The score rows of a turn carry the figures the agent's per-tool pass reads, one row per loaded capability, highest
 * eviction score first.
 */
class EvictionSimulationScoreRowsTest extends UnitTestCase
{
    public function testARowCarriesTheFiguresOfTheLoadedCapability(): void
    {
        $settings = $this->buildSettings();
        $loadedCapability = EvictionSimulationCatalog::buildBuiltIn()->getByName('listTickets')?->buildLoadedCapability(4000, 2);
        self::assertInstanceOf(LoadedCapability::class, $loadedCapability);

        $evictionSimulationScoreRow = EvictionSimulationScoreRow::forLoadedCapability($loadedCapability, 9000, 6, 12000, $settings);

        self::assertSame('listTickets', $evictionSimulationScoreRow->capabilityName);
        self::assertSame(LoadedCapability::TYPE_TOOL, $evictionSimulationScoreRow->capabilityType);
        self::assertSame(1100, $evictionSimulationScoreRow->residentTokens);
        self::assertFalse($evictionSimulationScoreRow->isAlwaysLoaded);
        self::assertSame(5000, $evictionSimulationScoreRow->tokensSinceLastUse);
        self::assertSame(4, $evictionSimulationScoreRow->turnsSinceLastUse);
        self::assertSame($loadedCapability->staleness(9000, 6, $settings), $evictionSimulationScoreRow->staleness);
        self::assertSame($loadedCapability->sizeFactor($settings), $evictionSimulationScoreRow->sizeFactor);
        self::assertSame($loadedCapability->pressureFactor(12000, $settings), $evictionSimulationScoreRow->pressureFactor);
        self::assertSame($loadedCapability->evictionScore(9000, 6, 12000, $settings), $evictionSimulationScoreRow->evictionScore);
    }

    public function testRowsAreOrderedHighestScoreFirstAndAnEqualPrintedScoreKeepsItsOrder(): void
    {
        $evictionSimulationScoreRows = new EvictionSimulationScoreRows();
        foreach (['first' => 0.4, 'second' => 1.2, 'third' => 0.401, 'fourth' => 0.9] as $capabilityName => $evictionScore) {
            $evictionSimulationScoreRow = new EvictionSimulationScoreRow();
            $evictionSimulationScoreRow->capabilityName = $capabilityName;
            $evictionSimulationScoreRow->evictionScore = $evictionScore;
            $evictionSimulationScoreRows->add($evictionSimulationScoreRow);
        }

        $evictionSimulationScoreRows->sortByEvictionScoreDescending();

        $orderedNames = [];
        foreach ($evictionSimulationScoreRows->getElements() as $evictionSimulationScoreRow) {
            $orderedNames[] = $evictionSimulationScoreRow->capabilityName;
        }
        self::assertSame(['second', 'fourth', 'first', 'third'], $orderedNames);
        self::assertSame(0.9, $evictionSimulationScoreRows->getByCapabilityName('fourth')?->evictionScore);
    }

    public function testATurnHasOneRowPerCapabilityLoadedBeforeItsPass(): void
    {
        $evictionSimulationRun = EvictionSimulationRun::forScenario(
            EvictionSimulationCatalog::buildBuiltIn(),
            EvictionSimulationScenario::buildFloodScenario(),
            $this->buildSettings()
        )->play();

        foreach ($evictionSimulationRun->turns->getElements() as $evictionSimulationTurn) {
            $scoreRows = $evictionSimulationTurn->scoreRowsBeforeEviction;
            $rowTokens = 0;
            $previousScore = PHP_FLOAT_MAX;
            foreach ($scoreRows->getElements() as $evictionSimulationScoreRow) {
                $rowTokens += $evictionSimulationScoreRow->residentTokens;
                self::assertLessThanOrEqual(round($previousScore, 2), round($evictionSimulationScoreRow->evictionScore, 2), "turn {$evictionSimulationTurn->turnIndex}");
                $previousScore = $evictionSimulationScoreRow->evictionScore;
            }
            self::assertSame($evictionSimulationTurn->carriedTokensBeforeEviction, $rowTokens, "turn {$evictionSimulationTurn->turnIndex}");
            foreach ($evictionSimulationTurn->getEvictedCapabilityNames() as $evictedCapabilityName) {
                self::assertNotNull($scoreRows->getByCapabilityName($evictedCapabilityName), "an evicted capability was loaded before the pass, turn {$evictionSimulationTurn->turnIndex}");
            }
        }
    }

    protected function buildSettings(): ContextRetentionSettings
    {
        $settings = new ContextRetentionSettings();
        $settings->pressureBudgetTokens = 6000;
        $settings->hardCapTokens = 10000;
        $settings->referenceResidentTokens = LoadedCapability::DEFAULT_REFERENCE_RESIDENT_TOKENS;
        $settings->unloadToolAfterUnusedTokens = LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS;
        $settings->unloadToolAfterUnusedTurns = LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS;
        $settings->unloadSkillAfterUnusedTokens = LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS;
        $settings->unloadSkillAfterUnusedTurns = LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS;
        $settings->minResidencyTurns = LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS;
        $settings->loadToolsOnSkillLoad = false;
        return $settings;
    }
}
