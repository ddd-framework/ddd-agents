<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Drive ONE background turn of an `AIConversation` in a Messenger worker — the async half of the `bool $async` seam.
 * The synchronous agent loop (`generateAgentReply`) is unchanged; this message just moves WHERE it
 * runs (a worker) for the HTTP/admin path. The CLI/eval keep running it inline.
 *
 * **Ids on the wire, nothing else:** only the conversation id. The handler re-`find()`s the conversation + its
 * messages, space, and `budgetBypass` flag — no entity serialised onto the queue. `accountId` + the workspace are
 * captured by {@see AppMessage::__construct()} from the auth context at DISPATCH time (the dispatcher has already set
 * the conversation's account as the auth account), so the handler's `setAuthAccountFromMessage()` restores the right
 * account in the worker. `#[WithAIEnvelope]` makes the application's envelope middleware open
 * the AI-usage envelope around the handler — the loop's Argus model calls require an active envelope.

 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN)]
class AIConversationResumeMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $aiConversationId;

    /**
     * @var float|null Wall clock of the dispatching process right before the transport send — the API-side stamp that
     *      splits the owner-message → job-received gap into "append → send" and "send → received" on the turn-start
     *      stamps ({@see AIConversationAgentService::$lastWorkerJobReceipt}).
     *
     *      Unit: a Unix time in SECONDS with fractions, as `microtime(true)` returns it. The name ends in `Us`
     *      although the value does not hold microseconds; the name stays because it is a field of a queued message.
     */
    public ?float $dispatchedAtWallUs = null;

    /**
     * @var float|null Wall clock of the API process right after the owner's row was saved — the precise origin of
     *      "append → send" (the row's `created` column has second precision); null when the dispatch saved no owner
     *      row (a signal-only start, a re-fire).
     *
     *      Unit: a Unix time in SECONDS with fractions, as `microtime(true)` returns it. The name ends in `Us`
     *      although the value does not hold microseconds; the name stays because it is a field of a queued message.
     */
    public ?float $ownerRowSavedWallUs = null;

    public static string $messageHandler = AIConversationResumeHandler::class;

    public function __construct(int $aiConversationId)
    {
        parent::__construct();
        $this->aiConversationId = $aiConversationId;
        $this->dispatchedAtWallUs = microtime(true);
    }

    public function getAIConversationIdForOverheadCosts(): ?int
    {
        return $this->aiConversationId;
    }
}
