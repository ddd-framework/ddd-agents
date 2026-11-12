<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The words of the application in the sentences the module writes for the model and for the owner.
 *
 * The module knows an account, a space the agent works for and an entity that is billed; it does not know what an
 * application calls them. A sentence such as "automated work is paused for your account or …" is true of every
 * application, but its last word is the application's: a shop system says "shop", a booking system "venue". The
 * application declares these words once in `config/app/AI/agentApplicationWording.php` (it replaces the module's
 * file whole; a key it leaves out keeps the default below), and every sentence of the module reads them here.
 *
 * An application may use different words for these roles even where one row of its database fills all of them
 * today: the roles are declared one by one so that a later split of those rows changes no sentence.
 *
 * A space contributes a context text to the instructions (the `{%AGENT_SPACE_CONTEXT%}` placeholder of the base
 * prompt). The label of that line, the kind of the note that reports a change of it and the word for what the text
 * describes are the application's as well: its prompt file writes the label, and the notes name the line by it.
 *
 * The examples of the owner-consent check are declared the same way: which targets a confirmed step can have (an
 * address, a logo, a category) is knowledge of the application's tools, not of the module.
 *
 * So are the words for the application's own runs: what a conversation under a purpose-seeded plan is called and
 * which background work the platform runs there, what the model goes on with after it answered a message the owner
 * sent while it was working, and how the application's conversations reach the work of a skill that loads only
 * inside its own purposed conversation.
 *
 * And the words of its audit and plan rules: which kinds of outward actions its tools carry out and which exception
 * to the owner's explicit yes it grants, what the facts of the skill decision say, which further sentences the reply
 * language covers, and what a purpose whose criteria decide every tick calls its record and its stage tools.
 *
 * And what the decisions of a turn's start say about the space: the name of the state that lists its facts, examples
 * of its own data and of what a question about it asks, what an identity or classification step proposes and when
 * the owner rejects it, what kind of assistant the agent is, and the run report's sentence for items that do not
 * apply.
 *
 * @phpstan-consistent-constructor
 */
class AgentApplicationWording extends ValueObject
{
    /** The config key of the application's words (`config/app/AI/agentApplicationWording.php`). */
    public const string CONFIG_KEY = 'AI.agentApplicationWording';

    /** The keys of the config file: the properties an application declares. */
    public const array DECLARED_PROPERTY_NAMES = [
        'billingEntityName',
        'agentSpaceName',
        'agentSpaceContextLabel',
        'agentSpaceContextStateKind',
        'agentSpaceContextSubjectName',
        'ownerConsentSameTargetExamples',
        'ownerConsentSameTargetWrittenDifferentlyExample',
        'ownerConsentDifferentTargetExamples',
        'workContinuedAfterOwnerMessageName',
        'seededPlanConversationName',
        'seededPlanBackgroundWorkExamples',
        'purposeBoundSkillLoadAdvice',
        'outwardActionKinds',
        'outwardActionConsentException',
        'skillPreloadFactsMeaning',
        'replyLanguageFurtherCoveredSentences',
        'criterionRecordName',
        'criterionStageToolExamples',
        'agentSpaceFactsStateName',
        'agentSpaceOwnDataExamples',
        'agentSpaceQuestionTopicExamples',
        'identityOrClassProposalSubject',
        'identityOrClassRejectedCriterion',
        'identityOrClassNotRejectedCriterion',
        'assistantKindDescription',
        'runReportNotApplicableItemsSentence',
    ];

    public const string DEFAULT_BILLING_ENTITY_NAME = 'subscription';

    public const string DEFAULT_AGENT_SPACE_NAME = 'workspace';

    public const string DEFAULT_AGENT_SPACE_CONTEXT_LABEL = 'Work context';

    public const string DEFAULT_AGENT_SPACE_CONTEXT_STATE_KIND = 'work-context';

    public const string DEFAULT_AGENT_SPACE_CONTEXT_SUBJECT_NAME = 'workspace';

    public const string DEFAULT_WORK_CONTINUED_AFTER_OWNER_MESSAGE_NAME = 'your work';

    public const string DEFAULT_SEEDED_PLAN_CONVERSATION_NAME = 'conversation';

    public const string DEFAULT_SKILL_PRELOAD_FACTS_MEANING = 'holds the facts the application states';

    public const string DEFAULT_CRITERION_RECORD_NAME = 'the purpose\'s record';

