<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/**
 * The provenance of the running agent tool call — the two messages it traces back to. Built in
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallExecutionService::executeToolCall()} (which holds the conversation) and
 * injected into any tool that declares it: it is a {@see MCPServerProvidedInput}, so it is invisible to the model
 * (never in the tool JSON-Schema) and supplied by the server. On the HTTP-MCP path nothing populates it → both
 * messages are null.
 *
 * First use: the explicit `remember`/`updateMemory` tools stamp {@see $sourceUserMessage}'s id onto the memory's
 * `sourceMessageId`, so a via-skill memory carries the same source-message lineage the async curator already records
 * (provenance for conflict-resolution + audit). {@see $toolCallOriginMessage} (the assistant message that emitted the
 * call) is carried for future server-side consumers (audit, the owner's original wording, security taint).
 *
 * A transient in-memory injection carrier: never persisted, never returned to the model; the held messages are
 * `#[HideProperty]` so an accidental serialization never walks the message graph.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallProvenance extends ValueObject implements MCPServerProvidedInput
{
    /** The USER message the tool call traces back to (the turn's triggering message); null on the HTTP-MCP path. */
    #[HideProperty]
    public ?AIConversationMessage $sourceUserMessage = null;

    /** The ASSISTANT message that emitted this tool call (ToolCall's parent); null on the HTTP-MCP path. */
    #[HideProperty]
    public ?AIConversationMessage $toolCallOriginMessage = null;

    /** The conversation id this call runs in — the DELEGATION CHILD when the call runs inside a delegated sub-task; null on the HTTP-MCP path. */
    public ?int $conversationId = null;

    /**
     * The ROOT conversation of the delegation tree — the USER-FACING thread. For a delegation child this is the root's
     * id; for a root dialog it is the conversation's own id (`rootConversationId ?? id`, the same canonical form the
     * realtime signal + cost attribution use). A tool that must reach the USER (proactive signal, notification) targets
     * THIS, not {@see $conversationId} (which is the ephemeral child when delegated). Null on the HTTP-MCP path.
     */
    public ?int $rootConversationId = null;

    /**
     * The space the conversation of this call works for — the AUTHORITATIVE work context a tool acts on.
     * Server-injected so a tool need not (and should not) trust the model to echo it back — same contract as
     * "account from auth". Null for a conversation without a space (account level) and on the HTTP-MCP path. The
     * space a call TARGETS (an argument of the tool may name another one) is asked from
     * {@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::findAgentSpaceOfToolCall()}.
     */
    #[HideProperty]
    public ?AgentSpace $agentSpace = null;

    /** The id of {@see $agentSpace}; null without one. */
    public ?int $agentSpaceId = null;

    /**
     * The LIVE message set of the conversation this call runs in — server-only reference (provenance never
     * serializes to the wire), so the executor can check conversation state the model cannot forge: the
     * owner-consent check reads the owner's rows from it
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::reserveOwnerConsentForStep()}). Null on the HTTP-MCP path.
     */
    #[HideProperty]
    public ?AIConversationMessages $conversationMessages = null;

    /**
     * The schedule this conversation is a RUN of (null = not a scheduled run). Server-injected so the
     * schedule tools can structurally refuse schedule administration from within a scheduled run (the 2410
     * self-replication class) — a DB fact, never a text heuristic.
     */
    public ?int $scheduleId = null;

    /** The name of the tool this call runs — the provenance a tool-emitted message carries. Null on the HTTP-MCP path. */
    public ?string $toolName = null;

    /**
     * The LIVE conversation this call runs in (the delegation CHILD when delegated) — what a tool's row is appended to
     * ({@see getConversationToWriteInto()}). Held directly because the tool-call row's parent link is not guaranteed to be wired in memory
     * (a plain `$message->toolCall = $toolCall` assignment sets no parent). Server-only, never serialised. Null on the
     * HTTP-MCP path.
     */
    #[HideProperty]
    public ?AIConversation $conversation = null;

    /**
     * True for a call the EVAL HARNESS makes itself (a case's setup, capture, restore and after steps): it carries
     * a space like an agent call, but no conversation and no customer — the consent gates (mandate, owner
     * confirmation) are about what the AGENT may do for a customer and do not apply to it. Set only by the eval
     * runners through {@see forEvalHarness()}; the loop never sets it, the model never sees it.
     */
    public bool $isEvalHarnessCall = false;

    /**
     * The provenance of a deterministic eval-harness call on one space (null: account level) — with the JUDGED conversation when the
     * call reads the end state for the judge: the read tools then see what the agent sees on a resumed turn (the
     * decided variants, the owner rows), while the tool writer writes no row for it (a harness read never writes the
     * customer's transcript — {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::appendWidget()}).
     */
    public static function forEvalHarness(?AgentSpace $agentSpace, ?AIConversation $conversation = null): static
    {
        $provenance = new static(
            conversationId: isset($conversation->id) ? (int)$conversation->id : null,
            agentSpace: $agentSpace,
            rootConversationId: $conversation !== null ? (int)(($conversation->rootConversationId ?? null) ?? ($conversation->id ?? 0)) ?: null : null,
            conversationMessages: $conversation !== null && isset($conversation->messages) ? $conversation->messages : null,
            conversation: $conversation,
        );
        $provenance->isEvalHarnessCall = true;
        return $provenance;
    }

    public function __construct(
        ?AIConversationMessage $sourceUserMessage = null,
        ?AIConversationMessage $toolCallOriginMessage = null,
        ?int $conversationId = null,
        ?AgentSpace $agentSpace = null,
        ?int $rootConversationId = null,
        ?AIConversationMessages $conversationMessages = null,
        ?int $scheduleId = null,
        ?string $toolName = null,
        ?AIConversation $conversation = null,
    ) {
        parent::__construct();
        $this->toolName = $toolName;
        $this->conversation = $conversation;
        $this->sourceUserMessage = $sourceUserMessage;
        $this->toolCallOriginMessage = $toolCallOriginMessage;
        $this->conversationId = $conversationId;
        $this->agentSpace = $agentSpace;
        $this->agentSpaceId = $agentSpace !== null && isset($agentSpace->id) ? (int)$agentSpace->id : null;
        $this->rootConversationId = $rootConversationId;
        $this->conversationMessages = $conversationMessages;
        $this->scheduleId = $scheduleId;
    }

    /**
     * The zone this call thinks in ({@see AIConversation::getTimezone()} — the zone of the conversation's space as read for this call, a schedule-bound run's frozen one), or null on the HTTP-MCP path
     * (no conversation) and for a legacy conversation (no frozen zone) — null means "no conversion", today's behaviour.
     */
    public function getTimezone(): ?\DateTimeZone
    {
        return $this->conversation?->getTimezone();
    }

    /** Whether {@see getTimezone()} is a country default the owner has not confirmed; false without a conversation. */
    public function isTimezoneUnverified(): bool
    {
        return $this->conversation?->isTimezoneUnverified() ?? false;
    }

    /**
     * The provider's id of THIS tool call (the `tool_call_id` the model's response carried) — present on every agent-loop
     * call, unlike the origin message, whose link is not always wired. The owner-consent use record binds a reservation to
     * it ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::reserveOwnerConsentForStep()}). Null off the loop.
     */
    public ?string $toolCallId = null;

    public function getSourceUserMessageId(): ?int
    {
        $id = $this->sourceUserMessage->id ?? null;
        return $id !== null ? (int)$id : null;
    }

    public function getToolCallOriginMessageId(): ?int
    {
        $id = $this->toolCallOriginMessage->id ?? null;
        return $id !== null ? (int)$id : null;
    }

    /**
     * The conversation a tool's row is written into ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::appendWidget()}):
     * the live conversation the executor handed over; the origin message's back-reference is the fallback (isset walks
     * the chain null-safely AND tolerates an uninitialised `aiConversation` on a loaded message). Null on the HTTP-MCP
     * path.
     */
    public function getConversationToWriteInto(): ?AIConversation
    {
        $conversation = $this->conversation
            ?? (isset($this->toolCallOriginMessage->aiConversation) ? $this->toolCallOriginMessage->aiConversation : null);
        return $conversation instanceof AIConversation ? $conversation : null;
    }

    /**
     * Whether the owner is on a call over the PHONE LINE right now (the channel
     * decides, never the purpose name) — {@see AIConversation::isOwnerOnThePhoneLine()} of the call's conversation.
     * False without a conversation (the HTTP-MCP path). The one test the writer and the widget emitters ask.
     */
    public function isOwnerOnThePhoneLine(): bool
    {
        return $this->getConversationToWriteInto()?->isOwnerOnThePhoneLine() ?? false;
    }

    /**
     * The live message set of the OWNER'S own thread this call runs in — what a check of the owner's words reads: the
     * conversation's messages, else {@see $conversationMessages}; null where there is no owner thread to read (an
     * eval-harness call, a call inside a delegation child, the HTTP-MCP path).
     */
    public function getOwnerThreadMessages(): ?AIConversationMessages
    {
        if ($this->isEvalHarnessCall
            || ($this->conversationId !== null && $this->rootConversationId !== null && $this->conversationId !== $this->rootConversationId)
        ) {
            return null;
        }
        if ($this->conversation !== null && isset($this->conversation->messages)) {
            return $this->conversation->messages;
        }
        return $this->conversationMessages;
    }

    /**
     * Whether this call runs in a delegation CHILD — not the owner's thread (the one predicate the widget
     * emitters ask, and the tool writer itself; a host package's own delegation-child check may also
     * require a conversation id, a named divergence).
     */
    public function isInDelegationChild(): bool
    {
        return $this->rootConversationId !== null && $this->conversationId !== $this->rootConversationId;
    }

    /**
     * The id of the ROOT conversation of the delegation tree the call runs in — the calling conversation's own id when
     * it is the root; null on the HTTP-MCP path, where no conversation exists.
     */
    public function getTreeRootConversationIdForToolCall(): ?int
    {
        return $this->rootConversationId ?? $this->conversationId;
    }

    /** The calling conversation when it IS the tree's root ({@see getTreeRootConversationIdForToolCall()}) — no load needed; null otherwise. */
    public function getCallingConversationWhenItIsTheTreeRoot(): ?AIConversation
    {
        $treeRootConversationId = $this->getTreeRootConversationIdForToolCall();
        if ($treeRootConversationId === null) {
            return null;
        }
        $callingConversation = $this->conversation;
        if ($callingConversation instanceof AIConversation && (int)($callingConversation->id ?? 0) === (int)$treeRootConversationId) {
            return $callingConversation;
        }
        return null;
    }

    /**
     * The account a tool reads and writes for: the OWNER of the conversation this call runs in (a delegation child
     * carries its root's account), else `$authAccountId` — a call without a conversation (the HTTP-MCP path, an
     * eval-harness call). A tool scopes its rows on this account as a query filter, never through the read rights alone:
     * those are lifted inside many service paths of a turn, and the auth account is not always the owner (an admin
     * driving the conversation). Null when neither names an account.
     */
    public function getConversationOwnerAccountIdOrAuthAccountId(?int $authAccountId): ?int
    {
        $ownerAccountId = (int)($this->conversation->accountId ?? $authAccountId ?? 0);
        return $ownerAccountId > 0 ? $ownerAccountId : null;
    }
}
