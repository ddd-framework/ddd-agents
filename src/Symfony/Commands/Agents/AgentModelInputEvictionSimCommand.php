<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationCatalog;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationRun;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScenario;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScenarios;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationScoreRow;
use DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation\EvictionSimulationTurn;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Simulates the per-tool eviction pass of loaded skills and tools (`unloadGranularity: tool`): the load carried into
 * every model call (skill bodies and tool schemas, counted separately) stays under a hard ceiling, and under normal
 * load it shrinks gradually — cold capabilities leave, the ones in use stay. Plays scripted scenarios
 * ({@see EvictionSimulationScenario}) over a catalog ({@see EvictionSimulationCatalog}) with the pass the agent runs
 * under that granularity ({@see LoadedCapabilities::computeEviction()}, through {@see EvictionSimulationRun}), and prints
 * per scenario the lifecycle of every capability, the load per turn against the soft target and the hard ceiling, and
 * what left. No database, no account, no model call. The skill-granular unloading (`unloadGranularity: skill`, the
 * module's default) is a different pass that reads no pressure budget; this command does not simulate it.
 *
 * Two pressure levels (the score is `staleness × sizeFactor × pressureFactor`):
 *   - SOFT (`pressureBudgetTokens`): a tool whose score reaches 1 leaves; a skill body left without loaded tools
 *     follows.
 *   - HARD (`hardCapTokens`): while the carried load is above the ceiling, the tool with the highest score past its
 *     minimum residency leaves. Capabilities within their minimum residency stay, so the working set itself can sit
 *     above the ceiling for a while (printed as such).
 *
 *   php bin/console app:ai-agent:context-eviction-sim                       # all scenarios, load curves
 *   php bin/console app:ai-agent:context-eviction-sim --scenario=switch -v  # one scenario + per-turn score tables
 *   php bin/console app:ai-agent:context-eviction-sim --soft=6000 --hard=10000
 */
#[AsCommand(
    name: 'app:ai-agent:context-eviction-sim',
    description: 'Simulate the agent\'s per-tool eviction pass (unloadGranularity: tool): carried load under a ceiling, gradual decay.'
)]
class AgentModelInputEvictionSimCommand extends AgentConsoleCommand
{
    /** @var string The scenario option value that plays every scenario */
    public const string ALL_SCENARIOS = 'all';