    public const string DEFAULT_AGENT_SPACE_FACTS_STATE_NAME = 'workspace';

    public const string DEFAULT_IDENTITY_OR_CLASS_PROPOSAL_SUBJECT = 'the identity of the workspace or the class it was put into';

    public const string DEFAULT_IDENTITY_OR_CLASS_REJECTED_CRITERION = 'the owner says the proposed or stored identity is not theirs, or rejects '
        . 'the class as a whole as not what they are — a fact the assistant built on must be replaced and everything derived from it '
        . 're-thought';

    public const string DEFAULT_IDENTITY_OR_CLASS_NOT_REJECTED_CRITERION = 'one field value is corrected; one entry of a list is swapped or '
        . 'removed; the owner picks one of the offered candidates; the owner corrects their OWN name or how they want to be addressed; a '
        . 'deferral; an addition; an identity or a class stated for the first time when nothing was proposed or stored yet';

    public const string DEFAULT_ASSISTANT_KIND_DESCRIPTION = 'an AI assistant';

    public const string DEFAULT_RUN_REPORT_NOT_APPLICABLE_ITEMS_SENTENCE = 'Not applicable: %items%.';

    /**
     * @var string What the application calls the entity next to the account whose state stops or pauses automated
     *             work (the entity that is billed). Read in "this account or <name> is no longer active".
     */
    public string $billingEntityName = self::DEFAULT_BILLING_ENTITY_NAME;

    /** @var string What the application calls a space the agent works for. Read in "<subject> data in the <name>'s language". */
    public string $agentSpaceName = self::DEFAULT_AGENT_SPACE_NAME;

    /**
     * @var string The label of the line of the instructions that carries the context text of a space. A state note
     *             names the line by it: 'the "<label>" line of your instructions'. The application's prompt file
     *             writes the same label in front of `{%AGENT_SPACE_CONTEXT%}`.
     */
    public string $agentSpaceContextLabel = self::DEFAULT_AGENT_SPACE_CONTEXT_LABEL;

    /**
     * @var string The kind of the state note that reports a changed context text: the model reads it as
     *             `[SIGNAL:<kind>]`, and it is stored with the note. An application that changes it later no
     *             longer reads the notes stored under the earlier kind.
     */
    public string $agentSpaceContextStateKind = self::DEFAULT_AGENT_SPACE_CONTEXT_STATE_KIND;

    /**
     * @var string What the application calls the thing the context text of a space describes. Read in "The <name>
     *             record changed" and "The <name> time zone is now …".
     */
    public string $agentSpaceContextSubjectName = self::DEFAULT_AGENT_SPACE_CONTEXT_SUBJECT_NAME;

    /**
     * @var string Examples of "the same target" of a step the owner confirms, as a comma-separated list
     *             ('' = the sentence carries no examples).
     */
    public string $ownerConsentSameTargetExamples = '';

    /**
     * @var string One sentence on a target that is written differently and is still the same target
     *             ('' = no such sentence).
     */
    public string $ownerConsentSameTargetWrittenDifferentlyExample = '';

    /**
     * @var string Examples of "a different target" than the step's, as a comma-separated list
     *             ('' = the sentence carries no examples).
     */
    public string $ownerConsentDifferentTargetExamples = '';

    /**
     * @var string What the model goes on with after it answered a message the owner sent while it was working. Read
     *             in "… never re-ask what it already answers, and continue <name>."
     */
    public string $workContinuedAfterOwnerMessageName = self::DEFAULT_WORK_CONTINUED_AFTER_OWNER_MESSAGE_NAME;

    /**
     * @var string What the application calls a conversation that runs under a purpose-seeded plan. Read in "Not
     *             delegated: in this <name> the platform already runs the background work".
     */
    public string $seededPlanConversationName = self::DEFAULT_SEEDED_PLAN_CONVERSATION_NAME;

    /**
     * @var string Examples of the background work the platform runs under a purpose-seeded plan, as a
     *             comma-separated list ('' = the sentence carries no examples).
     */
    public string $seededPlanBackgroundWorkExamples = '';

    /**
     * @var string How the application's conversations reach the work of a skill that loads only inside its own
     *             purposed conversation: whole sentences that follow "Do not retry loading these here."
     *             ('' = nothing follows).
     */
    public string $purposeBoundSkillLoadAdvice = '';

