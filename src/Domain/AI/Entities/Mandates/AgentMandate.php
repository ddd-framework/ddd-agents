<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandate;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A MANDATE: the owner's persisted authorization for the agent to perform a CLASS of consequential,
 * outward-facing actions — once, for a time window, or until revoked. The standing generalization of the one-shot
 * {@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation} consent gate: consent
 * authorizes THIS instance, a mandate authorizes the class within its window.
 *
 * GRANT EVIDENCE is the customer's own typed statement: {@see $customerApprovalExcerpt} holds the verbatim
 * dialogue quotes (markdown, conversation language), produced by the SERVER-side approval check
 * ({@see \DDD\Domain\AI\Repo\Argus\Mandates\ArgusAgentMandateApproval}) over a transcript window the tool
 * pulls itself from the conversation — the model never supplies the transcript, so a grant cannot be forged.
 * Quantity-based grants ("die nächsten 20 Bewertungen") are structurally inexpressible (no count field) and
 * are rejected by the approval check with a conversion instruction.
 *
 * Enforcement: {@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresMandate} on a tool +
 * {@see AgentMandatesService::findActiveMandateFor()} in the executor gate chain (after entitlement/rights,
 * before per-instance confirmation). Standing automations re-validate at FIRE time in their workers.
 *
 * @method static AgentMandatesService getService()
 * @method static DBAgentMandate getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentMandate::class)]
