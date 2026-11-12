<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointHistory;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\MultiChoiceConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidgets;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;

/**
 * @property AIConversationMessage[] $elements;
 * @method AIConversationMessage|null first()
 * @method AIConversationMessage|null last()
 * @method AIConversationMessage|null getByUniqueKey(string $uniqueKey)
 * @method AIConversationMessage[] getElements()
 * @method static AIConversationMessagesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationMessages::class)]
class AIConversationMessages extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AIConversationMessagesService::class;

    public ?int $lastIndexInConversation = 0;

    /**
     * The loaded-capability chain over these rows (hydration after a load, change encoding before a write) — a
     * new chain on each call, bound to THIS set (it holds no state of its own, so a cloned set gets its own chain).
     */
    public function buildLoadedCapabilitiesChain(): LoadedCapabilitiesChain
    {
        return LoadedCapabilitiesChain::forMessages($this);
    }

    /**
     * Memo for {@see buildShownWidgets()}: one tick asks the view many times — the plan gates per item,
     * the turn-start sweep, the tool readers — and each build parses every assistant row's widget blocks. Kept while
     * {@see getShownWidgetsMemoKey()} is unchanged. Plain in-memory state, never persisted, never cached with the set.
     */
    #[HidePropertyOnSystemSerialization]
    protected ?ShownConversationWidgets $shownWidgetsMemo = null;

    /** The {@see getShownWidgetsMemoKey()} the memo was built for. */
    #[HidePropertyOnSystemSerialization]
    protected ?string $shownWidgetsMemoKey = null;

    /**
     * The widgets these rows show, with the owner's reactions after each — over the LOADED rows only,
     * for the in-memory gates. The window-independent view is `AIConversation::findShownWidgets()`. Memoised: rows that
     * read the same give the same view object back; a row added, removed, replaced or edited gives a new one. The view
     * is read-only — a caller never changes it.
     */
    public function buildShownWidgets(): ShownConversationWidgets
    {
        $shownWidgetsMemoKey = $this->getShownWidgetsMemoKey();
        if ($this->shownWidgetsMemo === null || $this->shownWidgetsMemoKey !== $shownWidgetsMemoKey) {
            $this->shownWidgetsMemo = ShownConversationWidgets::buildFor($this);
            $this->shownWidgetsMemoKey = $shownWidgetsMemoKey;
        }
        return $this->shownWidgetsMemo;
    }

    /**
     * A hash over every field of every row that {@see ShownConversationWidgets::buildFor()} reads: the row object, its id,
     * index, role, message type, author, signal source and text. Unlike the O(1) key of {@see getRowsSortedByIndex()} it
     * walks every row, because a text edited in place on an older row must give a new view: a stale view behind a plan
     * gate would change what the gate answers.
     */
    protected function getShownWidgetsMemoKey(): string
    {
        $hashState = hash_init('xxh128');
        foreach ($this->getElements() as $message) {
            $messageText = (string)($message->message ?? '');
            hash_update($hashState, implode("\x1F", [
                spl_object_id($message),
                (string)($message->id ?? ''),
                (string)($message->indexInConversation ?? ''),
                (string)($message->role ?? ''),
                (string)($message->messageType ?? ''),
                (string)($message->authorType ?? ''),
                (string)($message->signalSource ?? ''),
                strlen($messageText),
            ]) . "\x1E" . $messageText);
        }
        return hash_final($hashState);
    }

    public function sortByIndexInConversation(): void
    {
        parent::sort(function (AIConversationMessage $a, AIConversationMessage $b): int {
            return $a->indexInConversation <=> $b->indexInConversation;
        });
    }

    /**
     * Per instance, never static: a static hide outlives the request in a FrankenPHP worker and would strip the same
     * fields from every later message load on that worker, including other endpoints.
     *
     * @param string ...$propertyNames
     * @return void
     */
    public function hidePropertiesOnEveryMessage(string ...$propertyNames): void
    {
        foreach ($this->getElements() as $message) {
            $message->addPropertiesToHide(...$propertyNames);
        }
    }

    /**
     * Stamp a monotonic {@see AIConversationMessage::$indexInConversation} on every newly-added message that lacks
     * one, tracking the running high-water mark. Iterates {@see func_get_args()} (the runtime argument array) rather
     * than the variadic `$elements` directly: PHPStan mis-resolves a by-ref variadic (`&...$elements`) as a scalar
     * `BaseObject`, which breaks a direct `foreach` — `func_get_args()` is a plain `array` it types correctly, and
     * the parent `add()` only mutates (never reassigns) the handles, so these are the same instances now in the set.
     * The native by-ref variadic out-type (`BaseObject|null`) cannot be narrowed via docblock — callers that need the
     * concrete type after `add()` keep a typed handle to the instance from BEFORE the call.
     */
    public function add(?BaseObject &...$elements): void
    {
        parent::add(...$elements);
        foreach (func_get_args() as $element) {
            if (!$element instanceof AIConversationMessage) {
                continue;
            }
            if (!isset($element->indexInConversation)) {
                $element->indexInConversation = $this->lastIndexInConversation + 1;
            }
            $this->lastIndexInConversation = max($element->indexInConversation, $this->lastIndexInConversation);
        }
    }

    /** Highest message id present (0 if none) — seeds a run's transient id sequence above the existing ids. */
    public function getHighestMessageId(): int
    {
        $max = 0;
        foreach ($this->getElements() as $message) {
            if (isset($message->id) && $message->id > $max) {
                $max = $message->id;
            }
        }
        return $max;
    }

    /**
     * The messages of this set, as a new set, in the order of the given message ids. An id without a message in
     * this set is skipped; a message whose id is not named is left out. For a search that ranks ids in one query and
     * loads the rows in a second one.
     *
     * @param int[] $messageIds
     */
    public function buildMessagesInOrderOfIds(array $messageIds): AIConversationMessages
    {
        $messagesById = [];
        foreach ($this->getElements() as $message) {
            if (isset($message->id)) {
                $messagesById[(int)$message->id] = $message;
            }
        }
        $messagesInOrderOfIds = AIConversationMessages::newInstance();
        foreach ($messageIds as $messageId) {
            if (isset($messagesById[$messageId])) {
                $messagesInOrderOfIds->add($messagesById[$messageId]);
            }
        }
        return $messagesInOrderOfIds;
    }

    /**
     * This set with the OWNER EXCHANGE rows of `$persistedMessages` it lacks — the owner's own inbound rows
     * ({@see AIConversationMessage::isOwnerAuthoredInbound()}) and the assistant's TEXT rows whose
     * `indexInConversation` no row of this set carries — as a new set in index order. A row this set holds wins over
     * its persisted copy; rows of other kinds (tool calls, loop and platform signals) are never added, so the set's
     * turn reading changes only where the owner wrote. For a run whose loaded set is older than the database: a second
     * run on the same conversation persisted the owner's newer rows, and only the database has them. This set itself
     * when nothing is missing.
     */
    public function buildWithOwnerExchangeRowsMissingFrom(AIConversationMessages $persistedMessages): AIConversationMessages
    {
        $messagesByIndex = [];
        $messagesWithoutIndex = [];
        foreach ($this->getElements() as $message) {
            if (!isset($message->indexInConversation)) {
                $messagesWithoutIndex[] = $message;
                continue;
            }
            $messagesByIndex[(int)$message->indexInConversation] = $message;
        }
        $missingRowCount = 0;
        foreach ($persistedMessages->getElements() as $persistedMessage) {
            if (!isset($persistedMessage->indexInConversation)
                || isset($messagesByIndex[(int)$persistedMessage->indexInConversation])
                || (!$persistedMessage->isOwnerAuthoredInbound() && !$persistedMessage->isAssistantText())
            ) {
                continue;
            }
            $messagesByIndex[(int)$persistedMessage->indexInConversation] = $persistedMessage;
            $missingRowCount++;
        }
        if ($missingRowCount === 0) {
            return $this;
        }
        ksort($messagesByIndex);
        $messagesWithOwnerExchangeRows = AIConversationMessages::newInstance();
        foreach (array_merge(array_values($messagesByIndex), $messagesWithoutIndex) as $message) {
            $messagesWithOwnerExchangeRows->add($message);
        }
        return $messagesWithOwnerExchangeRows;
    }


    /**
     * the zone the MODEL REQUEST that first read $message thought in — the request snapshot of the
     * first ASSISTANT message after it in this set. Null when none carries a zone (a pre-rollout message, or the
     * reply is not loaded): unknown stays unknown, never today's zone.
     */
    public function findRequestTimezoneIdentifierFor(AIConversationMessage $message): ?string
    {
        if (!isset($message->indexInConversation)) {
            return null;
        }
        $best = null;
        foreach ($this->getElements() as $candidate) {
            if (!$candidate instanceof AIConversationMessage || !isset($candidate->indexInConversation)
                || $candidate->indexInConversation <= $message->indexInConversation
                || ($candidate->role ?? null) !== AIConversationMessage::ROLE_ASSISTANT) {
                continue;
            }
            $timezoneIdentifier = (isset($candidate->requestSnapshot) ? $candidate->requestSnapshot : null)?->timezoneIdentifier;
            if ($timezoneIdentifier === null) {
                continue;
            }
            if ($best === null || $candidate->indexInConversation < $best->indexInConversation) {
                $best = $candidate;
            }
        }
        return $best?->requestSnapshot?->timezoneIdentifier;
    }

    /**
     * Memo for {@see getRowsSortedByIndex()} (moved from the conversation). The same sort is consumed
     * many times per turn AND per compaction round (the context build, the footprint map, EVERY leaf's range fetch) —
     * re-sorting the loaded tail each time was pure waste. Invalidated when the set GROWS or shrinks (count) or its
     * NEWEST element changes ({@see $rowsSortedByIndexMemoLastElementObjectId}); a replaced set is a new object with
     * its own memo. Plain in-memory state, never persisted, never cached with the set.
     *
     * @var AIConversationMessage[]|null
     */
    #[HidePropertyOnSystemSerialization]
    protected ?array $rowsSortedByIndexMemo = null;

    #[HidePropertyOnSystemSerialization]
    protected int $rowsSortedByIndexMemoCount = -1;

    /**
     * @var int O(1) fingerprint of the NEWEST element at memo time (spl_object_id + its index): a same-length window with
     * DIFFERENT content (a reload that swapped or shifted the window) must re-sort, or the context build serves a stale
     * range (a wire rollback: count alone cannot see a swapped window). A shifted window by definition
     * has a different newest element, so this catches everything a full max-index scan would — without the O(N) walk
     * per memo hit.
     */
    #[HidePropertyOnSystemSerialization]
    protected int $rowsSortedByIndexMemoLastElementObjectId = -1;

    /** @var int The newest element's indexInConversation at memo time — second half of the O(1) fingerprint. */
    #[HidePropertyOnSystemSerialization]
    protected int $rowsSortedByIndexMemoLastElementIndex = -1;

    /**
     * The rows sorted by (indexInConversation, id) — repo load order is NOT trustworthy (the lazy repo has no ORDER BY;
     * the in-order service query re-orders by roleOrder first). A row without an index or id sorts last. Memoised.
     *
     * @return AIConversationMessage[]
     */
    public function getRowsSortedByIndex(): array
    {
        $elements = $this->getElements();
        $elementCount = count($elements);
        $lastElement = $elementCount > 0 ? $elements[array_key_last($elements)] : null;
        $lastElementObjectId = $lastElement !== null ? spl_object_id($lastElement) : -1;
        $lastElementIndex = $lastElement?->indexInConversation ?? -1;
        if ($this->rowsSortedByIndexMemo !== null
            && $this->rowsSortedByIndexMemoCount === $elementCount
            && $this->rowsSortedByIndexMemoLastElementObjectId === $lastElementObjectId
            && $this->rowsSortedByIndexMemoLastElementIndex === $lastElementIndex) {
            return $this->rowsSortedByIndexMemo;
        }
        $sortedMessages = $elements;
        usort(
            $sortedMessages,
            static function (AIConversationMessage $a, AIConversationMessage $b): int {
                $indexOfA = $a->indexInConversation ?? PHP_INT_MAX;
                $indexOfB = $b->indexInConversation ?? PHP_INT_MAX;
                if ($indexOfA !== $indexOfB) {
                    return $indexOfA <=> $indexOfB;
                }
                return ($a->id ?? PHP_INT_MAX) <=> ($b->id ?? PHP_INT_MAX);
            }
        );
        $this->rowsSortedByIndexMemo = $sortedMessages;
        $this->rowsSortedByIndexMemoCount = $elementCount;
        $this->rowsSortedByIndexMemoLastElementObjectId = $lastElementObjectId;
        $this->rowsSortedByIndexMemoLastElementIndex = $lastElementIndex;
        return $sortedMessages;
    }

    /** Next monotonic conversation index (max existing `indexInConversation` + 1; 0 if none). */
    public function getNextIndex(): int
    {
        $max = -1;
        foreach ($this->getElements() as $message) {
            if (isset($message->indexInConversation) && $message->indexInConversation > $max) {
                $max = $message->indexInConversation;
            }
        }
        return $max + 1;
    }

    /**
     * Distinct skill names loaded across the conversation (recorded on each `load_skills` call message), in first-seen
     * order.
     *
     * @return string[]
     */
    public function getLoadedSkillNames(): array
    {
        $names = [];
        foreach ($this->getElements() as $message) {
            foreach ($message->getLoadedSkillNames() as $skillName) {
                if (!in_array($skillName, $names, true)) {
                    $names[] = $skillName;
                }
            }
        }
        return $names;
    }

    /**
     * Messages of the CURRENT turn: everything from the most recent INITIATOR message ({@see isTurnInitiatorMessage()}:
     * the owner's message, or a machine WORK signal; the loop's nudges are AUTHOR_AGENT and never initiators).
     * The loop-control folds below are scoped HERE, not over the whole conversation — the earlier per-thread run
     * object (not today's {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun}) was created fresh per user
     * turn, so its counters were per-turn by construction; conversation-wide folds would
     * e.g. disarm the forced-tool-choice stall breaker for every turn after the first worked one (observed:
     * Gemini announcing "I will now load the skills" as a final prose answer in interactive multi-turn sessions).
     *
     * @return AIConversationMessage[]
     */
    protected function getCurrentTurnElements(): array
    {
        $elements = $this->getElements();
        $currentTurnStart = 0;
        foreach ($elements as $position => $message) {
            if (self::isTurnInitiatorMessage($message)) {
                $currentTurnStart = $position;
            }
        }
        return array_slice($elements, $currentTurnStart);
    }

    /**
     * The turn that ENDED at the given message (the pause report's turn): from that turn's initiator
     * (the newest initiator at or before the message) up to and including the message, in set order. Empty when no
     * initiator precedes it.
     */
    public function buildTurnEndingAtMessageIndex(int $endMessageIndex): AIConversationTurnMessages
    {
        return AIConversationTurnMessages::fromRows($this->getRowsOfTurnEndingAtMessageIndex($endMessageIndex));
    }

    /** @return AIConversationMessage[] */
    protected function getRowsOfTurnEndingAtMessageIndex(int $endMessageIndex): array
    {
        $turnRows = [];
        $turnStart = null;
        foreach ($this->getElements() as $position => $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex > $endMessageIndex) {
                break;
            }
            if (self::isTurnInitiatorMessage($message)) {
                $turnStart = $position;
            }
        }
        if ($turnStart === null) {
            return [];
        }
        foreach (array_slice($this->getElements(), $turnStart) as $message) {
            if (($message->indexInConversation ?? -1) > $endMessageIndex) {
                break;
            }
            $turnRows[] = $message;
        }
        return $turnRows;
    }

    /**
     * Whether a message STARTS a turn: a message the owner wrote (ROLE_USER authored by AUTHOR_USER, or a legacy
     * row's null authorType) — or a machine WORK signal ({@see AIConversationSignalSource::isWorkOrder()}: a PURPOSE_KICKOFF, a
     * work order an application registers) delivered into an autonomous purpose's conversation — or an owner signal that stands for the
     * owner's typed answer ({@see AIConversationSignalSource::startsAnOwnerTurn()}: a form widget's submit). The loop's own nudges, bounces,
     * continuation and fold signals are AUTHOR_AGENT and never initiators. In one run, with
     * owner-only initiators, a rebuild signal's turn was scoped from the owner's LAST message weeks earlier — the
     * step budget counted every earlier run's steps as "this turn" (exhausted after three steps), the plan stamps
     * never matched and the checked stop stayed blind.
     */
    public static function isTurnInitiatorMessage(AIConversationMessage $message): bool
    {
        if ($message->isOwnerOrLegacyUserRoleRow()) {
            return true;
        }
        return ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
            && $message->getSignalSource()?->startsATurn() === true;
    }

    /**
     * Whether the row is a form widget's submit under its DEDICATED source (the one source that starts an owner turn,
     * {@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::startsAnOwnerTurn()}; the
     * form is recognised by its own class through the registry,
     * {@see ConversationWidgetClasses::findFormWidgetTypeSubmittedByRow()}) — the owner-form turn start the owner-message
     * sites (the injection screen, the skill preload, the reasoning effort, the turn-end event) admit beside the owner's
     * TEXT. The transition-window click row is not: it starts no turn.
     */
    public static function isOwnerFormSubmitInitiator(AIConversationMessage $message): bool
    {
        return ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
            && $message->getSignalSource()?->startsAnOwnerTurn() === true
            && ConversationWidgetClasses::findFormWidgetTypeSubmittedByRow($message) !== null;
    }

    /**
     * Whether a turn's initiator carries the OWNER's words for the owner-message decision sites: the owner's non-empty
     * USER TEXT, or a form widget's submit ({@see isOwnerFormSubmitInitiator()}). A machine work signal, a widget
     * click, a delegation seed does not.
     */
    public static function initiatorCarriesOwnerWords(?AIConversationMessage $initiatorMessage): bool
    {
        if ($initiatorMessage === null) {
            return false;
        }
        if ($initiatorMessage->isUserRoleText()) {
            return trim((string)($initiatorMessage->message ?? '')) !== '';
        }
        return self::isOwnerFormSubmitInitiator($initiatorMessage);
    }

    /**
     * The `indexInConversation` of the CURRENT turn's initiator — the newest USER-initiator message (same predicate
     * as {@see buildCurrentTurn()}). Null when no initiator exists (e.g. a signal-only start). Backs the
     * compaction unfinished-turn floor: a round running concurrently with a live turn must never cover the
     * in-flight turn's own messages.
     */
    /** The highest `indexInConversation` in the loaded set (null when empty) — the provenance stamp for a platform plan verdict. */
    public function getNewestMessageIndex(): ?int
    {
        $newestIndex = null;
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex !== null && ($newestIndex === null || $messageIndex > $newestIndex)) {
                $newestIndex = $messageIndex;
            }
        }
        return $newestIndex;
    }

    /**
     * The lowest `indexInConversation` in the loaded set (null when empty) — the boundary between the history the
     * compaction cover summarises and the raw rows the set carries.
     */
    public function getLowestMessageIndex(): ?int
    {
        $lowestIndex = null;
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex !== null && ($lowestIndex === null || $messageIndex < $lowestIndex)) {
                $lowestIndex = $messageIndex;
            }
        }
        return $lowestIndex;
    }

    /**
     * The owner's reaction the NEWEST row is ({@see ConversationWidgetReaction::fromMessage()}: their text, their widget
     * click, a form widget's submit); null when the newest row is no owner row. The newest row is the one with the
     * highest `indexInConversation`, a row without one counting as -1 — the turn-start sweep runs on a click or a form
     * submit that opens the turn. Pure.
     */
    public function buildOwnerReactionOfNewestRow(): ?ConversationWidgetReaction
    {
        $newestMessage = null;
        foreach ($this->getElements() as $message) {
            if ($newestMessage === null || ($message->indexInConversation ?? -1) > ($newestMessage->indexInConversation ?? -1)) {
                $newestMessage = $message;
            }
        }
        return $newestMessage !== null ? ConversationWidgetReaction::fromMessage($newestMessage) : null;
    }

    /** Whether the loaded set ends on the BACKGROUND_WORK_FINISHED signal row — the turn it heads starts with the seeded-leaf sweep (a fold turn's DELEGATIONS_SETTLED does not). */
    public function isNewestMessageBackgroundWorkFinishedSignal(): bool
    {
        $newestMessage = null;
        foreach ($this->getElements() as $message) {
            if ($newestMessage === null || ($message->indexInConversation ?? -1) > ($newestMessage->indexInConversation ?? -1)) {
                $newestMessage = $message;
            }
        }
        return $newestMessage !== null
            && ($newestMessage->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
            && ($newestMessage->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_BACKGROUND_WORK_FINISHED;
    }

    /**
     * The TEXTS of a stored tool result: the result column holds the raw JSON blob (`{"content":[{"type":"text","text":…}]}`),
     * whose escapes (`\/`, `\n`, `\u00b7`) stand uninterpreted in the raw string — a line-and-separator parse over the
     * raw blob put every network of the verified-URL line under the first one. The decoded content texts
     * joined by newlines; a result that is not such a blob is returned as it is.
     */
    public static function decodeToolResultTexts(string $rawToolResult): string
    {
        if ($rawToolResult === '') {
            return '';
        }
        $decoded = json_decode($rawToolResult, true);
        if (!is_array($decoded)) {
            return $rawToolResult;
        }
        $texts = [];
        foreach (is_array($decoded['content'] ?? null) ? $decoded['content'] : [] as $contentEntry) {
            if (is_array($contentEntry) && is_string($contentEntry['text'] ?? null)) {
                $texts[] = $contentEntry['text'];
            }
        }
        if (isset($decoded['structuredContent'])) {
            $texts[] = (string)json_encode($decoded['structuredContent'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $texts === [] ? $rawToolResult : implode("\n", $texts);
    }

    /**
     * How many rows a TOOL wrote into the transcript so far — tool messages (widgets,
     * {@see AIConversationMessage::MESSAGE_TOOL_MESSAGE}) and purpose-consequence signals. The dispatch block compares
     * the count before and after a call: a READ that raised it wrote a widget and earns the post-write re-sweep;
     * sibling call rows of a parallel step never do.
     */
    public function countToolEmittedRows(): int
    {
        $count = 0;
        foreach ($this->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_MESSAGE
                || ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_PURPOSE_CONSEQUENCE
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether a tool call ran in a turn the OWNER opened — their text message, or their click / confirmation signal
     * as the turn's initiator (provenance by ordering): what such a call recorded is the owner's answer;
     * a call in a kickoff, work-signal or background turn recorded the model's own reading. False when the call is not
     * in this set.
     */
    public function wasToolCallMadeInATurnTheOwnerOpened(ToolCall $toolCall): bool
    {
        $toolCallMessageIndex = $this->buildToolCallHistory()->getMessageIndexOfToolCall($toolCall);
        if ($toolCallMessageIndex === null) {
            return false;
        }
        $initiatorMessage = $this->buildTurnEndingAtMessageIndex($toolCallMessageIndex)->getInitiatorMessage();
        if ($initiatorMessage === null) {
            return false;
        }
        if ($initiatorMessage->isUserRoleOrOwnerAuthoredText()) {
            return true;
        }
        return ($initiatorMessage->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
            && $initiatorMessage->getSignalSource()?->isOwnerClickOrConfirm() === true;
    }

    /**
     * The index of the row that opened the current OWNER turn: the newest row the owner wrote (their text, a legacy user
     * row) or a form widget's submit ({@see isOwnerFormSubmitInitiator()}); -1 while the owner has not written. A widget
     * click, a supervisor correction or a work signal opens no owner turn — a tool that wrote its widget in this
     * owner turn reuses it on a retry, and a call in a later owner turn writes a new one. Pure.
     */
    public function getNewestOwnerTurnStartIndex(): int
    {
        $newestOwnerTurnStartIndex = -1;
        foreach ($this->getElements() as $message) {
            if (!isset($message->indexInConversation)) {
                continue;
            }
            if ($message->isOwnerOrLegacyUserRoleRow() || self::isOwnerFormSubmitInitiator($message)) {
                $newestOwnerTurnStartIndex = max($newestOwnerTurnStartIndex, (int)$message->indexInConversation);
            }
        }
        return $newestOwnerTurnStartIndex;
    }

    /** The newest turn-initiator message of the loaded set (the message the current turn answers), pure. */
    public function getNewestInitiatorMessage(): ?AIConversationMessage
    {
        $newestInitiatorMessage = null;
        foreach ($this->getElements() as $message) {
            if (self::isTurnInitiatorMessage($message) && isset($message->indexInConversation)) {
                $newestInitiatorMessage = $message;
            }
        }
        return $newestInitiatorMessage;
    }

    public function getNewestInitiatorMessageIndex(): ?int
    {
        $newestInitiatorIndex = null;
        foreach ($this->getElements() as $message) {
            if (self::isTurnInitiatorMessage($message)) {
                $newestInitiatorIndex = $message->indexInConversation ?? $newestInitiatorIndex;
            }
        }
        return $newestInitiatorIndex;
    }

    /**
     * The CURRENT turn: the rows from the newest turn initiator on, in set order, the initiator
     * included; the whole set when no initiator exists. Build it at each question — the loop appends rows between two
     * questions, a view never outlives a row change.
     */
    public function buildCurrentTurn(): AIConversationTurnMessages
    {
        return AIConversationTurnMessages::fromRows($this->getCurrentTurnElements());
    }

    /**
     * The turn the given initiator opened (a bounded turn): from the row carrying that message index
     * up to the next turn initiator, excluded, in set order. When no row carries the index the turn starts at the
     * first row — the activity stack's reading, kept as it is.
     */
    public function buildTurnStartingAtMessageIndex(int $initiatorMessageIndex): AIConversationTurnMessages
    {
        return AIConversationTurnMessages::fromRows($this->getRowsOfTurnStartingAtMessageIndex($initiatorMessageIndex));
    }

    /** @return AIConversationMessage[] */
    protected function getRowsOfTurnStartingAtMessageIndex(int $initiatorMessageIndex): array
    {
        $orderedMessages = array_values($this->getElements());
        $turnStart = 0;
        foreach ($orderedMessages as $position => $message) {
            if (($message->indexInConversation ?? null) === $initiatorMessageIndex) {
                $turnStart = $position;
                break;
            }
        }
        $turnRows = [];
        foreach (array_slice($orderedMessages, $turnStart) as $position => $message) {
            if ($position > 0 && self::isTurnInitiatorMessage($message)) {
                break;
            }
            $turnRows[] = $message;
        }
        return $turnRows;
    }

    /** The tool calls over the set order — repeats, identical writes, failed reads, resolved calls, confirmations. */
    public function buildToolCallHistory(): AIConversationToolCallHistory
    {
        return AIConversationToolCallHistory::fromRows($this->getElements());
    }

    /** A supervisor tandem's past checkpoints over these rows ({@see SupervisorCheckpointHistory}). */
    public function buildSupervisorCheckpointHistory(): SupervisorCheckpointHistory
    {
        return SupervisorCheckpointHistory::fromRows($this->getElements());
    }

    /**
     * The task this delegation CHILD was given — its seed (first, lowest-index) message's text, which `delegate()`
     * sets to the task. The pairing partner of {@see getNewestAssistantAuthoredText()} (the verdict) when composing a delegate
     * call's derived result ({@see AIConversationMessage::getDelegationResult()}). Null on a conversation with no messages.
     */
    public function getFirstMessageText(): ?string
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        return ($sortedMessages[0] ?? null)?->message ?? null;
    }

    /**
     * The customer's message the coming turn answers — the newest turn initiator ({@see AIConversationMessage::isOwnerOrLegacyUserRoleRow()})
     * not yet followed by a real assistant answer (a status-only announcement line does not close the exchange). Null
     * when the newest exchange is answered, or when nothing inbound exists. Pure read over the index-sorted messages;
     * every role read is `?? null`-guarded inside the predicates (tool-call/error rows leave `role` uninitialized).
     */
    public function getNewestUnansweredOwnerRow(): ?AIConversationMessage
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        for ($messagePosition = count($sortedMessages) - 1; $messagePosition >= 0; $messagePosition--) {
            $trailingMessage = $sortedMessages[$messagePosition];
            if ($trailingMessage->isOwnerOrLegacyUserRoleRow()) {
                return $trailingMessage;
            }
            if ($trailingMessage->isAssistantText()
                && !$trailingMessage->isStatusOnlyAnnouncementLine()) {
                return null;
            }
        }
        return null;
    }

    /**
     * Whether the LAST message is an unanswered INBOUND message — a turn is owed NOW,
     * regardless of outstanding delegates (an inbound is processable while delegates run; the turn may spawn more).
     * TWO kinds of inbound owe a turn: (1) a USER message, AND (2) an injected SIGNAL — a MEMORY_CONFLICT
     * clarify request now, frontend-widget interactions later. A SIGNAL is stored role=SYSTEM but folds to a `user`
     * wire entry ({@see AIConversationMessage::toOpenAiChatMessageArray}) and demands the agent act on it, so it
     * triggers a turn exactly like a user message — this is what makes the curator's conflict signal proactively
     * surface (the host's memory curator dispatches the
     * resume; this gate then recognises the trailing SIGNAL as owed work). Pure read over the index-sorted messages.
     */
    public function hasUnansweredInboundMessage(): bool
    {
        // an orchestrator's message no model call has read owes the sub-agent a turn wherever it sits — it
        // may have landed during the last model call, below the final text the tail walk would stop at; an
        // unread run end of a sub-agent below the newest wait row, with no text or error after it, owes the parent a turn
        return $this->hasOrchestratorMessageWaitingForAModelCall() || $this->hasSubAgentRunEndWaitingForAModelCall()
            || $this->hasUnansweredInboundRowAtTheTail();
    }

    /**
     * The tail walk of {@see hasUnansweredInboundMessage()} alone: the newest wire row is an unanswered inbound row. A
     * sub-agent's turn that ended without an answer (a cost or step cap, an aborted repeat) asks only this — its
     * orchestrator's waiting message goes into its run end instead of re-driving a turn that cannot reach a model call.
     */
    public function hasUnansweredInboundRowAtTheTail(): bool
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        for ($messagePosition = count($sortedMessages) - 1; $messagePosition >= 0; $messagePosition--) {
            $trailingMessage = $sortedMessages[$messagePosition];
            // Phone-call lifecycle markers (call started/ended) are chat chips + structural context, NEVER owed
            // work — see through them (the LOOP_NUDGE pattern), else every call end would fire an agent turn.
            // A TOOL_MESSAGE (a tool's own words to the customer) is seen through the same way: it is stored
            // role=ASSISTANT for the frontend, but it is NOT the agent's answer — a customer question followed only by
            // a tool's status widget stays owed. The platform's notice that it wrote a widget on its own is
            // information, never owed work: written after the final text, it must not re-fire the turn.
            // Neither does any other signal that owes no work (the frontend's widget render report).
            if ($trailingMessage->isPhoneCallLifecycleSignal() || $trailingMessage->isToolMessage()
                || $trailingMessage->isRepeatedTextSuppressedSignal() || $trailingMessage->isResolvedSupervisorNote()
                || $trailingMessage->isPlatformWidgetEmittedSignal() || $trailingMessage->isSubAgentRunEndThatWaitsForTheNextTurn()
                || $trailingMessage->isSignalThatOwesNoWork()
            ) {
                continue;
            }
            // ERROR / CHECKPOINT rows are bookkeeping the egress never wires: the owed-turn
            // recovery pass already walks past them ({@see isNewestWireMessageFinalAssistantText()}); this predicate must
            // agree, else a trailing bookkeeping row HIDES the owed USER message from the re-driven worker's derive
            // (re-dispatch → claim → "no work" release → the turn is lost again).
            $trailingMessageType = $trailingMessage->messageType ?? null;
            if ($trailingMessageType === AIConversationMessage::MESSAGE_ERROR || $trailingMessageType === AIConversationMessage::MESSAGE_CHECKPOINT) {
                continue;
            }
            // a completed `wait_for_sub_agents` call ended the turn without a text — nothing is owed until
            // the next notification
            if ($trailingMessage->isTurnEndWithoutTextCall()) {
                return false;
            }
            // A trailing tool call of a model step: the model called tools and its next call — the one that writes the
            // final text — never came (the call failed and the turn was released owed). The owner has no answer, so the
            // turn stays owed; the owed-turn pass already reads it so ({@see isNewestWireMessageFinalAssistantText()}),
            // and the re-driven worker must agree or it releases the turn as answered.
            // The rows a step answers with are told apart in {@see endsOnModelStepWithoutFinalText()}.
            if ($trailingMessageType === AIConversationMessage::MESSAGE_TOOL_CALL) {
                return self::endsOnModelStepWithoutFinalText($sortedMessages, $messagePosition);
            }
            // `?? null` is REQUIRED: a tool-call message leaves `role` an UNINITIALIZED typed property, and a direct
            // `->role` read throws "must not be accessed before initialization". After async delegations the last
            // message IS a delegate tool-call, so the fan-in fold's derive hit exactly that — the resume threw before
            // the claim-release, leaving the parent stuck RUNNING and the fold never running. A SIGNAL carries
            // role=SYSTEM, so it is recognised by its messageType, not its role.
            $trailingMessageRole = $trailingMessage->role ?? null;
            if ($trailingMessageType === AIConversationMessage::MESSAGE_SIGNAL) {
                return true;
            }
            if ($trailingMessageRole === AIConversationMessage::ROLE_SYSTEM) {
                continue; // a stored SYSTEM row that is not a SIGNAL is never wired — the same walk as the owed-turn pass
            }
            return $trailingMessageRole === AIConversationMessage::ROLE_USER;
        }

        return false;
    }

    /**
     * Whether the tool-call row at `$trailingCallPosition` (the newest wire row) ends a MODEL step whose final text never
     * came: walking back through the step's rows, the step's head is found — the call row or the prose head that carries
     * the model call's usage stamp (`inputTokens > 0`, {@see AIConversationMessage::applyUsageAsStepHead()}). Not owed:
     * - a `delegate` call in the step — the step parked for its sub-agents, their run-end notifications open the next turn;
     * - an assistant text without a usage stamp before the head — the platform's answer of the turn (the owner report the
     *   run delivered, a terminal report);
     * - a turn initiator (the owner's row, a turn-starting signal) before any head — no model call made these calls.
     * Rows a call writes while it runs (a tool's message, a consequence signal) and bookkeeping rows are walked past.
     *
     * @param AIConversationMessage[] $sortedMessages index-sorted
     */
    protected static function endsOnModelStepWithoutFinalText(array $sortedMessages, int $trailingCallPosition): bool
    {
        for ($messagePosition = $trailingCallPosition; $messagePosition >= 0; $messagePosition--) {
            $stepMessage = $sortedMessages[$messagePosition];
            if (($stepMessage->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL) {
                if ($stepMessage->isDelegationCall()) {
                    return false;
                }
                if (($stepMessage->inputTokens ?? 0) > 0) {
                    return true;
                }
                continue;
            }
            if ($stepMessage->isAssistantText()) {
                return ($stepMessage->inputTokens ?? 0) > 0;
            }
            if (self::isTurnInitiatorMessage($stepMessage)) {
                return false;
            }
        }
        return false;
    }

    /**
     * Whether the newest WIRE-visible message is a FINAL assistant TEXT — the owed-turns recovery pass's
     * discriminator: every healthy run END (final answer, accepted checked-stop, cap/budget terminal
     * report) leaves an assistant TEXT as the newest wire message — or, for a turn that sub-agent notifications started,
     * a completed `wait_for_sub_agents` call ({@see AIConversationMessage::isTurnEndWithoutTextCall()}); ANYTHING else trailing on an IDLE conversation
     * past the quiet window (a TURN_CONTINUATION/bounce SIGNAL, a USER message, a stranded TOOL_CALL) means a turn
     * is genuinely OWED. Walks backwards past the non-wire bookkeeping the egress never sends (ERROR/CHECKPOINT
     * rows and stored role=SYSTEM messages that are not SIGNALs). Pure read over the loaded tail.
     */
    public function isNewestWireMessageFinalAssistantText(): bool
    {
        // an unread orchestrator's message below the final text still owes the sub-agent a turn; plan
        // 103b-7: an unread run end of a sub-agent below the newest wait row (nothing ended the turn after it) owes one
        if ($this->hasOrchestratorMessageWaitingForAModelCall() || $this->hasSubAgentRunEndWaitingForAModelCall()) {
            return false;
        }
        $sortedMessages = $this->getRowsSortedByIndex();
        for ($position = count($sortedMessages) - 1; $position >= 0; $position--) {
            $message = $sortedMessages[$position];
            $messageType = $message->messageType ?? null;
            if ($messageType === AIConversationMessage::MESSAGE_CHECKPOINT || $messageType === AIConversationMessage::MESSAGE_ERROR) {
                continue; // never wired through — bookkeeping
            }
            if ($message->isPhoneCallLifecycleSignal() || $message->isToolMessage() || $message->isRepeatedTextSuppressedSignal()
                || $message->isResolvedSupervisorNote() || $message->isPlatformWidgetEmittedSignal()
                || $message->isSubAgentRunEndThatWaitsForTheNextTurn() || $message->isSignalThatOwesNoWork()
            ) {
                continue; // a call marker / a tool's message / a suppressed repeat is not the agent's final text — keep walking
            }
            if ($messageType === AIConversationMessage::MESSAGE_SIGNAL) {
                return false; // a trailing signal demands a turn
            }
            if (strtolower((string)($message->role ?? '')) === 'system') {
                continue; // stored system rows are not wired through
            }
            // the turn a sub-agent notification started ended with `wait_for_sub_agents` — a healthy run end
            // like a final text, never a lost resume the recovery pass re-fires
            return $message->isAssistantText() || $message->isTurnEndWithoutTextCall();
        }
        return false;
    }

    /**
     * Whether the conversation's last message is a DELEGATIONS_SETTLED signal — the FOLD idempotency guard: a
     * fold turn that THREW before {@see AIConversationDelegationBatchesService::markChildResultsReadByParent()} ran leaves the batch
     * finished-but-unread, so the next resume re-fires trigger B; without this the signal would be injected twice. The
     * already-injected (and persisted) signal sits as the last message until the model answers, so this catches the
     * retry. Pure, over the loaded message tail.
     */
    public function isNewestMessageDelegationsSettledSignal(): bool
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        $lastMessage = end($sortedMessages);
        return $lastMessage !== false
            && ($lastMessage->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
            && ($lastMessage->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED;
    }

    /** The verdict text of a finished turn: this conversation's last assistant TEXT message (the final answer), or null. */
    public function getNewestAssistantAuthoredText(): ?string
    {
        $verdict = null;
        foreach ($this->getRowsSortedByIndex() as $message) {
            if (
                $message->isAssistantAuthoredText()
                && trim((string)($message->message ?? '')) !== ''
            ) {
                $verdict = $message->message;
            }
        }
        return $verdict;
    }

    /**
     * The newest USER-authored message in this conversation — the message the current turn (and thus any tool call it
     * emits) traces back to. Used to stamp a memory's source-message provenance for the explicit `remember` path
     * (mirrors what the async curator records as `sourceMessageId`). Mirrors {@see getNewestAssistantAuthoredText()}'s scan.
     * In a delegation child the orchestrator's task ({@see AIConversationMessage::isOrchestratorTaskText()}, plan
     * 103b-6a) is that message: the child's turn-start decisions, its tool provenance and its turn read it as before.
     */
    public function getNewestUserAuthoredRow(): ?AIConversationMessage
    {
        // Reverse the memoized sorted array and return the first USER message — the newest user turn sits at/near the
        // end (right before the assistant turn that emits a tool call), so this early-exits after a step or two. Pure
        // in-memory over already-loaded messages (no sort beyond getRowsSortedByIndex()'s memo, no DB).
        $sortedMessages = $this->getRowsSortedByIndex();
        for ($i = count($sortedMessages) - 1; $i >= 0; $i--) {
            if (($sortedMessages[$i]->authorType ?? null) === AIConversationMessage::AUTHOR_USER || $sortedMessages[$i]->isOrchestratorTaskText()) {
                return $sortedMessages[$i];
            }
        }
        return null;
    }

    /**
     * The current model-context footprint estimate: the input-token count the model last SAW —
     * the newest assistant turn's `inputTokens`, which already equals the whole backward-walked context (system prompt
     * + active summary + messages). NOT a sum over the tail (`inputTokens` is the full per-step prompt; summing
     * double-counts). Null when no assistant turn has run yet.
     */
    public function getNewestInputTokens(): ?int
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        for ($position = count($sortedMessages) - 1; $position >= 0; $position--) {
            $inputTokens = $sortedMessages[$position]->inputTokens ?? null;
            if ($inputTokens !== null && $inputTokens > 0) {
                return $inputTokens;
            }
        }
        return null;
    }

    /**
     * The EARLIEST measured whole-prompt footprint (the first assistant reply's `inputTokens`) — used as the
     * fixed-overhead proxy for the token budget: when history is still tiny, this footprint is
     * ≈ system prompt + tool schemas, the part that does NOT shrink with compaction. Re-read each round (not frozen at
     * turn 1) because a mid-conversation `load_skills` injects tool-schema tokens. Null when no reply is measured yet.
     */
    public function getOldestInputTokens(): ?int
    {
        foreach ($this->getRowsSortedByIndex() as $message) {
            $inputTokens = $message->inputTokens ?? null;
            if ($inputTokens !== null && $inputTokens > 0) {
                return $inputTokens;
            }
        }
        return null;
    }

    /**
     * The newest stamped FIXED overhead among the loaded messages, or null when no row carries one
     * (rows before the stamp shipped). Walks newest-first; `?? null` on the possibly-unset column.
     */
    public function getNewestFixedOverheadTokens(): ?int
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        for ($position = count($sortedMessages) - 1; $position >= 0; $position--) {
            $fixedOverheadTokens = $sortedMessages[$position]->fixedOverheadTokens ?? null;
            if ($fixedOverheadTokens !== null && $fixedOverheadTokens > 0) {
                return $fixedOverheadTokens;
            }
        }
        return null;
    }

    /**
     * The compaction UNANSWERED-INBOUND FLOOR: the index of the OLDEST inbound message (a USER message or an injected
     * SIGNAL) that no final assistant TEXT has answered yet — the first index no round may cover — or null when the
     * newest wire-visible message is the agent's answer (nothing owed). A covered inbound message is invisible to the
     * bounded derive ({@see hasUnansweredInboundMessage()} reads only the raw tail past the cover edge), so a completion
     * pass over a FAILED conversation with unanswered customer messages made the retry see "answered" and refuse —
     * the customer's messages were summarized away instead of driven. Same walk as the owed-turn predicates: ERROR /
     * CHECKPOINT rows, call-lifecycle markers, tool messages, the platform's widget notices and stored
     * non-SIGNAL SYSTEM rows are seen through; a
     * stranded TOOL_CALL after the inbound leaves it unanswered (the turn started, it never answered).
     */
    public function getOldestUnansweredInboundIndex(): ?int
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        $oldestUnansweredIndex = null;
        for ($position = count($sortedMessages) - 1; $position >= 0; $position--) {
            $message = $sortedMessages[$position];
            $messageType = $message->messageType ?? null;
            if ($messageType === AIConversationMessage::MESSAGE_ERROR || $messageType === AIConversationMessage::MESSAGE_CHECKPOINT) {
                continue;
            }
            if ($message->isPhoneCallLifecycleSignal() || $message->isToolMessage() || $message->isPlatformWidgetEmittedSignal()
                || $message->isSignalThatOwesNoWork()
            ) {
                continue;
            }
            if ($message->isSignalOrUserRoleRow()) {
                $oldestUnansweredIndex = $message->indexInConversation ?? $oldestUnansweredIndex;
                continue;
            }
            if ($message->isAssistantText()) {
                break; // the agent's answer — everything before it is answered history
            }
            // a stored non-SIGNAL SYSTEM row, or a TOOL_CALL (role uninitialized): not an answer — keep walking
        }
        return $oldestUnansweredIndex;
    }

    /**
     * The RAW messages whose indexInConversation falls in [$fromIndex, $toIndex]. The summarizer input + the
     * covered-message count are built from this. Pure.
     *
     * @return AIConversationMessage[]
     */
    public function getRowsInIndexRange(int $fromIndex, int $toIndex): array
    {
        $result = [];
        foreach ($this->getRowsSortedByIndex() as $message) {
            $index = $message->indexInConversation ?? -1;
            if ($index >= $fromIndex && $index <= $toIndex) {
                $result[] = $message;
            }
        }
        return $result;
    }

    /** The owner's turns over the set order — owner texts, window lists, turn counts. */
    public function buildOwnerTurns(): AIConversationOwnerTurns
    {
        return AIConversationOwnerTurns::fromRows($this->getElements());
    }

    /**
     * The text of the newest signal row of any of the given sources (a renamed source and its legacy value), null when none exists.
     *
     * @param string[] $signalSources
     */
    public function findNewestSignalTextOfSources(array $signalSources): ?string
    {
        $newestSignal = $this->findNewestSignalOfSources($signalSources);
        return $newestSignal === null ? null : (string)($newestSignal->message ?? '');
    }

    /**
     * The newest signal row of any of the given sources in the conversation (a renamed source and its legacy value), null when none exists.
     *
     * @param string[] $signalSources
     */
    public function findNewestSignalOfSources(array $signalSources): ?AIConversationMessage
    {
        $newest = null;
        foreach ($this->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && in_array($message->signalSource ?? null, $signalSources, true)
                && ($newest === null || (int)($message->indexInConversation ?? -1) > (int)($newest->indexInConversation ?? -1))
            ) {
                $newest = $message;
            }
        }
        return $newest;
    }

    /** The message that opened the current turn (the newest turn initiator), null when none exists. */
    public function findNewestInitiatorMessage(): ?AIConversationMessage
    {
        $newestInitiatorIndex = $this->getNewestInitiatorMessageIndex();
        if ($newestInitiatorIndex === null) {
            return null;
        }
        foreach ($this->getElements() as $message) {
            if ((int)($message->indexInConversation ?? -1) === $newestInitiatorIndex) {
                return $message;
            }
        }
        return null;
    }

    /**
     * @var float Conservative blended $/1M-tokens rate for the cost-cap ESTIMATE fallback: a model
     * call whose provider reported NO cost (costInUSD stayed 0.0) is estimated from its token counts so
     * `maxTurnCostUsd` degrades to an estimate — never silently to "uncapped".
     */
    public const float ESTIMATED_USD_PER_MILLION_TOKENS_FALLBACK = 3.0;

    /**
     * The index of the message that opens the NEWEST model step: exactly one row per model call carries the usage
     * stamp (the prose head or the first call row), so the newest stamped row is where the model's current response
     * begins. Everything the platform did at or after this index happened after the model wrote that response.
     */
    public function getNewestModelStepHeadMessageIndex(): ?int
    {
        $newestModelStepHeadMessageIndex = null;
        foreach ($this->getElements() as $message) {
            if (($message->inputTokens ?? 0) > 0 && isset($message->indexInConversation)) {
                $newestModelStepHeadMessageIndex = max($newestModelStepHeadMessageIndex ?? 0, (int)$message->indexInConversation);
            }
        }
        return $newestModelStepHeadMessageIndex;
    }

    /**
     * The keys an EARLIER `update_task_plan` call of the current model response added to `$standingTaskPlan`: the standing plan's keys minus the keys of the plan as it stood before the first keyed call of that response
     * (its newest snapshot before that call; none = no plan stood). Empty when no other keyed call ran in the response —
     * the R8 line then names no item as "added by an earlier call in this response".
     *
     * @return string[]
     */
    public function getTaskPlanKeysAddedByEarlierCallsOfTheCurrentModelStep(?TaskPlan $standingTaskPlan, ToolCall $currentToolCall): array
    {
        $modelStepHeadMessageIndex = $this->getNewestModelStepHeadMessageIndex();
        if ($standingTaskPlan?->items === null || $modelStepHeadMessageIndex === null) {
            return [];
        }
        $firstKeyedCallMessageIndex = null;
        foreach ($this->getElements() as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall !== null && $toolCall !== $currentToolCall
                && ($message->indexInConversation ?? -1) >= $modelStepHeadMessageIndex
                && $toolCall->name === UpdateTaskPlanTool::NAME
                && $toolCall->taskPlanCallOutcome === ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED
            ) {
                $firstKeyedCallMessageIndex = (int)$message->indexInConversation;
                break;
            }
        }
        if ($firstKeyedCallMessageIndex === null) {
            return [];
        }
        $keysBeforeTheResponse = [];
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? PHP_INT_MAX) < $firstKeyedCallMessageIndex && isset($message->taskPlanSnapshot)) {
                $keysBeforeTheResponse = [];
                foreach ($message->taskPlanSnapshot->items?->buildItemAddresses()->getElements() ?? [] as $snapshotItemAddress) {
                    $keysBeforeTheResponse[] = $snapshotItemAddress->key;
                }
            }
        }
        $standingKeys = [];
        foreach ($standingTaskPlan->items->buildItemAddresses()->getElements() as $standingItemAddress) {
            $standingKeys[] = $standingItemAddress->key;
        }
        return array_values(array_diff($standingKeys, $keysBeforeTheResponse));
    }

    /**
     * Whether a DELEGATIONS_SETTLED signal was injected in the CURRENT turn — i.e. the orchestrator is in its FOLD drive
     * (its sub-agents settled, their verdicts are inline in the `delegate` tool-result). Discriminates the stall recovery
     * (see {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService::buildStallSignalText}): an empty turn
     * here must be steered to "ANSWER from the verdicts" — NOT the generic "call your loaded tools" (which makes a weak
     * model re-run a sub-agent's tool) — and the last-resort force must produce TEXT, not a tool call. Per-turn
     * scope mirrors {@see AIConversationTurnMessages::hasSkillOrToolLoadCall()}.
     */
    /**
     * The NEWEST message's loaded-capability set, searched BACKWARD past capability-less messages
     * (the pin-wipe root cause). The old pattern `last()->loadedCapabilities ?? null` at every consumer meant ONE
     * message appended without a set (an injected signal, any gap in the carry) returned null — and the next
     * `loadSkills()` then promoted onto a NULL prior, wiping the ENTIRE cumulative set including the purpose-pinned
     * conductor (the pinned skill ABSENT at one message while every earlier message carried it). A pinned
     * skill must never vanish because an unrelated message missed the carry — the truth is the newest message THAT
     * HAS a set.
     */
    public function getNewestLoadedCapabilities(): ?LoadedCapabilities
    {
        return $this->getNewestMessageWithLoadedCapabilities()?->getLoadedCapabilities();
    }

    /**
     * The newest message that CARRIES a capability set — the anchor the turn-start unloading mutates
     * and persists: at a turn start `last()` is the owner's initiator row without a set, and anchoring on it would
     * skip the whole turn (a carry gap).
     */
    public function getNewestMessageWithLoadedCapabilities(): ?AIConversationMessage
    {
        $elements = $this->getElements();
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            // a change record loaded outside the hydrating loaders cannot be read as a set
            if ($elements[$i]->hasLoadedCapabilities()) {
                return $elements[$i];
            }
        }
        return null;
    }

    /**
     * The widget types of the widgets a TOOL wrote into the CURRENT owner turn (rows after the newest initiator,
     * {@see AIConversationMessage::AUTHOR_TOOL}): a confirmation widget, a form, a selection widget, a
     * closing widget. They are the turn's deliveries and questions exactly like a fence in the model's own text —
     * without them the line under a tool-written form was bounced as "asks the owner nothing", and so was
     * the closing line under a closing widget.
     *
     * @return string[]
     */
    public function findToolEmittedWidgetTypesInCurrentTurn(): array
    {
        $initiatorIndex = $this->getNewestInitiatorMessageIndex() ?? -1;
        $widgetTypes = [];
        // the turn's recorded askOwner question IS a question-asking widget — rendered at turn end, after the checks
        // that read this list ran (announce-only, delivers-only, the checked stop): counted from the call row
        $askOwnerCall = $this->buildCurrentTurn()->getNewestCompletedToolCallNamedSinceOwnerInbound(AskOwnerTool::NAME);
        if ($askOwnerCall !== null && !$askOwnerCall->wasRefusedAsDuplicate) {
            $widgetTypes[] = filter_var($askOwnerCall->arguments['multiSelect'] ?? false, FILTER_VALIDATE_BOOLEAN) ? MultiChoiceConversationWidget::WIDGET_TYPE : QuickRepliesConversationWidget::WIDGET_TYPE;
        }
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? -1) <= $initiatorIndex
                || $message->messageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE
                || ($message->authorType ?? null) !== AIConversationMessage::AUTHOR_TOOL
            ) {
                continue;
            }
            // every VALID widget block's written top-level type (the differences are
            // named on AgentPausedTurnRecord::widgetTypesOf()); a tool-written `…Directive` stays as written, so it is
            // not "asking" in AIConversation::hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose() (exact canonical names) while
            // findClosedWidgetTypesOfText() resolves the suffix — a known difference, to be removed by a canonical-name
            // step that also moves this scan to ShownConversationWidgets
            foreach ($message->buildWidgetBlocks()->getWrittenWidgetTypes() as $widgetType) {
                $widgetTypes[] = $widgetType;
            }
        }
        return array_values(array_unique($widgetTypes));
    }

    /**
     * The widget types of {@see findToolEmittedWidgetTypesInCurrentTurn()}, but cut at the newest owner-authored inbound:
     * a widget click starts no turn, so a widget the owner already answered by a click is no
     * widget of the reply that follows. The turn's recorded `askOwner` question is cut at the same row.
     *
     * @return string[]
     */
    public function findToolEmittedWidgetTypesSinceOwnerInbound(): array
    {
        $currentTurn = $this->buildCurrentTurn();
        $widgetTypes = $currentTurn->findToolWrittenWidgetTypesSinceOwnerInbound();
        $askOwnerCall = $currentTurn->getNewestCompletedToolCallNamedSinceOwnerInbound(AskOwnerTool::NAME);
        if ($askOwnerCall !== null && !$askOwnerCall->wasRefusedAsDuplicate) {
            $widgetTypes[] = filter_var($askOwnerCall->arguments['multiSelect'] ?? false, FILTER_VALIDATE_BOOLEAN) ? MultiChoiceConversationWidget::WIDGET_TYPE : QuickRepliesConversationWidget::WIDGET_TYPE;
        }
        return array_values(array_unique($widgetTypes));
    }

    /**
     * The canonical types of the CLOSED, VALID widget blocks of `$answerText`, message order, repeats dropped — the
     * text's half of {@see AIConversation::hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose()}. A block
     * is read as {@see answerCarriesWidgetOfType()} reads it: a payload cut off before its JSON balances
     * does not count, a closed block with invalid JSON does not count, and a type written with the legacy `…Directive`
     * suffix is that suffix stripped once ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock::isOfType()}).
     *
     * @return string[]
     */
    public static function findClosedWidgetTypesOfText(string $answerText): array
    {
        $closedWidgetTypes = [];
        foreach (WidgetBlocks::fromMarkdown($answerText)->getElements() as $widgetBlock) {
            $writtenWidgetType = $widgetBlock->isClosed ? $widgetBlock->getWrittenWidgetType() : null;
            if ($writtenWidgetType === null || $writtenWidgetType === '') {
                continue;
            }
            $closedWidgetTypes[] = str_ends_with($writtenWidgetType, ConversationWidgetClasses::LEGACY_WIDGET_TYPE_SUFFIX)
                ? substr($writtenWidgetType, 0, -strlen(ConversationWidgetClasses::LEGACY_WIDGET_TYPE_SUFFIX))
                : $writtenWidgetType;
        }
        return array_values(array_unique($closedWidgetTypes));
    }

    /**
     * Whether `$answerText` holds a CLOSED, VALID widget block that awaits the owner by its content — a `ComposedView`
     * with a `Form` — the text's half of the owner-awaiting question that a widget TYPE cannot
     * answer ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget::AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT}).
     */
    public static function textCarriesWidgetWhoseContentAwaitsOwnerInput(string $answerText): bool
    {
        return WidgetBlocks::fromMarkdown($answerText)->hasClosedBlockWhoseContentAwaitsOwnerInput();
    }

    /**
     * Whether a tool or platform row of the CURRENT owner turn holds a widget that awaits the owner by its content (the
     * rows of {@see findToolEmittedWidgetTypesInCurrentTurn()}).
     */
    public function hasToolEmittedWidgetWhoseContentAwaitsOwnerInputInCurrentTurn(): bool
    {
        $initiatorIndex = $this->getNewestInitiatorMessageIndex() ?? -1;
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? -1) > $initiatorIndex
                && $message->messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE
                && ($message->authorType ?? null) === AIConversationMessage::AUTHOR_TOOL
                && $message->buildWidgetBlocks()->hasClosedBlockWhoseContentAwaitsOwnerInput()
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the answer carries a CLOSED, VALID widget block of one of `$widgetTypes` — a payload cut off before its
     * JSON balances does not count; a ``` fence without a closer line whose JSON balances does, as the frontend repairs
     * and renders it (read by WidgetBlocks). Changes against the old regex: the block's own top-level
     * type, plain or `…Directive` (a nested "type" or one in a later non-widget fence no longer counts); `~~~`, bare,
     * one-line and leaf blocks count; a closed block with invalid JSON no longer counts (the frontend renders nothing
     * for it either).
     *
     * @param string[] $widgetTypes
     */
    public static function answerCarriesWidgetOfType(string $answerText, array $widgetTypes): bool
    {
        foreach (WidgetBlocks::fromMarkdown($answerText)->getElements() as $widgetBlock) {
            if (!$widgetBlock->isClosed) {
                continue;
            }
            foreach ($widgetTypes as $widgetType) {
                if ($widgetBlock->isOfType($widgetType)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether the NEWEST inbound message — the newest of (a) a message the owner wrote and (b) a machine WORK signal
     * (`PURPOSE_KICKOFF`, a work order an application registers) — is such a work signal, i.e. the turn now running was started by a
     * work order and not by the owner. The initiator predicate ({@see getNewestInitiatorMessageIndex()}) deliberately
     * sees only owner messages, so on a work-signal turn the checked stop's staleness stamp and the announce streak
     * are both blind: the plan was re-seeded (unstamped by design) and the owner window is an old one carrying earlier
     * runs' work. In one run, a rebuild signal was answered with a fabricated "all done" prose reply and
     * ZERO tool calls, accepted as the final answer. Pure fold over the loaded set.
     */
    public function hasWorkSignalAsNewestInboundMessage(): bool
    {
        $newestInboundIsWorkSignal = false;
        foreach ($this->getElements() as $message) {
            $isOwnerMessage = $message->isOwnerOrLegacyUserRoleRow();
            if ($isOwnerMessage) {
                $newestInboundIsWorkSignal = false;
                continue;
            }
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && $message->getSignalSource()?->isWorkOrder() === true
            ) {
                $newestInboundIsWorkSignal = true;
            }
        }
        return $newestInboundIsWorkSignal;
    }

    /**
     * Whether a supervisor steer or correction sits at the tail with NO model output after it — the one step that
     * must act on it (a steer materialized at a step boundary of the RUNNING
     * turn is consumed by the very next model call, which the loop pins to the expected tool; later steps of the
     * same turn run free). The settled-steer case (a steer before the owner's next message) is covered too.
     */
    public function hasSupervisorSignalAwaitingItsFirstModelCall(): bool
    {
        $elements = $this->getElements();
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            $message = $elements[$i];
            $messageType = $message->messageType ?? null;
            if ($messageType === AIConversationMessage::MESSAGE_TOOL_CALL
                || $message->isAssistantText()
            ) {
                return false;
            }
            if ($message->isArmingSupervisorSignal()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The tool name the ARMED supervisor steer expects the agent to call next, or null when no steer is armed or
     * the steer names no tool. Reads the machine-written `Expected tool: <name>` protocol line that
     * SupervisorCheckpointDecision::buildSignalTextForTerminalCall() appends from the decision's `expectedTool` — this parses
     * OUR OWN deterministic format, never model prose. The armed window: the newest steer counts until an assistant
     * TEXT answers it.
     */
    public function getExpectedToolNameFromArmedSupervisorSteer(): ?string
    {
        $elements = $this->getElements();
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            $message = $elements[$i];
            if ($message->isAssistantText()
                || $message->isRepeatedTextSuppressedSignal()
            ) {
                return null;
            }
            if ($message->isArmingSupervisorSignal()) {
                if (preg_match('/^Expected tool: ([A-Za-z0-9_]+)$/m', (string)($message->message ?? ''), $expectedToolMatch) === 1) {
                    return $expectedToolMatch[1];
                }
                return null;
            }
        }
        return null;
    }

    /**
     * The CONSECUTIVE nudge messages at the tail of this conversation (the loop's empty-turn nudges; a productive
     * turn breaks the run) — the derived replacement for the `$emptyTurnNudges` counter of the earlier per-thread
     * run object (not today's {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun}) AND the
     * forced-tool-choice budget (one forced attempt accompanies each consecutive empty turn while no work tool ran). Keyed on `messageType=SIGNAL && signalSource=LOOP_NUDGE` — NOT on role/author — so other
     * out-of-band signals (memory-conflict, widget) in the trailing stream are NOT miscounted as nudges. Resume-safe:
     * a reloaded conversation derives the exact same value.
     */
    public function countTrailingConsecutiveNudges(): int
    {
        $trailingNudges = 0;
        foreach (array_reverse($this->getElements()) as $message) {
            $isNudge = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE;
            if (!$isNudge) {
                break;
            }
            $trailingNudges++;
        }
        return $trailingNudges;
    }

    /**
     * Whether any message with an `indexInConversation` GREATER than $afterIndex is something OTHER than a LOOP_NUDGE
     * signal — the "did this turn make REAL progress" test at the resume boundary. A turn that appended ONLY LOOP_NUDGE
     * signals (a stalling model, e.g. Gemini empty-turns) made no real progress and must count toward the resume-attempt
     * cap so it cannot re-fire forever ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::resumeConversationIfReady});
     * an assistant answer, a tool call/result, an error/fallback, or a NON-LOOP_NUDGE signal (USER / widget-click /
     * memory-conflict / delegations-settled) IS real progress. Keyed on the structural messageType + signalSource enum
     * (mirrors {@see countTrailingConsecutiveNudges}), never on text. INDEX-based (not a count delta) so it stays correct
     * when the post-turn message set is a bounded / compacted tail reload. Pure.
     */
    public function hasNonNudgeMessageAfterIndex(int $afterIndex): bool
    {
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? -1) <= $afterIndex) {
                continue;
            }
            // a TASK_PLAN_OPEN_BOUNCE is loop-generated corrective steering exactly like a LOOP_NUDGE —
            // a turn that produced ONLY a bounce made no real progress and must count toward the resume-attempt cap
            // (else a model that keeps answering prematurely re-fires forever with the counter resetting each time).
            $isLoopCorrectiveSignal = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && $message->getSignalSource()?->isLoopSteeringWithoutProgress() === true;
            if (!$isLoopCorrectiveSignal) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the OWNER stated or accepted a value in this conversation — the structural consent check for an
     * owner-channel write (an observed regression: runs wrote a value they had read into the owner's notification
     * channels with no owner turn between the read and the write). True when the value occurs literally in an
     * owner-authored inbound row ({@see AIConversationMessage::isOwnerAuthoredInbound()}), OR when an owner-visible
     * ASSISTANT text mentions it (the proposal) and an owner-authored inbound row FOLLOWS that message (the yes —
     * what the owner said is not judged, only that they answered after the proposal). Digits-only comparison for
     * a phone-like value, case-insensitive containment otherwise.
     */
    public function hasOwnerStatedOrAcceptedValue(string $value): bool
    {
        $contains = static::buildValueMatcher($value);
        if ($contains === null) {
            return false;
        }
        $proposalSeen = false;
        foreach ($this->getElements() as $message) {
            $text = (string)($message->message ?? '');
            if ($message->isOwnerAuthoredInbound()) {
                if ($text !== '' && $contains($text)) {
                    return true;
                }
                if ($proposalSeen) {
                    return true;
                }
                continue;
            }
            if ($message->isAssistantText()
                && $text !== '' && $contains($text)
            ) {
                $proposalSeen = true;
            }
        }
        return false;
    }

    /**
     * True when the OWNER wrote the value in one of their own rows — the stricter evidence
     * ({@see hasOwnerStatedOrAcceptedValue()} also accepts a proposal the owner answered). A write of a contact value
     * needs this one: where the value's clause rides another question, an owner answer after it answers that question
     * and is no consent to the value — only a value the owner typed is written.
     */
    public function hasOwnerStatedValue(string $value): bool
    {
        $contains = static::buildValueMatcher($value);
        if ($contains === null) {
            return false;
        }
        foreach ($this->getElements() as $message) {
            if ($message->isOwnerAuthoredInbound() && $contains((string)($message->message ?? ''))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The matcher the two owner-evidence checks share: case-insensitive containment for text; a phone number is
     * compared on its LAST eight digits (the owner types it nationally "0151 …", the tool normalises it
     * internationally "+49 151 …" — the subscriber part is what both share). Null for an empty value.
     *
     * @return (callable(string): bool)|null
     */
    protected static function buildValueMatcher(string $value): ?callable
    {
        $needle = mb_strtolower(trim($value));
        if ($needle === '') {
            return null;
        }
        $digitsNeedle = preg_replace('/\D+/', '', $needle) ?? '';
        $phoneLike = mb_strlen($digitsNeedle) >= 7 && mb_strlen($digitsNeedle) >= mb_strlen($needle) / 2;
        $phoneSuffix = mb_substr($digitsNeedle, -8);
        return static function (string $haystack) use ($needle, $phoneSuffix, $phoneLike): bool {
            if ($haystack === '') {
                return false;
            }
            if ($phoneLike) {
                return str_contains(preg_replace('/\D+/', '', $haystack) ?? '', $phoneSuffix);
            }
            return str_contains(mb_strtolower($haystack), $needle);
        };
    }


    /**
     * The NEWEST message's {@see AIConversationMessage::$taskPlanSnapshot} array in this set, or null when no
     * message carries one — the snapshot-dedupe read at the loop's plan-persist gate (a byte-identical repeat of
     * the last stamped state must not be stamped again). Walks newest-first; truncated rows are skipped (their
     * branch is abandoned — the surviving timeline's newest snapshot is the comparison base).
     *
     * @return array<string, mixed>|null
     */
    public function findNewestTaskPlanSnapshotArray(): ?array
    {
        foreach (array_reverse($this->getElements()) as $message) {
            if (isset($message->truncatedAt)) {
                continue;
            }
            if (isset($message->taskPlanSnapshot)) {
                return (array)$message->taskPlanSnapshot->mapToRepository();
            }
        }
        return null;
    }

    /** The agent's final answer — the last non-empty assistant text message (null if it never produced one). */
    public function getFinalAssistantText(): ?string
    {
        $finalText = null;
        foreach ($this->getElements() as $message) {
            if (
                $message->isAssistantText()
                && ($message->message ?? '') !== ''
            ) {
                $finalText = $message->message; // last non-empty assistant text wins
            }
        }
        return $finalText;
    }

    /**
     * Whether a turn initiator lies before the current turn's initiator among the loaded rows: false on a run's first
     * turn (an unattended run), true on a later turn an owner wrote into. Pure read over the loaded set.
     */
    public function currentTurnFollowsAnEarlierTurn(): bool
    {
        $currentTurnInitiatorMessageIndex = $this->getNewestInitiatorMessageIndex();
        if ($currentTurnInitiatorMessageIndex === null) {
            return false;
        }
        foreach ($this->getElements() as $message) {
            if (self::isTurnInitiatorMessage($message)
                && (int)($message->indexInConversation ?? PHP_INT_MAX) < $currentTurnInitiatorMessageIndex
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The report an EMAIL run wrote without stating its outcome (no `send_owner_report` call): chosen by where the
     * text sits in the current turn, never by its wording. The final assistant text of the turn with content; when the
     * forced closing step was answered with a text again, the assistant text right before that step's note
     * ({@see AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT_FORCED_CALL}) — the report the note named, not the short
     * second answer. Null when the turn holds no such text. Pure read over the loaded set.
     */
    public function getReportTextOfTurnWithoutOutcome(): ?AIConversationMessage
    {
        $newestInitiatorIndex = $this->getNewestInitiatorMessageIndex() ?? -1;
        $forcedCallNoteIndex = null;
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? -1) > $newestInitiatorIndex
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT_FORCED_CALL
            ) {
                $forcedCallNoteIndex = (int)$message->indexInConversation;
            }
        }
        foreach (array_reverse($this->getElements()) as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex <= $newestInitiatorIndex) {
                return null;
            }
            if ($forcedCallNoteIndex !== null && $messageIndex > $forcedCallNoteIndex) {
                continue;
            }
            if ($message->isAssistantText() && trim((string)($message->message ?? '')) !== '') {
                return $message;
            }
        }
        return null;
    }

    public function getTotalInputTokens(): int
    {
        return (int)array_sum(array_map(static fn (AIConversationMessage $m): int => $m->inputTokens ?? 0, $this->getElements()));
    }

    public function getTotalOutputTokens(): int
    {
        return (int)array_sum(array_map(static fn (AIConversationMessage $m): int => $m->outputTokens ?? 0, $this->getElements()));
    }

    public function getTotalTokens(): int
    {
        return $this->getTotalInputTokens() + $this->getTotalOutputTokens();
    }

    /**
     * The spend of these rows in USD: every row's model cost plus what its tool call's execution booked outside the
     * model call ({@see AIConversationMessage::getCostInUSDIncludingToolCallExecution()}).
     */
    public function getTotalCostUsd(): float
    {
        return (float)array_sum(array_map(static fn (AIConversationMessage $m): float => $m->getCostInUSDIncludingToolCallExecution(), $this->getElements()));
    }

    /**
     * The conversation's WHOLE-SUBTREE model spend in USD: its own messages' costs
     * ({@see getTotalCostUsd()}) PLUS every delegate-call message's settle-slice `costInUSD` — each slice already
     * carries ITS child's whole subtree (the slices are written from exactly this method on the child), so deep
     * delegation trees attribute upward by induction, one fold level at a time. Feeds
     * {@see AIConversation::buildDelegationResultSlice()}. Pure read over the loaded set.
     */
    public function getTotalCostIncludingDelegationSlicesUsd(): float
    {
        $subtreeCostInUSD = $this->getTotalCostUsd();
        foreach ($this->getElements() as $message) {
            $delegationResultSlices = isset($message->delegationResults) ? $message->delegationResults->results : [];
            foreach ($delegationResultSlices as $delegationResultSlice) {
                $subtreeCostInUSD += $delegationResultSlice->costInUSD ?? 0.0;
            }
            // a sub-agent row carries its runs' costs on its record (Σ legacy slices + Σ runs)
            $subtreeCostInUSD += isset($message->delegationResults) ? $message->delegationResults->getSubAgentRunsCostInUsd() : 0.0;
        }
        return round($subtreeCostInUSD, 6);
    }

    /**
     * The NEWEST OWNER-REPORT message: the assistant TEXT message marked
     * {@see AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT} — the visible report body the customer sees in
     * chat and receives by mail. Null when no report was materialized (the email composer then falls back to
     * the final assistant text). Pure fold over the loaded set.
     */
    public function getNewestOwnerReportMessage(): ?AIConversationMessage
    {
        foreach (array_reverse($this->getElements()) as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TEXT
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT
            ) {
                return $message;
            }
        }
        return null;
    }

    /**
     * The newest owner report that exists AS CONTENT for the CURRENT run: an OWNER_REPORT text message with a
     * non-empty body that lies inside the run on BOTH boundaries the conversation offers —
     *  - after the newest initiator (user) message: the boundary of every user-started run, in every conversation;
     *  - created at or after `$runObligationStampedAt` (`AIConversation::$reportOwedAt`, re-armed per run on an
     *    unattended EMAIL conversation): the boundary of a signal-started run, which has no user message of its own.
     * Each boundary alone is wrong for the other run type (a stale stamp from an earlier stall give-up on an
     * interactive conversation; a signal-started run with no new user message), so both are applied; a null stamp
     * means no time boundary. EVERY marked message is inspected, not only the newest: a later empty OWNER_REPORT row
     * (a merged call row) must not hide a real report delivered before it.
     *
     * This is the question behind "was the report really delivered?": the delivered-claim on the conversation is a
     * separate row write and can be consumed without the content ever landing, so the claim alone proves nothing.
     * Pure read over the loaded set.
     */
    public function getNewestOwnerReportWithContentForRun(?DateTime $runObligationStampedAt): ?AIConversationMessage
    {
        $newestInitiatorIndex = $this->getNewestInitiatorMessageIndex() ?? -1;
        foreach (array_reverse($this->getElements()) as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TEXT
                || ($message->signalSource ?? null) !== AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT
                || trim((string)($message->message ?? '')) === ''
                || ($message->indexInConversation ?? -1) <= $newestInitiatorIndex
            ) {
                continue;
            }
            $createdAt = $message->getCreatedTime();
            if ($runObligationStampedAt !== null && ($createdAt === null || $createdAt < $runObligationStampedAt)) {
                continue;
            }
            return $message;
        }
        return null;
    }

    /** Whether {@see getNewestOwnerReportWithContentForRun()} finds a report — the delivered-claim's content check. */
    public function hasOwnerReportWithContentForRun(?DateTime $runObligationStampedAt): bool
    {
        return $this->getNewestOwnerReportWithContentForRun($runObligationStampedAt) !== null;
    }

    /**
     * The newest assistant TEXT (an owner-visible answer, widget fences stripped) that lies BEFORE the given row
     * index — the question the owner answered in that row (the host's owner-statements tools:
     * the statement-kind decision reads the owner's words as an answer, not in isolation). Null when none exists.
     */
    public function findNewestAssistantTextBeforeIndex(int $rowIndex): ?string
    {
        $newestText = null;
        $newestIndex = -1;
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex === null || $messageIndex >= $rowIndex || $messageIndex <= $newestIndex
                || !$message->isAssistantText()
            ) {
                continue;
            }
            $prose = trim($message->buildWidgetBlocks()->buildMarkdownWithoutBlocks());
            if ($prose === '') {
                continue;
            }
            $newestText = $prose;
            $newestIndex = (int)$messageIndex;
        }
        return $newestText;
    }

    /**
     * An OWNER inbound that landed DURING the agent's turn — a USER text or an owner signal (widget click, action
     * confirm) with an index above the pre-turn high-water mark that is NOT the trailing message (a trailing one is
     * owed work already, {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::hasUnansweredInboundMessage()}).
     * The worker's own set never held it, so without this walk it would read as answered by the agent's later
     * question. Returns every such row's index (ascending), empty when none. Pure fold over the (reloaded) set.
     *
     * @return int[]
     */
    public function findOwnerInboundArrivedMidTurnIndexes(int $highestIndexBeforeTurn): array
    {
        $newestIndex = $this->getNextIndex() - 1;
        // Rows a MID_TURN_OWNER_INBOUND note already quoted (the in-loop freshness retry) are
        // answered by the retried step — a second note would re-drive the turn for nothing.
        $quotedIndexes = $this->findOwnerInboundIndexesQuotedByMidTurnNotes();
        $midTurnIndexes = [];
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex <= $highestIndexBeforeTurn || $messageIndex >= $newestIndex || in_array($messageIndex, $quotedIndexes, true)) {
                continue;
            }
            if ($message->isOwnerAuthoredInbound()) {
                $midTurnIndexes[] = $messageIndex;
            }
        }
        sort($midTurnIndexes);
        return $midTurnIndexes;
    }

    /**
     * The in-loop freshness probe: OWNER inbounds with an index ABOVE the pre-turn high-water mark
     * that no MID_TURN_OWNER_INBOUND note quoted yet, the newest row INCLUDED (an answer can be the newest persisted
     * row — the post-turn walk's `< newest` filter is what left it unseen until the turn had ended). Run on a FRESHLY
     * loaded set; `$knownIndexes` are the owner rows the loop already holds (never re-quoted).
     *
     * @param int[] $knownIndexes
     * @return int[]
     */
    public function findOwnerInboundIndexesNotYetQuoted(int $highestIndexBeforeTurn, array $knownIndexes = []): array
    {
        $quotedIndexes = $this->findOwnerInboundIndexesQuotedByMidTurnNotes();
        $freshIndexes = [];
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex <= $highestIndexBeforeTurn || in_array($messageIndex, $quotedIndexes, true) || in_array($messageIndex, $knownIndexes, true)) {
                continue;
            }
            if ($message->isOwnerAuthoredInbound()) {
                $freshIndexes[] = $messageIndex;
            }
        }
        sort($freshIndexes);
        return $freshIndexes;
    }

    /**
     * The signal rows that landed DURING the turn (index above the pre-turn high-water mark) of a source
     * a running turn takes over ({@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::isPickedUpByRunningTurn()})
     * that is not the owner's own action (the owner-inbound walk carries those), that no later call of a tool consuming
     * the source ({@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::isConsumedByToolCall()})
     * consumed, and that were not replayed yet (no replay row above them,
     * {@see AIConversationMessage::isMidTurnReplay()}) — a result an application process delivers while the turn runs,
     * consumed by the tool that reads it. The trailing row is excluded: a trailing signal is owed work already. Returns
     * the rows in order. A row the running loop handed to the model at its next model call
     * is followed by that loop's replay row, so it is never returned here — the release path re-fires only for a
     * signal that landed after the turn's last model call. A row the hand-over marked read without a replay row (it was
     * on the wire already) is no longer PENDING and is skipped: every row that still needs this
     * replay is PENDING — the release marks the loaded waiting rows read only after it.
     *
     * @return AIConversationMessage[]
     */
    public function findUnconsumedMidTurnSignals(int $highestIndexBeforeTurn): array
    {
        $newestIndex = $this->getNextIndex() - 1;
        $sortedMessages = array_values($this->getElements());
        usort($sortedMessages, static fn (AIConversationMessage $a, AIConversationMessage $b): int => ($a->indexInConversation ?? -1) <=> ($b->indexInConversation ?? -1));
        $unconsumedSignals = [];
        foreach ($sortedMessages as $position => $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex <= $highestIndexBeforeTurn || $messageIndex >= $newestIndex
                || ($message->messageType ?? null) !== AIConversationMessage::MESSAGE_SIGNAL
            ) {
                continue;
            }
            $signalSource = $message->getSignalSource();
            if ($signalSource === null || !$signalSource->isPickedUpByRunningTurn() || $signalSource->isOwnerAuthored()
                || $message->isSubAgentRunEndThatWaitsForTheNextTurn()
                || (isset($message->status) && $message->status !== AIConversationMessage::STATUS_PENDING)
            ) {
                continue;
            }
            $consumedOrReplayed = false;
            for ($laterPosition = $position + 1; $laterPosition < count($sortedMessages); $laterPosition++) {
                $laterMessage = $sortedMessages[$laterPosition];
                $laterToolName = isset($laterMessage->toolCall) ? ($laterMessage->toolCall?->name ?? null) : null;
                if (($laterToolName !== null && $signalSource->isConsumedByToolCall($laterToolName))
                    || $laterMessage->isMidTurnReplay()
                ) {
                    $consumedOrReplayed = true;
                    break;
                }
            }
            if (!$consumedOrReplayed) {
                $unconsumedSignals[] = $message;
            }
        }
        return $unconsumedSignals;
    }

    /**
     * The loaded rows that are signals no model call has read yet ({@see AIConversationMessage::isSignalWaitingForRunningTurn()}),
     * in set order. The release path marks them read once it has handled them (replayed, consumed or owed).
     *
     * @return AIConversationMessage[]
     */
    public function getSignalsWaitingForRunningTurn(): array
    {
        return array_values(array_filter(
            $this->getElements(),
            static fn (AIConversationMessage $message): bool => $message->isSignalWaitingForRunningTurn()
        ));
    }

    /**
     * The set's write state — {@see AIConversationMessagesWriteState}: a claim-fenced write that
     * rolls back restores it, so the set describes the database again.
     */
    public function buildWriteState(): AIConversationMessagesWriteState
    {
        return AIConversationMessagesWriteState::ofMessages($this);
    }

    /** Put the set back to `$writeState`: its rows in their order, each restored to its own write state, then added. */
    public function restoreWriteState(AIConversationMessagesWriteState $writeState): void
    {
        $this->clear();
        foreach ($writeState->getMessageWriteStatesInSetOrder() as $messageWriteState) {
            $message = $messageWriteState->getMessage();
            $message->restoreWriteState($messageWriteState);
            parent::add($message);
        }
        $this->lastIndexInConversation = $writeState->getLastIndexInConversation();
    }

    /** The loaded message with this database id, or null when the set does not hold it. Pure lookup. */
    public function getMessageById(int $messageId): ?AIConversationMessage
    {
        foreach ($this->getElements() as $message) {
            if (isset($message->id) && (int)$message->id === $messageId) {
                return $message;
            }
        }
        return null;
    }

    /**
     * Whether a row the MODEL wrote (an assistant text or a tool call) sits above this index in the loaded set. A
     * signal row without model output after it is at the tail of what the next model call reads; a signal row with
     * model output after it was persisted while a model call ran and was never read as new input.
     */
    public function hasModelOutputAfterIndex(int $messageIndex): bool
    {
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? -1) <= $messageIndex) {
                continue;
            }
            $messageType = $message->messageType ?? null;
            if ($messageType === AIConversationMessage::MESSAGE_TOOL_CALL
                || $message->isAssistantText()
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * One text per signal source: the text of the row with the highest index among the given signal rows — two
     * signals of the same source the model has not read yet are handed to it as ONE row with the newest text.
     * Sources keep the order of their first row.
     *
     * @param AIConversationMessage[] $signalMessages
     * @return array<string, string> signalSource → newest text
     */
    public static function buildNewestTextPerSignalSource(array $signalMessages): array
    {
        $newestIndexPerSignalSource = [];
        $newestTextPerSignalSource = [];
        foreach ($signalMessages as $signalMessage) {
            $signalSource = (string)($signalMessage->signalSource ?? '');
            $signalIndex = $signalMessage->indexInConversation ?? -1;
            if (!array_key_exists($signalSource, $newestIndexPerSignalSource) || $signalIndex > $newestIndexPerSignalSource[$signalSource]) {
                $newestIndexPerSignalSource[$signalSource] = $signalIndex;
                $newestTextPerSignalSource[$signalSource] = (string)($signalMessage->message ?? '');
            }
        }
        return $newestTextPerSignalSource;
    }

    /**
     * Whether a loaded row is an orchestrator's message to this sub-agent that no model call has read yet
     * ({@see AIConversationMessage::isOrchestratorMessageWaitingForAModelCall()}).
     */
    public function hasOrchestratorMessageWaitingForAModelCall(): bool
    {
        foreach ($this->getElements() as $message) {
            if ($message->isOrchestratorMessageWaitingForAModelCall()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a sub-agent's resuming run end that no model call has read
     * ({@see AIConversationMessage::isSubAgentRunEndWaitingForAModelCall()}) sits BELOW the newest completed
     * `wait_for_sub_agents` row, and no assistant text and no ERROR row follow that wait row (the park race):
     * the run ended between the children's admission and the park, so the tail walk stops at the wait row above it.
     * Narrow on purpose — a turn that ended with a text or an error after the wait row (a step budget used up, a cost cap
     * at step 0, both before any hand-over) leaves its unread run ends unread, and they never owe a turn again (a re-fire
     * would hit the same cap, forever). Unread run ends ABOVE the wait row are the tail walk's.
     */
    public function hasSubAgentRunEndWaitingForAModelCall(): bool
    {
        $sortedMessages = $this->getRowsSortedByIndex();
        $newestWaitRowPosition = null;
        for ($messagePosition = count($sortedMessages) - 1; $messagePosition >= 0; $messagePosition--) {
            $message = $sortedMessages[$messagePosition];
            if ($message->isTurnEndWithoutTextCall()) {
                $newestWaitRowPosition = $messagePosition;
                break;
            }
            if ($message->isAssistantText() || ($message->messageType ?? null) === AIConversationMessage::MESSAGE_ERROR) {
                return false; // a turn ended after the newest wait row (or there is none to look below)
            }
        }
        if ($newestWaitRowPosition === null) {
            return false;
        }
        for ($messagePosition = $newestWaitRowPosition - 1; $messagePosition >= 0; $messagePosition--) {
            if ($sortedMessages[$messagePosition]->isSubAgentRunEndWaitingForAModelCall()) {
                return true;
            }
        }
        return false;
    }

    /** The signal sources whose replay keeps every row (in index order), never only the newest text. */
    public const array SIGNAL_SOURCES_REPLAYED_ROW_BY_ROW = [
        AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED,
        AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME,
        AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE,
    ];

    /**
     * The replay rows of the hand-over: one text per signal
     * source in the order of the sources' first rows — the newest text of the source, except for the sources of
     * {@see SIGNAL_SOURCES_REPLAYED_ROW_BY_ROW}, whose rows are COMBINED in index order ({@see buildCombinedSubAgentRunEndText()}):
     * every run end is its own fact, every orchestrator's message its own instruction — the newest one
     * does not replace the others. An orchestrator's message keeps its sender in the combined text.
     *
     * @param AIConversationMessage[] $signalMessages
     * @return array<string, string> signalSource → the text of its replay row
     */
    public static function buildReplayTextPerSignalSource(array $signalMessages): array
    {
        $replayTextPerSignalSource = self::buildNewestTextPerSignalSource($signalMessages);
        foreach (self::SIGNAL_SOURCES_REPLAYED_ROW_BY_ROW as $rowByRowSignalSource) {
            if (!array_key_exists($rowByRowSignalSource, $replayTextPerSignalSource)) {
                continue;
            }
            $rowByRowSignals = array_values(array_filter(
                $signalMessages,
                static fn (AIConversationMessage $signalMessage): bool => ($signalMessage->signalSource ?? null) === $rowByRowSignalSource
            ));
            $replayTextPerSignalSource[$rowByRowSignalSource] = $rowByRowSignalSource === AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE
                ? self::buildCombinedOrchestratorMessageText($rowByRowSignals)
                : self::buildCombinedSubAgentRunEndText($rowByRowSignals);
        }
        return $replayTextPerSignalSource;
    }

    /**
     * Several sub-agent run-end notifications that landed below the model's output, as ONE replay text: each notification's text in index order, separated by a blank line.
     *
     * @param AIConversationMessage[] $runEndSignals
     */
    public static function buildCombinedSubAgentRunEndText(array $runEndSignals): string
    {
        usort($runEndSignals, static fn (AIConversationMessage $a, AIConversationMessage $b): int => ($a->indexInConversation ?? -1) <=> ($b->indexInConversation ?? -1));
        return implode("\n\n", array_map(static fn (AIConversationMessage $runEndSignal): string => trim((string)($runEndSignal->message ?? '')), $runEndSignals));
    }

    /**
     * What a `delegate` call ended with, as an assertion reads it (the eval runner's
     * `resultContains`): a legacy row's result is its composed verdict map, as before; a row of a numbered sub-agent holds
     * only the spawn answer ("Sub-agent #3 … started"), so its outcome is that answer plus, for every run of its record,
     * the verdict or failure as the record holds it (as loaded) plus the text of every run-end notification of THIS set
     * that names the sub-agent (`Sub-agent #3 «…»` — a notification row, or a replay row that combined several). The
     * notifications carry a run's end even when the loaded record predates it (an async child wrote it).
     */
    public function buildDelegateCallOutcomeText(AIConversationMessage $delegateCallMessage): string
    {
        $spawnAnswerText = $delegateCallMessage->getToolResultText();
        if (!isset($delegateCallMessage->delegationResults) || $delegateCallMessage->delegationResults->isLegacyMap()) {
            return $spawnAnswerText;
        }
        $subAgentRecord = $delegateCallMessage->delegationResults->subAgent;
        $outcomeParts = [$spawnAnswerText];
        foreach ($subAgentRecord->runs->getRunsInOrder() as $subAgentRun) {
            $runOutcome = $subAgentRun->hasFailed() ? (string)$subAgentRun->buildFailureText() : trim((string)$subAgentRun->verdict);
            if ($runOutcome !== '') {
                $outcomeParts[] = 'run ' . $subAgentRun->runNumber . ' ' . $subAgentRun->status . ': ' . $runOutcome;
            }
        }
        if ($subAgentRecord->number !== null) {
            $subAgentNamePrefix = 'Sub-agent #' . $subAgentRecord->number . ' «';
            $delegateConversationId = $delegateCallMessage->aiConversationId ?? null;
            foreach ($this->getElements() as $message) {
                $isRunEndNotification = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                    && in_array($message->signalSource ?? null, [
                        AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED,
                        AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME,
                    ], true);
                $notificationConversationId = $message->aiConversationId ?? null;
                $isOfTheSameConversation = $delegateConversationId === null || $notificationConversationId === null
                    || (int)$delegateConversationId === (int)$notificationConversationId;
                if ($isRunEndNotification && $isOfTheSameConversation && str_contains((string)($message->message ?? ''), $subAgentNamePrefix)) {
                    $outcomeParts[] = trim((string)$message->message);
                }
            }
        }
        return implode("\n", $outcomeParts);
    }

    /**
     * Several orchestrator's messages that landed below the sub-agent's model output, as ONE replay text:
     * each message in index order on its own line, each marked as the orchestrator's — the replay row's own source names
     * the replay, not the sender.
     *
     * @param AIConversationMessage[] $orchestratorMessages
     */
    public static function buildCombinedOrchestratorMessageText(array $orchestratorMessages): string
    {
        usort($orchestratorMessages, static fn (AIConversationMessage $a, AIConversationMessage $b): int => ($a->indexInConversation ?? -1) <=> ($b->indexInConversation ?? -1));
        return implode("\n\n", array_map(
            static fn (AIConversationMessage $orchestratorMessage): string => self::ORCHESTRATOR_MESSAGE_REPLAY_PREFIX . trim((string)($orchestratorMessage->message ?? '')),
            $orchestratorMessages
        ));
    }

    /** What marks an orchestrator's message inside a replay row. */
    public const string ORCHESTRATOR_MESSAGE_REPLAY_PREFIX = 'Your orchestrator writes: ';

    /** How many of a text's last prose lines may carry the owner question ({@see textEndsWithOwnerQuestion()}). */
    public const int OWNER_QUESTION_TAIL_LINES = 3;

    /**
     * Whether an owner-visible text ENDS on a question — one of its last {@see OWNER_QUESTION_TAIL_LINES} non-empty
     * prose lines (widget blocks removed) carries a sentence-ending question mark. The trigger for the
     * platform-attached answer pills and the announce-only check of the final text.
     * The question may be followed by a short closing remark: the e-mail sub-clause under the last owner question
     * ("Reports go to …; say so if you want another address"), a "take your time", a "the writes come after your
     * answer" — bounced as announce-only, such an answer made the model re-plan,
     * re-read and re-ask. A question buried further up, followed by a paragraph of announcements, does not count;
     * a question mark inside a URL (`?utm=`) is not a sentence end.
     */
    public static function textEndsWithOwnerQuestion(string $text): bool
    {
        $prose = WidgetBlocks::fromMarkdown($text)->buildMarkdownWithoutBlocks();
        $lines = array_values(array_filter(array_map('trim', explode("\n", $prose)), static fn (string $line): bool => $line !== ''));
        if ($lines === []) {
            return false;
        }
        foreach (array_slice($lines, -self::OWNER_QUESTION_TAIL_LINES) as $line) {
            if (preg_match('/\?[\s*_)"\'»]*(?:\s|$)/u', $line) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * The OWNER-authored rows ({@see AIConversationMessage::isOwnerAuthoredInbound()}: the owner's text, a widget pick,
     * an action confirm, a form) up to and including the given index, in set order. A row without an index counts
     * as index 0.
     *
     * @return AIConversationMessage[]
     */
    public function getOwnerAuthoredMessagesUpToIndex(int $upToMessageIndex): array
    {
        $ownerAuthoredMessages = [];
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? 0) <= $upToMessageIndex && $message->isOwnerAuthoredInbound()) {
                $ownerAuthoredMessages[] = $message;
            }
        }
        return $ownerAuthoredMessages;
    }

    /** The owner-row indexes this set's OWNER-authored rows carry — the loop's "known" rows for the freshness probe. @return int[] */
    public function getOwnerAuthoredInboundIndexes(): array
    {
        $ownerIndexes = [];
        foreach ($this->getElements() as $message) {
            if ($message->isOwnerAuthoredInbound() && ($message->indexInConversation ?? null) !== null) {
                $ownerIndexes[] = (int)$message->indexInConversation;
            }
        }
        return $ownerIndexes;
    }

    /**
     * The row indexes every MID_TURN_OWNER_INBOUND note in the set quotes (`(message N)` tokens of
     * {@see AIConversationMessage::buildMidTurnOwnerInboundNoteText()}).
     *
     * @return int[]
     */
    public function findOwnerInboundIndexesQuotedByMidTurnNotes(): array
    {
        $quotedIndexes = [];
        foreach ($this->getElements() as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_SIGNAL
                || $message->getSignalSource()?->quotesOwnerRows() !== true
            ) {
                continue;
            }
            if (preg_match_all('/\(message (\d+)\)/', (string)($message->message ?? ''), $indexMatches)) {
                foreach ($indexMatches[1] as $quotedIndex) {
                    $quotedIndexes[] = (int)$quotedIndex;
                }
            }
        }
        return array_values(array_unique($quotedIndexes));
    }

    /**
     * The index of the newest PURPOSE_KICKOFF signal in the loaded set, or null when the conversation was not started
     * by a purpose kickoff. The kickoff is the row that owes the agent's opener (it speaks first).
     */
    public function findPurposeKickoffSignalIndex(): ?int
    {
        $kickoffIndex = null;
        foreach ($this->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_PURPOSE_KICKOFF
                && ($message->indexInConversation ?? null) !== null
                && ($kickoffIndex === null || (int)$message->indexInConversation > $kickoffIndex)
            ) {
                $kickoffIndex = (int)$message->indexInConversation;
            }
        }
        return $kickoffIndex;
    }

    /**
     * Whether the loaded set is a purpose kickoff the owner has not answered yet: a PURPOSE_KICKOFF signal and no
     * owner-authored row ({@see AIConversationMessage::isOwnerAuthoredInbound()}) — nothing the owner asked can be owed.
     */
    public function isKickoffBeforeTheOwnerSpoke(): bool
    {
        if ($this->findPurposeKickoffSignalIndex() === null) {
            return false;
        }
        foreach ($this->getElements() as $message) {
            if ($message->isOwnerAuthoredInbound()) {
                return false;
            }
        }
        return true;
    }

    /**
     * The index of the kickoff OPENER — the first owner-visible assistant text after the PURPOSE_KICKOFF signal — or
     * null while the opener is still owed (no such text yet) or the set has no kickoff. The opener is the one greeting
     * of a purposed conversation: an owner who types before it is delivered gets exactly one greeting, never two and
     * never none. A status-only announcement line ("I am looking that up…", {@see AIConversationMessage::isStatusOnlyAnnouncementLine()})
     * and an empty text are not an opener — they greet nobody.
     */
    public function findKickoffOpenerIndex(): ?int
    {
        $kickoffIndex = $this->findPurposeKickoffSignalIndex();
        if ($kickoffIndex === null) {
            return null;
        }
        $openerIndex = null;
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex <= $kickoffIndex
                || !$message->isAssistantText()
                || trim((string)($message->message ?? '')) === ''
                || $message->isStatusOnlyAnnouncementLine()
            ) {
                continue;
            }
            if ($openerIndex === null || $messageIndex < $openerIndex) {
                $openerIndex = $messageIndex;
            }
        }
        return $openerIndex;
    }

    /** Whether the set holds a PURPOSE_KICKOFF signal that no assistant text has answered yet — the opener is still owed. */
    public function isKickoffOpenerStillOwed(): bool
    {
        return $this->findPurposeKickoffSignalIndex() !== null && $this->findKickoffOpenerIndex() === null;
    }

    /**
     * The OWNER-authored rows that landed after the PURPOSE_KICKOFF signal and before the opener (every owner row
     * after the kickoff while the opener is still owed) that no MID_TURN_OWNER_INBOUND note quoted yet — the owner
     * typed before the agent's greeting reached them. Ascending; empty without a kickoff.
     *
     * @return int[]
     */
    public function findOwnerInboundIndexesBeforeKickoffOpenerNotYetQuoted(): array
    {
        $kickoffIndex = $this->findPurposeKickoffSignalIndex();
        if ($kickoffIndex === null) {
            return [];
        }
        $openerIndex = $this->findKickoffOpenerIndex();
        $quotedIndexes = $this->findOwnerInboundIndexesQuotedByMidTurnNotes();
        $ownerIndexes = [];
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex <= $kickoffIndex || ($openerIndex !== null && $messageIndex > $openerIndex)
                || in_array($messageIndex, $quotedIndexes, true) || !$message->isOwnerAuthoredInbound()
            ) {
                continue;
            }
            $ownerIndexes[] = $messageIndex;
        }
        sort($ownerIndexes);
        return $ownerIndexes;
    }

    /**
     * Whether any ASSISTANT message carries a widget block of the given type — the structured evidence a plan item's
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$doneRequiresWidget} gate checks (e.g. a
     * purpose's closing widget). Read by WidgetBlocks: the block's own top-level type,
     * plain or `…Directive`, any syntax, a fence without a closer line counted as the frontend repairs it (it ends after
     * its balanced JSON, prose after it stays text); a "type" in a later non-widget fence and invalid JSON no longer
     * count (a ```widget glued to prose still does when a JSON object
     * follows on the next line — the frontend's repair). Pure fold over the loaded set.
     */
    public function hasAssistantWidgetBlockOfType(string $widgetType): bool
    {
        foreach ($this->getElements() as $message) {
            if (($message->role ?? null) === AIConversationMessage::ROLE_ASSISTANT
                && $message->buildWidgetBlocks()->findFirstBlockOfTypes($widgetType) !== null
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop every SECOND-or-later status line since the last USER message from an ordered message list — a
     * content answer in between does NOT restore the allowance (the render-time half
     * of the status-line hard limit; the prompt half lives in Agentic.md and is not enough alone:
     * a model repeats status lines with the rule resident). Applied ONLY at user-visible serialization sites (A2A
     * wire, console rendering, FE strip) — never at persistence, where a filtered set would hide the stall
     * evidence from the loop's announce-without-work bounce. The first status line of a turn always renders
     * (the one allowed announcement); {@see AIConversationMessage::isStatusOnlyAnnouncementLine()} is the
     * shared predicate.
     *
     * @param AIConversationMessage[] $orderedMessages
     * @return AIConversationMessage[]
     */
    public static function filterRepeatedStatusLineMessages(array $orderedMessages): array
    {
        $statusLineSeenSinceLastUserMessage = false;
        $renderableMessages = [];
        foreach ($orderedMessages as $message) {
            if (($message->role ?? null) === AIConversationMessage::ROLE_USER) {
                $statusLineSeenSinceLastUserMessage = false;
            }
            if ($message->isStatusOnlyAnnouncementLine()) {
                if ($statusLineSeenSinceLastUserMessage) {
                    continue;
                }
                $statusLineSeenSinceLastUserMessage = true;
            }
            $renderableMessages[] = $message;
        }
        return $renderableMessages;
    }

    /**
     * Whether an assistant ANSWER TEXT (a TEXT message that is not a status-only announcement line) already
     * exists AFTER the given message index — the delivery evidence the
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$doneRequiresOwnerVisibleAnswerInSameTurn}
     * gate checks with the current turn's initiator index: a done-tick riding a parallel tool batch BEFORE the
     * turn produced any owner-visible answer is rejected. Purely
     * structural — role + type + index ordering + the model-declared status-line marker; no content is read.
     */
    public function hasOwnerVisibleAssistantAnswerAfterIndex(int $messageIndex): bool
    {
        foreach ($this->getElements() as $message) {
            if (($message->indexInConversation ?? -1) > $messageIndex
                && $message->isAssistantText()
                && trim((string)($message->message ?? '')) !== ''
                && !$message->isStatusOnlyAnnouncementLine()
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The delivery evidence of the PREVIOUS turn for a next-turn done-tick
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$previousTurnDeliveryEvidenceToolNames}):
     * inside the previous turn — the messages after its initiator and before the current turn's initiator — one of
     * the named evidence tools COMPLETED, and the turn's LAST owner-visible assistant TEXT (not a status-only line)
     * came AFTER that call. Returns that answer's `indexInConversation`, null when the shape does not hold (no
     * previous turn, no evidence call in it, the evidence after the answer, a status line or nothing at the end).
     * DECLARED LIMIT: the platform cannot judge whether that answer IS the delivery — an unrelated text after the
     * read is accepted too (a stricter "exactly one answer after the read" rule would reject a legitimate
     * turn in which two answers follow the read, and re-create the unsatisfiable gate this
     * acceptance exists to remove). Purely structural, no content read. Pure fold over the loaded set.
     *
     * @param string[] $evidenceToolNames
     */
    public function findPreviousTurnDeliveryEvidenceIndex(int $currentTurnInitiatorMessageIndex, array $evidenceToolNames): ?int
    {
        if ($evidenceToolNames === []) {
            return null;
        }
        $previousTurnInitiatorIndex = null;
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex !== null && $messageIndex < $currentTurnInitiatorMessageIndex && self::isTurnInitiatorMessage($message)) {
                $previousTurnInitiatorIndex = $previousTurnInitiatorIndex === null ? $messageIndex : max($previousTurnInitiatorIndex, $messageIndex);
            }
        }
        if ($previousTurnInitiatorIndex === null) {
            return null;
        }
        $newestEvidenceCallIndex = null;
        $lastOwnerVisibleAnswerIndex = null;
        foreach ($this->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex === null || $messageIndex <= $previousTurnInitiatorIndex || $messageIndex >= $currentTurnInitiatorMessageIndex) {
                continue;
            }
            $toolCall = $message->toolCall ?? null;
            // COMPLETED only — a failed or cancelled read delivered nothing.
            if ($toolCall !== null
                && in_array($toolCall->name ?? null, $evidenceToolNames, true)
                && ($toolCall->status ?? null) === AIConversationMessage::STATUS_COMPLETED
            ) {
                $newestEvidenceCallIndex = $newestEvidenceCallIndex === null ? $messageIndex : max($newestEvidenceCallIndex, $messageIndex);
                continue;
            }
            if ($message->isAssistantText()
                && trim((string)($message->message ?? '')) !== ''
                && !$message->isStatusOnlyAnnouncementLine()
            ) {
                $lastOwnerVisibleAnswerIndex = $lastOwnerVisibleAnswerIndex === null ? $messageIndex : max($lastOwnerVisibleAnswerIndex, $messageIndex);
            }
        }
        if ($newestEvidenceCallIndex === null || $lastOwnerVisibleAnswerIndex === null || $lastOwnerVisibleAnswerIndex < $newestEvidenceCallIndex) {
            return null;
        }
        return $lastOwnerVisibleAnswerIndex;
    }

    /**
     * Whether any ASSISTANT message carries a widget block of the given type whose payload has every
     * declared required field FILLED (the gate of a closing widget that carries a promise): for each key of
     * $requiredFilledFields the payload must carry a value that is non-empty and not a `<placeholder>`; an array
     * skeleton value checks its sub-keys recursively (`promise: {date, topic}`). A rendered-but-unfilled block
     * does NOT satisfy this — that is the point: the fence dictation hands the model the skeleton, this check
     * verifies it actually filled it. Null/empty $requiredFilledFields degrades to the plain type check.
     *
     * @param array<string, mixed>|null $requiredFilledFields
     */
    public function hasAssistantWidgetBlockOfTypeWithFilledFields(string $widgetType, ?array $requiredFilledFields): bool
    {
        if ($requiredFilledFields === null || $requiredFilledFields === []) {
            return $this->hasAssistantWidgetBlockOfType($widgetType);
        }
        foreach ($this->getElements() as $message) {
            if (($message->role ?? null) !== AIConversationMessage::ROLE_ASSISTANT) {
                continue;
            }
            if (self::textCarriesWidgetBlockOfTypeWithFilledFields((string)($message->message ?? ''), $widgetType, $requiredFilledFields)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The per-TEXT half of {@see hasAssistantWidgetBlockOfTypeWithFilledFields()} — also callable on a model
     * response text that is NOT yet appended to the set (the pending-tick apply runs at checked-stop time,
     * BEFORE the final answer persists, so the widget it just delivered lives only in the response text).
     * Read by WidgetBlocks: a VALID block of the type, plain or `…Directive`, instead
     * of the regex ```widget … ``` over a `\n`-ended fence. Changes: `~~~`, bare, one-line and leaf blocks and an info
     * string after `widget` count; a block unclosed at the end of the text counts when its JSON parses (the frontend
     * renders it); a ``` inside a JSON string no longer cuts the payload; a ```widget glued to prose still counts when
     * a JSON object follows on the next line (the frontend's repair). A question on the blocks
     * ({@see WidgetBlocks::hasBlockOfTypeWithFilledFields()}, the field rule on {@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock::carriesFilledFields()}).
     *
     * @param array<string, mixed>|null $requiredFilledFields
     */
    public static function textCarriesWidgetBlockOfTypeWithFilledFields(string $messageText, string $widgetType, ?array $requiredFilledFields): bool
    {
        return WidgetBlocks::fromMarkdown($messageText)->hasBlockOfTypeWithFilledFields($widgetType, $requiredFilledFields ?? []);
    }

    /**
     * @var string[] The loop's own work tools that change NO account data — they never invalidate the read dedup. An
     *      MCP tool says the same through {@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect::$keepsEarlierReadsReusable}.
     */
    public const array NON_MUTATING_WORK_TOOL_NAMES = [AskOwnerTool::NAME];

    /**
     * The USER-VISIBLE dialogue window ending at (and including) the given message — the mandate grant's
     * evidence source: only customer messages and
     * the agent's TEXT replies (fenced ```…``` blocks stripped — widgets/code are not dialogue), walking
     * BACKWARD from the anchor and stopping once the word budget is exhausted, returned in chronological
     * order as "Kunde:"/"Agent:" lines. Pure fold over the loaded set — the caller (grantMandate) pulls
     * this SERVER-side via the provenance's live message set, so the model can never supply (or forge) the
     * transcript the approval check judges.
     */
    public function buildUserVisibleDialogueExcerptEndingAtMessage(?int $anchorMessageId, int $wordLimit = 1000): string
    {
        $dialogueLines = [];
        $wordsUsed = 0;
        $anchorSeen = $anchorMessageId === null;
        $orderedMessages = $this->getElements();
        for ($messageIndex = count($orderedMessages) - 1; $messageIndex >= 0; $messageIndex--) {
            $message = $orderedMessages[$messageIndex];
            if (!$anchorSeen) {
                if (($message->id ?? null) === $anchorMessageId) {
                    $anchorSeen = true;
                } else {
                    continue;
                }
            }
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TEXT) {
                continue;
            }
            $role = $message->role ?? null;
            if ($role !== AIConversationMessage::ROLE_USER && $role !== AIConversationMessage::ROLE_ASSISTANT) {
                continue;
            }
            $messageText = trim((string)preg_replace('/```[\s\S]*?```/', '', (string)($message->message ?? '')));
            if ($messageText === '') {
                continue;
            }
            $wordCount = count(preg_split('/\s+/', $messageText) ?: []);
            if ($wordsUsed + $wordCount > $wordLimit && $dialogueLines !== []) {
                break;
            }
            $wordsUsed += $wordCount;
            $speakerLabel = $role === AIConversationMessage::ROLE_USER ? 'Customer' : 'Agent';
            $dialogueLines[] = $speakerLabel . ': ' . $messageText;
        }
        return implode("\n", array_reverse($dialogueLines));
    }
}