    /**
     * @var string The kinds of outward actions the application's tools carry out, as a comma-separated list. Read in
     *             the audit's headline "[OUTWARD ACTIONS — <kinds>; each needs the owner's explicit yes …]"
     *             ('' = the headline names no kinds).
     */
    public string $outwardActionKinds = '';

    /**
     * @var string An exception of the application to the owner's explicit yes before an outward action. Read in the
     *             audit's headline after "… in a USER row BEFORE it — exception " ('' = no exception).
     */
    public string $outwardActionConsentException = '';

    /**
     * @var string What the application's facts of the skill decision say. Read in the decision's instructions:
     *             "`facts` <meaning>, to tell entries apart that depend on it."
     */
    public string $skillPreloadFactsMeaning = self::DEFAULT_SKILL_PRELOAD_FACTS_MEANING;

    /**
     * @var string Further owner-visible sentences the reply language covers, beyond answers and status updates. Read
     *             in "EVERY owner-visible sentence: answers, status updates, and <sentences>." ('' = none).
     */
    public string $replyLanguageFurtherCoveredSentences = '';

    /**
     * @var string What a purpose whose criteria decide every tick of its plan calls the record the criteria read.
     *             Read in "this item is decided by <name>" and "<name> shows this stage SATISFIED".
     */
    public string $criterionRecordName = self::DEFAULT_CRITERION_RECORD_NAME;

    /**
     * @var string Examples of the stage tools whose record ticks such an item, as one list. Read in "The stage
     *             tool's record (<examples>) ticks the item when the work exists." ('' = no examples).
     */
    public string $criterionStageToolExamples = '';

    /**
     * @var string The name of the decision state that lists the facts on file about the space; the instructions of
     *             the skill decision name the state by it ("`<name>` lists the … facts on file"). One word without
     *             spaces: it is a key of the request.
     */
    public string $agentSpaceFactsStateName = self::DEFAULT_AGENT_SPACE_FACTS_STATE_NAME;

    /**
     * @var string Examples of the space's own data the agent reads, as a comma-separated list. Read in "does it have
     *             to DO something first — read the <subject>'s own data (<examples>), search, …" ('' = no examples).
     */
    public string $agentSpaceOwnDataExamples = '';

    /**
     * @var string Examples of what a question about the space asks about, as one list. Read in "a question about the
     *             <subject>, <examples>, a change to make" ('' = no examples).
     */
    public string $agentSpaceQuestionTopicExamples = '';

    /**
     * @var string What an identity or classification tool or widget of the application proposes and the owner may
     *             reject. Read in "Does `message` declare that <subject> that `assistantText` proposed or
     *             `storedFacts` holds is WRONG?".
     */
    public string $identityOrClassProposalSubject = self::DEFAULT_IDENTITY_OR_CLASS_PROPOSAL_SUBJECT;

    /** @var string When the owner rejects the proposed or stored identity or class: the `true` criterion of that question. */
    public string $identityOrClassRejectedCriterion = self::DEFAULT_IDENTITY_OR_CLASS_REJECTED_CRITERION;

    /** @var string When the owner's words are no such rejection: the `false` criterion of that question. */
    public string $identityOrClassNotRejectedCriterion = self::DEFAULT_IDENTITY_OR_CLASS_NOT_REJECTED_CRITERION;

    /**
     * @var string What kind of assistant the agent is, with its article. Read by the check of a tool result:
     *             "content … that <description> is about to read".
     */
    public string $assistantKindDescription = self::DEFAULT_ASSISTANT_KIND_DESCRIPTION;

    /**
     * @var string The run report's sentence that lists the plan items that do not apply — a translation key of the
     *             application with the `%items%` placeholder.
     */
    public string $runReportNotApplicableItemsSentence = self::DEFAULT_RUN_REPORT_NOT_APPLICABLE_ITEMS_SENTENCE;

    /**
     * The words the application declares; every key it does not declare (or declares empty or not as a string) keeps
     * its default.
     *
     * @return static
     */
    public static function current(): static
    {
        $configuredWording = Config::get(self::CONFIG_KEY);
        $configuredWording = is_array($configuredWording) ? $configuredWording : [];
        $agentApplicationWording = new static();
        foreach (self::DECLARED_PROPERTY_NAMES as $propertyName) {
            $configuredText = $configuredWording[$propertyName] ?? null;
            if (is_string($configuredText) && $configuredText !== '') {
                $agentApplicationWording->$propertyName = $configuredText;
            }
        }
        return $agentApplicationWording;
    }
}
