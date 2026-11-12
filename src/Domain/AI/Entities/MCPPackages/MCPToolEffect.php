<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * What an #[MCPTool] does to the stored world — declared on the tool, never derived from its name.
 *
 * - READ: changes nothing outside the conversation — no row, link or external object is created or changed for the
 *   agent space or the account, no job is started, no outward action is taken. A language-model call, an external
 *   look-up, a cache and the housekeeping every load of an agent space performs (the stored time zone, closing
 *   expired mandates, the application's own clean-up of stale rows, a refreshed link) do not make a tool a
 *   write; neither does a widget the tool writes into its own conversation.
 * - WRITE: creates, changes, publishes, sends or starts something.
 * - DELETE: removes something (a WRITE that takes an object away).
 *
 * `identicalRepeatDoesNewWork` (WRITE / DELETE only): a byte-identical repeat of the call is real work — a build, a
 * batch tool re-called until nothing is pending, a retry the tool itself asks for, a started job, a sent message.
 * The identical-write repeat refusal ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory::getEarlierCompletedIdenticalWriteIndex()})
 * never refuses such a tool; every other WRITE / DELETE repeated with identical arguments is refused as no new work.
 * An application that replaces a name-prefix rule by this attribute sets the flag first on exactly the writes its
 * old rule did not name, so the refusal keeps its member set — some of them (a settings write, a removal) would be
 * refused safely; it keeps that list in a test of its own and re-decides it per tool.
 *
 * `resultIsReusedWithinTurn` (READ only; meaningless for WRITE / DELETE): an identical repeat of the read later in the
 * same owner turn, with no write since, gets the earlier result back instead of running again — the per-turn read reuse
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory::getReusablePriorReadCall()}), which
 * also drives the repeated-failed-read refusal and the read/write split of the agent context's turn texts. False on a
 * READ whose repeat must run: a generator that answers anew on every call (a reply draft, a proposal) or a tool that
 * writes its widget into the conversation again (an offer, a handover). An application that replaces a name-prefix
 * rule sets the flag to false on exactly the READ tools its old `get*` / `search*` / `list*` rule did not
 * reuse, except the pure reads, and keeps that list in a test of its own.
 *
 * `isOutwardAction` (WRITE / DELETE): the completed call acts outward or needs the owner's explicit yes — a mandate
 * grant, a replacement of something published, a publish, a message to the owner, an import. The audit lists such
 * calls separately for the judge and the supervisor
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService::renderOutwardActions()}).
 *
 * `keepsEarlierReadsReusable`: a completed call of this tool changes no account data, so an earlier read of the turn
 * stays reusable after it (the per-turn read reuse treats every other non-reusable completed call as a possible change).
 * Set on a tool that only writes a widget into its own conversation (an offer to connect an outside service).
 *
 * The supervisor, the paused-turn record, the write-less owner-turn windows, the compaction telemetry, the identical-write
 * repeat refusal, the record-change check after a completed call, the fork-join, the per-turn read reuse and the eval
 * read-only gate read this ({@see MCPToolEffectCatalog}); a test of the application requires it on every tool.
 *
 *   #[MCPTool]
 *   #[MCPToolEffect(MCPToolEffect::WRITE)]
 *   public function confirmBooking(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolEffect
{
    public const string READ = 'READ';
    public const string WRITE = 'WRITE';
    public const string DELETE = 'DELETE';

    public function __construct(
        public string $effect,
        public bool $identicalRepeatDoesNewWork = false,
        public bool $resultIsReusedWithinTurn = true,
        public bool $isOutwardAction = false,
        public bool $keepsEarlierReadsReusable = false
    ) {
    }

    /** True when the tool changes nothing outside the conversation. */
    public function isReadOnly(): bool
    {
        return $this->effect === self::READ;
    }

    /** True when the tool creates, changes, publishes, sends, starts or removes something. */
    public function changesState(): bool
    {
        return $this->effect === self::WRITE || $this->effect === self::DELETE;
    }

    /** True when an identical repeat of this READ within the owner turn gets the earlier result back instead of running. */
    public function isReusedWithinTurn(): bool
    {
        return $this->isReadOnly() && $this->resultIsReusedWithinTurn;
    }

    /** True when a repeat of the call with byte-identical arguments does no new work and is refused. */
    public function refusesIdenticalRepeat(): bool
    {
        return $this->changesState() && !$this->identicalRepeatDoesNewWork;
    }
}