    protected function configure(): void
    {
        $this
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, implode(' | ', $this->buildScenarios()->getNames()) . ' | ' . self::ALL_SCENARIOS, self::ALL_SCENARIOS)
            ->addOption('soft', null, InputOption::VALUE_REQUIRED, 'pressureBudgetTokens (soft target)', (string)LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS)
            ->addOption('hard', null, InputOption::VALUE_REQUIRED, 'hardCapTokens (ceiling)', (string)LoadedCapability::DEFAULT_HARD_CAP_TOKENS)
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'referenceResidentTokens', (string)LoadedCapability::DEFAULT_REFERENCE_RESIDENT_TOKENS)
            ->addOption('tool-tokens', null, InputOption::VALUE_REQUIRED, 'unloadToolAfterUnusedTokens', (string)LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS)
            ->addOption('tool-turns', null, InputOption::VALUE_REQUIRED, 'unloadToolAfterUnusedTurns', (string)LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS)
            ->addOption('skill-tokens', null, InputOption::VALUE_REQUIRED, 'unloadSkillAfterUnusedTokens', (string)LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS)
            ->addOption('skill-turns', null, InputOption::VALUE_REQUIRED, 'unloadSkillAfterUnusedTurns', (string)LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS)
            ->addOption('min-residency', null, InputOption::VALUE_REQUIRED, 'minResidencyTurns', (string)LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS)
            ->addOption('eager', null, InputOption::VALUE_NONE, 'Load all tools of a skill with the skill (default: a tool loads on its first use, an always-loaded tool with its skill)');
    }

    /**
     * Plays the selected scenarios and prints each.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws InvalidOptionException when `--scenario` names no scenario
     * @throws InvalidArgumentException when a scenario names a capability the catalog lacks
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->buildSettingsFromInput($input);
        $output->writeln(sprintf(
            '<info>Eviction simulator</info> — per-tool pass (unloadGranularity: tool) · soft=%d hard=%d ref=%d tool=%d/%dt skill=%d/%dt minRes=%d',
            $settings->pressureBudgetTokens,
            $settings->hardCapTokens,
            $settings->referenceResidentTokens,
            $settings->unloadToolAfterUnusedTokens,
            $settings->unloadToolAfterUnusedTurns,
            $settings->unloadSkillAfterUnusedTokens,
            $settings->unloadSkillAfterUnusedTurns,
            $settings->minResidencyTurns
        ));
        $output->writeln('  loadToolsOnSkillLoad: ' . ($settings->loadToolsOnSkillLoad ? 'true → EAGER (all of a skill\'s tools on load)' : 'false → LAZY (a tool loads on first use)'));

        $evictionSimulationScenarios = $this->buildScenarios();
        $selectedScenarioName = (string)$input->getOption('scenario');
        if ($selectedScenarioName !== self::ALL_SCENARIOS && $evictionSimulationScenarios->getByName($selectedScenarioName) === null) {
            throw new InvalidOptionException("--scenario must be one of: " . implode(', ', $evictionSimulationScenarios->getNames()) . ', ' . self::ALL_SCENARIOS);
        }
        $evictionSimulationCatalog = $this->buildCatalog();
        foreach ($evictionSimulationScenarios->getElements() as $evictionSimulationScenario) {
            $scenarioName = $evictionSimulationScenario->name;
            if ($selectedScenarioName !== self::ALL_SCENARIOS && $selectedScenarioName !== $scenarioName) {
                continue;
            }
            $evictionSimulationRun = EvictionSimulationRun::forScenario($evictionSimulationCatalog, $evictionSimulationScenario, $settings)->play();
            if ($output->isVerbose() && $selectedScenarioName === $scenarioName) {
                $this->renderScoreTables($evictionSimulationRun, $output);
            }
            $this->renderRun($evictionSimulationRun, $output);
        }
        return Command::SUCCESS;
    }

    /**
     * The scenarios the command can play, one per name. Override it (with {@see buildCatalog()}) to simulate an
     * application's own conversations.
     *
     * @return EvictionSimulationScenarios
     */
    protected function buildScenarios(): EvictionSimulationScenarios
    {
        return EvictionSimulationScenario::buildBuiltInScenarios();
    }

    /**
     * The catalog the scenarios load from. Override it to simulate an application's own skills.
     *
     * @return EvictionSimulationCatalog
     */
    protected function buildCatalog(): EvictionSimulationCatalog
    {
        return EvictionSimulationCatalog::buildBuiltIn();
    }

    /**
     * @param InputInterface $input
     * @return ContextRetentionSettings the eviction thresholds and the loading mode the options name
     */
    protected function buildSettingsFromInput(InputInterface $input): ContextRetentionSettings
    {
        $settings = new ContextRetentionSettings();
        $settings->unloadGranularity = ContextRetentionSettings::UNLOAD_GRANULARITY_TOOL;
        $settings->pressureBudgetTokens = (int)$input->getOption('soft');
        $settings->hardCapTokens = (int)$input->getOption('hard');
        $settings->referenceResidentTokens = (int)$input->getOption('ref');
        $settings->unloadToolAfterUnusedTokens = (int)$input->getOption('tool-tokens');
        $settings->unloadToolAfterUnusedTurns = (int)$input->getOption('tool-turns');
        $settings->unloadSkillAfterUnusedTokens = (int)$input->getOption('skill-tokens');
        $settings->unloadSkillAfterUnusedTurns = (int)$input->getOption('skill-turns');
        $settings->minResidencyTurns = (int)$input->getOption('min-residency');
        $settings->loadToolsOnSkillLoad = (bool)$input->getOption('eager');
        return $settings;
    }

    /**
     * The lifecycle matrix of one run: one row per capability ever loaded (catalog order), one column per turn
     * (L = loaded, * = used, = resident, X = left), the turn each left with its reason, then the token rows.
     *
     * @param EvictionSimulationRun $evictionSimulationRun
     * @param OutputInterface $output
     * @return void
     */
    protected function renderRun(EvictionSimulationRun $evictionSimulationRun, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>══ scenario: ' . $evictionSimulationRun->scenario->name . ' ══</info>  (L=load  *=used  ==resident  X=unload)');

        $everLoadedCapabilityNames = $evictionSimulationRun->getEverLoadedCapabilityNamesInCatalogOrder();
        $turnIndexes = [];
        $cellsByCapabilityName = [];
        $lastUnloadByCapabilityName = [];
        $everLoadedSoFar = [];
        foreach ($evictionSimulationRun->turns->getElements() as $evictionSimulationTurn) {
            $turnIndex = $evictionSimulationTurn->turnIndex;
            $turnIndexes[] = $turnIndex;
            foreach ($evictionSimulationTurn->loadedCapabilityNames as $loadedCapabilityName) {
                $everLoadedSoFar[$loadedCapabilityName] = true;
                unset($lastUnloadByCapabilityName[$loadedCapabilityName]); // loaded again: the earlier unload no longer holds
            }
            foreach ($everLoadedCapabilityNames as $capabilityName) {
                $cellsByCapabilityName[$capabilityName][$turnIndex] = $this->buildLifecycleCell($capabilityName, $evictionSimulationTurn, isset($everLoadedSoFar[$capabilityName]));
                if (isset($evictionSimulationTurn->evictionReasonsByName[$capabilityName])) {
                    $lastUnloadByCapabilityName[$capabilityName] = 't' . $turnIndex . ' (' . $evictionSimulationTurn->evictionReasonsByName[$capabilityName] . ')';
                }
            }
        }

        $tableRows = [];
        foreach ($everLoadedCapabilityNames as $capabilityName) {
            $isToolOfSkill = $evictionSimulationRun->catalog->getByName($capabilityName)?->isToolOfSkill() ?? false;
            $tableRow = [($isToolOfSkill ? '  ' : '') . $capabilityName];
            foreach ($turnIndexes as $turnIndex) {
                $tableRow[] = $cellsByCapabilityName[$capabilityName][$turnIndex] ?? ' ';
            }
            $tableRow[] = $lastUnloadByCapabilityName[$capabilityName] ?? '';
            $tableRows[] = $tableRow;
        }
        $tableRows[] = new TableSeparator();
        $tableRows[] = $this->buildTokenRow('ctxTok', $evictionSimulationRun, 'contextInputTokens');
        $tableRows[] = $this->buildTokenRow('skillTok', $evictionSimulationRun, 'skillTokens');
        $tableRows[] = $this->buildTokenRow('toolTok', $evictionSimulationRun, 'toolTokens');
        $tableRows[] = $this->buildTokenRow('Σ total', $evictionSimulationRun, null);

        $lifecycleTable = new Table($output);
        $lifecycleTable->setHeaders(array_merge(['capability'], array_map('strval', $turnIndexes), ['unload@']));
        $lifecycleTable->setRows($tableRows);
        $lifecycleTable->render();
        $settings = $evictionSimulationRun->settings;
        $output->writeln(sprintf(
            '  peak carried = %d tok · soft target = %d · hard ceiling = %d · %s',
            $evictionSimulationRun->getPeakCarriedTokens(),
            $settings->pressureBudgetTokens ?? LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS,
            $settings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS,
            $evictionSimulationRun->wasEverAboveHardCeiling() ? '<fg=yellow>working set briefly OVER ceiling (active/protected)</>' : '<fg=green>stayed under ceiling</>'
        ));
    }

    /**
     * @param string $capabilityName
     * @param EvictionSimulationTurn $evictionSimulationTurn
     * @param bool $loadedBefore whether the capability was loaded at this turn or before
     * @return string the cell of the capability in the turn's column
     */
    protected function buildLifecycleCell(string $capabilityName, EvictionSimulationTurn $evictionSimulationTurn, bool $loadedBefore): string
    {
        if (!$loadedBefore) {
            return ' ';
        }
        if (isset($evictionSimulationTurn->evictionReasonsByName[$capabilityName])) {
            return '<fg=red>X</>';
        }
        if (!in_array($capabilityName, $evictionSimulationTurn->residentCapabilityNames, true)) {
            return ' ';
        }
        if (in_array($capabilityName, $evictionSimulationTurn->loadedCapabilityNames, true)) {
            return '<fg=cyan>L</>';
        }
        if (in_array($capabilityName, $evictionSimulationTurn->usedCapabilityNames, true)) {
            return '<fg=green>*</>';
        }
        return '=';
    }

    /**
     * @param string $label
     * @param EvictionSimulationRun $evictionSimulationRun
     * @param string|null $turnFieldName `contextInputTokens`, `skillTokens` or `toolTokens`; null = the carried total
     * @return string[] the label, one value per turn, an empty unload cell
     */
    protected function buildTokenRow(string $label, EvictionSimulationRun $evictionSimulationRun, ?string $turnFieldName): array
    {
        $tokenRow = ['<comment>' . $label . '</comment>'];
        foreach ($evictionSimulationRun->turns->getElements() as $evictionSimulationTurn) {
            $tokenRow[] = (string)match ($turnFieldName) {
                'contextInputTokens' => $evictionSimulationTurn->contextInputTokens,
                'skillTokens' => $evictionSimulationTurn->skillTokens,
                'toolTokens' => $evictionSimulationTurn->toolTokens,
                default => $evictionSimulationTurn->getCarriedTokens(),
            };
        }
        $tokenRow[] = '';
        return $tokenRow;
    }

    /**
     * The eviction figures of every loaded capability before each turn's pass.
     *
     * @param EvictionSimulationRun $evictionSimulationRun
     * @param OutputInterface $output
     * @return void
     */
    protected function renderScoreTables(EvictionSimulationRun $evictionSimulationRun, OutputInterface $output): void
    {
        foreach ($evictionSimulationRun->turns->getElements() as $evictionSimulationTurn) {
            $output->writeln('  <comment>turn ' . $evictionSimulationTurn->turnIndex . '</comment> (ctx=' . $evictionSimulationTurn->contextInputTokens . ', loadedCap=' . $evictionSimulationTurn->carriedTokensBeforeEviction . ')');
            $scoreTable = new Table($output);
            $scoreTable->setHeaders(['name', 'type', 'resTok', '', 'tokΔ', 'turnΔ', 'stale', 'sizeF', 'pressF', 'score']);
            foreach ($evictionSimulationTurn->scoreRowsBeforeEviction->getElements() as $evictionSimulationScoreRow) {
                $scoreTable->addRow($this->buildScoreTableRow($evictionSimulationScoreRow));
            }
            $scoreTable->render();
        }
    }

    /**
     * @param EvictionSimulationScoreRow $evictionSimulationScoreRow
     * @return string[] the cells of the row in the score table: name, type, resident tokens, `AUTO` for an
     *     always-loaded capability, token and turn distance since the last use, the three factors and the score
     */
    protected function buildScoreTableRow(EvictionSimulationScoreRow $evictionSimulationScoreRow): array
    {
        return [
            $evictionSimulationScoreRow->capabilityName,
            $evictionSimulationScoreRow->capabilityType === LoadedCapability::TYPE_SKILL ? 'skill' : 'tool',
            (string)$evictionSimulationScoreRow->residentTokens,
            $evictionSimulationScoreRow->isAlwaysLoaded ? 'AUTO' : '',
            (string)$evictionSimulationScoreRow->tokensSinceLastUse,
            (string)$evictionSimulationScoreRow->turnsSinceLastUse,
            number_format($evictionSimulationScoreRow->staleness, 2),
            number_format($evictionSimulationScoreRow->sizeFactor, 2),
            number_format($evictionSimulationScoreRow->pressureFactor, 2),
            number_format($evictionSimulationScoreRow->evictionScore, 2),
        ];
    }
}
