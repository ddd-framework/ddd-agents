<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationPostMessagePostRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /** @var AIConversationMessage The AIConversationMessage that initiates the conversation */
    #[Parameter(in: Parameter::BODY, required: true)]
    public AIConversationMessage $aiConversationMessage;

    /**
     * @var bool|null Admin-debug param, honoured ONLY when a validated admin is impersonating this customer
     * (server-checked via AuthService::getAdminAccountOrNull()); ignored for a plain customer. Lifts the budget cap.
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $budgetBypass = null;

    /**
     * @var string|null The agent model to run this conversation on, by name (e.g. ALIBABA.QWEN3_235B_INSTRUCT).
     * CUSTOMER: must be one of the selectable-models whitelist (GET /ai/conversations/selectable-models) — any other
     * value is rejected fail-closed. Impersonating admin: any agent-eligible model. Omitted = keep the conversation's
     * stored pick, else the agent default.
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $aiModelName = null;

    /** @var string|null honoured ONLY when an admin impersonates the customer (the impersonation panel's switch): `inherit`, `on`, `off`. A customer's value is ignored. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $decisionModelSkillPreload = null;

    /** @var string|null honoured ONLY when an admin impersonates the customer: `inherit`, `off`, `deterministicCounter`, `decisionModel`, `counterThenDecisionModel`. A customer's value is ignored. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $decisionModelTurnClosing = null;

    /** @var bool|null honoured ONLY when an admin impersonates the customer: the decision-layer kill switch for this conversation. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $decisionModelOff = null;

    /** @var string|null honoured ONLY when an admin impersonates the customer: `inherit`, `off`, `low`, `medium`, `high` (a pinned effort floor). A customer's value is ignored. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $decisionModelReasoningEffort = null;

    /** @var string|null honoured ONLY when an admin impersonates the customer: `inherit`, `on`, `off` (the stall-recovery decision). A customer's value is ignored. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $decisionModelStallRecovery = null;

    /** @var string|null honoured ONLY when an admin impersonates the customer: `inherit`, `off`, `on` (the reply-language decision). A customer's value is ignored. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $decisionModelReplyLanguage = null;

    /** @var string|null switch 1 — honoured ONLY when an admin impersonates the customer: `inherit` / `yes` / `no` (is the decision model asked the plan question?). A customer's value is ignored. */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $planDynamicsAskDecisionModel = null;

    /** @var string|null switch 2 — honoured ONLY under admin impersonation: `inherit` / `yes` / `no` (does the chat model receive the verdict?). */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $planDynamicsModelReceivesVerdict = null;
}