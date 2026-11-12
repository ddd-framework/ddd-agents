<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the sites of one turn start contribute to the folded turn-start request: each
 * site's question (null = the site does not run this turn), the sub-skill questions of the skills loaded before the
 * turn (one child request each) and the owner message the injection check reads (one more child).
 */
class AgentTurnStartSiteInputs extends ValueObject
{
    public function __construct()
    {
        parent::__construct();
        $this->loadedSkillSubSkillQuestions = new AgentTurnStartSubSkillQuestions();
    }

    /** The reply-language site's question. */
    public ?AgentReplyLanguageDecisionQuestion $replyLanguageQuestion = null;

    /** The skill-preload roots pass's question. */
    public ?AgentSkillPreloadDecisionQuestion $skillPreloadQuestion = null;

    /** @var AgentTurnStartSubSkillQuestions The sub-skill pass questions, one per skill loaded before the turn. */
    public AgentTurnStartSubSkillQuestions $loadedSkillSubSkillQuestions;

    /** The reasoning-effort site's question (only when its questions are asked). */
    public ?AgentReasoningEffortDecisionQuestion $reasoningEffortQuestion = null;

    /** The plan-dynamics site's question. */
    public ?AgentPlanDynamicsDecisionQuestion $planDynamicsQuestion = null;

    /** The direct-widget site's question. */
    public ?AgentDirectWidgetDecisionQuestion $directWidgetQuestion = null;

    /** The turn-start line site's question. */
    public ?AgentTurnStartLineDecisionQuestion $turnStartLineQuestion = null;

    /** The owner message the injection check reads, null = no check this turn. */
    public ?AIConversationMessage $ownerMessageForInjectionCheck = null;

    /** The newest owner message's index — the request event's initiator when no preload question names one. */
    public ?int $ownerMessageIndex = null;

    /** The primary request carries at least one site's questions (else only children, if any, are sent). */
    public function primaryRequestCarriesASite(): bool
    {
        return $this->replyLanguageQuestion !== null || $this->skillPreloadQuestion !== null || $this->reasoningEffortQuestion !== null || $this->planDynamicsQuestion !== null
            || $this->directWidgetQuestion !== null || $this->turnStartLineQuestion !== null;
    }

    /** Nothing to ask at all: no site, no sub-skill child, no injection check. */
    public function asksNothing(): bool
    {
        return !$this->primaryRequestCarriesASite() && $this->loadedSkillSubSkillQuestions->isEmpty() && $this->ownerMessageForInjectionCheck === null;
    }

    /** @return string[] The sites the primary request carries, for its request event. */
    public function getRequestedSiteNames(): array
    {
        return array_values(array_filter([
            $this->replyLanguageQuestion !== null ? 'replyLanguage' : null,
            $this->skillPreloadQuestion !== null ? 'skillPreload' : null,
            $this->reasoningEffortQuestion !== null ? AgentDecisionModelSettings::SITE_REASONING_EFFORT : null,
            $this->planDynamicsQuestion !== null ? AgentDecisionModelSettings::SITE_PLAN_DYNAMICS : null,
            $this->directWidgetQuestion !== null ? AgentDecisionModelSettings::SITE_DIRECT_WIDGET : null,
            $this->turnStartLineQuestion !== null ? AgentDecisionModelSettings::SITE_TURN_START_LINE : null,
        ]));
    }
}
