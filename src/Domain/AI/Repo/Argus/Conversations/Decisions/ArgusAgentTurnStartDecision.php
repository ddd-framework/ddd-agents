<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Languages\AgentLanguageNames;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionChoiceAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationRecentTurnTexts;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartLineDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadLoadedSkills;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The TYPED reply-language facts of one owner turn, asked of a System One DECISION model (TypeSafe Jev):
 * three narrow questions in ONE request — the language the owner WRITES in, the language the owner explicitly
 * ASKS for, and the language the standing DIRECTIVES prescribe — each with a declared abstention (`undecidable`,
 * `none`, `conflicting`). The precedence between them is NOT asked here; the loop resolves it in PHP
 * ({@see AgentReplyLanguageDecision::resolveByRecency()}).
 *
 * Transport, gates, cost booking and outage behaviour: {@see ArgusAgentDecisionTrait} (an
 * override, not a copy). Question design follows the question-design rules to the letter — one judgment per question, every
 * state path backticked, criteria as situations, the negative branch enumerating the traps, the blind spot named —
 * because a question that breaks one of those rules measured 0/5 where the well-formed one measured 5/5.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: 0,
    timeout: 2.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    // The registry NAME of this call (cost counters are keyed per account × interval × prompt): the .md documents the
    // questions, its text is never sent — the questions are built in code below.
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_TURN_START_DECISION,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    // Counted against the account's usage cap like every generative op — the admin
    // bypass (`budgetBypass` → AccountAICostsService::isBudgetLifted()) flows through the same gate.
    enforceBudget: true,
)]
class ArgusAgentTurnStartDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    public const string QUESTION_WRITTEN_LANGUAGE = 'written_language';
    public const string QUESTION_REQUESTED_LANGUAGE = 'requested_language';
    public const string QUESTION_DIRECTIVE_LANGUAGE = 'directive_language';

    public const string STATE_OWNER_WORDS = 'ownerWordsThisTurn';
    public const string STATE_STANDING_DIRECTIVES = 'standingDirectives';
    /** The language the conversation has been held in so far (the sticky anchor) — the written question reads it. */
    public const string STATE_CONVERSATION_LANGUAGE = 'conversationLanguage';

    // --- the SKILL-PRELOAD site, FOLDED into this turn-start request when the caller sets a catalog ---
    // ONE question: which skill is to be loaded. Whether a tool will be called is not asked — a skill carries the
    // working rules of its domain and may bring tools, and it is loaded for its domain, with or without a tool call.
    public const string QUESTION_SKILL = 'skill';

    public const string STATE_OWNER_MESSAGE = 'ownerMessage';
    public const string STATE_ASSISTANT_TEXT = 'assistantTextBefore';
    public const string STATE_OPEN_WORK_ITEM = 'openWorkItem';
    public const string STATE_LOADED_SKILLS = 'loadedSkills';
    public const string STATE_CATALOG = 'catalog';
    /** The SUB-SKILL pass: the loaded skill whose sub-skills are judged, and those sub-skills (name → entry) — the answer set. */
    public const string STATE_LOADED_SKILL = 'loadedSkill';
    public const string STATE_SUB_SKILLS = 'subSkills';
    /** The plan as the chat model sees it (the tail-hint render: done / open items, `← next`), '' = no plan. */
    public const string STATE_PLAN = 'plan';
    /** The sub-skills of the loaded root that are ALREADY loaded (name → what it does), {} = none. */
    public const string STATE_LOADED_SUB_SKILLS = 'loadedSubSkills';
    /** The plan's open acts after the current item whose sub-skill is not loaded, in plan order (the platform's walk) */
    public const string STATE_ACTS_AHEAD = 'actsAhead';
    /** The first entry of `actsAhead` — the act the assistant enters next once the current item is closed; null when none */
    public const string STATE_NEXT_ACT = 'nextAct';
    public const int MAX_PLAN_CHARS = 3200;
    /** The marker the plan render puts at the end of the line the work is on ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::toTailHintLine()}). */
    public const string PLAN_NEXT_MARKER = ' ← next';
    public const string STATE_FACTS = 'facts';
    /** The last owner / assistant texts before this turn (the decision model sees the thread it routes in). */
    public const string STATE_RECENT_TURNS = 'recentTurns';
    /** What the chat model also sees: the loop's skill-less meta tools (and the facts on file about the space, {@see agentSpaceFactsStateName()}). */
    public const string STATE_META_TOOLS = 'metaTools';

    /** The owner message the preload questions read, capped (the language questions read {@see $ownerWordsThisTurn}). */
    public const int MAX_OWNER_MESSAGE_CHARS = 600;
    public const int MAX_ASSISTANT_TEXT_CHARS = 300;

    // --- the REASONING-EFFORT site, FOLDED into this request when the PHP precondition admits it ---
    public const string QUESTION_DISPUTES_WHAT_THE_ASSISTANT_CLAIMS = 'disputes_what_the_assistant_claims';
    public const string QUESTION_REJECTS_IDENTITY_OR_CLASS = 'rejects_identity_or_class';
    public const string QUESTION_ASKS_FOR_A_DIFFERENT_COURSE = 'asks_for_a_different_course';
    public const string QUESTION_REQUIRES_WEIGHING_CONFLICTING_REQUIREMENTS = 'requires_weighing_conflicting_requirements';

    public const string STATE_STORED_FACTS = 'storedFacts';
    public const string STATE_NEXT_PLAN_ITEM = 'nextPlanItem';
    public const string STATE_DISAGREEMENT = 'disagreement';
    /** The effort the assistant ALREADY reasons at this turn (the floor) and the model's ceiling — */
    public const string STATE_ACTIVE_EFFORT = 'activeReasoningEffort';
    public const string STATE_CEILING_EFFORT = 'ceilingReasoningEffort';

    public const int MAX_STORED_FACTS_CHARS = 400;

    // --- the PLAN-DYNAMICS site, FOLDED into this request when no plan with open items is active ---
    public const string QUESTION_NEEDS_WORKING_PLAN = 'needs_working_plan';

    /** The finished plans of this conversation, one line each (≤ 3), so a follow-up on finished work is not a new job. */
    public const string STATE_FINISHED_PLANS = 'finishedPlans';

    public const int MAX_FINISHED_PLANS_IN_STATE = 3;

    // --- the DIRECT-WIDGET site, FOLDED into this request for a root owner turn on a screen ---
    public const string QUESTION_DIRECT_WIDGET = 'direct_widget';

    // --- the TURN-START LINE site, FOLDED into this request for a root owner turn on a screen ---
    public const string QUESTION_WORK_BEFORE_ANSWER = 'work_before_answer';

    /** English display names for the criteria — a code without one falls back to the code itself. */
    protected const array LANGUAGE_NAMES = [
        'de' => 'German', 'en' => 'English', 'ro' => 'Romanian', 'es' => 'Spanish', 'it' => 'Italian',
        'fr' => 'French', 'nl' => 'Dutch', 'pl' => 'Polish', 'pt' => 'Portuguese', 'tr' => 'Turkish',
        'cs' => 'Czech', 'hu' => 'Hungarian', 'sv' => 'Swedish', 'da' => 'Danish', 'fi' => 'Finnish',
        'nb' => 'Norwegian', 'no' => 'Norwegian', 'el' => 'Greek', 'bg' => 'Bulgarian', 'hr' => 'Croatian',
        'sk' => 'Slovak', 'sl' => 'Slovenian', 'ru' => 'Russian', 'uk' => 'Ukrainian',
    ];

    /** @var string The owner's own words, newest last, capped by the caller — the ONLY evidence for two of the three questions. */
    public string $ownerWordsThisTurn = '';

    /** @var string The rendered standing-instruction block ({@see \DDD\Domain\AI\Entities\Conversations\Memories\AgentStandingDirectives::$directivesBlock}), '' = none. */
    public string $standingDirectivesBlock = '';

    /** @var string[] The closed answer set: the ACTIVE app language codes (lowercase). */
    public array $supportedLanguageCodes = [];

    // --- the conversational context of the language site: the sticky anchor, the assistant's last text
    // and the recent turns, so the written question can tell borrowed terms ("online booking") from the owner's own
    // language. Set by the caller independently of the preload site; empty = the context-free question. ---
    public ?string $languageConversationLanguageCode = null;
    public string $languageAssistantTextBefore = '';
    /** @var string[] */
    public array $languageRecentTurns = [];

    // --- preload site inputs (empty catalog = the site is not part of this request) ---

    /**
     * @var array<string, string> Canonical skill name → its catalog ENTRY (the `description` the chat model reads in the
     * `load_skills` catalog, plus a root's sub-skill line) of the OFFERED skills not yet loaded — the answer set.
     */
    public array $preloadCatalog = [];

    /**
     * @var AgentSkillPreloadLoadedSkills|null The skills ALREADY loaded — context for the questions, never an answer;
     * null until {@see fillSkillPreloadQuestions()} sets them.
     */
    public ?AgentSkillPreloadLoadedSkills $preloadLoadedSkills = null;

    /**
     * @var string|null Set = the SUB-SKILL pass: this skill is loaded and {@see $preloadCatalog} holds its sub-skills that
     * are not loaded yet. The request then asks whether loading one of them makes sense and sends the loaded skill by
     * name only — its catalog entry names the purposes of its sub-skills and would read as covering every one of them.
     */
    public ?string $preloadLoadedRootSkillName = null;

    /**
     * @var array<string, string> The SUB-SKILL pass: what the loaded skill does BY ITSELF, tool name → summary. The
     * decision model needs it to see that a request is already served without a sub-skill; without it the loaded skill
     * is a bare name and every question that touches a sub-skill's topic reads as the sub-skill's.
     */
    public array $preloadLoadedRootSkillTools = [];

    /** @var string[] The last owner / assistant texts before the owner message, oldest first, each prefixed and capped by the caller. */
    public array $preloadRecentTurns = [];

    /**
     * @var string The SUB-SKILL pass: the conversation's task plan as the chat model reads it (the tail-hint render —
     * what is done, what is open, the `← next` marker, each act item naming its skill), cut to {@see MAX_PLAN_CHARS} around
     * the `← next` line ({@see buildPlanWindowText()});
     * '' when the conversation has no plan. The decision model reads the plan too — a reply that
     * closes the current act is what makes the NEXT act's sub-skill the one to load, and that is visible only here.
     */
    public string $preloadPlan = '';

    /** @var string The SUB-SKILL pass: what the loaded root skill is about (its catalog description), so the act order it declares is readable. */
    public string $preloadRootSkillDescription = '';

    /** @var array<string, string> The SUB-SKILL pass: the root's sub-skills that are ALREADY loaded, name → what it does — never an answer. */
    public array $preloadLoadedSubSkills = [];

    /** @var string[] "item 4 → Setup.Keywords" lines ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan::toActsAheadLines()}) */
    public array $preloadActsAhead = [];

    /** @var string The facts on file about the space, one per line; '' = none. */
    public string $preloadAgentSpaceFacts = '';

    /** @var array<string, string> The loop's skill-less meta tools, name → what it does. */
    public array $preloadMetaTools = [];

    /** @var string The owner's message this turn, capped to {@see MAX_OWNER_MESSAGE_CHARS}. */
    public string $preloadOwnerMessage = '';

    /** @var string The last assistant TEXT before the owner message, capped to {@see MAX_ASSISTANT_TEXT_CHARS} ('' = none). */
    public string $preloadAssistantTextBefore = '';

    /** @var string The open plan item the turn is on ('' = none). */
    public string $preloadOpenWorkItem = '';

    /** @var array<string, bool> Facts the criteria need, keyed by the application's fact names. */
    public array $preloadFacts = [];

    // --- effort site inputs (`$effortSiteRequested` = the site is part of this request) ---

    /** @var bool True when the caller folded the effort questions into this request (the PHP precondition admitted the turn). */
    public bool $effortSiteRequested = false;

    /** @var string The owner's message this turn for the effort questions, capped to {@see MAX_OWNER_MESSAGE_CHARS}. */
    public string $effortOwnerMessage = '';

    /** @var string The last assistant TEXT before the owner message, capped. */
    public string $effortAssistantTextBefore = '';

    /** @var string What PHP already holds about the space (its stored facts; the confirmed identity), capped. */
    public string $effortStoredFacts = '';

    /** @var string The open plan item the turn is on ('' = none). */
    public string $effortNextPlanItem = '';

    /** @var string Two differing stored values for one field, rendered by PHP ('' = none → the weighing question is not sent). */
    public string $effortDisagreement = '';

    /** @var string The turn's ACTIVE effort before the decision (the floor) — only questions for levels ABOVE it are sent. */
    public string $effortActiveEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW;

    /** @var string The model's ceiling, shown to the decision model as the highest reachable level. */
    public string $effortCeilingEffort = AgentReasoningEffortDecision::DEFAULT_CEILING;

    // --- plan-dynamics site inputs (`$planDynamicsSiteRequested` = the site is part of this request) ---

    /** @var bool True when the caller folded the plan question in (no active plan with open items, an owner-text turn, site not off). */
    public bool $planDynamicsSiteRequested = false;

    /** @var string The owner's message this turn for the plan question, capped to {@see MAX_OWNER_MESSAGE_CHARS}. */
    public string $planOwnerMessage = '';

    /** @var string The last assistant TEXT before the owner message, capped. */
    public string $planAssistantTextBefore = '';

    /** @var string[] The last owner / assistant texts before the owner message, oldest first (the preload site's shape). */
    public array $planRecentTurns = [];

    /** @var string[] The finished plans, newest first, one line each (title + outcome counts); [] = none. */
    public array $planFinishedPlans = [];

    public ?float $needsWorkingPlanProbability = null;

    // --- direct-widget site inputs (empty answer set = the site is not part of this request) ---

    /** @var array<string, string> Widget type → the situation it is decided for — the answer set, sorted by type. */
    public array $directWidgetWhenLines = [];

    /** @var string The owner's message this turn for the widget question, capped to {@see MAX_OWNER_MESSAGE_CHARS}. */
    public string $directWidgetOwnerMessage = '';

    /** @var string The last assistant TEXT before the owner message, capped. */
    public string $directWidgetAssistantTextBefore = '';

    /** @var string[] The last owner / assistant texts before the owner message, oldest first. */
    public array $directWidgetRecentTurns = [];

    /** True when the caller folded the turn-start line question into this request. */
    public bool $turnStartLineSiteRequested = false;

    public string $turnStartLineOwnerMessage = '';

    public string $turnStartLineAssistantTextBefore = '';

    /** @var string[] */
    public array $turnStartLineRecentTurns = [];

    /** P(the assistant reads, searches or changes something before it answers); null = no usable answer. */
    public ?float $workBeforeAnswerProbability = null;

    public ?string $directWidgetPickedToken = null;
    public float $directWidgetPickConfidence = 0.0;
    /** @var array<string, mixed> The RAW reported distribution of the `direct_widget` answer (validated by the decision VO). */
    public array $directWidgetProbabilities = [];

    public ?float $disputesWhatTheAssistantClaimsProbability = null;
    public ?float $rejectsIdentityOrClassProbability = null;
    public ?float $asksForADifferentCourseProbability = null;
    public ?float $requiresWeighingConflictingRequirementsProbability = null;

    public ?string $preloadPickedToken = null;
    public float $preloadPickConfidence = 0.0;
    /** @var array<string, mixed> The RAW reported distribution of the `skill` answer (validated by the decision VO). */
    public array $preloadProbabilities = [];

    public ?string $writtenLanguageCode = null;
    public float $writtenConfidence = 0.0;
    public ?string $requestedLanguageCode = null;
    public float $requestedConfidence = 0.0;
    public ?string $directiveLanguageCode = null;
    public float $directiveConfidence = 0.0;

    /**
     * The three questions, built to the question-design rules. Pure and public so the unit test can pin the rules
     * (every instruction backticks its state path, every choice declares its abstention).
     *
     * @param string[] $supportedLanguageCodes
     * @return array<string, array<string, mixed>>
     */
    public static function buildQuestions(array $supportedLanguageCodes, bool $withDirectiveQuestion, bool $withConversationContext = false): array
    {
        $languageCriteria = [];
        foreach ($supportedLanguageCodes as $languageCode) {
            $languageCriteria[$languageCode] = $withConversationContext
                ? 'the owner writes their own sentence or phrase in ' . self::languageName($languageCode) . ' — grammar and everyday words of '
                    . self::languageName($languageCode) . ', also when short ("Estoy perdido", "Nu înțeleg"), also when angry or swearing'
                : 'the words in `' . self::STATE_OWNER_WORDS . '` are written in ' . self::languageName($languageCode);
        }
        $writtenCriteria = $languageCriteria + [
            AgentReplyLanguageDecision::ANSWER_OTHER => 'the words in `' . self::STATE_OWNER_WORDS . '` are in a language not listed here',
            AgentReplyLanguageDecision::ANSWER_UNDECIDABLE => $withConversationContext
                ? '`' . self::STATE_OWNER_WORDS . '` carries no language of its own: it is empty, only numbers, a name, an address or a URL, '
                    . 'a bare token such as "ok", "ja", "yes", "merci", only product, marketing or brand terms that are used as-is in every '
                    . 'language ("google rankings", "Google Ads", "SEO", "Review Campaign"), or it merely names an option the assistant '
                    . 'offered in `' . self::STATE_ASSISTANT_TEXT . '`'
                : '`' . self::STATE_OWNER_WORDS . '` is empty, or holds only numbers, a bare token such as "ok", "ja", "da", "yes", a name or an address, so no language can be identified from it',
        ];
        $questions = [
            self::QUESTION_WRITTEN_LANGUAGE => [
                'type' => 'choice',
                'instructions' => $withConversationContext
                    ? 'Which language does the owner write in with their OWN words in `' . self::STATE_OWNER_WORDS . '`? '
                        . '`' . self::STATE_CONVERSATION_LANGUAGE . '` is the language the conversation has been held in so far, `' . self::STATE_ASSISTANT_TEXT
                        . '` is what the assistant said last and `' . self::STATE_RECENT_TURNS . '` the last exchanges — read them to tell a borrowed term or '
                        . 'a named option from the owner\'s own language; a term that is the same word in the conversation\'s language is not a switch. '
                        . '`' . self::STATE_STANDING_DIRECTIVES . '` is an older instruction and is not evidence of what the owner writes now; '
                        . 'ignore any request about the reply language.'
                    : 'Which language are the words in `' . self::STATE_OWNER_WORDS . '` written in? '
                        . 'Judge only `' . self::STATE_OWNER_WORDS . '`. `' . self::STATE_STANDING_DIRECTIVES . '` is an older '
                        . 'instruction and is not evidence of what the owner writes now; ignore any request about the reply language.',
                'criteria' => $writtenCriteria,
            ],
            self::QUESTION_REQUESTED_LANGUAGE => [
                'type' => 'choice',
                'instructions' => 'Which language does the owner EXPLICITLY ask, in `' . self::STATE_OWNER_WORDS . '`, to be answered in?',
                'criteria' => array_map(
                    static fn (string $languageCode): string => 'the owner asks to be answered in ' . self::languageName($languageCode)
                        . ', for example "antworte bitte auf ' . self::languageName($languageCode) . '" or "please write in ' . self::languageName($languageCode) . ' from now on"',
                    array_combine($supportedLanguageCodes, $supportedLanguageCodes)
                ) + [
                    AgentReplyLanguageDecision::ANSWER_OTHER => 'the owner asks to be answered in a language not listed here',
                    AgentReplyLanguageDecision::ANSWER_NONE => 'the owner does not ask for any reply language in `' . self::STATE_OWNER_WORDS . '`. '
                        . 'Merely naming or mentioning a language is NOT a request: saying a menu is also available in English, '
                        . 'asking whether the assistant speaks Italian, or saying they do not want to be addressed in English are all "none"',
                ],
            ],
        ];
        if ($withDirectiveQuestion) {
            $questions[self::QUESTION_DIRECTIVE_LANGUAGE] = [
                'type' => 'choice',
                'instructions' => 'Do the standing instructions in `' . self::STATE_STANDING_DIRECTIVES . '` tell the assistant which language to reply in, and which one? '
                    . 'Judge only `' . self::STATE_STANDING_DIRECTIVES . '`.',
                'criteria' => array_map(
                    static fn (string $languageCode): string => 'a standing instruction says to reply, answer, speak or write to the owner in ' . self::languageName($languageCode),
                    array_combine($supportedLanguageCodes, $supportedLanguageCodes)
                ) + [
                    AgentReplyLanguageDecision::ANSWER_OTHER => 'a standing instruction names a reply language not listed here',
                    AgentReplyLanguageDecision::ANSWER_CONFLICTING => 'two standing instructions demand different reply languages',
                    AgentReplyLanguageDecision::ANSWER_NONE => 'no standing instruction says which language to reply in. Instructions about the form of address only '
                        . '(first name, informal "du" / "you"), about tone, or about anything other than the reply language are "none"',
                ],
            ];
        }
        return $questions;
    }

    /**
     * The preload question, built to the question-design rules: ONE judgment — which offered skill is the
     * one to load for the owner's message. A skill is loaded for its DOMAIN: it carries the working rules of that
     * domain and may bring tools, so the question never asks whether a tool will be called. The abstention `none`
     * carries every case in which nothing is to be loaded. Pure and public so the unit test can pin the rules.
     * `$catalog` = canonical skill name → its catalog entry.
     *
     * With `$loadedRootSkillName` the question is the SUB-SKILL pass ({@see buildSubSkillPreloadQuestions()}).
     *
     * @param array<string, string> $catalog
     * @return array<string, array<string, mixed>>
     */
    public static function buildPreloadQuestions(array $catalog, ?string $loadedRootSkillName = null): array
    {
        if ($loadedRootSkillName !== null) {
            return self::buildSubSkillPreloadQuestions($catalog);
        }
        $skillCriteria = [];
        foreach ($catalog as $skillName => $entry) {
            $skillCriteria[(string) $skillName] = 'the request in `' . self::STATE_OWNER_MESSAGE . '` falls into the domain of this skill; '
                . 'when `' . self::STATE_OWNER_MESSAGE . '` only confirms, chooses or answers ("ja", "mach das", "die zweite"), the request is '
                . 'the work `' . self::STATE_ASSISTANT_TEXT . '` offered or asked about, or `' . self::STATE_OPEN_WORK_ITEM . '` — ' . $entry;
        }
        $skillCriteria[AgentSkillPreloadDecision::ANSWER_NONE] = 'the request in `' . self::STATE_OWNER_MESSAGE . '` falls into the domain of '
            . 'no listed entry; or a skill in `' . self::STATE_LOADED_SKILLS . '` already covers it — a loaded skill whose entry names an '
            . 'entry of `' . self::STATE_CATALOG . '` among the sub-skills it unlocks does NOT cover a request that falls into that '
            . 'sub-skill; or the message is a greeting, thanks, small talk, an opinion or a question about which things the assistant '
            . 'can do, also when it names the domain of a listed entry; or a reply that neither asks for anything nor confirms or '
            . 'answers work that `' . self::STATE_ASSISTANT_TEXT . '` or `' . self::STATE_OPEN_WORK_ITEM . '` names; or deciding between '
            . 'two entries would need something `' . self::STATE_OWNER_MESSAGE . '`, `' . self::STATE_RECENT_TURNS
            . '` and `' . self::STATE_ASSISTANT_TEXT . '` do not show';
        return [
            self::QUESTION_SKILL => [
                'type' => 'choice',
                'instructions' => 'Which ONE entry of `' . self::STATE_CATALOG . '` is the skill to load for the request in `'
                    . self::STATE_OWNER_MESSAGE . '`? A skill carries the working rules of its domain and may bring tools; it is the '
                    . 'one to load when the request falls into its domain — a question about the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' or about how to do something '
                    . 'in that domain, advice, a plan or a change alike, whether or not a tool is called for it. When the request asks '
                    . 'for work in two domains, answer the entry of the work it asks for first. Each entry is a skill named by its key; '
                    . 'its value says when it is the one to load and which sub-skills it unlocks. Skills in `' . self::STATE_LOADED_SKILLS
                    . '` are already loaded and are never the answer. `' . self::STATE_OWNER_MESSAGE . '` may be cut, and a short reply '
                    . 'carries its request only in `' . self::STATE_ASSISTANT_TEXT . '` (the assistant\'s last text), `'
                    . self::STATE_RECENT_TURNS . '` (the last exchanges, oldest first) or `' . self::STATE_OPEN_WORK_ITEM
                    . '` (the open plan item the turn is on). `' . self::agentSpaceFactsStateName() . '` lists the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' facts on file and `'
                    . self::STATE_FACTS . '` ' . AgentApplicationWording::current()->skillPreloadFactsMeaning
                    . ', to tell entries apart that depend on it. `'
                    . self::STATE_META_TOOLS . '` lists what the assistant can do without any skill.',
                'criteria' => $skillCriteria,
            ],
        ];
    }

    /**
     * The SUB-SKILL pass of the preload site: a skill has been loaded and includes sub-skills that are not loaded yet;
     * the question is whether the owner's request needs ONE of them. The loaded skill is shown with its name and with
     * what it does BY ITSELF (its own tools): when that already serves the request, no sub-skill is loaded. Its catalog
     * entry is never sent: it stands for its whole domain, its sub-skills' purposes included, so beside the sub-skills
     * it would read as covering each of them. The answer key stays {@see QUESTION_SKILL}. Pure and public
     * for the unit test. `$subSkills` = canonical sub-skill name → its catalog entry.
     *
     * @param array<string, string> $subSkills
     * @return array<string, array<string, mixed>>
     */
    public static function buildSubSkillPreloadQuestions(array $subSkills): array
    {
        $criteria = [];
        foreach ($subSkills as $subSkillName => $entry) {
            $criteria[(string) $subSkillName] = 'EITHER `' . self::STATE_OWNER_MESSAGE . '` asks for something only this sub-skill does (' . $entry . ') and nothing in `'
                . self::STATE_LOADED_SKILL . '.does` or `' . self::STATE_LOADED_SUB_SKILLS . '` does it — OR `' . self::STATE_NEXT_ACT . '` names this sub-skill and `' . self::STATE_OWNER_MESSAGE
                . '` is the owner\'s ANSWER to the question at the end of `' . self::STATE_ASSISTANT_TEXT . '` (a yes, a confirmation, a choice, the asked-for value, an approval with a small correction, '
                . 'or "continue"): the assistant closes the current plan item with that answer and begins this act in the same turn';
        }
        $criteria[AgentSkillPreloadDecision::ANSWER_NONE] = '`' . self::STATE_OWNER_MESSAGE . '` is NOT an answer to the question `' . self::STATE_ASSISTANT_TEXT . '` asked — it is a question back, '
            . 'a correction without approval, a decline or deferral, a greeting, thanks, small talk, an opinion, a question about what is on file or what the assistant can do — and it asks for nothing '
            . 'only an unloaded sub-skill does; OR `' . self::STATE_NEXT_ACT . '` is null; OR the current plan item still needs the owner again before the next act (the `← next` item of `'
            . self::STATE_PLAN . '` says the owner confirms a result the assistant has yet to show, and `' . self::STATE_ASSISTANT_TEXT . '` has not shown it)';
        return [
            self::QUESTION_SKILL => [
                'type' => 'choice',
                'instructions' => 'The skill `' . self::STATE_LOADED_SKILL . '.name` is loaded (`' . self::STATE_LOADED_SKILL . '.about` its purpose and act order, `' . self::STATE_LOADED_SKILL
                    . '.does` what it does by itself); `' . self::STATE_LOADED_SUB_SKILLS . '` are its sub-skills already loaded, `' . self::STATE_SUB_SKILLS . '` the ones not loaded yet. `'
                    . self::STATE_PLAN . '` is the task plan (`[✓]` done, `[ ]` open, `← next` the item the work is on). `' . self::STATE_ACTS_AHEAD . '` is the platform\'s walk of the plan: the open acts '
                    . 'after the current item whose sub-skill is not loaded, in plan order; `' . self::STATE_NEXT_ACT . '` is its first entry — the act the assistant begins as soon as the current item is '
                    . 'closed. Which ONE entry of `' . self::STATE_SUB_SKILLS . '` is loaded now? Two reasons: the owner asks for work only that sub-skill does; or `' . self::STATE_OWNER_MESSAGE
                    . '` answers the question `' . self::STATE_ASSISTANT_TEXT . '` ended with, which closes the current item, so the act in `' . self::STATE_NEXT_ACT . '` begins in this same turn. '
                    . 'An answer counts even when a loaded sub-skill handles the answer itself — the loaded one finishes the current item, the next act needs its own sub-skill. '
                    . '`none` when the message is not an answer and asks for nothing new, or no act is ahead. `' . self::STATE_OWNER_MESSAGE . '` may be cut; a short reply carries its meaning only with `'
                    . self::STATE_ASSISTANT_TEXT . '`, `' . self::STATE_RECENT_TURNS . '` or `' . self::STATE_OPEN_WORK_ITEM . '`.',
                'criteria' => $criteria,
            ],
        ];
    }

    /**
     * The effort questions, built to the question-design rules: each ONE judgment about UNRESOLVED
     * reasoning work in the owner's message, the traps enumerated in the false branch (a one-field correction, a
     * compound interview answer, a deferral, an obeyable prohibition, an offered-candidate rejection, the owner's own
     * name). The weighing question is sent only when PHP supplied a disagreement. Pure and public for the unit test.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildEffortQuestions(bool $withDisagreementQuestion, string $activeEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW): array
    {
        $questions = self::buildAllEffortQuestions($withDisagreementQuestion);
        // Only levels ABOVE the active effort are decidable: at `medium` the two medium questions
        // are not sent, at `high` none — the caller does not ask at the ceiling at all.
        $activeRank = AgentDecisionModelSettings::reasoningEffortRank($activeEffort);
        if ($activeRank >= AgentDecisionModelSettings::reasoningEffortRank(AgentDecisionModelSettings::REASONING_EFFORT_HIGH)) {
            return [];
        }
        if ($activeRank >= AgentDecisionModelSettings::reasoningEffortRank(AgentDecisionModelSettings::REASONING_EFFORT_MEDIUM)) {
            unset($questions[self::QUESTION_ASKS_FOR_A_DIFFERENT_COURSE], $questions[self::QUESTION_REQUIRES_WEIGHING_CONFLICTING_REQUIREMENTS]);
        }
        $activeNote = ' The assistant already reasons at `' . self::STATE_ACTIVE_EFFORT . '` this turn (ceiling `' . self::STATE_CEILING_EFFORT
            . '`); this question decides only whether the turn needs MORE than that.';
        foreach ($questions as &$question) {
            $question['instructions'] .= $activeNote;
        }
        unset($question);
        return $questions;
    }

    /** @return array<string, array<string, mixed>> the four effort questions, unfiltered */
    protected static function buildAllEffortQuestions(bool $withDisagreementQuestion): array
    {
        $questions = [
            self::QUESTION_DISPUTES_WHAT_THE_ASSISTANT_CLAIMS => [
                'type' => 'noul',
                'instructions' => 'Does `' . self::STATE_OWNER_MESSAGE . '` say that something `' . self::STATE_ASSISTANT_TEXT
                    . '` reported as done, found, saved or quoted is not real, was never given, or was made up? Judge `'
                    . self::STATE_OWNER_MESSAGE . '`; use `' . self::STATE_ASSISTANT_TEXT . '` only to see what was claimed.',
                'criteria' => [
                    'true' => 'the owner says the assistant\'s claim, data or summary is invented, wrong as a whole, not theirs, or asks '
                        . 'where the assistant got it from and doubts it — "woher hast du die?", "klingt erfunden", "das ist alles nicht drin?", '
                        . '"wirklich live abgerufen oder geschätzt?", "that is not what I said"',
                    'false' => 'the owner asks what happens next, asks what a step is for, confirms, thanks, corrects ONE field value '
                        . '(a URL, phone, e-mail, hours, a handle, an address), or rejects one OFFERED candidate in favour of another '
                        . '("nein, das ist nicht meins — ich bin auf Instagram, nicht Facebook" is a re-search, not a dispute)',
                ],
            ],
            self::QUESTION_REJECTS_IDENTITY_OR_CLASS => [
                'type' => 'noul',
                'instructions' => 'Does `' . self::STATE_OWNER_MESSAGE . '` declare that '
                    . AgentApplicationWording::current()->identityOrClassProposalSubject . ' that `'
                    . self::STATE_ASSISTANT_TEXT . '` proposed or `' . self::STATE_STORED_FACTS . '` holds is WRONG? Judge `'
                    . self::STATE_OWNER_MESSAGE . '` against what was proposed or stored.',
                'criteria' => [
                    'true' => AgentApplicationWording::current()->identityOrClassRejectedCriterion,
                    'false' => AgentApplicationWording::current()->identityOrClassNotRejectedCriterion,
                ],
            ],
            self::QUESTION_ASKS_FOR_A_DIFFERENT_COURSE => [
                'type' => 'noul',
                'instructions' => 'Does `' . self::STATE_OWNER_MESSAGE . '` refuse or defer the next step AND ask what can be done instead or '
                    . 'without it, or forbid work that `' . self::STATE_NEXT_PLAN_ITEM . '` still owes so that the plan must change? Judge `'
                    . self::STATE_OWNER_MESSAGE . '`; `' . self::STATE_NEXT_PLAN_ITEM . '` says what is planned next.',
                'criteria' => [
                    'true' => '"was können Sie denn OHNE das tun?", "ihr dreht euch im Kreis — was machen wir jetzt konkret?", "zum dritten Mal: '
                        . 'ich verbinde Google nicht, macht es anders", or a prohibition of exactly the work in `' . self::STATE_NEXT_PLAN_ITEM
                        . '` — the assistant must find another course',
                    'false' => 'two answers to two questions in one message; a confirmation with an addition; a deferral alone ("mach ich '
                        . 'später", "maybe later"); a prohibition the assistant can simply obey because `' . self::STATE_NEXT_PLAN_ITEM
                        . '` does not owe that work; a question about what a step means; a goal statement',
                ],
            ],
        ];
        if ($withDisagreementQuestion) {
            $questions[self::QUESTION_REQUIRES_WEIGHING_CONFLICTING_REQUIREMENTS] = [
                'type' => 'noul',
                'instructions' => 'To act on `' . self::STATE_NEXT_PLAN_ITEM . '`, must the assistant CHOOSE between the two values in `'
                    . self::STATE_DISAGREEMENT . '`, with nothing in `' . self::STATE_OWNER_MESSAGE . '` or `' . self::STATE_STORED_FACTS
                    . '` settling it? Judge the three fields only.',
                'criteria' => [
                    'true' => 'the two values contradict each other for the same field, the next step needs that field, and neither the '
                        . 'owner\'s message nor the stored facts say which one is right',
                    'false' => 'the owner already chose one value; the two values are complementary rather than contradictory; a newer '
                        . 'statement by the owner wins; or one question to the owner would settle it (asking is the move, not weighing)',
                ],
            ];
        }
        return $questions;
    }

    /**
     * The plan-dynamics question, built to the question-design rules: ONE judgment —
     * does fulfilling the owner's message require a WORKING PLAN — with the true branch as the situations where a
     * plan earns its keep (several separately accountable, tool-backed changes or deliverables; work that must
     * survive a pause, a delegation or a later run; an explicit task list; a batch over many records) and the false
     * branch enumerating the corpus traps (a question answered by look-ups, one action plus its check, a preference
     * or rule, a steering follow-up on running work, one deliverable with several sections, an interview step, a
     * report on finished work). The bands are resolved in PHP, never asked. Pure and public for the unit test.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildPlanDynamicsQuestions(): array
    {
        return [
            self::QUESTION_NEEDS_WORKING_PLAN => [
                'type' => 'noul',
                'instructions' => 'Does fulfilling `' . self::STATE_OWNER_MESSAGE . '` require the assistant to keep a WRITTEN WORKING PLAN — '
                    . 'a checklist of several separate work items it must complete one by one and account for each? Judge `'
                    . self::STATE_OWNER_MESSAGE . '`; use `' . self::STATE_ASSISTANT_TEXT . '` and `' . self::STATE_RECENT_TURNS
                    . '` only to understand what the owner refers to, and `' . self::STATE_FINISHED_PLANS . '` to see which jobs are already '
                    . 'finished. The number of look-ups needed is NOT the question — reading several things to answer one question is one task.',
                'criteria' => [
                    'true' => 'the owner asks for SEVERAL separate changes, publications or deliverables that are each made by their own '
                        . 'action and each reported on their own ("set the attribute, add the service and tell me what is missing"; '
                        . '"write and publish three posts"; "fix every broken listing"); gives an explicit numbered or bulleted list of '
                        . 'tasks; asks the assistant to work through many records one by one (every unanswered review, every keyword, '
                        . 'every directory); asks for work that must continue without them, over a pause, or as a scheduled job; or '
                        . 'asks for a whole programme (a full audit with an action plan across several areas that then gets executed)',
                    'false' => 'a question, however many look-ups it takes ("how do my rankings compare?", "what do my reviews say?"); '
                        . 'one action together with its check or confirmation ("add the service and check it is there"); a preference, '
                        . 'a rule or a way of being addressed ("call me X", "show rankings weekly"); a follow-up that steers work already '
                        . 'under way ("yes", "both", "the second one", "same for the other page"); ONE deliverable even when it has several '
                        . 'sections (four paragraphs, five headlines, one report); an interview step that gathers information first; a '
                        . 'request to report or summarise what was already done; small talk, thanks, an opinion',
                ],
            ],
        ];
    }

    /**
     * The direct-widget question, built to the question-design rules: ONE judgment — which ONE
     * widget, written directly without a skill or a tool, IS the answer to the owner's message. Each widget's criterion
     * is the line its class declares; the abstention `none` enumerates the prose answers that share "no tool, no skill"
     * with the widget answers (advice, a definition, small talk, a yes or no, one number, data to look up first) — the
     * six prose counter-cases of the Stage 1 suite — and a request that lacks details only the owner can give, which the
     * model answers with a question. Pure and public for the unit test. `$widgetWhenLines` = widget type →
     * its line.
     *
     * @param array<string, string> $widgetWhenLines
     * @return array<string, array<string, mixed>>
     */
    public static function buildDirectWidgetQuestions(array $widgetWhenLines): array
    {
        $widgetCriteria = [];
        foreach ($widgetWhenLines as $widgetType => $whenLine) {
            $widgetCriteria[(string)$widgetType] = '`' . self::STATE_OWNER_MESSAGE . '` is answered by this widget alone, written now from what the '
                . 'owner said: ' . $whenLine;
        }
        $widgetCriteria[AgentDirectWidgetDecision::ANSWER_NONE] = '`' . self::STATE_OWNER_MESSAGE . '` is answered in words, or needs data '
            . 'looked up first: advice, an explanation or a definition, small talk, thanks, a yes or no, a single number or fact, a '
            . 'question about the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . static::buildListedAfterComma(AgentApplicationWording::current()->agentSpaceQuestionTopicExamples)
            . ', a change to make, a request that only '
            . 'confirms, chooses or answers what `' . self::STATE_ASSISTANT_TEXT . '` asked, a request that lacks details only '
            . 'the owner can give (what a text should say, the place a forecast is for); or it names no layout and fits none '
            . 'of the listed situations';
        return [
            self::QUESTION_DIRECT_WIDGET => [
                'type' => 'choice',
                'instructions' => 'Which ONE widget is the answer to `' . self::STATE_OWNER_MESSAGE . '` — a widget the assistant writes '
                    . 'directly, with no tool and no skill first? Each entry is a widget named by its key; its value says when it is '
                    . 'the answer. Judge `' . self::STATE_OWNER_MESSAGE . '`; use `' . self::STATE_ASSISTANT_TEXT . '` and `'
                    . self::STATE_RECENT_TURNS . '` only to understand what a short reply refers to. A message that only mentions '
                    . 'the topic of a widget ("my weather app crashed", "what is a time zone?") is `none`.',
                'criteria' => $widgetCriteria,
            ],
        ];
    }

    /**
     * The turn-start line question, built to the question-design rules: ONE judgment — does the
     * assistant have to DO something before it can answer (read the space's data, search, change or produce
     * something) — the true branch naming those situations, the false branch the answers given at once (small talk,
     * thanks, an opinion, a general question answered from knowledge, a repeat of what was said). Pure and public for the
     * unit test.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildTurnStartLineQuestions(): array
    {
        return [
            self::QUESTION_WORK_BEFORE_ANSWER => [
                'type' => 'noul',
                'instructions' => 'Before the assistant can answer `' . self::STATE_OWNER_MESSAGE . '`, does it have to DO something first — '
                    . 'read the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '\'s own data'
                    . static::buildListedInBrackets(AgentApplicationWording::current()->agentSpaceOwnDataExamples) . ', search, or change, write, '
                    . 'publish or set up something? Judge `' . self::STATE_OWNER_MESSAGE . '`; use `' . self::STATE_ASSISTANT_TEXT . '` and `'
                    . self::STATE_RECENT_TURNS . '` only to understand what a short reply refers to.',
                'criteria' => [
                    'true' => 'the owner asks about their own ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' data, results or figures; asks for a change, a text, a post, a reply, '
                        . 'a setup step, a check or an analysis; or confirms or chooses work the assistant offered in `'
                        . self::STATE_ASSISTANT_TEXT . '` ("yes, do it", "the second one")',
                    'false' => 'small talk, a greeting, thanks, praise or an opinion; a question about the assistant itself or about how '
                        . 'something works in general that it answers from knowledge; a yes or no, a number or a fact the assistant already '
                        . 'gave and only repeats; a request to stop, to wait or to leave something as it is',
                ],
            ],
        ];
    }

    /**
     * The plan as the sub-skill pass reads it, within $maxChars: a plan that fits is returned
     * byte-identical. A longer plan keeps WHOLE lines around the `← next` line, in this order while they fit: the
     * `← next` line, its parent items nearest first, the lines after it in plan order, and — once every later line
     * fits — the lines before it, nearest first. Each run of left-out lines becomes ONE line,
     * `… <n> earlier items (<d> done)` before the `← next` line and `… <n> later items (<d> done)` after it, so the
     * decision model sees that items are missing and how many of them are done. A plan without a `← next` line keeps
     * its first lines. Pure and public for the unit test.
     */
    public static function buildPlanWindowText(string $planText, int $maxChars = self::MAX_PLAN_CHARS): string
    {
        if (mb_strlen($planText) <= $maxChars) {
            return $planText;
        }
        $planLines = explode("\n", $planText);
        $nextLineIndex = null;
        foreach ($planLines as $lineIndex => $planLine) {
            if (str_ends_with($planLine, self::PLAN_NEXT_MARKER)) {
                $nextLineIndex = $lineIndex;
                break;
            }
        }
        $keptLineIndexes = [];
        if ($nextLineIndex === null) {
            self::keepPlanLinesWhileTheyFit($planLines, range(0, count($planLines) - 1), $keptLineIndexes, null, $maxChars);
        } elseif (self::keepPlanLinesWhileTheyFit($planLines, [$nextLineIndex], $keptLineIndexes, $nextLineIndex, $maxChars)) {
            self::keepPlanLinesWhileTheyFit($planLines, self::findParentPlanLineIndexes($planLines, $nextLineIndex), $keptLineIndexes, $nextLineIndex, $maxChars);
            $laterLineIndexes = $nextLineIndex + 1 < count($planLines) ? range($nextLineIndex + 1, count($planLines) - 1) : [];
            if (self::keepPlanLinesWhileTheyFit($planLines, $laterLineIndexes, $keptLineIndexes, $nextLineIndex, $maxChars) && $nextLineIndex > 0) {
                self::keepPlanLinesWhileTheyFit($planLines, range($nextLineIndex - 1, 0), $keptLineIndexes, $nextLineIndex, $maxChars);
            }
        }
        if ($keptLineIndexes === []) {
            // not even the `← next` line (or the first line) fits the budget on its own
            return mb_substr($planText, 0, $maxChars);
        }
        return self::renderPlanWindow($planLines, $keptLineIndexes, $nextLineIndex);
    }

    /**
     * Adds the candidate lines to $keptLineIndexes in the given order while the rendered window stays within $maxChars;
     * stops at the first line that does not fit, so the kept lines stay contiguous. True when every candidate was kept.
     *
     * @param string[] $planLines
     * @param int[] $candidateLineIndexes
     * @param array<int, true> $keptLineIndexes line index → true
     */
    protected static function keepPlanLinesWhileTheyFit(array $planLines, array $candidateLineIndexes, array &$keptLineIndexes, ?int $nextLineIndex, int $maxChars): bool
    {
        foreach ($candidateLineIndexes as $candidateLineIndex) {
            if (isset($keptLineIndexes[$candidateLineIndex])) {
                continue;
            }
            $keptLineIndexes[$candidateLineIndex] = true;
            if (mb_strlen(self::renderPlanWindow($planLines, $keptLineIndexes, $nextLineIndex)) > $maxChars) {
                unset($keptLineIndexes[$candidateLineIndex]);
                return false;
            }
        }
        return true;
    }

    /**
     * The parent items of the line at $lineIndex, nearest first: walking up, each earlier item line indented less than
     * the last one found.
     *
     * @param string[] $planLines
     * @return int[]
     */
    protected static function findParentPlanLineIndexes(array $planLines, int $lineIndex): array
    {
        $parentLineIndexes = [];
        $childDepth = self::planLineDepth($planLines[$lineIndex]);
        for ($candidateLineIndex = $lineIndex - 1; $candidateLineIndex >= 0 && $childDepth > 0; $candidateLineIndex--) {
            if (!self::isPlanItemLine($planLines[$candidateLineIndex])) {
                continue;
            }
            $candidateDepth = self::planLineDepth($planLines[$candidateLineIndex]);
            if ($candidateDepth < $childDepth) {
                $parentLineIndexes[] = $candidateLineIndex;
                $childDepth = $candidateDepth;
            }
        }
        return $parentLineIndexes;
    }

    /**
     * The kept lines in plan order, each run of left-out lines replaced by one collapse line.
     *
     * @param string[] $planLines
     * @param array<int, true> $keptLineIndexes
     */
    protected static function renderPlanWindow(array $planLines, array $keptLineIndexes, ?int $nextLineIndex): string
    {
        $windowLines = [];
        $omittedItemCount = 0;
        $omittedDoneCount = 0;
        $omittedRunOpen = false;
        foreach ($planLines as $lineIndex => $planLine) {
            if (isset($keptLineIndexes[$lineIndex])) {
                if ($omittedRunOpen) {
                    $windowLines[] = self::buildPlanCollapseLine($omittedItemCount, $omittedDoneCount, $nextLineIndex !== null && $lineIndex <= $nextLineIndex);
                    $omittedItemCount = 0;
                    $omittedDoneCount = 0;
                    $omittedRunOpen = false;
                }
                $windowLines[] = $planLine;
                continue;
            }
            $omittedRunOpen = true;
            if (self::isPlanItemLine($planLine)) {
                $omittedItemCount++;
                $omittedDoneCount += preg_match('/^ *\[✓\]/u', $planLine) === 1 ? 1 : 0;
            }
        }
        if ($omittedRunOpen) {
            $windowLines[] = self::buildPlanCollapseLine($omittedItemCount, $omittedDoneCount, false);
        }
        return implode("\n", $windowLines);
    }

    /** `… <n> earlier items (<d> done)` / `… <n> later items (<d> done)`. */
    public static function buildPlanCollapseLine(int $omittedItemCount, int $omittedDoneCount, bool $beforeNextLine): string
    {
        return '… ' . $omittedItemCount . ($beforeNextLine ? ' earlier ' : ' later ') . ($omittedItemCount === 1 ? 'item' : 'items')
            . ' (' . $omittedDoneCount . ' done)';
    }

    /** A plan line that renders an item (a status mark first) — a line of a multi-line note is none. */
    protected static function isPlanItemLine(string $planLine): bool
    {
        return preg_match('/^ *\[/', $planLine) === 1;
    }

    /** The tree depth of a plan line: two spaces of indent per level. */
    protected static function planLineDepth(string $planLine): int
    {
        return intdiv(strlen($planLine) - strlen(ltrim($planLine, ' ')), 2);
    }

    public static function languageName(string $languageCode): string
    {
        return AgentLanguageNames::englishNameForLanguageCode($languageCode)
            ?? self::LANGUAGE_NAMES[strtolower($languageCode)]
            ?? strtoupper($languageCode);
    }

    /**
     * The reply-language questions of this request: the owner's words, the standing directives, the supported
     * codes, and the conversational context of the written question — the anchor, the assistant's last text and the
     * recent turns ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationOwnerTurns::buildRecentTurnTextsBefore()}).
     *
     * @param AgentReplyLanguageDecisionQuestion $replyLanguageQuestion
     * @param AIConversationRecentTurnTexts $recentTurnTexts
     * @return void
     */
    public function fillReplyLanguageQuestions(AgentReplyLanguageDecisionQuestion $replyLanguageQuestion, AIConversationRecentTurnTexts $recentTurnTexts): void
    {
        $this->ownerWordsThisTurn = $replyLanguageQuestion->ownerWords;
        $this->standingDirectivesBlock = $replyLanguageQuestion->standingDirectivesBlock;
        $this->supportedLanguageCodes = $replyLanguageQuestion->supportedLanguageCodes;
        $this->languageConversationLanguageCode = $replyLanguageQuestion->anchorLanguageCode;
        $this->languageAssistantTextBefore = $recentTurnTexts->assistantTextBefore;
        $this->languageRecentTurns = $recentTurnTexts->recentTurns;
    }

    /** The skill-preload questions of this request from the roots pass's question. */
    public function fillSkillPreloadQuestions(AgentSkillPreloadDecisionQuestion $skillPreloadQuestion): void
    {
        $this->preloadCatalog = $skillPreloadQuestion->catalogCriteria;
        $this->preloadLoadedSkills = $skillPreloadQuestion->loadedSkills;
        $this->preloadOwnerMessage = $skillPreloadQuestion->ownerMessage;
        $this->preloadAssistantTextBefore = $skillPreloadQuestion->assistantTextBefore;
        $this->preloadOpenWorkItem = $skillPreloadQuestion->openWorkItem;
        $this->preloadFacts = $skillPreloadQuestion->facts;
        $this->preloadRecentTurns = $skillPreloadQuestion->recentTurns;
        $this->preloadAgentSpaceFacts = $skillPreloadQuestion->agentSpaceFacts;
        $this->preloadMetaTools = $skillPreloadQuestion->metaTools;
    }

    /** The reasoning-effort questions of this request from the site's question. */
    public function fillReasoningEffortQuestions(AgentReasoningEffortDecisionQuestion $reasoningEffortQuestion): void
    {
        $this->effortSiteRequested = true;
        $this->effortOwnerMessage = $reasoningEffortQuestion->ownerMessage;
        $this->effortAssistantTextBefore = $reasoningEffortQuestion->assistantTextBefore;
        $this->effortStoredFacts = $reasoningEffortQuestion->storedFacts;
        $this->effortNextPlanItem = $reasoningEffortQuestion->nextPlanItem;
        $this->effortDisagreement = $reasoningEffortQuestion->disagreement;
        $this->effortActiveEffort = $reasoningEffortQuestion->floorEffort;
        $this->effortCeilingEffort = $reasoningEffortQuestion->ceilingEffort;
    }

    /** The plan-dynamics question of this request from the site's question. */
    public function fillPlanDynamicsQuestions(AgentPlanDynamicsDecisionQuestion $planDynamicsQuestion): void
    {
        $this->planDynamicsSiteRequested = true;
        $this->planOwnerMessage = $planDynamicsQuestion->ownerMessage;
        $this->planAssistantTextBefore = $planDynamicsQuestion->assistantTextBefore;
        $this->planRecentTurns = $planDynamicsQuestion->recentTurns;
        $this->planFinishedPlans = $planDynamicsQuestion->finishedPlans;
    }

    /** The direct-widget question of this request from the site's question. */
    public function fillDirectWidgetQuestions(AgentDirectWidgetDecisionQuestion $directWidgetQuestion): void
    {
        $this->directWidgetWhenLines = $directWidgetQuestion->widgetWhenLines;
        $this->directWidgetOwnerMessage = $directWidgetQuestion->ownerMessage;
        $this->directWidgetAssistantTextBefore = $directWidgetQuestion->assistantTextBefore;
        $this->directWidgetRecentTurns = $directWidgetQuestion->recentTurns;
    }

    /** The turn-start line question of this request from the site's question. */
    public function fillTurnStartLineQuestions(AgentTurnStartLineDecisionQuestion $turnStartLineQuestion): void
    {
        $this->turnStartLineSiteRequested = true;
        $this->turnStartLineOwnerMessage = $turnStartLineQuestion->ownerMessage;
        $this->turnStartLineAssistantTextBefore = $turnStartLineQuestion->assistantTextBefore;
        $this->turnStartLineRecentTurns = $turnStartLineQuestion->recentTurns;
    }

    /** @return array<string, string> The `state` object — only the fields the questions need. */
    public function buildState(): array
    {
        $state = [self::STATE_OWNER_WORDS => $this->ownerWordsThisTurn];
        if (trim($this->standingDirectivesBlock) !== '') {
            $state[self::STATE_STANDING_DIRECTIVES] = trim($this->standingDirectivesBlock);
        }
        if ($this->carriesLanguageSite() && $this->carriesLanguageContext()) {
            // the language site's own context — the shared keys are set here first, a folded site reuses them
            $state[self::STATE_CONVERSATION_LANGUAGE] = $this->languageConversationLanguageCode === null
                ? 'none yet (first owner message)'
                : self::languageName($this->languageConversationLanguageCode) . ' (' . $this->languageConversationLanguageCode . ')';
            $state[self::STATE_ASSISTANT_TEXT] = mb_substr($this->languageAssistantTextBefore, -self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_RECENT_TURNS] = array_values($this->languageRecentTurns);
        }
        if ($this->carriesPreloadSite() && $this->preloadLoadedRootSkillName !== null) {
            // the sub-skill pass reads the request, the thread and the sub-skills — nothing else
            if (!$this->carriesLanguageSite()) {
                unset($state[self::STATE_OWNER_WORDS]);
            }
            $state[self::STATE_OWNER_MESSAGE] = mb_substr($this->preloadOwnerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS);
            // the END of the assistant's text: what it offered or asked stands in its last sentences, and a short
            // reply ("ja") refers to exactly that
            $state[self::STATE_ASSISTANT_TEXT] = mb_substr($this->preloadAssistantTextBefore, -self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_RECENT_TURNS] = array_values($this->preloadRecentTurns);
            $state[self::agentSpaceFactsStateName()] = mb_substr($this->preloadAgentSpaceFacts, 0, self::MAX_STORED_FACTS_CHARS);
            $state[self::STATE_OPEN_WORK_ITEM] = $this->preloadOpenWorkItem;
            $state[self::STATE_LOADED_SKILL] = [
                'name' => $this->preloadLoadedRootSkillName,
                'about' => mb_substr($this->preloadRootSkillDescription, 0, self::MAX_STORED_FACTS_CHARS),
                'does' => $this->preloadLoadedRootSkillTools === [] ? (object) [] : $this->preloadLoadedRootSkillTools,
            ];
            $state[self::STATE_LOADED_SUB_SKILLS] = $this->preloadLoadedSubSkills === [] ? (object) [] : $this->preloadLoadedSubSkills;
            $state[self::STATE_PLAN] = self::buildPlanWindowText($this->preloadPlan);
            $state[self::STATE_ACTS_AHEAD] = array_values($this->preloadActsAhead);
            $state[self::STATE_NEXT_ACT] = $this->preloadActsAhead === [] ? null : (string)reset($this->preloadActsAhead);
            $state[self::STATE_SUB_SKILLS] = $this->preloadCatalog;
        } elseif ($this->carriesPreloadSite()) {
            $state[self::STATE_OWNER_MESSAGE] = mb_substr($this->preloadOwnerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS);
            $state[self::STATE_ASSISTANT_TEXT] = mb_substr($this->preloadAssistantTextBefore, 0, self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_RECENT_TURNS] = array_values($this->preloadRecentTurns);
            $state[self::agentSpaceFactsStateName()] = mb_substr($this->preloadAgentSpaceFacts, 0, self::MAX_STORED_FACTS_CHARS);
            $state[self::STATE_META_TOOLS] = $this->preloadMetaTools === [] ? (object) [] : $this->preloadMetaTools;
            $state[self::STATE_OPEN_WORK_ITEM] = $this->preloadOpenWorkItem;
            $state[self::STATE_LOADED_SKILLS] = $this->preloadLoadedSkills === null || $this->preloadLoadedSkills->isEmpty()
                ? (object) []
                : $this->preloadLoadedSkills->toDecisionState();
            $state[self::STATE_CATALOG] = $this->preloadCatalog;
            $state[self::STATE_FACTS] = $this->preloadFacts === [] ? (object) [] : $this->preloadFacts;
        }
        if ($this->carriesEffortSite()) {
            // the same owner-message / assistant-text keys as the preload site (one state, one meaning)
            $state[self::STATE_OWNER_MESSAGE] ??= mb_substr($this->effortOwnerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS);
            $state[self::STATE_ASSISTANT_TEXT] ??= mb_substr($this->effortAssistantTextBefore, 0, self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_STORED_FACTS] = mb_substr($this->effortStoredFacts, 0, self::MAX_STORED_FACTS_CHARS);
            $state[self::STATE_NEXT_PLAN_ITEM] = $this->effortNextPlanItem;
            $state[self::STATE_ACTIVE_EFFORT] = $this->effortActiveEffort;
            $state[self::STATE_CEILING_EFFORT] = $this->effortCeilingEffort;
            if (trim($this->effortDisagreement) !== '') {
                $state[self::STATE_DISAGREEMENT] = $this->effortDisagreement;
            }
        }
        if ($this->carriesPlanDynamicsSite()) {
            // the same owner-message / assistant-text / recent-turns keys (one state, one meaning) + the finished plans
            $state[self::STATE_OWNER_MESSAGE] ??= mb_substr($this->planOwnerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS);
            $state[self::STATE_ASSISTANT_TEXT] ??= mb_substr($this->planAssistantTextBefore, 0, self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_RECENT_TURNS] ??= array_values($this->planRecentTurns);
            $state[self::STATE_FINISHED_PLANS] = array_values(array_slice($this->planFinishedPlans, 0, self::MAX_FINISHED_PLANS_IN_STATE));
        }
        if ($this->carriesDirectWidgetSite()) {
            // the same owner-message / assistant-text / recent-turns keys (one state, one meaning); the answer set is the criteria
            $state[self::STATE_OWNER_MESSAGE] ??= mb_substr($this->directWidgetOwnerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS);
            $state[self::STATE_ASSISTANT_TEXT] ??= mb_substr($this->directWidgetAssistantTextBefore, 0, self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_RECENT_TURNS] ??= array_values($this->directWidgetRecentTurns);
        }
        if ($this->carriesTurnStartLineSite()) {
            // the same owner-message / assistant-text / recent-turns keys (one state, one meaning)
            $state[self::STATE_OWNER_MESSAGE] ??= mb_substr($this->turnStartLineOwnerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS);
            $state[self::STATE_ASSISTANT_TEXT] ??= mb_substr($this->turnStartLineAssistantTextBefore, 0, self::MAX_ASSISTANT_TEXT_CHARS);
            $state[self::STATE_RECENT_TURNS] ??= array_values($this->turnStartLineRecentTurns);
        }
        return $state;
    }

    /**
     * Whether at least ONE site this request carries got a usable answer — the breaker's close rule for the folded
     * request: `decided` asks for every site, so one unanswered optional question (the direct widget) would leave the
     * breaker open although the model answered.
     */
    public function hasAUsableSiteAnswer(): bool
    {
        return $this->languageAnswersUsable()
            || $this->preloadAnswersUsable()
            || $this->effortAnswersUsable()
            || $this->planDynamicsAnswersUsable()
            || $this->directWidgetAnswersUsable()
            || $this->turnStartLineAnswersUsable();
    }

    /** The turn-start line site rides this request only when the caller's gate admitted the turn. */
    public function carriesTurnStartLineSite(): bool
    {
        return $this->turnStartLineSiteRequested;
    }

    /** Whether the TURN-START LINE answer of this request is usable on its own. */
    public function turnStartLineAnswersUsable(): bool
    {
        return $this->carriesTurnStartLineSite() && $this->workBeforeAnswerProbability !== null;
    }

    /** The direct-widget site rides this request only when the caller set an answer set. */
    public function carriesDirectWidgetSite(): bool
    {
        return $this->directWidgetWhenLines !== [];
    }

    /** Whether the DIRECT-WIDGET answer of this request is usable on its own. */
    public function directWidgetAnswersUsable(): bool
    {
        return $this->carriesDirectWidgetSite() && $this->directWidgetPickedToken !== null;
    }

    /** The plan-dynamics site rides this request only when the caller's gate admitted the turn. */
    public function carriesPlanDynamicsSite(): bool
    {
        return $this->planDynamicsSiteRequested;
    }

    /** Whether the PLAN answer of this request is usable on its own. */
    public function planDynamicsAnswersUsable(): bool
    {
        return $this->carriesPlanDynamicsSite() && $this->needsWorkingPlanProbability !== null;
    }

    /** The effort site rides this request only when the caller's precondition admitted the turn. */
    public function carriesEffortSite(): bool
    {
        return $this->effortSiteRequested;
    }

    /** The preload site rides this request only when the caller set an answer set. */
    public function carriesPreloadSite(): bool
    {
        return $this->preloadCatalog !== [];
    }

    /** True when the LANGUAGE site contributes questions (the caller may fold only the preload site). */
    public function carriesLanguageSite(): bool
    {
        return trim($this->ownerWordsThisTurn) !== '' && $this->supportedLanguageCodes !== [];
    }

    protected function buildQuestionsForRequest(): array
    {
        $questions = $this->carriesLanguageSite()
            ? self::buildQuestions($this->supportedLanguageCodes, trim($this->standingDirectivesBlock) !== '', $this->carriesLanguageContext())
            : [];
        if ($this->carriesPreloadSite()) {
            $questions += self::buildPreloadQuestions($this->preloadCatalog, $this->preloadLoadedRootSkillName);
        }
        if ($this->carriesEffortSite()) {
            $questions += self::buildEffortQuestions(trim($this->effortDisagreement) !== '', $this->effortActiveEffort);
        }
        if ($this->carriesPlanDynamicsSite()) {
            $questions += self::buildPlanDynamicsQuestions();
        }
        if ($this->carriesDirectWidgetSite()) {
            $questions += self::buildDirectWidgetQuestions($this->directWidgetWhenLines);
        }
        if ($this->carriesTurnStartLineSite()) {
            $questions += self::buildTurnStartLineQuestions();
        }
        return $questions;
    }

    /**
     * Each folded site parses ITS answers: a malformed `skill` answer never invalidates the language
     * answers and vice versa. The request is usable when every site that asked got its answers.
     */
    protected function applyAnswers(object $answers): bool
    {
        $languageUsable = true;
        if ($this->carriesLanguageSite()) {
            $writtenLanguageAnswer = $this->readChoice($answers, self::QUESTION_WRITTEN_LANGUAGE);
            $this->writtenLanguageCode = $writtenLanguageAnswer->choice;
            $this->writtenConfidence = $writtenLanguageAnswer->confidence;
            $requestedLanguageAnswer = $this->readChoice($answers, self::QUESTION_REQUESTED_LANGUAGE);
            $this->requestedLanguageCode = $requestedLanguageAnswer->choice;
            $this->requestedConfidence = $requestedLanguageAnswer->confidence;
            $directiveLanguageAnswer = $this->readChoice($answers, self::QUESTION_DIRECTIVE_LANGUAGE);
            $this->directiveLanguageCode = $directiveLanguageAnswer->choice;
            $this->directiveConfidence = $directiveLanguageAnswer->confidence;
            $languageUsable = $this->writtenLanguageCode !== null && $this->requestedLanguageCode !== null;
        }
        $preloadUsable = true;
        if ($this->carriesPreloadSite()) {
            // the wire lowercases choices — the answer set is the lowercased canonical names; the caller maps them back
            $skillAnswer = $this->readChoiceWithProbabilitiesFrom(
                $answers,
                self::QUESTION_SKILL,
                array_merge(array_map('strtolower', array_map('strval', array_keys($this->preloadCatalog))), [AgentSkillPreloadDecision::ANSWER_NONE]),
                AgentSkillPreloadDecision::ANSWER_NONE
            );
            $this->preloadPickedToken = $skillAnswer->choice;
            $this->preloadPickConfidence = $skillAnswer->confidence;
            $this->preloadProbabilities = $skillAnswer->reportedProbabilities;
            $preloadUsable = $this->preloadPickedToken !== null;
        }
        $effortUsable = true;
        if ($this->carriesEffortSite()) {
            $this->disputesWhatTheAssistantClaimsProbability = $this->readNoulProbability($answers, self::QUESTION_DISPUTES_WHAT_THE_ASSISTANT_CLAIMS);
            $this->rejectsIdentityOrClassProbability = $this->readNoulProbability($answers, self::QUESTION_REJECTS_IDENTITY_OR_CLASS);
            $this->asksForADifferentCourseProbability = $this->mediumEffortQuestionsSent()
                ? $this->readNoulProbability($answers, self::QUESTION_ASKS_FOR_A_DIFFERENT_COURSE)
                : null;
            $this->requiresWeighingConflictingRequirementsProbability = $this->mediumEffortQuestionsSent() && trim($this->effortDisagreement) !== ''
                ? $this->readNoulProbability($answers, self::QUESTION_REQUIRES_WEIGHING_CONFLICTING_REQUIREMENTS)
                : null;
            $effortUsable = $this->effortAnswersUsable();
        }
        $planUsable = true;
        if ($this->carriesPlanDynamicsSite()) {
            $this->needsWorkingPlanProbability = $this->readNoulProbability($answers, self::QUESTION_NEEDS_WORKING_PLAN);
            $planUsable = $this->needsWorkingPlanProbability !== null;
        }
        $directWidgetUsable = true;
        if ($this->carriesDirectWidgetSite()) {
            // the wire lowercases choices — the answer set is the lowercased widget types; the site maps them back
            $directWidgetAnswer = $this->readChoiceWithProbabilitiesFrom(
                $answers,
                self::QUESTION_DIRECT_WIDGET,
                array_merge(array_map('strtolower', array_map('strval', array_keys($this->directWidgetWhenLines))), [AgentDirectWidgetDecision::ANSWER_NONE]),
                AgentDirectWidgetDecision::ANSWER_NONE
            );
            $this->directWidgetPickedToken = $directWidgetAnswer->choice;
            $this->directWidgetPickConfidence = $directWidgetAnswer->confidence;
            $this->directWidgetProbabilities = $directWidgetAnswer->reportedProbabilities;
            $directWidgetUsable = $this->directWidgetPickedToken !== null;
        }
        $turnStartLineUsable = true;
        if ($this->carriesTurnStartLineSite()) {
            $this->workBeforeAnswerProbability = $this->readNoulProbability($answers, self::QUESTION_WORK_BEFORE_ANSWER);
            $turnStartLineUsable = $this->workBeforeAnswerProbability !== null;
        }
        return $languageUsable && $preloadUsable && $effortUsable && $planUsable && $directWidgetUsable && $turnStartLineUsable;
    }

    /** Whether the EFFORT answers of this request are usable on their own (the three always-sent questions answered). */
    public function effortAnswersUsable(): bool
    {
        return $this->carriesEffortSite()
            && $this->disputesWhatTheAssistantClaimsProbability !== null
            && $this->rejectsIdentityOrClassProbability !== null
            && (!$this->mediumEffortQuestionsSent() || $this->asksForADifferentCourseProbability !== null);
    }

    /** The two medium questions ride the request only while the active effort is below `medium`. */
    public function mediumEffortQuestionsSent(): bool
    {
        return AgentDecisionModelSettings::reasoningEffortRank($this->effortActiveEffort)
            < AgentDecisionModelSettings::reasoningEffortRank(AgentDecisionModelSettings::REASONING_EFFORT_MEDIUM);
    }

    /** Whether the LANGUAGE answers of this request are usable on their own (the orchestrator's per-site completion). */
    public function languageAnswersUsable(): bool
    {
        return $this->carriesLanguageSite() && $this->writtenLanguageCode !== null && $this->requestedLanguageCode !== null;
    }

    /** Whether the PRELOAD answers of this request are usable on their own. */
    public function preloadAnswersUsable(): bool
    {
        return $this->carriesPreloadSite() && $this->preloadPickedToken !== null;
    }

    /**
     * One `choice` answer → lowercase choice and confidence; an answer outside the closed set (and not an abstention
     * token) degrades to `other` HERE — the answer space is enforced by us, never trusted from the model.
     *
     * @param object $answers
     * @param string $questionName
     * @return AgentDecisionChoiceAnswer
     */
    protected function readChoice(object $answers, string $questionName): AgentDecisionChoiceAnswer
    {
        $allowedChoices = array_merge($this->supportedLanguageCodes, [
            AgentReplyLanguageDecision::ANSWER_NONE,
            AgentReplyLanguageDecision::ANSWER_UNDECIDABLE,
            AgentReplyLanguageDecision::ANSWER_OTHER,
            AgentReplyLanguageDecision::ANSWER_CONFLICTING,
        ]);
        return $this->readChoiceFrom($answers, $questionName, $allowedChoices, AgentReplyLanguageDecision::ANSWER_OTHER);
    }

    /**
     * The name of the state that lists the facts on file about the space the agent works for — the application's
     * word ({@see AgentApplicationWording::$agentSpaceFactsStateName}); the instructions name the state by it.
     */
    public static function agentSpaceFactsStateName(): string
    {
        return AgentApplicationWording::current()->agentSpaceFactsStateName;
    }

    /** ', <examples>' for a declared list of examples, '' for none. */
    protected static function buildListedAfterComma(string $examples): string
    {
        return $examples !== '' ? ', ' . $examples : '';
    }

    /** ' (<examples>)' for a declared list of examples, '' for none. */
    protected static function buildListedInBrackets(string $examples): string
    {
        return $examples !== '' ? ' (' . $examples . ')' : '';
    }

    /**
     * The state keys ONE site of a combined request reads (the decision record shows each site its own input, not
     * the whole combined request).
     *
     * @return string[]
     */
    public static function stateKeysForSite(string $site): array
    {
        return match ($site) {
            AgentDecisionModelSettings::SITE_REPLY_LANGUAGE => [self::STATE_OWNER_WORDS, self::STATE_STANDING_DIRECTIVES, self::STATE_CONVERSATION_LANGUAGE, self::STATE_ASSISTANT_TEXT, self::STATE_RECENT_TURNS],
            AgentDecisionModelSettings::SITE_SKILL_PRELOAD => [self::STATE_OWNER_MESSAGE, self::STATE_ASSISTANT_TEXT, self::STATE_RECENT_TURNS, self::agentSpaceFactsStateName(), self::STATE_META_TOOLS, self::STATE_OPEN_WORK_ITEM, self::STATE_LOADED_SKILLS, self::STATE_CATALOG, self::STATE_FACTS, self::STATE_LOADED_SKILL, self::STATE_SUB_SKILLS, self::STATE_PLAN, self::STATE_LOADED_SUB_SKILLS, self::STATE_ACTS_AHEAD, self::STATE_NEXT_ACT],
            AgentDecisionModelSettings::SITE_REASONING_EFFORT => [self::STATE_OWNER_MESSAGE, self::STATE_ASSISTANT_TEXT, self::STATE_STORED_FACTS, self::STATE_NEXT_PLAN_ITEM, self::STATE_ACTIVE_EFFORT, self::STATE_CEILING_EFFORT, self::STATE_DISAGREEMENT],
            AgentDecisionModelSettings::SITE_PLAN_DYNAMICS => [self::STATE_OWNER_MESSAGE, self::STATE_ASSISTANT_TEXT, self::STATE_RECENT_TURNS, self::STATE_FINISHED_PLANS],
            AgentDecisionModelSettings::SITE_DIRECT_WIDGET => [self::STATE_OWNER_MESSAGE, self::STATE_ASSISTANT_TEXT, self::STATE_RECENT_TURNS],
            AgentDecisionModelSettings::SITE_TURN_START_LINE => [self::STATE_OWNER_MESSAGE, self::STATE_ASSISTANT_TEXT, self::STATE_RECENT_TURNS],
            default => [],
        };
    }

    /**
     * The question names ONE folded site asked.
     *
     * @return string[]
     */
    public static function questionNamesForSite(string $site): array
    {
        return match ($site) {
            AgentDecisionModelSettings::SITE_REPLY_LANGUAGE => [self::QUESTION_WRITTEN_LANGUAGE, self::QUESTION_REQUESTED_LANGUAGE, self::QUESTION_DIRECTIVE_LANGUAGE],
            AgentDecisionModelSettings::SITE_SKILL_PRELOAD => [self::QUESTION_SKILL],
            AgentDecisionModelSettings::SITE_REASONING_EFFORT => [self::QUESTION_DISPUTES_WHAT_THE_ASSISTANT_CLAIMS, self::QUESTION_REJECTS_IDENTITY_OR_CLASS, self::QUESTION_ASKS_FOR_A_DIFFERENT_COURSE, self::QUESTION_REQUIRES_WEIGHING_CONFLICTING_REQUIREMENTS],
            AgentDecisionModelSettings::SITE_PLAN_DYNAMICS => [self::QUESTION_NEEDS_WORKING_PLAN],
            AgentDecisionModelSettings::SITE_DIRECT_WIDGET => [self::QUESTION_DIRECT_WIDGET],
            AgentDecisionModelSettings::SITE_TURN_START_LINE => [self::QUESTION_WORK_BEFORE_ANSWER],
            default => [],
        };
    }

    /** The sent state restricted to ONE site's keys. @return array<string, mixed> */
    public function stateForSite(string $site): array
    {
        $stateKeys = self::stateKeysForSite($site);
        if ($site === AgentDecisionModelSettings::SITE_REPLY_LANGUAGE && !$this->carriesLanguageContext()) {
            // the context-free language question read only its own two keys — a folded sibling's turns are not its record
            $stateKeys = [self::STATE_OWNER_WORDS, self::STATE_STANDING_DIRECTIVES];
        }
        return array_intersect_key($this->buildState(), array_fill_keys($stateKeys, true));
    }

    /** True when the caller handed the language site its conversational context (anchor, last assistant text, recent turns). */
    public function carriesLanguageContext(): bool
    {
        return $this->languageConversationLanguageCode !== null || trim($this->languageAssistantTextBefore) !== '' || $this->languageRecentTurns !== [];
    }

    /** The raw answers restricted to ONE site's questions. @return array<string, mixed> */
    public function answersForSite(string $site): array
    {
        return array_intersect_key($this->rawAnswers, array_fill_keys(self::questionNamesForSite($site), true));
    }

    /** The questions ONE site sent, exactly as built (the decision record's "prompt"). @return array<string, mixed> */
    public function questionsForSite(string $site): array
    {
        return array_intersect_key($this->buildQuestionsForRequest(), array_fill_keys(self::questionNamesForSite($site), true));
    }
}
