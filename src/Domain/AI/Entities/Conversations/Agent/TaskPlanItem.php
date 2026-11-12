<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * ONE node of a {@see TaskPlan}'s item TREE: a sub-task with a status walk of open → done / blocked /
 * not_applicable, every terminal state carrying a MANDATORY {@see $statusNote} — and optionally its own
 * {@see $subtasks} (the tree edge; every node may decompose further, to any depth — a plan has no item limit).
 *
 * IDENTITY: every node carries a server-minted hierarchical {@see $key} ("7.2.11") — minted at the seed
 * and by an `update_task_plan` call for each new item ({@see mintSubtaskKey()}, {@see TaskPlan::mintTopLevelKey()}),
 * derived on read for a plan stored before keys existed ({@see TaskPlanStoredKeyPreservation::deriveOrPreserveKeys()}).
 * The key, not the text, is the node's {@see uniqueKey()}; the renders print it where the number path stood, and a plan
 * never restructured has key = number path.
 *
 * OPENNESS is tree-aware ({@see isOpen()}): `done` with open subtasks still counts as OPEN (a parent tick must
 * never waive the checked-stop contract for unfinished children), `blocked` PRUNES its subtree (the one
 * named blocker covers the children — the run may end cleanly on it), and an `open` parent whose subtasks ALL
 * reached a terminal state derives CLOSED from its subtree (a container node is never itself the remaining work —
 * the model does not have to tick parents).
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanItem extends ValueObject
{
    /** @var string Not finished and not blocked — the state the checked stop refuses to end on. */
    public const string STATUS_OPEN = 'open';

    /** @var string Truly completed (the action executed, not merely planned — the Agentic.md discipline). */
    public const string STATUS_DONE = 'done';

    /** @var string Cannot be completed — requires a concrete {@see $statusNote}; SATISFIES the checked stop. */
    public const string STATUS_BLOCKED = 'blocked';

    /**
     * @var string Does NOT apply to THIS space — the agent judged the item irrelevant (e.g. a workshop that takes no
     * walk-in customers has no walk-in hours to set). A terminal
     * state that SATISFIES the checked stop, distinct from `done` (nothing was produced, so a done+result note would
     * be the "ticked done, nothing delivered" lie) and from `blocked` (no obstacle, so it must NOT surface as a
     * call-to-action in the run report). Requires a concrete {@see $statusNote} (why it does not apply).
     */
    public const string STATUS_NOT_APPLICABLE = 'not_applicable';

    /** @var int Hard cap for {@see $statusNote} — one line, never a second final answer (parsers truncate). */
    public const int STATUS_NOTE_MAX_LENGTH = 200;

    /**
     * @var array<string, string> The names a seed may declare in {@see $doneRequiresConnectedChannel}, each with the
     * words the status tool uses for it in its note (`calendar` → `the shared calendar`). The module knows no
     * connection of its own: an application declares its names on its item class of the same path, and a name that is
     * not declared is no gate.
     */
    public const array CONNECTED_CHANNEL_LABELS = [];

    /**
     * @var string The item-key grammar: one segment per level, any number of levels, each segment 1–999
     * without a leading zero, joined by dots — nothing around it.
     */
    public const string KEY_PATTERN = '^[1-9][0-9]{0,2}(\.[1-9][0-9]{0,2})*$';

    /** @var int The highest number one key segment can carry (the segment width is fixed at three digits). */
    public const int KEY_SEGMENT_MAX = 999;

    /** @var string The sub-task, short and concrete — the agent's instruction; never the identity (that is {@see $key}). */
    public string $text = '';

    /**
     * @var string|null The server-minted, STABLE hierarchical key ("7.2.11"): its prefix is the parent's
     * key, its last segment the number the parent's counter minted. Never renumbered and never reused within a plan.
     * Null only on an item built in memory and not minted yet — every seeded, parsed or stored item carries one.
     */
    public ?string $key = null;

    /**
     * @var int The highest segment ever minted for a direct subtask of this item — the next new
     * subtask is `key.(n + 1)`; a subtask that leaves the plan never lowers it. A full replace (the unkeyed
     * `update_task_plan`) mints the whole tree anew, so it sets the counter to the item's subtask count.
     */
    public int $highestSubtaskKeyNumber = 0;

    /** @var bool The item was minted from the purpose seed; false for an item an `update_task_plan` call created. */
    public bool $seededByPurpose = false;

    /**
     * @var string|null The CUSTOMER-facing name of this item — an ENGLISH SOURCE STRING (app-translation key),
     * maintained in the purpose blueprints. {@see $text} is the agent's INSTRUCTION (English work prose, tool
     * names, skill-load markers) and must never reach the owner; this field is the ONLY item text the
     * {@see AgentRunReport} quotes in the pause/terminal chat message. Null (every model-created item, and any
     * seed item without one) = the item is never named to the customer — it is counted, not quoted. Replaces the
     * report's former 60-char length heuristic, which let short internal titles ("… (load Setup.Keywords)")
     * leak verbatim into the customer message.
     */
    public ?string $ownerFacingTitle = null;

    /** @var string This node's OWN status ({@see self::STATUS_OPEN}/{@see self::STATUS_DONE}/{@see self::STATUS_BLOCKED}/{@see self::STATUS_NOT_APPLICABLE}); effective openness is tree-aware via {@see isOpen()}. */
    #[Choice(choices: [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_BLOCKED, self::STATUS_NOT_APPLICABLE])]
    public string $status = self::STATUS_OPEN;

    /**
     * @var bool Blocked AND only the USER can unblock it (expired connection, missing information, an unclear
     * task = the clarifying question) — the block-taxonomy axis stage 4 keys `AWAITING_USER_INPUT` on and the run
     * report renders as an explicit call-to-action. Meaningful only on {@see self::STATUS_BLOCKED}.
     */
    public bool $requiresUserAction = false;

    /**
     * @var string|null Done-evidence GATE (declared in the purpose seed, checked generically by SetTaskStatusTool):
     * the item accepts `done` only after a COMPLETED, non-errored call of THIS tool exists in the conversation —
     * e.g. the goals item requires `addGoal`. Null = no gate (agent-judged, unchanged behaviour).
     */
    public ?string $doneRequiresToolCall = null;

    /**
     * @var array<string, array<int, mixed>>|null Argument filter on the {@see $doneRequiresToolCall} gate: a map of
     * argument name → DISQUALIFYING values. A completed call of the gate tool does NOT satisfy the gate when its
     * decoded arguments carry one of these values — e.g. the goals gate declares `{statedByOwner: [false]}` so an
     * agent-proposed `addGoal` never counts as the owner-stated goal the gate demands (without the filter exactly such
     * proposals satisfy the gate).
     * Null = any completed, non-errored call qualifies (unchanged behaviour). Meaningful only alongside
     * {@see $doneRequiresToolCall}.
     */
    public ?array $doneRequiresToolCallArgumentsAbsent = null;

    /**
     * @var array<string, array<int, mixed>|mixed>|null REQUIRED-argument filter on the {@see $doneRequiresToolCall}
     * gate — the qualifying-present twin of {@see $doneRequiresToolCallArgumentsAbsent}: a completed call
     * satisfies the gate only when EVERY named argument carries one of its accepted values (an absent argument
     * never qualifies). E.g. the service-areas item declares `{areasStatedByOwner: [true]}` — only an area list
     * saved AS the owner's answer completes the item. Null = no required-argument filter. Meaningful only
     * alongside {@see $doneRequiresToolCall}.
     */
    public ?array $doneRequiresToolCallArgumentsPresent = null;

    /**
     * @var string|null NOT-APPLICABLE-evidence GATE (the wrong-n/a class: an
     * item marked n/a while the space's own record says the opposite and
     * the deciding question was never asked): the item accepts `not_applicable` only after a COMPLETED,
     * non-errored call of THIS tool exists — optionally filtered by
     * {@see $notApplicableRequiresToolCallArgumentsPresent}; a call that did no work by design
     * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::didNoWorkByDesign()}) counts.
     * The SYSTEM-side criterion pre-resolution ({@see TaskPlanItems::applyPreResolutions()}) bypasses the gate by
     * design (a data-derived verdict needs no agent evidence). Null = n/a stays ungated (unchanged behaviour).
     */
    public ?string $notApplicableRequiresToolCall = null;

    /**
     * @var array<string, array<int, mixed>|mixed>|null Required-argument filter on the
     * {@see $notApplicableRequiresToolCall} gate — same semantics as {@see $doneRequiresToolCallArgumentsPresent}.
     * E.g. the service-areas item declares `{serviceAreaTypeStatedByOwner: [true], serviceAreaType:
     * ["CUSTOMER_AND_BUSINESS_LOCATION"]}`: n/a ("walk-in only, no areas needed") is claimable only after the
     * owner's walk-in answer was actually persisted.
     */
    public ?array $notApplicableRequiresToolCallArgumentsPresent = null;

    /**
     * @var array<string, array<int, mixed>|mixed>|null Disqualifying-argument filter on the
     * {@see $notApplicableRequiresToolCall} gate — same semantics as {@see $doneRequiresToolCallArgumentsAbsent}:
     * e.g. an offer item declares `{topics: ["other_topic"]}`, so an owner statement
     * about ANOTHER topic re-labelled with the offer's topic never resolves the offer n/a.
     */
    public ?array $notApplicableRequiresToolCallArgumentsAbsent = null;

    /**
     * @var string|null NOT-APPLICABLE CONTRADICTION GATE (the inverse of
     * {@see $notApplicableRequiresToolCall}): the item REJECTS `not_applicable` when a COMPLETED, non-errored
     * call of THIS tool exists in the conversation — the call proves the topic IS real for this space, so
     * "does not apply" is a misverdict. E.g. the social-connect item declares `updateProfileSocialMediaLinks`:
     * once a social link was persisted, the account exists and the connect offer is owed — n/a claimed
     * "no social presence" against the conversation's own evidence. Null = no contradiction gate (unchanged
     * behaviour).
     */
    public ?string $notApplicableForbiddenAfterToolCall = null;

    /**
     * @var string|null Done-evidence GATE, widget flavour: the item accepts `done` only after an assistant message
     * carries a ```widget block of THIS `"type"` (e.g. the handover item requires `SetupHandover`). Null = no gate.
     */
    public ?string $doneRequiresWidget = null;

    /**
     * @var string|null Done-evidence GATE, connection flavour: the item accepts `done` only when
     * the named channel (a name of {@see self::CONNECTED_CHANNEL_LABELS}) is CONNECTED
     * on the space's record — the purpose reads the live connection state; a rendered connect widget alone never
     * proves a connection (a model can tick the connect done while the widget stays unclicked). Null = no gate.
     */
    public ?string $doneRequiresConnectedChannel = null;

    /**
     * @var array<string, string[]>|null The answer PILLS of the
     * closed question this item ends its turn on, per language code (`de` → ["Ja, so übernehmen", …]). The PLATFORM
     * attaches the `QuickReplies` widget to the final text of a turn that ends on this item's question — the model
     * never writes the fence for a seeded closed question. Null = the item asks no seeded closed question.
     */
    public ?array $closedQuestionOptions = null;


    /**
     * @var string|null The MINIMUM reasoning effort (`low` / `medium` / `high`) of the
     *      model call that works on this item while it is the next open one — the loop raises the call's effort to
     *      this value when the turn's decided effort lies below it and the model accepts a reasoning effort at all;
     *      it never lowers a higher effort, and the model's ceiling still clamps. Declared by the purpose in its plan
     *      seed for items whose delivery is a composed, structured answer (a widget block, a combined draft). Null =
     *      the turn's effort. A parent's value covers its subtasks (the maximum along the open chain applies).
     */
    #[Choice(choices: ['low', 'medium', 'high'])]
    public ?string $minReasoningEffort = null;

    /**
     * @var bool Done-evidence GATE, delivery flavour     * baseline items were ticked done with the numbers in the statusNote while NO owner-visible message carried
     * them — the tick rode a parallel tool batch one model step BEFORE the turn's answer text): the item accepts
     * `done` only when the CURRENT turn already contains an assistant TEXT message that is not a status-only
     * announcement line ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::isStatusOnlyAnnouncementLine()}).
     * Purely structural (role + type + index ordering + the model-declared marker) — no text content is read.
     * It enforces ORDER (deliver to the owner, then tick), not content; declared in the purpose seed on the
     * items whose done MEANS "the owner read it". The rejection does not force a tool-less step.
     */
    public bool $doneRequiresOwnerVisibleAnswerInSameTurn = false;

    /**
     * @var string|null The QUESTION a delivery leaf ({@see $doneRequiresOwnerVisibleAnswerInSameTurn}) closes on: the
     * `askOwner` catalogue key ({@see Tools\AskOwnerQuestionCatalogue}) the owner must have been asked after
     * the leaf's evidence call — the delivered answer alone (a message that names the result) does not close the leaf.
     * A leaf asked in a later turn closes in that turn; {@see $doneAlsoOnOwnerAnswerOfWidget} is the other way. Null =
     * the delivered answer closes the leaf.
     */
    public ?string $doneRequiresOwnerQuestionKey = null;

    /**
     * @var string|null With {@see $doneRequiresOwnerQuestionKey}: the widget type whose answer by the owner (or
     * decline) also closes the leaf — the owner answered the result's own widget, so the question was answered without
     * being asked. Null = only the asked question closes it.
     */
    public ?string $doneAlsoOnOwnerAnswerOfWidget = null;

    /**
     * @var string|null With {@see $doneRequiresOwnerQuestionKey}: a tool (or `|` alternatives) whose call shows the
     * owner the result WITHOUT saving it — a proposal the owner approves before the save (the proposing tool writes
     * the widget, the owner answers the question, the saving tool saves). It is never the done evidence
     * ({@see $doneRequiresToolCall} stays the persisting call); an ask the owner answered after such a call and before the
     * save counts as the leaf's question once the save lands. Null = only a done-evidence call shows the result.
     */
    public ?string $doneOwnerQuestionMayFollowToolCall = null;

    /**
     * @var string[]|null Next-turn acceptance of the {@see $doneRequiresOwnerVisibleAnswerInSameTurn} gate: a tick
     * arriving at the start of the turn AFTER the delivery is accepted when the PREVIOUS turn both completed one of
     * these evidence tools and ended on the delivering owner-visible answer (the delivery item's "read it, then told
     * the owner" order, one turn late — the way models reliably tick). Null = strict same-turn only. Seeded on the
     * item; carried like the other gates.
     */
    public ?array $previousTurnDeliveryEvidenceToolNames = null;

    /**
     * @var int|null The `indexInConversation` of the owner-visible answer that satisfied the delivery gate on a
     * next-turn tick — an AUDIT record persisted with the plan column (the admin inspect shows which answer the
     * platform accepted as the delivery); nothing gates on it. Null while the item is open or was ticked in its
     * own turn.
     */
    public ?int $evidenceMessageIndex = null;

    /**
     * @var int|null Platform record: the message index where this item's owner-answered widget
     * ({@see $doneRequiresWidgetAnswered}) was rendered (the sweep stamps it) — from then on the item awaits the owner.
     */
    public ?int $offerRenderedAtMessageIndex = null;

    /**
     * @var bool PROVENANCE: the platform resolved this leaf from the space's live state at seed time — BEFORE
     * the dialog (see {@see TaskPlanItems::applyPreResolutions()}). A terminal leaf with this flag is not the
     * agent's progress: the customer stepper ({@see Progress\CustomerProgressSteps}) must not read a pre-resolved
     * "not applicable" item of the last act as "the run reached its wrap-up" on turn 0. Persisted and
     * carried across resends; nothing else gates on it.
     */
    public bool $preResolvedAtSeed = false;

    /**
     * @var int|null PROVENANCE detail for {@see $preResolvedAtSeed}: the newest `indexInConversation` at the moment
     * the platform resolved this leaf (0 when it happened at seed time, before any message) — the judge trace
     * renders it as `(platform-resolved @N)` so a verdict the PLATFORM produced mid-run (the post-write re-sweep)
     * is never read as the agent's work, and the moment is checkable against the messages. Cleared together with
     * the flag when the agent's own tick changes the status. Persisted; nothing gates on it.
     */
    public ?int $platformResolvedAtMessageIndex = null;

    /**
     * @var array<string, mixed>|null Required-field skeleton on the {@see $doneRequiresWidget} gate:
     * a map of payload field name → skeleton value the widget block must carry FILLED — e.g.
     * the handover item declares `{"promise": {"date": "<YYYY-MM-DD>", "topic": "<what you deliver>"}}`, so a
     * SetupHandover block whose promise is missing, empty, or still a `<placeholder>` does not satisfy the gate.
     * The SAME skeleton is what every fence dictation appends to the block it tells the model to copy — gate
     * and dictation come from this ONE declaration and can never drift. Null = the plain type check (unchanged).
     */
    public ?array $doneRequiresWidgetFilledFields = null;

    /**
     * @var bool The {@see $doneRequiresWidget} widget is the OWNER's question, not the agent's delivery: the leaf
     * closes when the owner ANSWERED the widget (the confirm click, for a multi-row widget the click that names a row),
     * never on the render alone. Rendered and unanswered, the leaf is AWAITING the owner — not the plan's next move,
     * not owed, not a bounce reason ({@see isAwaitingOwnerOffer()}).
     */
    public bool $doneRequiresWidgetAnswered = false;

    /**
     * @var string|null Blocked-evidence GATE, widget flavour: the item accepts
     * `blocked` only after an assistant message carries a ```widget block of THIS type — `blocked` on a connect
     * item means "the owner DEFERRED an offer", which presupposes the offer was rendered; going straight to
     * blocked without the widget claims a deferral that never happened. `not_applicable` stays ungated (never
     * offered because never relevant). Null = no gate.
     */
    public ?string $blockedRequiresWidget = null;

    /**
     * @var string|null Blocked-evidence GATE, tool-call flavour: on an item whose seed
     * declares it, `blocked` + `requiresUserAction` (the owner deferred or declined) is accepted only after a
     * COMPLETED, non-errored call of THIS tool exists — optionally filtered by
     * {@see $blockedRequiresToolCallArgumentsPresent} (e.g. the `addOwnerStatement` record of the owner's words). A
     * PROSE offer has no widget to demand, so the recorded statement is its deferral evidence; on an item that also
     * declares {@see $blockedRequiresWidget} the record is the ALTERNATIVE to the rendered widget (a pre-widget
     * deferral in the owner's own words never deadlocks). A technical block (`requiresUserAction: false`, e.g. a
     * retryable tool rejection) is not gated here. A closing the platform writes bypasses it. Null = no gate.
     */
    public ?string $blockedRequiresToolCall = null;

    /**
     * @var array<string, array<int, mixed>|mixed>|null Required-argument filter on the {@see $blockedRequiresToolCall}
     * gate — same semantics as {@see $notApplicableRequiresToolCallArgumentsPresent}; an ARRAY argument on the call
     * qualifies when it CONTAINS an accepted value (e.g. `topics: ["google_listing"]`).
     */
    public ?array $blockedRequiresToolCallArgumentsPresent = null;

    /**
     * @var bool The supervisor's MILESTONE marker: declared in the purpose seed on the item whose tick means the
     * run is about to close (a purpose's handover item); carried across full-replace resends like the evidence
     * gates. Its reader, the v1 milestone trigger of the supervisor, was removed —
     * the v2 beats ({@see \DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointTrigger::buildSettledBeatReason()})
     * do not read it; the flag stays on the stored plans and seeds.
     */
    public bool $triggersSupervisorMilestoneCheckpoint = false;

    /**
     * @var bool Blocked by a TRANSIENT external failure (an API down, a rate limit) — the system may simply retry
     * on a later run without user help: the axis stage 5 keys self-retry on, and the run report words as "will
     * retry". Meaningful only on {@see self::STATUS_BLOCKED}; both taxonomy bools false = hard impossibility.
     */
    public bool $retryable = false;

    /**
     * @var string|null The ONE-line justification for this item's TERMINAL status — the unified accountability note
     * across all three terminal states (it replaced the former separate `completionNote`
     * + `blockReason`, which were the same slot). Its meaning follows the status:
     *   - `done` → WHAT was produced/found ("draft written: 'Mo–Fr 9–17'", "profile connected, sync pending");
     *   - `blocked` → WHY, concretely ("Facebook connection expired");
     *   - `not_applicable` → WHY it does not apply ("reines Online-Business, keine Öffnungszeiten").
     * MANDATORY on every terminal status (the parsers reject a terminal status without it — a note-less terminal
     * would let the model end the run on everything with zero accountability). Rendered on the item's tail-hint line
     * (the model's own progress memory — it survives compaction because the plan lives in the `agentWorkingState`
     * column, outside the wire window) and carried into the run report. Capped at
     * {@see self::STATUS_NOTE_MAX_LENGTH}; null on {@see self::STATUS_OPEN}.
     */
    public ?string $statusNote = null;

    /**
     * @var string|null The MACHINE done-criterion key of this leaf (evaluated by the application,
     * e.g. 'keywords.curated', 'reach.set') — set ONLY on seeded leaves whose "done" is decided by underlying
     * data, not by the agent's word. It lets the seed PRE-TICK an already-satisfied topic: a
     * resumable run arrives reflecting reality instead of a flat all-open list. Null = no machine criterion (the
     * agent's `set_task_status` alone decides this item, the normal case). Advisory only — never overrides an agent
     * tick/block; a null-or-UNKNOWN evaluation just leaves the leaf as seeded.
     */
    public ?string $doneCriterion = null;

    /**
     * @var string|null The customer-facing progress step this item belongs to (a guided run's stepper)
     * — a key from the purpose's `customerProgressSteps` frontmatter declaration (e.g. 'identify').
     * Declared in the purpose seed; a subtask without its own key INHERITS the parent's at seed-parse time
     * ({@see TaskPlanItems::fromPurposeSeedDefinition()}), so runtime aggregation stays a flat key match. Items
     * the agent adds mid-run carry no key and are invisible to the stepper (the customer UI never jumps on
     * dynamic plan growth). Kept with its item by the keyed `update_task_plan`; losing it only degrades the
     * stepper display, so it deliberately does NOT ride {@see carriesEvidenceGate()} (no reject semantics).
     */
    public ?string $customerProgressStepKey = null;

    /**
     * @var string[] Site A — the skills this plan item NEEDS (seed frontmatter `skillNames`): when the item is
     *      the plan's `← next`, the loop preloads them before the model call (deterministic, no decision model). A
     *      container contributes its OWN names, no inheritance. Seed-only: the model's `update_task_plan` never sets
     *      it; a replacement carries it by text like the other seeded declarations.
     */
    public array $skillNames = [];

    /**
     * @var TaskPlanItems|null This node's SUB-TASKS (the tree edge) — the same uniform set type as the plan's top
     * level, so folds recurse without per-depth logic. Null = a leaf.
     */
    public ?TaskPlanItems $subtasks = null;

    /**
     * This node's identity within its level's set — the item KEY, so two siblings with the same text
     * stay two items. A node not minted yet (an item built in memory) falls back to its text, the identity it had
     * before keys existed; the parse removes its text duplicates explicitly ({@see TaskPlanItems::containsItemWithText()}).
     */
    public function uniqueKey(): string
    {
        return $this->key !== null
            ? self::uniqueKeyStatic('key:' . $this->key)
            : self::uniqueKeyStatic($this->text);
    }

    /**
     * Whether $key conforms to {@see KEY_PATTERN}. `D`: the `$` anchors at the very end of the string — without it a
     * trailing newline ("2.2\n") passed.
     */
    public static function isConformingKey(string $key): bool
    {
        return preg_match('/' . self::KEY_PATTERN . '/D', $key) === 1;
    }

    /** The key's last segment as a number ("7.2.11" → 11), null when the key does not conform. */
    public static function getLastKeySegment(string $key): ?int
    {
        if (!self::isConformingKey($key)) {
            return null;
        }
        $segments = explode('.', $key);
        return (int)end($segments);
    }

    /**
     * The ONE allocation rule of a key segment (the write side and the read side share it): the
     * number after the level's highest one ever minted, or null when that number would exceed {@see KEY_SEGMENT_MAX} — a
     * number is never reused, so a level that used all 999 numbers takes no further item.
     */
    public static function allocateKeySegmentAfter(int $highestKeyNumber): ?int
    {
        $nextKeySegment = max(0, $highestKeyNumber) + 1;
        return $nextKeySegment <= self::KEY_SEGMENT_MAX ? $nextKeySegment : null;
    }

    /**
     * Mint the key of a NEW direct subtask of this item: `key.(n + 1)` from {@see $highestSubtaskKeyNumber},
     * which it raises. Null — and nothing raised — when the level used all 999 numbers ({@see allocateKeySegmentAfter()};
     * the update check rejects such a call before anything is minted, R24).
     */
    public function mintSubtaskKey(): ?string
    {
        $keySegment = self::allocateKeySegmentAfter($this->highestSubtaskKeyNumber);
        if ($keySegment === null) {
            return null;
        }
        $this->highestSubtaskKeyNumber = $keySegment;
        return self::composeChildKey((string)$this->key, $keySegment);
    }

    /**
     * The key a malformed value most likely meant (the R3 normalization: trimmed, cut at the first space, `-`
     * and `/` as dots, a trailing dot and leading zeros dropped) when that candidate conforms to {@see KEY_PATTERN}, else
     * null. A hint only — never applied as an address.
     */
    public static function buildKeyCandidate(string $value): ?string
    {
        $candidate = preg_split('/\s+/u', trim($value))[0] ?? '';
        $candidate = rtrim(str_replace(['-', '/'], '.', $candidate), '.');
        $candidate = implode('.', array_map(static fn (string $segment): string => ltrim($segment, '0'), explode('.', $candidate)));
        return self::isConformingKey($candidate) ? $candidate : null;
    }

    /** An item text as the update check compares texts: trimmed, whitespace collapsed, case-insensitive. */
    public static function normalizeTextForComparison(string $itemText): string
    {
        return mb_strtolower((string)preg_replace('/\s+/u', ' ', trim($itemText)));
    }

    /** The key a child of $parentKey gets for segment $segment ("" = the top level). */
    public static function composeChildKey(string $parentKey, int $segment): string
    {
        return ($parentKey === '' ? '' : $parentKey . '.') . $segment;
    }

    /** Whether the item carries any of its seeded declarations ({@see TaskPlanItemDeclaration::hasAnyDeclaration()}). */
    public function hasAnyDeclaration(): bool
    {
        return TaskPlanItemDeclaration::hasAnyDeclaration($this);
    }

    /**
     * The seeded declarations of this item, property name → value kind ({@see TaskPlanItemDeclaration::FIELD_KINDS}).
     * An application whose purposes declare a field of their own on its item class of the same path adds it here: the
     * seed reader, the reader of a stored plan and {@see hasAnyDeclaration()} then know it.
     *
     * @return array<string, string>
     */
    public function getSeededDeclarationKinds(): array
    {
        return TaskPlanItemDeclaration::FIELD_KINDS;
    }

    /** The words the status tool uses for this item's connected channel: its declared label, else the name itself. */
    public function getConnectedChannelLabel(): string
    {
        $connectedChannelName = (string)($this->doneRequiresConnectedChannel ?? '');
        return static::CONNECTED_CHANNEL_LABELS[$connectedChannelName] ?? $connectedChannelName;
    }

    /**
     * What the status tool tells the model to do with this item while its channel is not connected. An application
     * whose purposes treat such an item in a way of their own states that in its item class.
     */
    public function getNotConnectedChannelAdvice(): string
    {
        return 'An unclicked widget stays OPEN (awaiting the owner); a deferral or decline in the owner\'s WORDS is '
            . '`blocked` with requiresUserAction. The platform ticks it on the CONNECTED signal.';
    }

    /**
     * A blocked note the platform wrote in fixed English, which the run report translates for the owner. Null = the
     * note is the model's own, already in the conversation's language. The module writes no such note; an application
     * whose own code blocks items with a fixed note returns it here.
     */
    public function getFixedEnglishBlockedStatusNote(): ?string
    {
        return null;
    }

    /**
     * What the judge's line says, after `blocked`, about a block the application's own code wrote (` (parked at the
     * run's end)`); empty in the module, which blocks no item by itself.
     */
    public function getBlockedMarkOnJudgeLine(): string
    {
        return '';
    }

    /** The legend sentence for {@see getBlockedMarkOnJudgeLine()} in the judge's plan header, ending with a space; empty in the module. */
    public function getJudgeLegendOfBlockedMark(): string
    {
        return '';
    }

    /**
     * An open item whose owner-answered widget ({@see $doneRequiresWidgetAnswered}) is rendered: waiting for the owner,
     * not for the agent.
     */
    public function isAwaitingOwnerOffer(): bool
    {
        return $this->doneRequiresWidgetAnswered
            && $this->offerRenderedAtMessageIndex !== null
            && $this->status === self::STATUS_OPEN;
    }

    /**
     * Open AND owed by the agent — an item awaiting the owner's answer on its widget is open but not the agent's next
     * move.
     */
    public function isOwedOpen(): bool
    {
        return $this->isOpen() && $this->isOwedWhenOpen();
    }

    /** The owed half of {@see isOwedOpen()} for a caller that already knows the node is open (the address walk reads isOpen() once). */
    public function isOwedWhenOpen(): bool
    {
        return !$this->isAwaitingOwnerOffer();
    }

    /**
     * The tool names that satisfy {@see $doneRequiresToolCall} — the field may name ALTERNATIVES separated by `|`
     * ("addBookings|updateBookings": a fresh list is added, an existing one updated). Empty without a gate.
     *
     * @return string[]
     */
    public function getDoneRequiresToolCallAlternatives(): array
    {
        if ($this->doneRequiresToolCall === null) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('|', $this->doneRequiresToolCall)), static fn (string $name): bool => $name !== ''));
    }

    /**
     * The tool names of {@see $doneOwnerQuestionMayFollowToolCall} (`|` alternatives). Empty without the declaration.
     *
     * @return string[]
     */
    public function getOwnerQuestionMayFollowToolCallAlternatives(): array
    {
        if ($this->doneOwnerQuestionMayFollowToolCall === null) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('|', $this->doneOwnerQuestionMayFollowToolCall)), static fn (string $name): bool => $name !== ''));
    }

    /**
     * Open, or `blocked` by the MODEL's own word (no platform marker) — the status every record closer may override:
     * a leaf the model parked as blocked before its evidence landed (blocked before the owner's answer, blocked after
     * its own write) stayed blocked for ever because the closers acted on open leaves only. A status the
     * PLATFORM wrote (the owner-statement close, the park at the purposed run's end) stands; so does
     * `not_applicable` (a verdict, not a wait). Leaves only — a container derives from its subtasks.
     */
    public function isOpenOrBlockedByTheModel(): bool
    {
        return $this->isOpen()
            || ($this->status === self::STATUS_BLOCKED && $this->platformResolvedAtMessageIndex === null && ($this->subtasks === null || $this->subtasks->count() === 0));
    }

    /**
     * TREE-AWARE openness — the checked stop's per-node predicate: `blocked` and `not_applicable` PRUNE the subtree
     * (false regardless of children — the named blocker / the "does not apply" covers them); `done` is still OPEN
     * while any subtask is open (a parent tick must never waive the contract for unfinished children); and an `open`
     * node WITH subtasks derives from them — once every subtask is terminal (done/blocked/not_applicable) the parent
     * is CLOSED, because a container node's work IS its subtree: the model ticks leaves, never containers (an
     * un-ticked parent must not re-arm the checked stop or fail the completion gate — the `multistep.nesteddone`
     * finding).
     */
    public function isOpen(): bool
    {
        if ($this->status === self::STATUS_BLOCKED || $this->status === self::STATUS_NOT_APPLICABLE) {
            return false;
        }
        if ($this->subtasks !== null && $this->subtasks->count() > 0) {
            return $this->subtasks->hasOpenItems();
        }
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Whether the PLATFORM closes this leaf from the record at done (a tool-call gate, a criterion, a widget, a
     * connected channel; an application's item class adds its own) — such a leaf never needs the model's terminal verdict, so a transient
     * obstacle the model reports keeps it open with the obstacle noted ({@see TaskPlanItemTickGate::checkBlockedEvidence()}). An offer leaf
     * whose only gate is the owner's recorded deferral is NOT one: a technical block there is a real block.
     */
    public function isClosedByThePlatformFromTheRecord(): bool
    {
        return $this->doneRequiresToolCall !== null || $this->doneCriterion !== null
            || $this->doneRequiresWidget !== null || $this->doneRequiresConnectedChannel !== null;
    }

    /**
     * Whether the RECORD can prove this leaf — a declared tool call, widget, delivered answer, connected channel,
     * owner-statement record or purpose criterion — a leaf that closes itself
     * from the record; on a leaf WITHOUT such a gate the model's own tick applies. Flags that
     * describe a leaf's role (`preResolvedAtSeed`, the contradiction gate) are NOT evidence — {@see carriesEvidenceGate()} counts them for
     * the checked stop, this predicate does not.
     */
    public function carriesRecordEvidenceGate(): bool
    {
        return $this->doneRequiresToolCall !== null || $this->doneRequiresWidget !== null
            || $this->doneRequiresConnectedChannel !== null || $this->blockedRequiresWidget !== null
            || $this->doneRequiresOwnerVisibleAnswerInSameTurn || $this->previousTurnDeliveryEvidenceToolNames !== null
            || $this->notApplicableRequiresToolCall !== null || $this->blockedRequiresToolCall !== null
            || $this->doneCriterion !== null;
    }

    /**
     * True when this item declares ANY seeded evidence requirement — a named tool call or widget for `done`, or
     * the rendered-offer widget for `blocked`; the keyed `update_task_plan` keeps them on the stored item by construction.
     */
    public function carriesEvidenceGate(): bool
    {
        return $this->doneRequiresToolCall !== null || $this->doneRequiresWidget !== null
            || $this->doneRequiresConnectedChannel !== null
            || $this->blockedRequiresWidget !== null || $this->doneRequiresOwnerVisibleAnswerInSameTurn
            || $this->previousTurnDeliveryEvidenceToolNames !== null
            || $this->preResolvedAtSeed
            || $this->notApplicableRequiresToolCall !== null
            || $this->notApplicableForbiddenAfterToolCall !== null
            || $this->blockedRequiresToolCall !== null;
    }

    /**
     * The `← next` predicate (ONE method, shared by the render and the selector): an OPEN node that is
     * not blocked / n-a, not awaiting the owner's answer on its widget and has no OWED open subtasks — so a container
     * whose remaining children all await the owner claims the marker itself.
     */
    public function claimsNextMarker(): bool
    {
        if ($this->status === self::STATUS_BLOCKED || $this->status === self::STATUS_NOT_APPLICABLE || !$this->isOpen()) {
            return false;
        }
        if ($this->isAwaitingOwnerOffer()) {
            return false;
        }
        if ($this->subtasks !== null && $this->subtasks->count() > 0) {
            // a container claims the marker for its awaiting offers
            return !$this->subtasks->hasOwedOpenItems();
        }
        return true;
    }

    /**
     * The criterion PRE-RESOLUTION closes this node with the purpose criterion's verdict
     * ({@see TaskPlanItemPlatformCloser::applyPreResolution()}): the verdict's status and note, the provenance stamp at
     * `$resolvedAtMessageIndex` and {@see $preResolvedAtSeed}; a `blocked` verdict writes its `requiresUserAction` and
     * `retryable` off, any other leaves both flags.
     */
    public function closeFromCriterionVerdict(TaskPlanDoneCriterionVerdict $taskPlanDoneCriterionVerdict, int $resolvedAtMessageIndex): void
    {
        $this->writePlatformClosing(
            $taskPlanDoneCriterionVerdict->status,
            $taskPlanDoneCriterionVerdict->statusNote,
            $resolvedAtMessageIndex,
            requiresUserActionWhenBlocked: $taskPlanDoneCriterionVerdict->requiresUserAction,
            clearsUserActionFlagsUnlessBlocked: false,
            marksPreResolvedAtSeed: true
        );
    }

    /**
     * A COMPLETED call of `$toolName` at `$toolCallMessageIndex` proved `$status` for this leaf
     * ({@see TaskPlanItemPlatformCloser::closeWhenCompletedCallProvesStatus()}): the note names the tool and the call's
     * index, the call's index is the provenance stamp; `blocked` is owner-caused
     * (`requiresUserAction`, never retryable), another verdict over a model-set blocked drops both flags.
     */
    public function closeOnCompletedCall(string $status, string $toolName, int $toolCallMessageIndex): void
    {
        $this->writePlatformClosing(
            $status,
            'closed by the platform: ' . $toolName . ' COMPLETED @' . $toolCallMessageIndex,
            $toolCallMessageIndex,
            requiresUserActionWhenBlocked: true
        );
    }

    /**
     * The TURN-END evidence closes this leaf `done` ({@see TaskPlanItemPlatformCloser::applyTurnEndEvidenceClosing()}):
     * the note `closed by the platform: <evidence>`, the provenance stamp at `$resolvedAtMessageIndex`, the evidence index
     * (null included), both blocked-only flags off.
     */
    public function closeOnTurnEndEvidence(string $evidenceDescription, int $resolvedAtMessageIndex, ?int $evidenceMessageIndex): void
    {
        $this->writePlatformClosing(
            self::STATUS_DONE,
            'closed by the platform: ' . $evidenceDescription,
            $resolvedAtMessageIndex,
            stampsEvidenceMessageIndex: true,
            evidenceMessageIndex: $evidenceMessageIndex
        );
    }

    /**
     * The owner DECLINED this leaf's question widget at the turn end
     * ({@see TaskPlanItemPlatformCloser::applyTurnEndEvidenceClosing()}): `not_applicable` with the note naming the
     * widget, the provenance stamp at `$resolvedAtMessageIndex` and the evidence index (null included); the
     * blocked-only flags stay as they are.
     */
    public function closeOnDeclinedOwnerWidget(int $resolvedAtMessageIndex, ?int $evidenceMessageIndex): void
    {
        $this->writePlatformClosing(
            self::STATUS_NOT_APPLICABLE,
            'closed by the platform: the owner declined on the ' . $this->doneRequiresWidget . ' widget',
            $resolvedAtMessageIndex,
            clearsUserActionFlagsUnlessBlocked: false,
            stampsEvidenceMessageIndex: true,
            evidenceMessageIndex: $evidenceMessageIndex
        );
    }

    /**
     * The RECORD decides a `set_task_status` tick before the tick applies
     * ({@see TaskPlanItemTickGate::checkAgainstTheRecordCriterion()}, the criterion leaf the record already resolves): the record's status and note, the
     * provenance stamp at the turn initiator's index, both blocked-only flags off.
     */
    public function adoptRecordVerdictInsteadOfTick(TaskPlanDoneCriterionVerdict $recordVerdict, int $currentTurnInitiatorMessageIndex): void
    {
        $this->writePlatformClosing(
            $recordVerdict->status,
            $recordVerdict->statusNote,
            $currentTurnInitiatorMessageIndex
        );
    }

    /**
     * The status the MODEL chose for this item, written as a transition (the one writer of
     * `set_task_status`'s applied tick and of `update_task_plan`'s status transition): `$status`; a CHANGED status drops the platform's provenance
     * ({@see $preResolvedAtSeed}, {@see $platformResolvedAtMessageIndex}) — a `(platform-resolved)` label on a status the
     * model set would misattribute it. `blocked` takes the note (capped) and both flags; leaving blocked clears the flags;
     * `not_applicable` takes the note; `done` takes the note, or keeps the old one only on a done → done re-tick without
     * one (a transition into done never inherits a block reason); `open` clears the note. The platform records stay.
     */
    public function applyStatusChosenByTheModel(string $status, string $statusNote, bool $requiresUserAction, bool $retryable): void
    {
        $previousStatus = $this->status;
        $this->status = $status;
        if ($status !== $previousStatus) {
            $this->preResolvedAtSeed = false;
            $this->platformResolvedAtMessageIndex = null;
        }
        if ($status === self::STATUS_BLOCKED) {
            $this->statusNote = mb_substr($statusNote, 0, self::STATUS_NOTE_MAX_LENGTH);
            $this->requiresUserAction = $requiresUserAction;
            $this->retryable = $retryable;
            return;
        }
        $this->requiresUserAction = false;
        $this->retryable = false;
        if ($status === self::STATUS_NOT_APPLICABLE) {
            $this->statusNote = mb_substr($statusNote, 0, self::STATUS_NOTE_MAX_LENGTH);
        } elseif ($status === self::STATUS_DONE) {
            $this->statusNote = $statusNote !== ''
                ? mb_substr($statusNote, 0, self::STATUS_NOTE_MAX_LENGTH)
                : ($previousStatus === self::STATUS_DONE ? $this->statusNote : null);
        } else {
            $this->statusNote = null;
        }
    }

    /**
     * A next-turn `done` tick was accepted on the previous turn's delivered answer at `$evidenceMessageIndex` — the audit
     * record of {@see $evidenceMessageIndex}, written only once the tick applied.
     */
    public function recordAcceptedDeliveryEvidenceAt(int $evidenceMessageIndex): void
    {
        $this->evidenceMessageIndex = $evidenceMessageIndex;
    }

    /**
     * The RECORD's verdict lands on an APPLIED `set_task_status` tick ({@see TaskPlanItemTickGate::applyTick()}): it confirms the
     * model's status (the status written is the one the tick wrote) or overrides it — the record's status and note, the
     * provenance stamp at the turn initiator's index; the flags stay as the tick left them.
     */
    public function adoptRecordVerdictOnAppliedTick(TaskPlanDoneCriterionVerdict $recordVerdict, int $currentTurnInitiatorMessageIndex): void
    {
        $this->writePlatformClosing(
            $recordVerdict->status,
            $recordVerdict->statusNote,
            $currentTurnInitiatorMessageIndex,
            clearsUserActionFlagsUnlessBlocked: false
        );
    }

    /**
     * A same-status re-tick refreshes the note of this terminal item (a cheap refresh) — never the note of an item the
     * platform resolved at seed, which states what the record shows.
     */
    public function refreshStatusNoteUnlessPreResolvedAtSeed(string $statusNote): void
    {
        if ($statusNote !== '' && !$this->preResolvedAtSeed) {
            $this->statusNote = mb_substr($statusNote, 0, self::STATUS_NOTE_MAX_LENGTH);
        }
    }

    /**
     * A TRANSIENT obstacle the model reported on a leaf the platform closes from the record ({@see TaskPlanItemTickGate::checkBlockedEvidence()}):
     * back to `open` with the obstacle as the note (capped), both blocked-only flags off; the provenance stays.
     */
    public function reopenWithObstacleNote(string $obstacleStatusNote): void
    {
        $this->writeReopening($obstacleStatusNote, clearsUserActionFlags: true);
    }

    /**
     * The ONE writer of every platform closing: `$status`, the note capped at
     * {@see STATUS_NOTE_MAX_LENGTH}, and per closing what it writes beyond them — the intent-named closings above pass
     * their profile, nothing else calls this.
     *
     * @param int|null $platformResolvedAtMessageIndex the provenance stamp; null leaves it untouched
     * @param bool $requiresUserActionWhenBlocked a `blocked` close writes this flag and `retryable` off
     * @param bool $clearsUserActionFlagsUnlessBlocked a close into another status clears both flags; false leaves them
     * @param bool $stampsEvidenceMessageIndex writes `$evidenceMessageIndex` (null included)
     * @param bool $marksPreResolvedAtSeed the pre-resolution's provenance flag
     */
    protected function writePlatformClosing(
        string $status,
        string $statusNote,
        ?int $platformResolvedAtMessageIndex,
        bool $requiresUserActionWhenBlocked = false,
        bool $clearsUserActionFlagsUnlessBlocked = true,
        bool $stampsEvidenceMessageIndex = false,
        ?int $evidenceMessageIndex = null,
        bool $marksPreResolvedAtSeed = false
    ): void {
        $this->status = $status;
        if ($status === self::STATUS_BLOCKED) {
            $this->requiresUserAction = $requiresUserActionWhenBlocked;
            $this->retryable = false;
        } elseif ($clearsUserActionFlagsUnlessBlocked) {
            $this->requiresUserAction = false;
            $this->retryable = false;
        }
        $this->statusNote = mb_substr($statusNote, 0, self::STATUS_NOTE_MAX_LENGTH);
        if ($platformResolvedAtMessageIndex !== null) {
            $this->platformResolvedAtMessageIndex = $platformResolvedAtMessageIndex;
        }
        if ($stampsEvidenceMessageIndex) {
            $this->evidenceMessageIndex = $evidenceMessageIndex;
        }
        if ($marksPreResolvedAtSeed) {
            $this->preResolvedAtSeed = true;
        }
    }

    /**
     * The ONE writer of every re-open: `open`, `$statusNote` capped (null clears the note), and per re-open what else it
     * clears — the intent-named re-opens above pass their profile, nothing else calls this.
     */
    protected function writeReopening(
        ?string $statusNote,
        bool $clearsUserActionFlags = false,
        bool $clearsPlatformProvenance = false,
        bool $clearsPreResolvedAtSeed = false
    ): void {
        $this->status = self::STATUS_OPEN;
        if ($clearsUserActionFlags) {
            $this->requiresUserAction = false;
            $this->retryable = false;
        }
        $this->statusNote = $statusNote !== null ? mb_substr($statusNote, 0, self::STATUS_NOTE_MAX_LENGTH) : null;
        if ($clearsPlatformProvenance) {
            $this->platformResolvedAtMessageIndex = null;
            $this->evidenceMessageIndex = null;
        }
        if ($clearsPreResolvedAtSeed) {
            $this->preResolvedAtSeed = false;
        }
    }


    /**
     * An open leaf whose widget is the owner's question (`doneRequiresWidgetAnswered`) and whose widget
     * ({@see $doneRequiresWidget}) carries no render stamp yet; rendered and unanswered it waits for the owner, not for
     * the agent ({@see markOfferRenderedAt()}).
     */
    public function isOfferWithoutRenderStamp(): bool
    {
        return $this->doneRequiresWidgetAnswered && $this->offerRenderedAtMessageIndex === null
            && $this->status === self::STATUS_OPEN && $this->doneRequiresWidget !== null;
    }

    /**
     * Record the message index where this offer's widget was rendered — from then on the item awaits the owner
     * ({@see isAwaitingOwnerOffer()}).
     */
    public function markOfferRenderedAt(int $renderedAtMessageIndex): void
    {
        $this->offerRenderedAtMessageIndex = $renderedAtMessageIndex;
    }


    /**
     * The note a closed line carries: the item's own `statusNote`, or — for a CONTAINER that is closed only through
     * its subtasks and never ticked itself — a derived summary of the subtree (`all 3 subtasks closed`), so a
     * closed parent never renders as a bare tick: the agent's memory line and the judge's plan-hygiene read both
     * see WHY the container counts as done.
     */
    public function getClosedLineStatusNote(): string
    {
        $statusNote = trim((string)($this->statusNote ?? ''));
        if ($statusNote !== '' || $this->subtasks === null || $this->subtasks->count() === 0) {
            return $statusNote;
        }
        $subtaskCount = $this->subtasks->count();
        $closedSubtaskCount = 0;
        foreach ($this->subtasks->getElements() as $subtask) {
            if (!$subtask->isOpen()) {
                $closedSubtaskCount++;
            }
        }
        return $closedSubtaskCount === $subtaskCount
            ? "all $subtaskCount subtasks closed"
            : "$closedSubtaskCount of $subtaskCount subtasks closed";
    }

    /**
     * This node's single tail-hint render line (WITHOUT indent — the set owns depth): `[✓] 2.1 text`,
     * `[✗ blocked: reason] 3 text`, `[– n/a: reason] 4 text`, or `[ ] 2.2 text` — `$numberPath` is the node's
     * ADDRESS, its key (the exact string `set_task_status` resolves), with ` ← next` when `$carriesNextMarker` — the tree passes
     * it to the one node {@see TaskPlanItems::getNextMarkedItem()} names ({@see claimsNextMarker()}: an open node with no
     * OWED open subtasks — a container whose remaining children all await the owner takes it). Renders the
     * EFFECTIVE state ({@see isOpen()}): a container whose subtasks are all terminal shows `[✓]` even while its own
     * status is still `open` — any blocked / not-applicable remainder is visible on the child lines right below it.
     */
    public function toTailHintLine(string $numberPath, bool $carriesNextMarker): string
    {
        $labelledText = $numberPath . ' ' . $this->text;
        $statusNote = trim((string)($this->statusNote ?? ''));
        if ($this->status === self::STATUS_BLOCKED) {
            return '[✗ blocked: ' . ($statusNote !== '' ? $statusNote : 'no reason given') . '] ' . $labelledText;
        }
        if ($this->status === self::STATUS_NOT_APPLICABLE) {
            return '[– n/a: ' . ($statusNote !== '' ? $statusNote : 'no reason given') . '] ' . $labelledText;
        }
        if (!$this->isOpen()) {
            // The ✓ line carries the item's stated RESULT — the model's own progress memory across steps and chunks.
            $closedNote = $this->getClosedLineStatusNote();
            return '[✓] ' . $labelledText . ($closedNote !== '' ? ' — ' . $closedNote : '');
        }
        if ($this->isAwaitingOwnerOffer()) {
            // The widget is on the owner's screen and the owner's answer closes the leaf — never `← next`; the turn
            // ends on it instead of moving on.
            return '[⏳ awaiting the owner\'s answer on the widget — END YOUR TURN; nothing to do here] ' . $labelledText;
        }
        // An open leaf with a note carries a transient obstacle the model reported (kept open by the status tool); a
        // done item that renders open because a new subtask is open keeps its done note off this line.
        $obstaclePrefix = $statusNote !== '' && $this->status === self::STATUS_OPEN ? '(obstacle noted, retry: ' . $statusNote . ') ' : '';
        if ($carriesNextMarker) {
            return '[ ] ' . $obstaclePrefix . $labelledText . ' ← next';
        }
        return '[ ] ' . $obstaclePrefix . $labelledText;
    }

    /**
     * Marks an open item the platform closes from the record on its compact plan line: its `done` comes from the record,
     * never from the model's tick ({@see TaskPlan::buildTickRules()}). A blocked or not_applicable verdict stays the model's only
     * where the record does not decide it ({@see TaskPlanItemTickGate}): a not_applicable on a criterion item whose
     * record is still open is not applied (G7), and on a stage the purpose's record decides a blocked with requiresUserAction
     * is not applied (G11).
     */
    public const string SELF_CLOSING_MARK = ' (closes itself when done)';

    /** The same mark on the `← next` line, where the model works the item now. */
    public const string SELF_CLOSING_NEXT_MARK = ' (closes itself when done — do its work, never tick it done)';

    /** The widest a title-less item's text renders on a compact plan line that is not the `← next` item. */
    public const int COMPACT_LINE_TEXT_CHARS = 80;

    /** The widest a blocked / not-applicable reason or an obstacle note renders on a compact plan line. */
    public const int COMPACT_LINE_NOTE_CHARS = 60;

    /**
     * The item's TITLE on a compact plan line and in the checked-stop list: the owner-facing title, else the text clipped
     * to {@see COMPACT_LINE_TEXT_CHARS} — never inside a backtick span (a clip that would leave a span open ends before it).
     */
    public function buildPlanLineTitle(): string
    {
        $title = trim((string)($this->ownerFacingTitle ?? ''));
        if ($title !== '') {
            return $title;
        }
        return self::clipForPlanLine(trim($this->text), self::COMPACT_LINE_TEXT_CHARS);
    }

    /**
     * Whether the item is open and the platform closes it from the record — the plan line marks it, the checked stop
     * asks for its work instead of a tick.
     */
    public function isOpenAndClosedByTheRecord(): bool
    {
        return $this->isOpen() && $this->isClosedByThePlatformFromTheRecord();
    }

    /** Whether the plan line shows this item done (`[✓]`): closed and neither blocked nor not applicable. */
    public function rendersAsDoneOnPlanLine(): bool
    {
        return !$this->isOpen() && $this->status !== self::STATUS_BLOCKED && $this->status !== self::STATUS_NOT_APPLICABLE;
    }

    /** `$text` cut to `$maxChars` with an ellipsis, never leaving a backtick span open. */
    protected static function clipForPlanLine(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $clippedText = rtrim(mb_substr($text, 0, $maxChars - 1));
        if (substr_count($clippedText, '`') % 2 === 1) {
            $clippedText = rtrim(mb_substr($clippedText, 0, (int)mb_strrpos($clippedText, '`')));
        }
        return $clippedText . '…';
    }

    /**
     * The COMPACT plan line of this node: `[status] <key> <title>` ({@see buildPlanLineTitle()}); the
     * `← next` item alone carries its full text (its procedure). A blocked or not-applicable item keeps its reason and an
     * open item its noted obstacle, each clipped to {@see COMPACT_LINE_NOTE_CHARS}; no other note: the results stand in the
     * model's own calls. An open item the platform closes from the record says so on its line ({@see SELF_CLOSING_MARK}),
     * unless its text already says it — a `← next` item awaiting its proof never invites a tick. An offer widget
     * on the owner's screen keeps its one-line instruction.
     */
    public function toCompactTailHintLine(string $key, bool $carriesNextMarker): string
    {
        $statusNote = trim((string)($this->statusNote ?? ''));
        $closesItself = $this->isOpenAndClosedByTheRecord() && mb_stripos($this->text, 'closes itself') === false;
        $obstaclePrefix = $statusNote !== '' && $this->status === self::STATUS_OPEN
            ? '(obstacle noted, retry: ' . self::clipForPlanLine($statusNote, self::COMPACT_LINE_NOTE_CHARS) . ') '
            : '';
        if ($carriesNextMarker) {
            return '[ ] ' . $obstaclePrefix . $key . ' ' . $this->text . ($closesItself ? self::SELF_CLOSING_NEXT_MARK : '') . ' ← next';
        }
        $labelledTitle = $key . ' ' . $this->buildPlanLineTitle();
        $reason = $statusNote !== '' ? self::clipForPlanLine($statusNote, self::COMPACT_LINE_NOTE_CHARS) : 'no reason given';
        return match (true) {
            $this->status === self::STATUS_BLOCKED => '[✗ blocked: ' . $reason . '] ' . $labelledTitle,
            $this->status === self::STATUS_NOT_APPLICABLE => '[– n/a: ' . $reason . '] ' . $labelledTitle,
            !$this->isOpen() => '[✓] ' . $labelledTitle,
            $this->isAwaitingOwnerOffer() => '[⏳ awaiting the owner\'s answer on the widget — END YOUR TURN; nothing to do here] ' . $labelledTitle,
            default => '[ ] ' . $obstaclePrefix . $labelledTitle . ($closesItself ? self::SELF_CLOSING_MARK : ''),
        };
    }

    /**
     * The JUDGE-ONLY render of this node — the tail line's sibling with PROVENANCE the agent
     * never needs but the evaluator must see: `[✓ (platform-resolved @31)]` / `[– n/a (platform-resolved @0): …]`
     * when the platform closed the leaf from live data (at seed = @0, mid-run = the newest message index then),
     * `[✗ blocked<mark>: …]` with the application's own mark ({@see getBlockedMarkOnJudgeLine()}), `(evidence @57)` when a
     * next-turn tick was accepted on a delivered answer. Never rendered to the agent — its own tail hint stays.
     */
    public function toJudgeLine(string $numberPath): string
    {
        $labelledText = $numberPath . ' ' . $this->text;
        $statusNote = trim((string)($this->statusNote ?? ''));
        $platformSuffix = $this->preResolvedAtSeed
            ? ' (platform-resolved @' . (int)($this->platformResolvedAtMessageIndex ?? 0) . ')'
            : '';
        $evidenceSuffix = $this->evidenceMessageIndex !== null ? ' (evidence @' . $this->evidenceMessageIndex . ')' : '';
        if ($this->status === self::STATUS_BLOCKED) {
            return '[✗ blocked' . $this->getBlockedMarkOnJudgeLine() . $platformSuffix . ': ' . ($statusNote !== '' ? $statusNote : 'no reason given') . '] ' . $labelledText;
        }
        if ($this->status === self::STATUS_NOT_APPLICABLE) {
            return '[– n/a' . $platformSuffix . ': ' . ($statusNote !== '' ? $statusNote : 'no reason given') . '] ' . $labelledText;
        }
        if ($this->isAwaitingOwnerOffer()) {
            // Provenance, not seed metadata: the platform observed the rendered widget at this index.
            return '[ ] (awaiting the owner\'s answer, widget rendered @'
                . (int)$this->offerRenderedAtMessageIndex . ') ' . $labelledText;
        }
        if (!$this->isOpen()) {
            $closedNote = $this->getClosedLineStatusNote();
            return '[✓' . $platformSuffix . $evidenceSuffix . '] ' . $labelledText . ($closedNote !== '' ? ' — ' . $closedNote : '');
        }
        // An open leaf with a note carries a transient obstacle the model reported (kept open by the status tool).
        return '[ ] ' . ($statusNote !== '' && $this->status === self::STATUS_OPEN ? '(obstacle noted, retry: ' . $statusNote . ') ' : '') . $labelledText;
    }

    /**
     * The SUPERVISOR's plan line of this node (its briefing's PLAN layer): number path, status, gate declaration(s),
     * statusNote. BOTH gates render — a handover item gated by a tool call and by a widget is exactly the
     * item the supervisor inspects before the run closes; showing only one gate hides half its obligation.
     */
    public function renderSupervisorPlanLine(string $numberPath): string
    {
        $gateDeclarations = [];
        if ($this->doneRequiresToolCall !== null) {
            $gateDeclarations[] = $this->doneRequiresToolCall;
        }
        if ($this->doneRequiresWidget !== null) {
            $gateDeclarations[] = 'widget ' . $this->doneRequiresWidget;
        }
        $gate = $gateDeclarations !== [] ? ' [gate: ' . implode(' + ', $gateDeclarations) . ']' : '';
        $note = ($this->statusNote ?? '') !== '' ? " — {$this->statusNote}" : '';
        return "$numberPath. [{$this->status}]{$gate} {$this->text}{$note}";
    }

    /**
     * This node as its persisted field array — EVERY key explicitly present (the RFC-7396 complete-slice rule:
     * omitted keys would survive stale under the column's JSON_MERGE_PATCH), `subtasks` always an ARRAY (arrays
     * replace wholesale — the property that makes full-replace semantics hold).
     *
     * @return array<string, mixed>
     */
    public function toRepositoryFields(): array
    {
        return [
            'text' => $this->text,
            // the identity fields ride right after the text
            'key' => $this->key,
            'highestSubtaskKeyNumber' => $this->highestSubtaskKeyNumber,
            'seededByPurpose' => $this->seededByPurpose,
            'ownerFacingTitle' => $this->ownerFacingTitle,
            'status' => $this->status,
            'statusNote' => $this->statusNote,
            'requiresUserAction' => $this->requiresUserAction,
            'retryable' => $this->retryable,
            'doneCriterion' => $this->doneCriterion,
            // The done-evidence gates MUST survive the snapshot round-trip: every turn after the first rebuilds
            // the plan from the newest snapshot, so a key omitted here silently disarms the gate from turn 2 on
            // (gated items then get ticked done although the
            // persisting tool call / widget never happened — the SetTaskStatusTool rejection has nothing to check).
            'doneRequiresToolCall' => $this->doneRequiresToolCall,
            'doneRequiresToolCallArgumentsAbsent' => $this->doneRequiresToolCallArgumentsAbsent,
            'doneRequiresToolCallArgumentsPresent' => $this->doneRequiresToolCallArgumentsPresent,
            'notApplicableRequiresToolCall' => $this->notApplicableRequiresToolCall,
            'notApplicableRequiresToolCallArgumentsPresent' => $this->notApplicableRequiresToolCallArgumentsPresent,
            'notApplicableRequiresToolCallArgumentsAbsent' => $this->notApplicableRequiresToolCallArgumentsAbsent,
            'notApplicableForbiddenAfterToolCall' => $this->notApplicableForbiddenAfterToolCall,
            'doneRequiresWidget' => $this->doneRequiresWidget,
            'doneRequiresConnectedChannel' => $this->doneRequiresConnectedChannel,
            'closedQuestionOptions' => $this->closedQuestionOptions,
            'minReasoningEffort' => $this->minReasoningEffort,
            'doneRequiresWidgetFilledFields' => $this->doneRequiresWidgetFilledFields,
            'doneRequiresWidgetAnswered' => $this->doneRequiresWidgetAnswered,
            'doneRequiresOwnerVisibleAnswerInSameTurn' => $this->doneRequiresOwnerVisibleAnswerInSameTurn,
            'doneRequiresOwnerQuestionKey' => $this->doneRequiresOwnerQuestionKey,
            'doneAlsoOnOwnerAnswerOfWidget' => $this->doneAlsoOnOwnerAnswerOfWidget,
            'doneOwnerQuestionMayFollowToolCall' => $this->doneOwnerQuestionMayFollowToolCall,
            'previousTurnDeliveryEvidenceToolNames' => $this->previousTurnDeliveryEvidenceToolNames,
            'evidenceMessageIndex' => $this->evidenceMessageIndex,
            'offerRenderedAtMessageIndex' => $this->offerRenderedAtMessageIndex,
            'preResolvedAtSeed' => $this->preResolvedAtSeed,
            'platformResolvedAtMessageIndex' => $this->platformResolvedAtMessageIndex,
            'blockedRequiresWidget' => $this->blockedRequiresWidget,
            'blockedRequiresToolCall' => $this->blockedRequiresToolCall,
            'blockedRequiresToolCallArgumentsPresent' => $this->blockedRequiresToolCallArgumentsPresent,
            'triggersSupervisorMilestoneCheckpoint' => $this->triggersSupervisorMilestoneCheckpoint,
            'customerProgressStepKey' => $this->customerProgressStepKey,
            'skillNames' => $this->skillNames,
            'subtasks' => $this->subtasks?->toRepositoryArray() ?? [],
        ];
    }

    /**
     * `$repositoryFields` with `$insertedFields` placed right after the key `$precedingFieldName` (at the end when the
     * key is missing) — for an application's item class that stores fields of its own at a fixed place of the item.
     *
     * @param array<string, mixed> $repositoryFields
     * @param array<string, mixed> $insertedFields
     * @return array<string, mixed>
     */
    protected static function insertRepositoryFieldsAfter(array $repositoryFields, string $precedingFieldName, array $insertedFields): array
    {
        $position = array_search($precedingFieldName, array_keys($repositoryFields), true);
        if ($position === false) {
            return $repositoryFields + $insertedFields;
        }
        return array_slice($repositoryFields, 0, $position + 1, true)
            + $insertedFields
            + array_slice($repositoryFields, $position + 1, null, true);
    }

    /**
     * Rebuild one node (and its subtree) from its persisted field array — the inverse of
     * {@see toRepositoryFields()}, as an object of the application's item class ({@see newInstance()}); the fields
     * are read by {@see readRepositoryFields()}.
     *
     * @param array<string, mixed> $itemFields
     */
    public static function fromRepositoryFields(array $itemFields): static
    {
        $taskPlanItem = static::newInstance();
        $taskPlanItem->readRepositoryFields($itemFields);
        return $taskPlanItem;
    }

    /**
     * Read this node (and its subtree) from its persisted field array; unknown statuses degrade to OPEN (the safe direction: keeps the checked stop
     * armed instead of waiving the contract). The key is read AS STORED (a plan stored before keys reads null here);
     * deriving and checking the keys is the tree's read ({@see TaskPlanItems::fromRepositoryArray()}). A stored item
     * without `seededByPurpose` counts as seeded when it carries a declaration. An application's item class reads the
     * fields it stores itself after this.
     *
     * @param array<string, mixed> $itemFields
     */
    public function readRepositoryFields(array $itemFields): void
    {
        $taskPlanItem = $this;
        $taskPlanItem->text = (string)($itemFields['text'] ?? '');
        $storedKey = $itemFields['key'] ?? null;
        $taskPlanItem->key = is_scalar($storedKey) ? (string)$storedKey : null;
        $taskPlanItem->highestSubtaskKeyNumber = isset($itemFields['highestSubtaskKeyNumber']) && is_numeric($itemFields['highestSubtaskKeyNumber'])
            ? max(0, (int)$itemFields['highestSubtaskKeyNumber'])
            : 0;
        $status = (string)($itemFields['status'] ?? self::STATUS_OPEN);
        $taskPlanItem->status = in_array($status, [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_BLOCKED, self::STATUS_NOT_APPLICABLE], true)
            ? $status
            : self::STATUS_OPEN;
        // statusNote unifies the former completionNote (done) + blockReason (blocked) — read both legacy keys so
        // in-flight plans persisted before the merge keep their note (each old item had exactly one set).
        $statusNote = $itemFields['statusNote'] ?? $itemFields['completionNote'] ?? $itemFields['blockReason'] ?? null;
        $taskPlanItem->statusNote = $statusNote !== null
            ? mb_substr((string)$statusNote, 0, self::STATUS_NOTE_MAX_LENGTH)
            : null;
        $taskPlanItem->requiresUserAction = (bool)($itemFields['requiresUserAction'] ?? false);
        $taskPlanItem->retryable = (bool)($itemFields['retryable'] ?? false);
        TaskPlanItemDeclaration::readRepositoryFields($itemFields, $taskPlanItem);
        // a plan stored while never-gating criteria existed may mark its criterion as never gating (a leaf the
        // model judges itself): such a key decides nothing — the item is a plain model item
        if (($itemFields['doneCriterionNeverGates'] ?? false) === true) {
            $taskPlanItem->doneCriterion = null;
        }
        $taskPlanItem->seededByPurpose = array_key_exists('seededByPurpose', $itemFields)
            ? (bool)$itemFields['seededByPurpose']
            : $taskPlanItem->hasAnyDeclaration();
        // the platform records
        $taskPlanItem->platformResolvedAtMessageIndex = isset($itemFields['platformResolvedAtMessageIndex'])
            ? (int)$itemFields['platformResolvedAtMessageIndex']
            : null;
        $taskPlanItem->evidenceMessageIndex = isset($itemFields['evidenceMessageIndex'])
            ? (int)$itemFields['evidenceMessageIndex']
            : null;
        $taskPlanItem->offerRenderedAtMessageIndex = isset($itemFields['offerRenderedAtMessageIndex']) && is_numeric($itemFields['offerRenderedAtMessageIndex'])
            ? (int)$itemFields['offerRenderedAtMessageIndex']
            : null;
        $taskPlanItem->preResolvedAtSeed = (bool)($itemFields['preResolvedAtSeed'] ?? false);
        $subtaskFields = $itemFields['subtasks'] ?? null;
        $taskPlanItem->subtasks = is_array($subtaskFields) && $subtaskFields !== []
            ? TaskPlanItems::buildFromRepositoryArrayAsStored($subtaskFields)
            : null;
    }
}
