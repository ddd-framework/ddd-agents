<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one pass of the skill-preload site asks: the roots pass over the offered
 * roots, or a SUB-SKILL pass over the sub-skills one loaded root unlocks ({@see isSubSkillPass()} — a follow-up of the
 * roots pass `pass<n>`, a skill loaded in an earlier turn `loadedSkill`, a load of the chat model `modelLoad`). It
 * carries what the decision model reads (the catalog entries, the thread, the plan, the facts) and what the application
 * needs (the answer tokens, the arm, the snapshot of the offer for the turn event). Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService::buildSkillPreloadRequestInput()}.
 */
class AgentSkillPreloadDecisionQuestion extends ValueObject
{
    public function __construct()
    {
        parent::__construct();
        $this->loadedSkills = new AgentSkillPreloadLoadedSkills();
    }

    /** The site mode for this conversation (`on` admits, `shadow` dry-runs). */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** The cohort arm of the account, null outside a cohort run. */
    public ?string $arm = null;

    /** Where the site mode came from (an AIConversationTurnEvent::OVERRIDE_SOURCE_*). */
    public string $overrideSource = '';

    /** 1 = the roots pass; ≥ 2 = a sub-skill pass. */
    public int $pass = 1;

    /** The loaded root whose sub-skills this pass judges, null = the roots pass. */
    public ?string $unlockedByRootSkillName = null;

    /** The turn event's subject (`<pass<n>|loadedSkill|modelLoad>:<root>`), null = the roots pass. */
    public ?string $subject = null;

    /** The subject prefix of a sub-skill pass that is no follow-up of the roots pass, null = `pass<n>`. */
    public ?string $subSkillPassSubject = null;

    /** @var array<string, string> What the unlocking skill does by itself: tool name → summary. */
    public array $unlockedByRootToolSummaries = [];

    /** @var string[] The skills the pass treats as loaded although they are not yet (a shadow follow-up). */
    public array $assumeLoadedSkillNames = [];

    /** The index of the owner message that started this turn. */
    public int $initiatorMessageIndex = 0;

    /** @var array<string, string> The answer set: lowercased token → canonical skill name. */
    public array $catalogTokens = [];

    /** @var array<string, string> Skill name → its decision catalog entry. */
    public array $catalogCriteria = [];

    /** @var AgentSkillPreloadLoadedSkills The skills already loaded: context for the questions, never an answer. */
    public AgentSkillPreloadLoadedSkills $loadedSkills;

    /** @var string[] The recent turns before the owner message, one line each. */
    public array $recentTurns = [];

    /** The stored facts about the space, one per line. */
    public string $agentSpaceFacts = '';

    /** The task plan as the decision model reads it ('' = none). */
    public string $planRender = '';

    /** @var string[] The acts ahead in the plan (a sub-skill pass). */
    public array $actsAhead = [];

    /** The unlocking root's own description (a sub-skill pass). */
    public string $rootSkillDescription = '';

    /** @var array<string, string> The unlocking root's sub-skills already resident: name → short description. */
    public array $loadedSubSkills = [];

    /** @var array<string, string> The loop's meta tools: name → what it does. */
    public array $metaTools = [];

    public string $ownerMessage = '';

    /** The last assistant text before the owner message. */
    public string $assistantTextBefore = '';

    /** The first open item of the task plan ('' = none). */
    public string $openWorkItem = '';

    /** @var array<string, mixed> Facts the question tolerates being absent (e.g. `hasBuiltWebsite`). */
    public array $facts = [];

    public ?string $criteriaVersion = null;

    /** @var array<string, mixed> The offer at decision time (`offered`, `loaded`, `criteriaVersion`), stored for the turn event. */
    public array $snapshot = [];

    /** A pass over the sub-skills of one loaded root (any subject), not the roots pass. */
    public function isSubSkillPass(): bool
    {
        return $this->unlockedByRootSkillName !== null;
    }
}
