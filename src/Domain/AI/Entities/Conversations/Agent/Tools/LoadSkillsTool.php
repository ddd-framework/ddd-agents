<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `load_skills` meta-tool offered to the model every turn: it lets the agent load skills (gaining their tools).
 * Its `skill_names` parameter is enum-constrained to the live catalog. The catalog itself — one line per offered
 * skill with its purpose and the sub-skills a root unlocks — is NOT in the tool description any more: it
 * is rendered by {@see self::renderCatalogBlock()} into the SYSTEM prompt
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::buildSystemSegments()}, segment SKILL_CATALOG),
 * where the model reads it as instructions and where it can be measured on its own. `toOpenAiToolSchema()` emits the
 * OpenAI function-tool envelope — the wire shape lives here, not as a hand-built array in the loop (mirrors
 * {@see \DDD\Domain\AI\Entities\MCPPackages\ToolDefinition}).
 *
 * @phpstan-consistent-constructor
 */
class LoadSkillsTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'load_skills';

    /** The effect the stored-call readers see ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = MCPToolEffect::READ;

    /** What a `load_skills` call that names no skill is answered with. */
    public const string RESULT_WITHOUT_SKILL_NAMES = 'Nothing was loaded: `skill_names` named no skill. Name the skill '
        . 'you need exactly as "Available skills" or the sub-skill list of a loaded skill states it. A tool of a skill you have loaded is '
        . 'called directly. Do not repeat this call without a name.';

    /** How many tool names an answer of this tool lists at most; the rest is counted ("and 4 more"). */
    public const int MAX_TOOL_NAMES_LISTED_IN_A_RESULT = 12;

    /**
     * What a `load_skills` call that names no skill is answered with. While skills are loaded and their tools are
     * callable, the answer names both: such a call asks for nothing, so the model's next move is a tool it already
     * has — "name the skill you need" alone sent it back into the same empty call. Without a loaded skill or a callable
     * tool the answer is {@see RESULT_WITHOUT_SKILL_NAMES}.
     *
     * @param string[] $loadedSkillNames the skills loaded before the call
     * @param string[] $callableToolNames the loaded skills' tools the model can call now
     */
    public static function buildResultOfCallNamingNoSkill(array $loadedSkillNames, array $callableToolNames): string
    {
        if ($loadedSkillNames === [] || $callableToolNames === []) {
            return self::RESULT_WITHOUT_SKILL_NAMES;
        }
        return 'Nothing was loaded: `skill_names` named no skill. ' . self::renderLoadedSkillsWithTheirTools($loadedSkillNames, $callableToolNames)
            . ' Call the tool the request needs directly NOW. `' . self::NAME . '` takes only a skill that is not loaded yet, named exactly as '
            . '"Available skills" or the sub-skill list of a loaded skill states it; never call it with an empty list.';
    }

    /**
     * What the identical `load_skills` call is answered with once the repeat guard stops running it, while skills are
     * loaded and their tools are callable: the call is not executed, the loaded skills and their tools are named, and
     * the next move is one of those tools or the answer. Null without a loaded skill or a callable tool — the repeat
     * guard's own text applies then. The dispatch gives this answer in ONE step of a turn; a later repeated call gets
     * the repeat guard's own text and the tool-less step.
     *
     * @param string[] $loadedSkillNames the skills loaded before the call
     * @param string[] $callableToolNames the loaded skills' tools the model can call now
     */
    public static function buildResultOfRepeatedCall(int $identicalCallCount, array $loadedSkillNames, array $callableToolNames): ?string
    {
        if ($loadedSkillNames === [] || $callableToolNames === []) {
            return null;
        }
        return sprintf(
            'NOT executed: you have called `%s` with these exact arguments %d times and it loads nothing more. %s Call the tool the '
                . 'request needs directly NOW, or answer if the request needs no tool. Do not repeat this `%s` call.',
            self::NAME,
            $identicalCallCount,
            self::renderLoadedSkillsWithTheirTools($loadedSkillNames, $callableToolNames),
            self::NAME
        );
    }

    /**
     * "Loaded right now: A, B — their tools are ALREADY in your tool list (t1, t2 and 3 more)."
     *
     * @param string[] $loadedSkillNames
     * @param string[] $callableToolNames
     */
    protected static function renderLoadedSkillsWithTheirTools(array $loadedSkillNames, array $callableToolNames): string
    {
        $callableToolNames = array_values(array_unique($callableToolNames));
        $listedToolNames = array_slice($callableToolNames, 0, self::MAX_TOOL_NAMES_LISTED_IN_A_RESULT);
        $unlistedToolCount = count($callableToolNames) - count($listedToolNames);
        return 'Loaded right now: ' . implode(', ', $loadedSkillNames) . ' — their tools are ALREADY in your tool list ('
            . implode(', ', $listedToolNames) . ($unlistedToolCount > 0 ? ' and ' . $unlistedToolCount . ' more' : '') . ').';
    }

    /** The system-prompt catalog header — `renderCatalogBlock()` emits it, the composition labels it. */
    public const string CATALOG_HEADER = "\n\n# Available skills — load with load_skills when a request needs them\n";

    /**
     * ONE catalog line: `- <name> — <description>` plus, for a ROOT, the sub-skills it unlocks (names only, no
     * descriptions: the discoverability cue costs a few tokens; the sub-skills enter the catalog with their full
     * descriptions once the root is loaded). Generated from the frontmatter edge list, never hand-written. The same
     * renderer serves the wire block and the admin catalog measurement, so both count the same bytes.
     *
     * @param array<string, string> $toolSummaries tool name => one-line summary, for a sub-skill's line
     */
    public static function renderCatalogLine(AISkill $skill, array $toolSummaries = [], bool $namesSubSkills = true): string
    {
        $catalogLine = "- {$skill->name} — {$skill->description}";
        if ($namesSubSkills && $skill->subSkillNames !== []) {
            $catalogLine .= ' · unlocks after loading: ' . implode(', ', $skill->subSkillNames);
        }
        // A SUB-SKILL's line names its tools (it is in the catalog only while its root is loaded): the root's body
        // speaks of the sub-skill in prose, the tool names tell the model that a tool for the request exists.
        if ($toolSummaries !== []) {
            $toolEntries = [];
            foreach ($toolSummaries as $toolName => $toolSummary) {
                $toolEntries[] = $toolName . ' (' . rtrim((string)$toolSummary, " .") . ')';
            }
            $catalogLine .= ' · its tools: ' . implode('; ', $toolEntries);
        }
        return $catalogLine;
    }

    /**
     * The whole catalog block for the system prompt: header + one line per offered skill, loaded ones included (which
     * skills are loaded is stated by the loaded-skills block). With `$topLevelOnly` the block holds the top-level
     * skills only and is the same for the whole conversation; without it the sub-skills of the loaded skills are
     * listed too. Empty string for an empty catalog (the segment is then omitted).
     */
    public static function renderCatalogBlock(
        AISkills $catalog,
        bool $listSubSkillTools = false,
        ?AgentFeatureFlagsInterface $featureFlags = null,
        ?AgentAccountRightsInterface $accountRights = null,
        bool $topLevelOnly = false
    ): string {
        $lines = [];
        foreach ($catalog->getElements() as $skill) {
            if ($topLevelOnly) {
                // the hierarchy: one line per top-level skill, which speaks for its whole sub-tree; a sub-skill is
                // listed by its loaded parent, so this block is the same for the whole conversation
                if (!$skill->isSubSkill) {
                    $lines[] = self::renderCatalogLine($skill, [], false);
                }
                continue;
            }
            $lines[] = self::renderCatalogLine(
                $skill,
                $listSubSkillTools && $skill->isSubSkill ? $skill->getToolSummaries(AIConversationEntitlement::forAxes($featureFlags, $accountRights)) : []
            );
        }
        return $lines === [] ? '' : self::CATALOG_HEADER . implode("\n", $lines);
    }

    /** The skill catalog this tool offers (its names become the parameter enum; its descriptions the help text). */
    protected AISkills $catalog;

    /** @var string[] skills already loaded on this turn — excluded from the enum so the model cannot re-load them. */
    protected array $alreadyLoadedSkillNames = [];

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    /**
     * @param string[] $alreadyLoadedSkillNames skills active on the turn — removed from the offered enum so
     *   the model structurally cannot re-load a skill it already has (prompt-only "don't reload" hints are ignored by
     *   weak models). Their tools stay available via the loaded skills' ToolDefinitions; only re-loading is removed.
     */
    public static function forCatalog(AISkills $catalog, array $alreadyLoadedSkillNames = []): static
    {
        $tool = new static();
        $tool->catalog = $catalog;
        $tool->alreadyLoadedSkillNames = $alreadyLoadedSkillNames;
        return $tool;
    }

    /** @return string[] the catalog skill names still loadable this turn (catalog minus already-loaded). */
    public function getLoadableSkillNames(): array
    {
        $loadableSkillNames = [];
        foreach ($this->catalog->getElements() as $skill) {
            if (!in_array($skill->name, $this->alreadyLoadedSkillNames, true)) {
                $loadableSkillNames[] = $skill->name;
            }
        }
        return $loadableSkillNames;
    }

    /** True when at least one catalog skill is still unloaded — the loop omits the tool entirely when none remain. */
    public function hasLoadableSkills(): bool
    {
        return $this->getLoadableSkillNames() !== [];
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** The call loads the skills it names, through the gates of the load path. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return AgentToolCallWorkRequest::loadSkills($toolCall->arguments);
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> The OpenAI function-tool envelope — a lean one sentence; the catalog is in the system prompt. */
    public function toOpenAiToolSchema(): array
    {
        $names = $this->getLoadableSkillNames(); // catalog minus already-loaded: the model structurally cannot re-load

        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Load one or more skills to gain their tools — pick from the "Available skills" list in '
                    . 'the system prompt or from the sub-skills a loaded skill lists. Call when the request needs '
                    . 'capabilities not yet loaded; callable again at any step.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['skill_names'],
                    'properties' => [
                        'skill_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $names],
                            'description' => 'Names of the skills to load.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
