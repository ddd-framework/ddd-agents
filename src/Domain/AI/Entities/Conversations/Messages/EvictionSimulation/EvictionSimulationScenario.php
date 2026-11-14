<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A scripted conversation for the eviction simulator: its name and its turns. The built-in scenarios
 * ({@see buildBuiltInScenarios()}) work on the built-in catalog ({@see EvictionSimulationCatalog::buildBuiltIn()}):
 * a skill load loads only the skill body, a tool loads on its first use, and every active span uses several tools of
 * its skill, so no tool is loaded that is never used. Identified by its name ({@see EvictionSimulationScenarios}).
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationScenario extends ValueObject
{
    /** @var string[] The always-loaded meta tools of the built-in catalog */
    public const array META_TOOL_NAMES = ['load_skills', 'load_tool', 'delegate'];

    /** @var string[] The tools of the skill `Documents.Search` */
    public const array DOCUMENT_TOOL_NAMES = ['searchDocuments', 'compareDocuments', 'listFolders', 'listTags'];

    /** @var string[] The tools of the skill `Tickets.Overview` */
    public const array TICKET_TOOL_NAMES = ['getTicketSummary', 'listTickets', 'getTicketTopics'];

    /** @var string[] The tools of the skill `Calendar.Events` */
    public const array CALENDAR_TOOL_NAMES = ['getEvents', 'getCalendars'];

    /** @var string[] The tools of the skill `Notes.Drafts` */
    public const array NOTE_TOOL_NAMES = ['getNotes', 'createNote'];

    /** @var string The scenario name */
    public string $name = '';

    /** @var EvictionSimulationSteps The turns in order, one step per turn number */
    public EvictionSimulationSteps $steps;

    public function __construct()
    {
        parent::__construct();
        $this->steps = new EvictionSimulationSteps();
    }

    /**
     * @param string $name
     * @param EvictionSimulationSteps $steps
     * @return static
     */
    public static function forSteps(string $name, EvictionSimulationSteps $steps): static
    {
        $evictionSimulationScenario = new static();
        $evictionSimulationScenario->name = $name;
        $evictionSimulationScenario->steps = $steps;
        return $evictionSimulationScenario;
    }

    /**
     * @param string $scenarioName
     * @return string the key of the scenario of that name in an {@see EvictionSimulationScenarios}
     */
    public static function uniqueKeyForName(string $scenarioName): string
    {
        return self::class . '_' . $scenarioName;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForName($this->name);
    }

    /**
     * The built-in scenarios: steady, switch, paste, flood, interleaved, revisit, longhaul, idle.
     *
     * @return EvictionSimulationScenarios
     */
    public static function buildBuiltInScenarios(): EvictionSimulationScenarios
    {
        return (new EvictionSimulationScenarios())
            ->addScenario(static::buildSteadyScenario())
            ->addScenario(static::buildSwitchScenario())
            ->addScenario(static::buildPasteScenario())
            ->addScenario(static::buildFloodScenario())
            ->addScenario(static::buildInterleavedScenario())
            ->addScenario(static::buildRevisitScenario())
            ->addScenario(static::buildLonghaulScenario())
            ->addScenario(static::buildIdleScenario());
    }

    /** @return string[] the meta tools and the body of `Documents.Search`: the first load of every scenario */
    protected static function getFirstLoadNames(): array
    {
        return [...self::META_TOOL_NAMES, 'Documents.Search'];
    }

    /**
     * STEADY — one skill worked across its tools, then settling on one tool; the tools that went idle early cool
     * down and leave.
     *
     * @return static
     */
    public static function buildSteadyScenario(): static
    {
        $usedToolNames = ['searchDocuments', 'compareDocuments', 'listFolders', 'listTags', 'searchDocuments', 'compareDocuments'];
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use searchDocuments', static::getFirstLoadNames()));
        for ($turnIndex = 2; $turnIndex <= 18; $turnIndex++) {
            $usedToolName = $usedToolNames[$turnIndex - 1] ?? 'searchDocuments';
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 2500 + ($turnIndex - 1) * 1300, $usedToolName, 'use ' . $usedToolName));
        }
        return static::forSteps('steady', $steps);
    }

    /**
     * SWITCH — topic after topic; each phase loads a skill and works several of its tools, then the cold topic (body
     * and its used tools) leaves as the next one loads.
     *
     * @return static
     */
    public static function buildSwitchScenario(): static
    {
        $phases = [
            [1, 'Documents.Search', self::DOCUMENT_TOOL_NAMES],
            [5, 'Tickets.Overview', self::TICKET_TOOL_NAMES],
            [9, 'Calendar.Events', self::CALENDAR_TOOL_NAMES],
            [13, 'Notes.Drafts', self::NOTE_TOOL_NAMES],
        ];
        $steps = new EvictionSimulationSteps();
        foreach ($phases as $phaseIndex => [$firstTurnIndex, $skillName, $toolNames]) {
            for ($turnIndex = $firstTurnIndex; $turnIndex <= $firstTurnIndex + 3; $turnIndex++) {
                $usedToolName = $toolNames[($turnIndex - $firstTurnIndex) % count($toolNames)];
                $contextInputTokens = 2500 + ($turnIndex - 1) * 1500;
                if ($turnIndex === $firstTurnIndex) {
                    $loadNames = $phaseIndex === 0 ? static::getFirstLoadNames() : [$skillName];
                    $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, $contextInputTokens, $usedToolName, 'SWITCH → load ' . $skillName . ' + use ' . $usedToolName, $loadNames));
                    continue;
                }
                $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, $contextInputTokens, $usedToolName, 'use ' . $usedToolName));
            }
        }
        return static::forSteps('switch', $steps);
    }

    /**
     * PASTE — two skills worked, then a 12k paste by the owner pushes the cold first skill out in one turn; the work
     * continues on the second skill.
     *
     * @return static
     */
    public static function buildPasteScenario(): static
    {
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use', static::getFirstLoadNames()));
        $steps->addStep(EvictionSimulationStep::forTurn(2, 4200, 'compareDocuments', 'use compareDocuments'));
        $steps->addStep(EvictionSimulationStep::forTurn(3, 5800, 'listFolders', 'use listFolders'));
        $steps->addStep(EvictionSimulationStep::forTurn(4, 7500, 'getTicketSummary', 'load Tickets + use', ['Tickets.Overview']));
        $steps->addStep(EvictionSimulationStep::forTurn(5, 9500, 'listTickets', 'use listTickets'));
        $steps->addStep(EvictionSimulationStep::forTurn(6, 11000, 'getTicketTopics', 'use getTicketTopics'));
        $steps->addStep(EvictionSimulationStep::forTurn(7, 25000, 'getTicketSummary', 'OWNER PASTES a 12k page → +14k'));
        for ($turnIndex = 8; $turnIndex <= 16; $turnIndex++) {
            $usedToolName = self::TICKET_TOOL_NAMES[($turnIndex - 8) % count(self::TICKET_TOOL_NAMES)];
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 25000 + ($turnIndex - 7) * 2000, $usedToolName, 'use ' . $usedToolName));
        }
        return static::forSteps('paste', $steps);
    }

    /**
     * FLOOD — five skills loaded in quick succession, the working set far above the ceiling; the hard ceiling pulls
     * the carried load back as the earliest cold branches leave. Then the work settles on one skill.
     *
     * @return static
     */
    public static function buildFloodScenario(): static
    {
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use', static::getFirstLoadNames()));
        $steps->addStep(EvictionSimulationStep::forTurn(2, 3500, 'getTicketSummary', 'load Tickets + use', ['Tickets.Overview']));
        $steps->addStep(EvictionSimulationStep::forTurn(3, 4500, 'getEvents', 'load Calendar + use', ['Calendar.Events']));
        $steps->addStep(EvictionSimulationStep::forTurn(4, 5500, 'getNotes', 'load Notes + use', ['Notes.Drafts']));
        $steps->addStep(EvictionSimulationStep::forTurn(5, 6500, 'getSettings', 'load Assistant + use (5 skills!)', ['Assistant.Settings']));
        for ($turnIndex = 6; $turnIndex <= 16; $turnIndex++) {
            $usedToolName = $turnIndex % 3 === 0 ? 'updateSettings' : 'getSettings';
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 6500 + ($turnIndex - 5) * 1000, $usedToolName, 'settle on Assistant'));
        }
        return static::forSteps('flood', $steps);
    }

    /**
     * INTERLEAVED — two skills kept warm by alternating, each through its own tools; neither leaves.
     *
     * @return static
     */
    public static function buildInterleavedScenario(): static
    {
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use', static::getFirstLoadNames()));
        $steps->addStep(EvictionSimulationStep::forTurn(2, 3700, 'getTicketSummary', 'load Tickets + use', ['Tickets.Overview']));
        for ($turnIndex = 3; $turnIndex <= 22; $turnIndex++) {
            $usedToolName = $turnIndex % 2 === 1
                ? self::DOCUMENT_TOOL_NAMES[intdiv($turnIndex, 2) % count(self::DOCUMENT_TOOL_NAMES)]
                : self::TICKET_TOOL_NAMES[intdiv($turnIndex, 2) % count(self::TICKET_TOOL_NAMES)];
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 2500 + ($turnIndex - 1) * 1200, $usedToolName, 'use ' . $usedToolName));
        }
        return static::forSteps('interleaved', $steps);
    }

    /**
     * REVISIT — one skill worked, then two others (pressure rises) so the first cools down and leaves; later it is
     * needed again and loaded again.
     *
     * @return static
     */
    public static function buildRevisitScenario(): static
    {
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use', static::getFirstLoadNames()));
        $steps->addStep(EvictionSimulationStep::forTurn(2, 4000, 'compareDocuments', 'use compareDocuments'));
        $steps->addStep(EvictionSimulationStep::forTurn(3, 5500, 'getTicketSummary', 'load Tickets', ['Tickets.Overview']));
        $steps->addStep(EvictionSimulationStep::forTurn(4, 7000, 'getEvents', 'load Calendar (pressure ↑)', ['Calendar.Events']));
        for ($turnIndex = 5; $turnIndex <= 12; $turnIndex++) {
            $usedToolName = $turnIndex % 2 === 0
                ? self::TICKET_TOOL_NAMES[intdiv($turnIndex, 2) % count(self::TICKET_TOOL_NAMES)]
                : self::CALENDAR_TOOL_NAMES[intdiv($turnIndex, 2) % count(self::CALENDAR_TOOL_NAMES)];
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 2500 + ($turnIndex - 1) * 1500, $usedToolName, 'use ' . $usedToolName . ' (Documents cooling)'));
        }
        $steps->addStep(EvictionSimulationStep::forTurn(13, 2500 + 12 * 1500, 'searchDocuments', 'REVISIT → load Documents again', ['Documents.Search']));
        for ($turnIndex = 14; $turnIndex <= 20; $turnIndex++) {
            $usedToolName = self::DOCUMENT_TOOL_NAMES[$turnIndex % count(self::DOCUMENT_TOOL_NAMES)];
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 2500 + ($turnIndex - 1) * 1500, $usedToolName, 'use ' . $usedToolName));
        }
        return static::forSteps('revisit', $steps);
    }

    /**
     * LONGHAUL — 30 turns of normal load, one skill used every third turn with a rotating tool: rarely used tools
     * leave between uses, the body stays, nothing loads and leaves in a loop.
     *
     * @return static
     */
    public static function buildLonghaulScenario(): static
    {
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use', static::getFirstLoadNames()));
        for ($turnIndex = 2; $turnIndex <= 30; $turnIndex++) {
            $contextInputTokens = 2500 + ($turnIndex - 1) * 1200;
            if ($turnIndex % 3 === 0) {
                $usedToolName = self::DOCUMENT_TOOL_NAMES[intdiv($turnIndex, 3) % count(self::DOCUMENT_TOOL_NAMES)];
                $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, $contextInputTokens, $usedToolName, 'use ' . $usedToolName));
                continue;
            }
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, $contextInputTokens, null, '…talk…'));
        }
        return static::forSteps('longhaul', $steps);
    }

    /**
     * IDLE — two skills seeded with a few tool uses, then only talk: everything leaves except what loads with every
     * turn.
     *
     * @return static
     */
    public static function buildIdleScenario(): static
    {
        $steps = new EvictionSimulationSteps();
        $steps->addStep(EvictionSimulationStep::forTurn(1, 2500, 'searchDocuments', 'load Documents + use', static::getFirstLoadNames()));
        $steps->addStep(EvictionSimulationStep::forTurn(2, 4000, 'compareDocuments', 'use compareDocuments'));
        $steps->addStep(EvictionSimulationStep::forTurn(3, 5500, 'getTicketSummary', 'load Tickets + use', ['Tickets.Overview']));
        $steps->addStep(EvictionSimulationStep::forTurn(4, 7000, 'listTickets', 'use listTickets'));
        for ($turnIndex = 5; $turnIndex <= 18; $turnIndex++) {
            $steps->addStep(EvictionSimulationStep::forTurn($turnIndex, 2500 + ($turnIndex - 1) * 1500, null, '…only talking, no tool use…'));
        }
        return static::forSteps('idle', $steps);
    }
}