class AgentMandate extends Entity implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    // ---- action classes ----

    /** @var string Creating/altering unattended agent runs (schedules, background tasks). */
    public const string ACTION_STANDING_AUTOMATION = 'STANDING_AUTOMATION';

    /**
     * @var string[] Every action class a mandate can be granted for. The package knows one: the unattended runs it
     * owns. An application declares the classes of its own outward actions (publishing, replying, deleting) as
     * constants of its mandate class and redeclares this list there with the package's class and its own; the stored
     * values are the application's. Code reads the list through {@see getActionClasses()}, never off this class.
     */
    public const array ACTION_CLASSES = [self::ACTION_STANDING_AUTOMATION];

    /**
     * @var string[] The classes whose action runs on later, without the customer (an unattended run, an automatic
     * reply): a single request is no consent for them, so the executor never grants them a ONE_INSTRUCTION itself —
     * the customer's standing permission is granted through `grantMandate`. Read through
     * {@see isStandingGrantNeededForActionClass()}.
     */
    public const array ACTION_CLASSES_NEEDING_A_STANDING_GRANT = [self::ACTION_STANDING_AUTOMATION];

    /**
     * @var array<string, string> Plain-language description per action class — fed to the approval check so it
     * judges the right scope, and named in the executor's refusals. Read through {@see getActionClassDescription()}.
     */
    public const array ACTION_CLASS_DESCRIPTIONS = [
        self::ACTION_STANDING_AUTOMATION => 'Creating or altering unattended recurring agent runs (schedules, background tasks).',
    ];

    // ---- scope types ----

    /**
     * @var string Authorizes ONE customer request — every call of the granted action class needed to carry
     * out the one customer instruction it was granted from (e.g.
     * "delete my 13 draft posts" is ONE consent and must not cost 14 grants). The gate never consumes it per
     * call: it passes while the call's root conversation + turn-initiator message match
     * {@see $sourceConversationId}/{@see $sourceUserMessageId} and the call's tool matches the first-use
     * binding ({@see $coveredToolName}); the first gated call from a LATER request closes it out
     * (ACTIVE → CONSUMED), as does the deterministic staleness sweep in
     * {@see AgentMandatesService::markExpiredMandates()}.
     */
    public const string SCOPE_ONE_INSTRUCTION = 'ONE_INSTRUCTION';

    /** @var string The pre-rename stored value (rows granted before the rename) — accepted everywhere via
     * {@see hasOneInstructionScope()} until the value migration lands; never written anew. */
    public const string LEGACY_SCOPE_ONE_SHOT = 'ONE_SHOT';

    /** @var string Valid between validFrom and validUntil ("die nächsten 2 Wochen"). */
    public const string SCOPE_TIME_WINDOW = 'TIME_WINDOW';

    /** @var string Valid until revoked (the owner's "von nun an" / "regelmäßig"). */
    public const string SCOPE_UNLIMITED = 'UNLIMITED';

    public const array SCOPE_TYPES = [self::SCOPE_ONE_INSTRUCTION, self::SCOPE_TIME_WINDOW, self::SCOPE_UNLIMITED];

    // ---- statuses ----

    public const string STATUS_ACTIVE = 'ACTIVE';

    /** @var string A ONE_INSTRUCTION mandate whose customer request is over — closed out by the first gated call of a
     * later request, or by the 24h staleness sweep ({@see AgentMandatesService::markExpiredMandates()}). */
    public const string STATUS_CONSUMED = 'CONSUMED';

    /** @var string A TIME_WINDOW mandate whose validUntil passed — set by the deterministic normalization
     * ({@see AgentMandatesService::markExpiredMandates()}), so lists and the tab never show a dead window as active. */
    public const string STATUS_EXPIRED = 'EXPIRED';

    /** @var string The customer withdrew it (any utterance suffices — safety-directed, never verified). */
    public const string STATUS_REVOKED = 'REVOKED';

    /** @var string Replaced by a NEWER grant of the same class covering the same scope
     * (the memory-DIRECTIVE-supersede pattern made deterministic): the row stays forever as
     * evidence, {@see $supersededByMandateId} points to the successor — the chain is a first-class fact. */
    public const string STATUS_SUPERSEDED = 'SUPERSEDED';

    /**
     * @var int|null The granting owner's account. ALWAYS set (the mandate is the OWNER's authorization);
     * an account-wide mandate carries no {@see $agentSpaceId}, a mandate bound to a space carries both.
     */
    #[NotNull]
    public ?int $accountId = null;

    // explicit CASCADE: accountId is NOT NULL (#[NotNull]), so the generator's default SET NULL FK is
    // impossible (errno 150 on apply — the generator's null-guard reads the PHP type `?int`, not the
    // NotNull attribute that drives the column). A deleted account takes its mandates with it.
    // An application whose account class lives elsewhere redeclares the property in its same-path subclass with
    // `entityClassName:` naming its account class (the declared type alone resolves the framework's account repo).
    #[LazyLoad]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?Account $account;

    /**
     * @var int|null Id of the {@see AgentSpace} the mandate is bound to. Empty = ACCOUNT-WIDE: the mandate covers
     * every space of the account and the account level — the OPPOSITE reading of an empty reference on a
     * conversation or a schedule, where it names the account-level rows only. A lookup of the mandates that apply
     * to a space therefore matches both ({@see AgentSpaceSqlCondition::forAgentSpaceOrAccountWide()}); a lookup
     * without a space matches the account-wide mandates only. Written when the mandate is granted and never
     * changed afterwards (an update keeps the stored value): emptying it would widen a mandate bound to one space
     * to all of them.
     */
    #[DatabaseColumn(onUpdateAction: '`agentSpaceId`')]
    public ?int $agentSpaceId = null;

    /**
     * @var AgentSpace|null The space the mandate is bound to; deleting the space deletes its mandates (it never
     * turns them into account-wide ones). The repo type is named: a space is a database entity of this package,
     * whatever repo type the application uses by default.
     */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AgentSpace $agentSpace;

    /** @var string The authorized action CLASS — one of the ACTION_* constants. */
    #[NotNull]
    #[Choice(callback: [self::class, 'getActionClasses'])]
    #[Length(max: 64)]
    public ?string $actionClass = null;

    /** @var string ONE_INSTRUCTION / TIME_WINDOW / UNLIMITED. NEVER quantity-based — no count field exists by design. */
    #[NotNull]
    #[Choice(choices: self::SCOPE_TYPES)]
    #[Length(max: 16)]
    public ?string $scopeType = null;

    #[NotNull]
    public ?DateTime $validFrom = null;

    /** @var DateTime|null Required for TIME_WINDOW; NULL only on UNLIMITED / ONE_INSTRUCTION. */
    public ?DateTime $validUntil = null;

    #[NotNull]
    #[Choice(choices: [self::STATUS_ACTIVE, self::STATUS_CONSUMED, self::STATUS_EXPIRED, self::STATUS_REVOKED, self::STATUS_SUPERSEDED])]
    #[Length(max: 16)]
    public ?string $status = self::STATUS_ACTIVE;

    /**
     * @var string|null THE EVIDENCE ARTIFACT: an exact markdown summary of the granting dialogue with
     * VERBATIM customer quotes, in the conversation's language — produced by the server-side approval
     * check, never by the dialog model. Example:
     * Customer: „Kannst du für mich von nun an alle Bewertungen beantworten?" — Agent: „Ja, soll ich das von
     * nun an machen?" — Customer: „Ja, das wäre top."
     */
    #[\DDD\Domain\Base\Repo\DB\Database\DatabaseColumn(sqlType: \DDD\Domain\Base\Repo\DB\Database\DatabaseColumn::SQL_TYPE_TEXT)]
    public ?string $customerApprovalExcerpt = null;

    /**
     * @var string|null Free-prose scope constraints the approval check extracted ("nur positive
     * Bewertungen", "nur Facebook und Instagram") — v1 prose; a typed constraint payload comes when a
     * class demonstrably needs machine enforcement.
     */
    #[Length(max: 500)]
    public ?string $constraintsNote = null;

    /** @var int|null Conversation traceability (like memories): the conversation the grant happened in. */
    public ?int $sourceConversationId = null;

    /** @var int|null The customer message that triggered the grant — the excerpt window's anchor, AND (for
     * ONE_INSTRUCTION) the request binding: the gate passes only calls whose turn-initiator message is this one. */
    public ?int $sourceUserMessageId = null;

    /**
     * @var string|null ONE_INSTRUCTION first-use tool binding: the FIRST gated tool that rode this mandate stamps
     * its name here (atomic, {@see AgentMandatesService::bindOneInstructionMandateToTool()}); every later pass
     * requires the same tool. Closes the class-width hole inside one request — a consent for "delete my
     * drafts" (deletePost) must not also cover publishPost, although both carry SOCIAL_PUBLISH. NULL on
     * non-ONE_INSTRUCTION mandates and on a ONE_INSTRUCTION no call has ridden yet.
     */
    #[Length(max: 100)]
    public ?string $coveredToolName = null;

    /**
     * @var AgentMandateSourceConversation|null Where the mandate was granted — conversation + triggering
     * message resolved from the source ids (title, summary, message excerpt, when it was said). Carried WITH
     * the mandate via this CLASS_METHOD lazy-load so the list `expand()` brings it along (the memories'
     * provenance treatment). Computed, never persisted.
     */
    #[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'loadSourceConversation')]
    #[HidePropertyOnSystemSerialization]
    #[DatabaseColumn(ignoreProperty: true)]
    public ?AgentMandateSourceConversation $sourceConversation = null;

    public ?DateTime $revokedAt = null;

    /** @var int|null The message whose utterance revoked it (revocation is deliberately un-checked — safety-directed). */
    public ?int $revokedByMessageId = null;

    /**
     * The mandate class of the running application: its same-path subclass when it has one, else this class. The
     * action classes, their descriptions and the standing-grant list are constants of THAT class.
     *
     * @return class-string<AgentMandate>
     */
    public static function getMandateClassOfTheApplication(): string
    {
        /** @var class-string<AgentMandate> $mandateClassOfTheApplication */
        $mandateClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $mandateClassOfTheApplication;
    }

    /**
     * Every action class a mandate can be granted for in the running application ({@see ACTION_CLASSES} of its
     * mandate class). The allowed values of {@see $actionClass} and of the `grantMandate` tool.
     *
     * @return string[]
     */
    public static function getActionClasses(): array
    {
        return (self::getMandateClassOfTheApplication())::ACTION_CLASSES;
    }

    /**
     * The plain-language description of an action class in the running application; the class's own name when the
     * application describes none.
     *
     * @param string $actionClass
     * @return string
     */
    public static function getActionClassDescription(string $actionClass): string
    {
        return (self::getMandateClassOfTheApplication())::ACTION_CLASS_DESCRIPTIONS[$actionClass] ?? $actionClass;
    }

    /**
     * Whether an action of this class runs on later, without the customer, so that only a standing grant covers it
     * ({@see ACTION_CLASSES_NEEDING_A_STANDING_GRANT} of the application's mandate class).
     *
     * @param string $actionClass
     * @return bool
     */
    public static function isStandingGrantNeededForActionClass(string $actionClass): bool
    {
        return in_array($actionClass, (self::getMandateClassOfTheApplication())::ACTION_CLASSES_NEEDING_A_STANDING_GRANT, true);
    }

    /**
     * Lazy-load body for {@see $sourceConversation} (CLASS_METHOD): pure guard on the object, repo-touching
     * tail delegated to the service (it loads the message + its conversation, rights-gated, best-effort).
     */
    public function loadSourceConversation(): ?AgentMandateSourceConversation
    {
        if (!isset($this->sourceConversationId) && !isset($this->sourceUserMessageId)) {
            return null;
        }
        return self::getService()->loadSourceConversation($this);
    }

    /**
     * @var int|null On a SUPERSEDED mandate: the id of the NEWER mandate that replaced it — the explicit
     * traceability link of the supersede chain (old grant → successor), navigable in both directions.
     * The end timestamp of a superseded mandate is its `updated` column; `revokedAt` stays reserved for
     * genuine customer withdrawal.
     */
    public ?int $supersededByMandateId = null;

    /**
     * Whether a NEW grant of {@see $newerMandate}'s shape REPLACES this mandate — same action class and the
     * newer one's coverage INCLUDES this one's: a mandate bound to a space is covered by a mandate of the same space
     * or by an account-wide one; an account-wide mandate only by another account-wide (narrowing account-wide down
     * to one space is deliberately NOT an automatic supersede — that intent is an explicit revoke + grant).
     * Pure fold on {@see $agentSpaceId} (no load) — the auto-supersede in
     * {@see AgentMandatesService::grantMandateFromDialogue()} applies it.
     */
    public function isSupersededBy(AgentMandate $newerMandate): bool
    {
        if ($this->actionClass !== $newerMandate->actionClass) {
            return false;
        }
        if ($newerMandate->agentSpaceId === null) {
            return true;
        }
        return $this->agentSpaceId !== null && (int)$this->agentSpaceId === (int)$newerMandate->agentSpaceId;
    }

    /**
     * Whether this ONE_INSTRUCTION mandate authorizes a call belonging to the given customer request — the lazy
     * request predicate: PASS iff the call's root conversation and turn-initiator
     * message are the ones the grant was made from, and the calling tool matches the first-use binding (an
     * unbound mandate accepts any tool of its class — the gate stamps the binding on first pass). Pure id
     * comparison, no repo, no fold over messages — crash-/race-/compaction-safe by construction. Non-ONE_INSTRUCTION
     * scopes are request-independent and always return true here.
     */
    /** Whether this mandate's scope is the single-request one — matches the current value AND the pre-rename
     * stored 'ONE_SHOT' rows ({@see LEGACY_SCOPE_ONE_SHOT}). */
    public function hasOneInstructionScope(): bool
    {
        return $this->scopeType === self::SCOPE_ONE_INSTRUCTION || $this->scopeType === self::LEGACY_SCOPE_ONE_SHOT;
    }

    public function authorizesRequest(
        ?int $rootConversationId,
        ?int $turnInitiatorMessageId,
        string $toolName,
        bool $callRunsInDelegationChild = false,
    ): bool {
        if (!$this->hasOneInstructionScope()) {
            return true;
        }
        if (($this->sourceConversationId ?? null) === null && $rootConversationId === null) {
            // BOTH sides unidentified = the same transient in-process context (the eval harness drives
            // unpersisted conversations — no ids exist on either the grant or the call). Real conversations
            // are always persisted, so any identified side keeps the strict matching below.
            return $this->coveredToolName === null || $this->coveredToolName === $toolName;
        }
        if ($rootConversationId === null) {
            // Fail closed: a ONE_INSTRUCTION is bound to one request — a call that cannot name its request never rides it.
            return false;
        }
        if (($this->sourceConversationId ?? null) !== $rootConversationId) {
            return false;
        }
        // A delegation child executes its ROOT thread's current request but carries a child-local user message,
        // so the initiator comparison is skipped for it ("delete all posts via
        // delegates" must ride the parent's grant). Safe because the turn-start close-out
        // ({@see AgentMandatesService::closeOutOneInstructionMandatesFromEarlierRequests()}) guarantees only the
        // CURRENT request's mandate can still be ACTIVE in this conversation when a child runs.
        if (!$callRunsInDelegationChild) {
            if ($turnInitiatorMessageId === null || ($this->sourceUserMessageId ?? null) !== $turnInitiatorMessageId) {
                return false;
            }
        }
        return $this->coveredToolName === null || $this->coveredToolName === $toolName;
    }

    /** Whether this mandate authorizes an execution of its action class RIGHT NOW. Pure time/state fold. */
    public function isCurrentlyValid(?DateTime $now = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        $now ??= new DateTime();
        if ($this->validFrom !== null && $now < $this->validFrom) {
            return false;
        }
        if ($this->scopeType === self::SCOPE_TIME_WINDOW) {
            return $this->validUntil !== null && $now <= $this->validUntil;
        }
        return true;
    }

    /**
     * One compact line for the agent/list rendering. Example:
     *   REVIEW_REPLY · time window until 2026-09-21 · ACTIVE — "Customer: „…beantworte 2 Wochen…""
     */
    public function toLlmMarkdown(): string
    {
        return $this->toLlmMarkdownInZone(null);
    }

    /**
     * The same line with the zone given EXPLICITLY — for a tool body that composes prose outside the executor's
     * model-facing region; `toLlmMarkdown()` (inside the region) resolves the zone from the registry.
     */
    public function toLlmMarkdownInZone(?\DateTimeZone $timezone): string
    {
        $scopeText = match ($this->scopeType) {
            self::SCOPE_ONE_INSTRUCTION, self::LEGACY_SCOPE_ONE_SHOT => 'one-instruction',
            self::SCOPE_TIME_WINDOW => 'time window until ' . ($this->validUntil !== null ? AgentClock::formatDay($this->validUntil, $timezone) : '?'),
            default => 'until revoked',
        };
        $line = '- [' . ($this->id ?? 'new') . '] ' . $this->actionClass . ' · ' . $scopeText . ' · ' . $this->status
            . ' · ' . $this->buildCoverageText();
        if (($this->constraintsNote ?? '') !== '') {
            $line .= ' · constraints: ' . $this->constraintsNote;
        }
        if (($this->customerApprovalExcerpt ?? '') !== '') {
            $line .= "\n  granted on: " . str_replace("\n", ' ', mb_substr($this->customerApprovalExcerpt, 0, 220));
        }
        return $line;
    }

    /**
     * What the mandate covers, as the model reads it in the list line: the space it is bound to (by id, no load), or
     * the whole account. An application's subclass may name the space its own way.
     *
     * @return string
     */
    protected function buildCoverageText(): string
    {
        return $this->agentSpaceId !== null ? 'space ' . $this->agentSpaceId : 'account-wide';
    }

    /** Entity shorthand: revoke this mandate (repo tail in the service). */
    public function revoke(?int $revokedByMessageId = null): AgentMandate
    {
        return self::getService()->revokeMandate($this, $revokedByMessageId);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
