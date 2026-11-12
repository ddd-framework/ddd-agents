<?php

declare(strict_types=1);

/**
 * The words of the application in the sentences the module writes — the package default, read as
 * `Config::get('AI.agentApplicationWording')` by
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording::current()}. An application replaces this
 * file whole with its own `config/app/AI/agentApplicationWording.php`; a key it leaves out keeps the default of the
 * class.
 *
 * `billingEntityName`: the entity next to the account whose state stops or pauses automated work ("this account or
 * subscription is no longer active").
 * `agentSpaceName`: a space the agent works for ("… data in the workspace's language").
 * `agentSpaceContextLabel`: the label of the line of the instructions that carries the context text of a space — the
 * same label the prompt file writes in front of `{%AGENT_SPACE_CONTEXT%}`.
 * `agentSpaceContextStateKind`: the kind of the note that reports a changed context text (`[SIGNAL:work-context]`).
 * `agentSpaceContextSubjectName`: what the context text describes ("The workspace record changed", "The workspace
 * time zone is now …").
 * `ownerConsentSameTargetExamples`, `ownerConsentSameTargetWrittenDifferentlyExample`,
 * `ownerConsentDifferentTargetExamples`: the examples of the owner-consent check, taken from the application's own
 * confirmed tools; empty = the check's sentences carry no examples.
 * `workContinuedAfterOwnerMessageName`: what the model goes on with after it answered a message the owner sent while
 * it was working ("… and continue your work.").
 * `seededPlanConversationName`, `seededPlanBackgroundWorkExamples`: a conversation under a purpose-seeded plan and the
 * background work the platform runs there ("Not delegated: in this conversation the platform already runs the
 * background work"); empty examples = the sentence carries none.
 * `purposeBoundSkillLoadAdvice`: whole sentences after "Do not retry loading these here." — how the application's
 * conversations reach the work of a skill that loads only inside its own purposed conversation; empty = nothing follows.
 * `outwardActionKinds`, `outwardActionConsentException`: the kinds of outward actions the application's tools carry
 * out and an exception it grants to the owner's explicit yes, read in the audit's headline "[OUTWARD ACTIONS — …]";
 * empty = the headline names no kinds and no exception.
 * `skillPreloadFactsMeaning`: what the application's facts of the skill decision say ("`facts` holds the facts the
 * application states, to tell entries apart that depend on it.").
 * `replyLanguageFurtherCoveredSentences`: further owner-visible sentences the reply language covers ("EVERY
 * owner-visible sentence: answers, status updates, and …"); empty = none.
 * `criterionRecordName`, `criterionStageToolExamples`: the record a purpose's criteria read when they decide every
 * tick of its plan, and examples of the stage tools that write it ("this item is decided by the purpose's record");
 * empty examples = the sentence carries none.
 * `agentSpaceFactsStateName`: the name of the decision state that lists the facts on file about the space (one word,
 * it is a key of the request).
 * `agentSpaceOwnDataExamples`, `agentSpaceQuestionTopicExamples`: examples of the space's own data the agent reads
 * and of what a question about the space asks about; empty = the sentences carry none.
 * `identityOrClassProposalSubject`, `identityOrClassRejectedCriterion`, `identityOrClassNotRejectedCriterion`: what
 * an identity or classification step of the application proposes, and when the owner's words reject it or do not.
 * `assistantKindDescription`: what kind of assistant the agent is, with its article ("an AI assistant").
 * `runReportNotApplicableItemsSentence`: the run report's sentence for the plan items that do not apply, a
 * translation key with the `%items%` placeholder.
 */
return [
    'billingEntityName' => 'subscription',
    'agentSpaceName' => 'workspace',
    'agentSpaceContextLabel' => 'Work context',
    'agentSpaceContextStateKind' => 'work-context',
    'agentSpaceContextSubjectName' => 'workspace',
    'ownerConsentSameTargetExamples' => '',
    'ownerConsentSameTargetWrittenDifferentlyExample' => '',
    'ownerConsentDifferentTargetExamples' => '',
    'workContinuedAfterOwnerMessageName' => 'your work',
    'seededPlanConversationName' => 'conversation',
    'seededPlanBackgroundWorkExamples' => '',
    'purposeBoundSkillLoadAdvice' => '',
    'outwardActionKinds' => '',
    'outwardActionConsentException' => '',
    'skillPreloadFactsMeaning' => 'holds the facts the application states',
    'replyLanguageFurtherCoveredSentences' => '',
    'criterionRecordName' => 'the purpose\'s record',
    'criterionStageToolExamples' => '',
    'agentSpaceFactsStateName' => 'workspace',
    'agentSpaceOwnDataExamples' => '',
    'agentSpaceQuestionTopicExamples' => '',
    'identityOrClassProposalSubject' => 'the identity of the workspace or the class it was put into',
    'identityOrClassRejectedCriterion' => 'the owner says the proposed or stored identity is not theirs, or rejects the class as a whole as '
        . 'not what they are — a fact the assistant built on must be replaced and everything derived from it re-thought',
    'identityOrClassNotRejectedCriterion' => 'one field value is corrected; one entry of a list is swapped or removed; the owner picks one '
        . 'of the offered candidates; the owner corrects their OWN name or how they want to be addressed; a deferral; an addition; an '
        . 'identity or a class stated for the first time when nothing was proposed or stored yet',
    'assistantKindDescription' => 'an AI assistant',
    'runReportNotApplicableItemsSentence' => 'Not applicable: %items%.',
];
