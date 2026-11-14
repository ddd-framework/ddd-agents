<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\Base\Entities\ValueObject;
use InvalidArgumentException;

/**
 * One run of the eviction simulator: a scenario played over a catalog with the given settings. Every turn applies the
 * step's loads and its tool use, then asks the per-tool eviction pass of the agent —
 * {@see LoadedCapabilities::computeEviction()}, which the agent runs when `unloadGranularity` is `tool` — what leaves,
 * removes it and records an {@see EvictionSimulationTurn}. The simulator shows what that pass does; it carries no
 * eviction rule of its own. The skill-granular unloading (`unloadGranularity: skill`, the module's default,
 * {@see LoadedCapabilities::computeSkillUnloading()}) is not simulated.
 *
 * Loading follows the agent: loading a skill provisions all its tools when
 * {@see ContextRetentionSettings::$loadToolsOnSkillLoad} is set (eager), otherwise only its tools marked
 * {@see LoadedCapability::$isAlwaysLoaded}; every other tool of a skill loads on its first use. A step that lists a tool
 * of a skill loads it under the same rule; a meta tool loads whenever a step lists it. A step that names a capability
 * the catalog lacks is refused.
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationRun extends ValueObject
{
    /** @var EvictionSimulationCatalog The capabilities the scenario may load */
    public EvictionSimulationCatalog $catalog;

    /** @var EvictionSimulationScenario The scripted conversation */
    public EvictionSimulationScenario $scenario;

    /** @var ContextRetentionSettings The eviction thresholds and the loading mode */
    public ContextRetentionSettings $settings;

    /** @var LoadedCapabilities The capabilities loaded at the current point of the run */
    public LoadedCapabilities $loadedCapabilities;

    /** @var EvictionSimulationTurns What was observed per turn, in order */
    public EvictionSimulationTurns $turns;

    public function __construct()
    {
        parent::__construct();
        $this->turns = new EvictionSimulationTurns();
    }

    /**
     * @param EvictionSimulationCatalog $catalog
     * @param EvictionSimulationScenario $scenario
     * @param ContextRetentionSettings $settings
     * @return static a run not played yet
     */
    public static function forScenario(EvictionSimulationCatalog $catalog, EvictionSimulationScenario $scenario, ContextRetentionSettings $settings): static
    {
        $evictionSimulationRun = new static();
        $evictionSimulationRun->catalog = $catalog;
        $evictionSimulationRun->scenario = $scenario;
        $evictionSimulationRun->settings = $settings;
        $evictionSimulationRun->loadedCapabilities = new LoadedCapabilities();
        return $evictionSimulationRun;
    }

    /**
     * Plays every step of the scenario.
     *
     * @return static
     */
    public function play(): static
    {
        foreach ($this->scenario->steps->getElements() as $evictionSimulationStep) {
            $evictionSimulationTurn = $this->playStep($evictionSimulationStep);
            $this->turns->add($evictionSimulationTurn);
        }
        return $this;
    }

    /**
     * Applies one step's loads and tool use, runs the eviction pass and records the turn.
     *
     * @param EvictionSimulationStep $evictionSimulationStep
     * @return EvictionSimulationTurn
     * @throws InvalidArgumentException when the step names a capability the catalog lacks
     */
    public function playStep(EvictionSimulationStep $evictionSimulationStep): EvictionSimulationTurn
    {
        $evictionSimulationTurn = new EvictionSimulationTurn();
        $evictionSimulationTurn->turnIndex = $evictionSimulationStep->turnIndex;
        $evictionSimulationTurn->contextInputTokens = $evictionSimulationStep->contextInputTokens;

        foreach ($evictionSimulationStep->capabilityNamesToLoad as $capabilityName) {
            $evictionSimulationCapability = $this->getCapabilityOfStep($capabilityName, $evictionSimulationStep);
            if (!$this->isProvisionedWithoutUse($evictionSimulationCapability)) {
                continue; // a tool of a skill loads on its first use
            }
            $this->loadCapability($evictionSimulationCapability, $evictionSimulationStep, $evictionSimulationTurn);
            if ($evictionSimulationCapability->type === LoadedCapability::TYPE_SKILL) {
                $this->provisionToolsOfLoadedSkill($evictionSimulationCapability->name, $evictionSimulationStep, $evictionSimulationTurn);
            }
        }
        if ($evictionSimulationStep->usedToolName !== null) {
            $this->useTool($evictionSimulationStep->usedToolName, $evictionSimulationStep, $evictionSimulationTurn);
        }

        $evictionSimulationTurn->carriedTokensBeforeEviction = $this->loadedCapabilities->getResidentTokens();
        $evictionSimulationTurn->scoreRowsBeforeEviction = $this->buildScoreRows($evictionSimulationStep);
        $evictionSimulationTurn->evictionReasonsByName = $this->evictAndDescribeReasons($evictionSimulationStep);
        foreach ($this->loadedCapabilities->getElements() as $loadedCapability) {
            $evictionSimulationTurn->residentCapabilityNames[] = $loadedCapability->name;
        }
        $evictionSimulationTurn->skillTokens = $this->loadedCapabilities->getResidentTokensOfType(LoadedCapability::TYPE_SKILL);
        $evictionSimulationTurn->toolTokens = $this->loadedCapabilities->getResidentTokensOfType(LoadedCapability::TYPE_TOOL);
        return $evictionSimulationTurn;
    }

    /**
     * Runs {@see LoadedCapabilities::computeEviction()} on the current set, removes what it names and labels each
     * removal: a skill body left without tools (`casc`), a tool whose score reached 1 (its score), a tool the hard
     * ceiling removed (`cap:<score>`). The labels are read before the removal, from the same set and settings.
     *
     * @param EvictionSimulationStep $evictionSimulationStep
     * @return string[] the reason label by evicted capability name
     */
    protected function evictAndDescribeReasons(EvictionSimulationStep $evictionSimulationStep): array
    {
        $contextInputTokens = $evictionSimulationStep->contextInputTokens;
        $turnIndex = $evictionSimulationStep->turnIndex;
        $evictedCapabilityNames = $this->loadedCapabilities->computeEviction($contextInputTokens, $turnIndex, $this->settings);
        $carriedTokensBefore = $this->loadedCapabilities->getResidentTokens();
        $evictionReasonsByName = [];
        foreach ($evictedCapabilityNames as $evictedCapabilityName) {
            $loadedCapability = $this->loadedCapabilities->getByName($evictedCapabilityName);
            if ($loadedCapability === null) {
                continue;
            }
            if ($loadedCapability->type === LoadedCapability::TYPE_SKILL) {
                $evictionReasonsByName[$evictedCapabilityName] = EvictionSimulationTurn::REASON_CASCADE;
                continue;
            }
            $evictionScore = number_format($loadedCapability->evictionScore($contextInputTokens, $turnIndex, $carriedTokensBefore, $this->settings), 2);
            $evictionReasonsByName[$evictedCapabilityName] = $loadedCapability->shouldUnload($contextInputTokens, $turnIndex, $carriedTokensBefore, $this->settings)
                ? $evictionScore
                : EvictionSimulationTurn::REASON_PREFIX_HARD_CEILING . $evictionScore;
        }
        $this->loadedCapabilities->removeByNames(...$evictedCapabilityNames);
        return $evictionReasonsByName;
    }

    /**
     * @param string $capabilityName
     * @param EvictionSimulationStep $evictionSimulationStep
     * @return EvictionSimulationCapability the catalog entry the step names
     * @throws InvalidArgumentException when the catalog lacks it
     */
    protected function getCapabilityOfStep(string $capabilityName, EvictionSimulationStep $evictionSimulationStep): EvictionSimulationCapability
    {
        $evictionSimulationCapability = $this->catalog->getByName($capabilityName);
        if ($evictionSimulationCapability === null) {
            throw new InvalidArgumentException(
                "Scenario '{$this->scenario->name}', turn {$evictionSimulationStep->turnIndex} names '{$capabilityName}', which the catalog lacks."
            );
        }
        return $evictionSimulationCapability;
    }

    /**
     * Whether a capability loads without being used: a skill body and a meta tool always; a tool of a skill when tools
     * load with their skill (eager) or when it is marked always-loaded.
     *
     * @param EvictionSimulationCapability $evictionSimulationCapability
     * @return bool
     */
    protected function isProvisionedWithoutUse(EvictionSimulationCapability $evictionSimulationCapability): bool
    {
        if (!$evictionSimulationCapability->isToolOfSkill()) {
            return true;
        }
        return $this->settings->loadToolsOnSkillLoad === true || $evictionSimulationCapability->isAlwaysLoaded;
    }

    /**
     * Loads the tools a skill load provisions: all its tools when eager, its always-loaded tools when lazy. A tool
     * already loaded keeps its state.
     *
     * @param string $skillName
     * @param EvictionSimulationStep $evictionSimulationStep
     * @param EvictionSimulationTurn $evictionSimulationTurn
     * @return void
     */
    protected function provisionToolsOfLoadedSkill(string $skillName, EvictionSimulationStep $evictionSimulationStep, EvictionSimulationTurn $evictionSimulationTurn): void
    {
        foreach ($this->catalog->getToolsOfSkill($skillName)->getElements() as $evictionSimulationCapability) {
            if (!$this->isProvisionedWithoutUse($evictionSimulationCapability) || $this->loadedCapabilities->getByName($evictionSimulationCapability->name) !== null) {
                continue;
            }
            $this->loadCapability($evictionSimulationCapability, $evictionSimulationStep, $evictionSimulationTurn);
        }
    }

    /**
     * Loads a capability (again): a former copy of it is replaced.
     *
     * @param EvictionSimulationCapability $evictionSimulationCapability
     * @param EvictionSimulationStep $evictionSimulationStep
     * @param EvictionSimulationTurn $evictionSimulationTurn
     * @return void
     */
    protected function loadCapability(EvictionSimulationCapability $evictionSimulationCapability, EvictionSimulationStep $evictionSimulationStep, EvictionSimulationTurn $evictionSimulationTurn): void
    {
        $this->loadedCapabilities->removeByNames($evictionSimulationCapability->name);
        // the set takes its elements by reference: the new capability is a variable first
        $loadedCapability = $evictionSimulationCapability->buildLoadedCapability($evictionSimulationStep->contextInputTokens, $evictionSimulationStep->turnIndex);
        $this->loadedCapabilities->add($loadedCapability);
        $evictionSimulationTurn->loadedCapabilityNames[] = $evictionSimulationCapability->name;
    }

    /**
     * Uses a tool: loads it on first use, marks it and its loaded skill as used.
     *
     * @param string $toolName
     * @param EvictionSimulationStep $evictionSimulationStep
     * @param EvictionSimulationTurn $evictionSimulationTurn
     * @return void
     * @throws InvalidArgumentException when the catalog lacks the tool
     */
    protected function useTool(string $toolName, EvictionSimulationStep $evictionSimulationStep, EvictionSimulationTurn $evictionSimulationTurn): void
    {
        $evictionSimulationCapability = $this->getCapabilityOfStep($toolName, $evictionSimulationStep);
        if ($this->loadedCapabilities->getByName($toolName) === null) {
            $this->loadCapability($evictionSimulationCapability, $evictionSimulationStep, $evictionSimulationTurn);
        }
        $this->loadedCapabilities->getByName($toolName)?->markUsedAt($evictionSimulationStep->contextInputTokens, $evictionSimulationStep->turnIndex);
        $evictionSimulationTurn->usedCapabilityNames[] = $toolName;
        $parentSkillName = $evictionSimulationCapability->parentSkillName;
        $parentSkill = $parentSkillName !== null ? $this->loadedCapabilities->getByName($parentSkillName) : null;
        if ($parentSkill !== null) {
            $parentSkill->markUsedAt($evictionSimulationStep->contextInputTokens, $evictionSimulationStep->turnIndex);
            $evictionSimulationTurn->usedCapabilityNames[] = $parentSkillName;
        }
    }

    /**
     * The eviction figures of every loaded capability at this step, before the pass, highest score first
     * ({@see EvictionSimulationTurn::$scoreRowsBeforeEviction}).
     *
     * @param EvictionSimulationStep $evictionSimulationStep
     * @return EvictionSimulationScoreRows
     */
    protected function buildScoreRows(EvictionSimulationStep $evictionSimulationStep): EvictionSimulationScoreRows
    {
        $carriedTokens = $this->loadedCapabilities->getResidentTokens();
        $evictionSimulationScoreRows = new EvictionSimulationScoreRows();
        foreach ($this->loadedCapabilities->getElements() as $loadedCapability) {
            $evictionSimulationScoreRow = EvictionSimulationScoreRow::forLoadedCapability(
                $loadedCapability,
                $evictionSimulationStep->contextInputTokens,
                $evictionSimulationStep->turnIndex,
                $carriedTokens,
                $this->settings
            );
            $evictionSimulationScoreRows->add($evictionSimulationScoreRow);
        }
        $evictionSimulationScoreRows->sortByEvictionScoreDescending();
        return $evictionSimulationScoreRows;
    }

    /** @return int the highest load carried after a turn */
    public function getPeakCarriedTokens(): int
    {
        $peakCarriedTokens = 0;
        foreach ($this->turns->getElements() as $evictionSimulationTurn) {
            $peakCarriedTokens = max($peakCarriedTokens, $evictionSimulationTurn->getCarriedTokens());
        }
        return $peakCarriedTokens;
    }

    /** @return bool whether the load carried after some turn stayed above the hard ceiling (only fresh capabilities left) */
    public function wasEverAboveHardCeiling(): bool
    {
        $hardCapTokens = $this->settings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS;
        foreach ($this->turns->getElements() as $evictionSimulationTurn) {
            if ($evictionSimulationTurn->getCarriedTokens() > $hardCapTokens) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] every capability loaded at some turn, in catalog order */
    public function getEverLoadedCapabilityNamesInCatalogOrder(): array
    {
        $everLoadedNames = [];
        foreach ($this->turns->getElements() as $evictionSimulationTurn) {
            foreach ($evictionSimulationTurn->loadedCapabilityNames as $loadedCapabilityName) {
                $everLoadedNames[$loadedCapabilityName] = true;
            }
        }
        return array_values(array_filter(
            $this->catalog->getCapabilityNames(),
            static fn (string $capabilityName): bool => isset($everLoadedNames[$capabilityName])
        ));
    }
}
