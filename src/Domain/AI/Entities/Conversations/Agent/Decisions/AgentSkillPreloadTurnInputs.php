<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What every skill-preload question of ONE turn start reads alike: the site mode, the owner's initiator, the offered
 * catalog, the entitlement, the loaded skills with their entries and tools, the thread, the space, the plan and the
 * facts. The turn start builds it once and hands it to the roots question and to the sub-skill question of each skill
 * loaded before the turn — each of them used to rebuild the catalog, the entitlement and the tool summaries of every
 * loaded skill on its own. A plain runtime object, never persisted; a later pass (after an admission changed the loaded
 * set) builds its own.
 */
class AgentSkillPreloadTurnInputs extends ValueObject
{
    public function __construct()
    {
        parent::__construct();
        $this->loadedSkills = new AgentSkillPreloadLoadedSkills();
    }

    /** @var string The site mode for this conversation (never `off` — then no inputs are built). */
    public string $siteMode = '';

    /** @var string Whether the conversation's own switch decided the mode ({@see \DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent} `OVERRIDE_SOURCE_*`). */
    public string $overrideSource = '';

    /** @var string|null The account's arm in `cohort` mode, null otherwise. */
    public ?string $arm = null;

    /** @var string[] The skills treated as loaded when the inputs were built. */
    public array $assumeLoadedSkillNames = [];

    /** @var int The index of the owner message that opened the turn. */
    public int $initiatorMessageIndex = 0;

    /** @var AIConversationMessage The owner message that opened the turn. */
    public AIConversationMessage $initiatorMessage;

    /** @var OfferedSkillCatalog The catalog the conversation offers at the turn start. */
    public OfferedSkillCatalog $offeredSkillCatalog;

    /** @var AIConversationEntitlement What the space and the acting user may use. */
    public AIConversationEntitlement $entitlement;

    /** @var AgentSkillPreloadLoadedSkills The skills already loaded at the turn start. */
    public AgentSkillPreloadLoadedSkills $loadedSkills;

    /** @var string[] The recent owner / assistant turns before the initiator, one line each. */
    public array $recentTurns = [];

    /** @var string The space's facts on file, one per line. */
    public string $agentSpaceFacts = '';

    /** @var string The task plan as the decision model reads it, '' = no plan. */
    public string $planRender = '';

    /** @var array<string, string> The loop's skill-less meta tools, name → what it does. */
    public array $metaTools = [];

    /** @var string The last assistant text before the initiator. */
    public string $assistantTextBefore = '';

    /** @var string The plan's first open item, '' = none. */
    public string $openWorkItem = '';

    /**
     * @var array<string, bool> The facts the question reads (a fact the application contributes, e.g. `hasBookings`).
     */
    public array $facts = [];

    /** @var string The version of the skill catalog's decision criteria. */
    public string $criteriaVersion = '';

    /**
     * True when these inputs were built for the same assumed-loaded skills — the only case they may be reused.
     *
     * @param string[] $assumeLoadedSkillNames
     */
    public function isBuiltForAssumedLoadedSkills(array $assumeLoadedSkillNames): bool
    {
        return $this->assumeLoadedSkillNames === $assumeLoadedSkillNames;
    }
}
