<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationStartWithMessagePostRequestDto extends RequestDto
{
    /** @var AIConversationMessage The AIConversationMessage that initiates the conversation */
    #[Parameter(in: Parameter::BODY, required: true)]
    public AIConversationMessage $aiConversationMessage;

    /** @var int|null The id of the space the conversation works for; omit it for a conversation at account level */
    #[Parameter(in: Parameter::PATH, required: false)]
    public ?int $agentSpaceId = null;

    /** @var int|null The id of the AIPromptExtension used for this conversation */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $aiPromptExtensionId = null;

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

    /**
     * @var string|null Invisible START context: appended as a model-visible, user-INVISIBLE SIGNAL message
     * before the user message, so the agent's FIRST turn already has it — a dashboard widget hands over its data/state
     * this way when a click on it starts the conversation. With a non-empty signal the visible message MAY be empty
     * (signal-only start: the agent speaks first). Sanitized + length-capped server-side
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversationDriveRequest::MAX_START_SIGNAL_TEXT_CHARS}).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $signalText = null;

    /**
     * @var string|null The signal provenance. Defaults server-side to
     * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::SIGNAL_SOURCE_DASHBOARD_WIDGET_CONTEXT}
     * when omitted; a future starter surface may pass its own source.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $signalSource = null;

    /**
     * @var string|null The product entry point the start comes from, e.g. `hub.starter.R1` — a stable key per entry
     * point (letters, digits and `.` `_` `:` `-`, at most 255 characters), never the message text. When the
     * account's conversation in the same space (without a space: at account level) with the same key was active within
     * the last 6 h, that conversation is returned instead (`reopenedExistingConversation` = true): nothing is appended
     * and no turn starts — the start's message and its signal (`signalText` / `signalSource`) are dropped. Otherwise
     * the new conversation is stamped with the key.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $entryPointKey = null;
}