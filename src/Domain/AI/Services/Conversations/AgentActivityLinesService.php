<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\BackgroundMechanicalOperationPurpose;
use DDD\Domain\AI\MessageHandlers\AgentActivityLinesNarrateMessage;
use DDD\Domain\AI\Repo\Argus\Conversations\Agent\ArgusAgentActivityLines;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Builds and narrates the customer-facing activity lines of a running turn. Two halves: the ELEMENTS
 * come from the persisted TOOL_CALL rows (state from the row's status and refusal flags, kind from the tool, the
 * narrator's view cut to its caps); the LINES come from ONE narrator call per batch of up to
 * {@see self::MAX_ELEMENTS_PER_NARRATOR_CALL} elements. Nothing here persists; the endpoint caches by element.
 */
class AgentActivityLinesService extends Service
{
    /** Elements one narrator call takes at most; the rest wait for the next request. */
    public const int MAX_ELEMENTS_PER_NARRATOR_CALL = 8;

    /** The customer's last message, cut (characters). */
    public const int MAX_CUSTOMER_MESSAGE_CHARS = 300;

    /** The cut arguments the narrator sees (characters; about 75 tokens). */
    public const int MAX_ARGUMENTS_CHARS = 300;

    /** The head of a result text the narrator sees (characters; about 125 tokens). */
    public const int MAX_RESULT_HEAD_CHARS = 500;

    /** How many already-told lines ride into the next narrator call. */
    public const int MAX_ALREADY_TOLD_LINES = 10;

    /** The assistant's own last reply, cut (characters): the narrator mirrors its form of address and voice. */
    public const int MAX_ASSISTANT_SAMPLE_CHARS = 300;

    /**
     * Narrator calls per conversation and hour; beyond it the endpoint returns states without lines. Sized for one
     * narration per changed step while a reader is attached, not for the chat's polling alone.
     */
    public const int MAX_NARRATOR_CALLS_PER_CONVERSATION_AND_HOUR = 120;

    /** Narrated lines per conversation; beyond it the endpoint returns states without lines. */
    public const int MAX_LINES_PER_CONVERSATION = 400;

    /** How long a reader stamp lives after the last read (seconds) — the chat reads every few seconds, the voice worker every 2 s. */
    public const int READER_STAMP_TTL_SECONDS = 10;

    /** How long a scheduled narration blocks the next schedule (seconds) — a job that died frees the root after this. */
    public const int NARRATION_SCHEDULED_TTL_SECONDS = 30;

    /** How long a narrated line stays cached (seconds): a turn is watched for minutes, a repeat request is free. */
    public const int LINE_CACHE_TTL_SECONDS = 7200;

    /** Part of every line's cache key: raise it when the prompt or the narrator's view changes, so cached lines expire. */
    public const int LINE_CACHE_VERSION = 6;

    /**
     * How long one narration holds the narrator lock of a conversation (seconds) — a `Cache::acquireLock()` lock, so a
     * second tab, the producer and the chat never narrate the same elements at once; a holder that died frees it after
     * this.
     */
    public const int NARRATOR_LOCK_TTL_SECONDS = 10;

    /** Tools of the loop itself: no element, ever. */
    protected const array SILENT_TOOL_NAMES = ['load_skills', 'load_tool', 'set_task_status', 'update_task_plan', 'get_task_plans', 'remember', 'check_delegations', 'send_owner_report', 'findWidgets'];

    /**
     * Argument keys the customer never needs to see: plumbing. Ids are dropped by their name ({@see cutArguments()}: `id`
     * and every key ending in `Id`, the id of the conversation's space among them).
     */
    protected const array DROPPED_ARGUMENT_KEYS = ['noCache', 'skiptoken', 'select', 'expand'];

    /** What the last narrator call cost and took — read by the comparison command after {@see narrate()}. */
    public ?int $lastInputTokens = null;

    public ?int $lastOutputTokens = null;

    public ?float $lastCostInUSD = null;

    public ?float $lastDurationSeconds = null;

    public ?string $lastModelName = null;

    /** @var string[] Raw answers of the last {@see narrate()} that were not the expected JSON, for diagnosis. */
    public array $lastUnparsedResults = [];

    /** @var AIConversations|null The delegation children of the last turn read, for {@see isTurnRunning()}. */
    protected ?AIConversations $lastDelegationChildren = null;

    /** @var int How many lines the last {@see findOrNarrateLinesForTurn()} narrated (0 = cache only, lock held or capped). */
    public int $lastNarratedLineCount = 0;

    /** @var bool Whether the last {@see findOrNarrateLinesForTurn()} left pending elements because another narration held the lock. */
    public bool $lastNarrationFoundTheLockHeld = false;

    /** How often a scheduled narration waits one second for a held narrator lock before it gives up. */
    public const int NARRATOR_LOCK_WAIT_ATTEMPTS = 3;

    /** @var string|null The exact prompt and user content of the last narrator call, for inspection. */
    public ?string $lastPayloadSample = null;

    /** @var int How many of a helper conversation's newest rows its activity lines read. */
    public const int HELPER_NEWEST_ROWS = 200;

    /** @var int Elements per narrator call; the comparison command lowers it to measure small batches. */
    public int $elementsPerCall = self::MAX_ELEMENTS_PER_NARRATOR_CALL;

    /**
     * The id of the message that starts the turn the stack follows: the requested one when it is an initiator of this
     * conversation, else the newest initiator. Null on a conversation without one.
     */
    public function resolveTurnMessageId(AIConversation $conversation, ?int $requestedTurnMessageId): ?int
    {
        $messages = $conversation->messages ?? null;
        if (!$messages instanceof AIConversationMessages) {
            return null;
        }
        $newestInitiatorId = null;
        foreach ($messages->getElements() as $message) {
            if (!AIConversationMessages::isTurnInitiatorMessage($message)) {
                continue;
            }
            $messageId = (int)($message->id ?? 0);
            if ($requestedTurnMessageId !== null && $messageId === $requestedTurnMessageId) {
                return $messageId;
            }
            $newestInitiatorId = $messageId;
        }
        return $newestInitiatorId;
    }

    /**
     * The endpoint's read: the elements of the turn that starts at $turnMessageId, every line served from
     * the shared cache where one exists, the rest narrated in ONE call (the debounce: whatever accumulated since the
     * last request goes together, up to {@see MAX_ELEMENTS_PER_NARRATOR_CALL}; more waits for the next request),
     * then cached by element and state. A per-conversation lock keeps a second tab from narrating the same elements;
     * the ceilings stop a watched conversation from narrating without end. A narrator failure leaves the lines null:
     * the customer sees the states, the next request tries again.
     */
    public function findOrNarrateLinesForTurn(AIConversation $conversation, ?int $turnMessageId): AgentActivityLines
    {
        $turnIndex = $this->indexOfMessage($conversation, $turnMessageId);
        $elements = $this->withHelperElements($conversation, $this->buildElementsForTurn($conversation, $turnIndex));
        $elements = $this->withTurnStartElementFirst($conversation, $turnIndex, $elements);
        if ($elements->count() === 0) {
            return $elements;
        }
        $languageCode = $conversation->resolveReplyLanguageCode();
        $cache = $this->cache();
        $this->fillLinesFromCache($cache, $elements, $languageCode);
        $this->lastNarratedLineCount = 0;
        $this->lastNarrationFoundTheLockHeld = false;

        $pending = $elements->withoutLine();
        if ($pending->count() === 0 || $cache === null) {
            $this->writeTurnFeed($cache, $conversation, $turnIndex, $elements);
            return $elements;
        }
        $conversationId = (int)($conversation->id ?? 0);
        $lockKey = "agentActivity:lock:{$conversationId}";
        $callsKey = 'agentActivity:calls:' . $conversationId . ':' . gmdate('YmdH');
        $linesKey = "agentActivity:lines:{$conversationId}";
        try {
            $narratorLockToken = null;
            if ((int)$cache->get($callsKey) >= self::MAX_NARRATOR_CALLS_PER_CONVERSATION_AND_HOUR
                || (int)$cache->get($linesKey) >= self::MAX_LINES_PER_CONVERSATION
                || ($narratorLockToken = $cache->acquireLock($lockKey, self::NARRATOR_LOCK_TTL_SECONDS)) === null
            ) {
                $this->lastNarrationFoundTheLockHeld = $narratorLockToken === null
                    && (int)$cache->get($callsKey) < self::MAX_NARRATOR_CALLS_PER_CONVERSATION_AND_HOUR
                    && (int)$cache->get($linesKey) < self::MAX_LINES_PER_CONVERSATION;
                $this->writeTurnFeed($cache, $conversation, $turnIndex, $elements);
                return $elements;
            }
        } catch (Throwable) {
            return $elements;
        }
        // a caller that waited for the lock reads what the holder narrated meanwhile: pending is the
        // state AFTER acquiring, never the list built before
        $this->fillLinesFromCache($cache, $elements, $languageCode);
        $pending = $elements->withoutLine();
        try {
            // ONE narrator call per request: the first batch of what is pending; the rest waits for the next request.
            $batch = new AgentActivityLines();
            foreach (array_slice($pending->getElements(), 0, $this->elementsPerCall) as $element) {
                $batch->add($element);
            }
            // The history of the turn rides along: the lines already told, from the cache-filled elements.
            $this->narrate($conversation, $batch, $turnIndex, alreadyTold: $elements->linesTold());
            $told = 0;
            foreach ($batch->getElements() as $element) {
                if ($element->line !== null) {
                    $cache->set($this->lineCacheKey($element, $languageCode), $element->line, self::LINE_CACHE_TTL_SECONDS);
                    $told++;
                }
            }
            $cache->set($callsKey, (int)$cache->get($callsKey) + 1, 3600);
            $cache->set($linesKey, (int)$cache->get($linesKey) + $told, 86400);
            $this->lastNarratedLineCount = $told;
        } catch (Throwable) {
            // The lines stay null; the states are still shown and the next request narrates again.
        } finally {
            try {
                $cache->releaseLock($lockKey, $narratorLockToken);
            } catch (Throwable) {
            }
        }
        $this->writeTurnFeed($cache, $conversation, $turnIndex, $elements);
        return $elements;
    }

    /**
     * A reader is attached to the root conversation: the web chat when it asks for the activity lines,
     * the voice worker while a call of the root runs. Refreshed by each read; it expires
     * {@see READER_STAMP_TTL_SECONDS} after the last one. Fail-soft: without a cache nobody is stamped.
     */
    public function markReaderPresent(int $rootConversationId): void
    {
        if ($rootConversationId <= 0) {
            return;
        }
        try {
            $this->cache()?->set(self::buildReaderStampKey($rootConversationId), time(), self::READER_STAMP_TTL_SECONDS);
        } catch (Throwable) {
            // No stamp only means no narration for this root until the next read.
        }
    }

    /** Whether a reader stamped the root conversation within the last {@see READER_STAMP_TTL_SECONDS}. */
    public function hasReader(int $rootConversationId): bool
    {
        if ($rootConversationId <= 0) {
            return false;
        }
        try {
            $readerStamp = $this->cache()?->get(self::buildReaderStampKey($rootConversationId));
            return $readerStamp !== false && $readerStamp !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One step of a turn changed (a TOOL_CALL row was written, a forked call's state moved, the turn-start decision
     * was stamped): when a reader is attached to the ROOT conversation, count the change and schedule
     * ONE narration of the root's current turn. A delegation child's step counts for its root (the root's narration
     * pulls the children's rows). Without a reader nothing happens — CLI, eval, scheduled and replay runs never
     * dispatch. Fail-soft: a broker or cache error never reaches the turn.
     *
     * The scheduled flag is a plain get/set: two triggers in the same millisecond may schedule two narrations, and the
     * second finds nothing pending (the narration's lock and the cache keep it cheap).
     */
    public function recordStepForReaders(AIConversation $conversation): void
    {
        try {
            $rootConversationId = $conversation->getTreeRootConversationId();
            if ($rootConversationId <= 0 || !$this->hasReader($rootConversationId)) {
                return;
            }
            $cache = $this->cache();
            if ($cache === null) {
                return;
            }
            $cache->increment(self::buildStepGenerationKey($rootConversationId), 1, AgentActivityTurnFeed::CACHE_TTL_SECONDS);
            $scheduledKey = self::buildNarrationScheduledKey($rootConversationId);
            $narrationScheduledAt = $cache->get($scheduledKey);
            if ($narrationScheduledAt !== false && $narrationScheduledAt !== null) {
                return;
            }
            $cache->set($scheduledKey, time(), self::NARRATION_SCHEDULED_TTL_SECONDS);
            (new AgentActivityLinesNarrateMessage($rootConversationId))->dispatch();
        } catch (Throwable) {
            // The next step or the chat's own read narrates; the turn never waits for its activity lines.
        }
    }

    /**
     * Narrate the root conversation's current turn. `async: true` schedules it on the
     * `ai_activity_lines` pool; the handler runs it with `async: false`: the scheduled flag is cleared FIRST (a step
     * that arrives during this narration schedules the next one), the turn's rows are read, the pending elements
     * narrated, the feed written — and when steps arrived meanwhile, or this run narrated lines and more are pending
     * (more than one batch), the next narration is scheduled at once.
     */
    public function narrateTurnActivityLines(AIConversation $rootConversation, bool $async = false): void
    {
        $rootConversationId = $rootConversation->getTreeRootConversationId();
        if ($async) {
            (new AgentActivityLinesNarrateMessage($rootConversationId))->dispatch();
            return;
        }
        $cache = $this->cache();
        $generationBefore = 0;
        try {
            $cache?->delete(self::buildNarrationScheduledKey($rootConversationId));
            $generationBefore = (int)($cache?->getCounter(self::buildStepGenerationKey($rootConversationId)) ?? 0);
        } catch (Throwable) {
            // without the counter the narration runs once, as the chat's read would
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessage::getService();
        // the rows of the current turn only — never the whole history
        $rootConversation->messages = $aIConversationMessagesService->findTurnMessages($rootConversation, null);
        $turnMessageId = $this->resolveTurnMessageId($rootConversation, null);
        $narratedElements = $this->findOrNarrateLinesForTurn($rootConversation, $turnMessageId);
        // another narration (the chat's read) holds the lock and narrates only the rows IT loaded: wait for it and
        // narrate what is still pending, so a step committed after that load does not stay without a line on a call
        for ($lockWaitAttempt = 1; $lockWaitAttempt <= self::NARRATOR_LOCK_WAIT_ATTEMPTS
            && $this->lastNarrationFoundTheLockHeld && $narratedElements->withoutLine()->count() > 0; $lockWaitAttempt++
        ) {
            sleep(1);
            $narratedElements = $this->findOrNarrateLinesForTurn($rootConversation, $turnMessageId);
        }

        $generationAfter = $generationBefore;
        try {
            $generationAfter = (int)($cache?->getCounter(self::buildStepGenerationKey($rootConversationId)) ?? 0);
        } catch (Throwable) {
        }
        $narrationIsBehind = $generationAfter > $generationBefore
            || ($this->lastNarratedLineCount > 0 && $narratedElements->withoutLine()->count() > 0);
        if ($narrationIsBehind && $this->hasReader($rootConversationId)) {
            try {
                $cache?->set(self::buildNarrationScheduledKey($rootConversationId), time(), self::NARRATION_SCHEDULED_TTL_SECONDS);
            } catch (Throwable) {
            }
            (new AgentActivityLinesNarrateMessage($rootConversationId))->dispatch();
        }
    }

    /**
     * The feed of the turn a reader waits for: the turn the newest narration of the root covered (the
     * pointer key), when that turn is the task's or a newer one ({@see AgentActivityTurnFeed::coversTaskAtMessageIndex()}
     * — a voice task's delegation signal starts no turn, the caller's row before it does). Two cache reads, no database
     * access — the voice worker calls this every 0.5 s. Null when nothing is narrated yet for that turn.
     */
    public function findTurnFeedForTaskAtOrAfterIndex(int $rootConversationId, int $taskMessageIndex): ?AgentActivityTurnFeed
    {
        try {
            $cache = $this->cache();
            $currentTurnInitiatorIndex = $cache?->get(AgentActivityTurnFeed::buildCurrentTurnPointerKey($rootConversationId));
            if (!is_numeric($currentTurnInitiatorIndex)) {
                return null;
            }
            $agentActivityTurnFeed = AgentActivityTurnFeed::fromCacheArray(
                $cache?->get(AgentActivityTurnFeed::buildCacheKey($rootConversationId, (int)$currentTurnInitiatorIndex))
            );
            return $agentActivityTurnFeed?->coversTaskAtMessageIndex($taskMessageIndex) ? $agentActivityTurnFeed : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write the turn's feed and the root's current-turn pointer — on every exit of the narration, so a
     * reader sees the cached lines also when nothing was narrated this time. The conversation handed to the narration
     * is the root (the chat's own conversation, or the root the producer resolved).
     */
    protected function writeTurnFeed(?Cache $cache, AIConversation $conversation, ?int $turnIndex, AgentActivityLines $elements): void
    {
        if ($cache === null) {
            return;
        }
        $rootConversationId = $conversation->getTreeRootConversationId();
        $turnInitiatorIndex = $turnIndex ?? ($conversation->messages ?? null)?->getNewestInitiatorMessageIndex();
        if ($rootConversationId <= 0 || $turnInitiatorIndex === null) {
            return;
        }
        try {
            $generation = $cache->getCounter(self::buildStepGenerationKey($rootConversationId));
            $newestRowIndex = $turnInitiatorIndex;
            foreach (($conversation->messages ?? null)?->getElements() ?? [] as $turnMessage) {
                $newestRowIndex = max($newestRowIndex, (int)($turnMessage->indexInConversation ?? -1));
            }
            $agentActivityTurnFeed = AgentActivityTurnFeed::buildForTurn($rootConversationId, $turnInitiatorIndex, $generation, $elements, $newestRowIndex);
            $cache->set(
                AgentActivityTurnFeed::buildCacheKey($rootConversationId, $turnInitiatorIndex),
                $agentActivityTurnFeed->toCacheArray(),
                AgentActivityTurnFeed::CACHE_TTL_SECONDS
            );
            $currentTurnInitiatorIndex = $cache->get(AgentActivityTurnFeed::buildCurrentTurnPointerKey($rootConversationId));
            if (!is_numeric($currentTurnInitiatorIndex) || (int)$currentTurnInitiatorIndex <= $turnInitiatorIndex) {
                $cache->set(AgentActivityTurnFeed::buildCurrentTurnPointerKey($rootConversationId), $turnInitiatorIndex, AgentActivityTurnFeed::CACHE_TTL_SECONDS);
            }
        } catch (Throwable) {
            // A missing feed only means the reader falls back to its bridge line.
        }
    }

    protected static function buildReaderStampKey(int $rootConversationId): string
    {
        return "agentActivity:reader:{$rootConversationId}";
    }

    protected static function buildStepGenerationKey(int $rootConversationId): string
    {
        return "agentActivity:stepGeneration:{$rootConversationId}";
    }

    protected static function buildNarrationScheduledKey(int $rootConversationId): string
    {
        return "agentActivity:narrationScheduled:{$rootConversationId}";
    }

    /**
     * Whether the turn the elements belong to is still in flight. The parent conversation is IDLE while its helpers
     * run (the async re-entry model), so the run state alone says nothing: a step still queued or running, or a
     * helper not yet terminal, keeps the turn open.
     */
    public function isTurnRunning(AIConversation $conversation, AgentActivityLines $elements): bool
    {
        if ($conversation->isRunInProgress()) {
            return true;
        }
        foreach ($elements->getElements() as $element) {
            if ($element->state === AgentActivityLine::STATE_QUEUED || $element->state === AgentActivityLine::STATE_RUNNING) {
                return true;
            }
        }
        foreach ($this->lastDelegationChildren?->getElements() ?? [] as $child) {
            if (!AIConversationRunState::of($child->runState ?? '')->isTerminal()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The helpers' own steps: a delegation is one element for the delegate call, and
     * every tool call a helper runs follows it as an element of its own with the helper's number, so the customer
     * reads what the helpers do while they run, not only that they run. One level deep: a helper's own delegate
     * rows are skipped.
     */
    protected function withHelperElements(AIConversation $conversation, AgentActivityLines $elements): AgentActivityLines
    {
        $this->lastDelegationChildren = null;
        $delegateMessageIds = [];
        foreach ($elements->getElements() as $element) {
            if ($element->kind === AgentActivityLine::KIND_DELEGATION || $element->kind === AgentActivityLine::KIND_BACKGROUND) {
                $delegateMessageIds[] = $element->toolCallMessageId;
            }
        }
        if ($delegateMessageIds === []) {
            return $elements;
        }
        /** @var AIConversationsService $conversationsService */
        $conversationsService = AIConversation::getService();
        $children = $conversationsService->findDelegationChildrenOfMessages($delegateMessageIds);
        $this->lastDelegationChildren = $children;
        if ($children->count() === 0) {
            return $elements;
        }
        /** @var AIConversationMessagesService $messagesService */
        $messagesService = AIConversationMessage::getService();
        $helperElementsByDelegateId = [];
        $helperIndexByDelegateId = [];
        foreach ($children->getElements() as $child) {
            $delegateId = (int)($child->delegatedFromMessageId ?? 0);
            $helperIndexByDelegateId[$delegateId] = ($helperIndexByDelegateId[$delegateId] ?? 0) + 1;
            // a helper's newest rows, never its whole history
            $childMessages = $messagesService->findNewestMessages($child, self::HELPER_NEWEST_ROWS);
            foreach ($childMessages?->getElements() ?? [] as $message) {
                if (($message->toolCall->name ?? null) === DelegateTool::NAME) {
                    continue;
                }
                $helperElement = $this->buildElement($message);
                if ($helperElement === null) {
                    continue;
                }
                $helperElement->helperIndex = $helperIndexByDelegateId[$delegateId];
                $helperElementsByDelegateId[$delegateId][] = $helperElement;
            }
        }
        $merged = new AgentActivityLines();
        foreach ($elements->getElements() as $element) {
            $merged->add($element);
            foreach ($helperElementsByDelegateId[$element->toolCallMessageId] ?? [] as $helperElement) {
                $merged->add($helperElement);
            }
        }
        return $merged;
    }

    protected function fillLinesFromCache(?Cache $cache, AgentActivityLines $elements, string $languageCode): void
    {
        if ($cache === null) {
            return;
        }
        $keys = [];
        foreach ($elements->getElements() as $element) {
            if ($element->wantsLine()) {
                $keys[$this->lineCacheKey($element, $languageCode)] = $element;
            }
        }
        if ($keys === []) {
            return;
        }
        try {
            $cached = count($keys) === 1 ? [array_key_first($keys) => $cache->get(array_key_first($keys))] : $cache->get(...array_keys($keys));
        } catch (Throwable) {
            return;
        }
        foreach ((array)$cached as $key => $line) {
            if (is_string($line) && $line !== '' && isset($keys[$key])) {
                $keys[$key]->line = $line;
            }
        }
    }

    protected function lineCacheKey(AgentActivityLine $element, string $languageCode): string
    {
        if ($element->kind === AgentActivityLine::KIND_TURN_START) {
            // one sentence for the owner message, whatever the state: the line stays when the turn's first step supersedes it
            return 'agentActivity:line:' . self::LINE_CACHE_VERSION . ":turnStart:{$element->toolCallMessageId}:{$languageCode}";
        }
        return 'agentActivity:line:' . self::LINE_CACHE_VERSION . ":{$element->toolCallMessageId}:{$element->state}:{$languageCode}";
    }

    /** The shared (Redis) cache; null when none is configured, in which case every request narrates afresh. */
    protected function cache(): ?Cache
    {
        try {
            return Cache::instance(Cache::CACHE_GROUP_REDIS);
        } catch (Throwable) {
            return null;
        }
    }

    protected function indexOfMessage(AIConversation $conversation, ?int $messageId): ?int
    {
        if ($messageId === null) {
            return null;
        }
        foreach (($conversation->messages ?? null)?->getElements() ?? [] as $message) {
            if ((int)($message->id ?? 0) === $messageId) {
                return (int)($message->indexInConversation ?? 0);
            }
        }
        return null;
    }

    /** The turn's elements with the turn-start element in front, when the turn opens with one ({@see buildTurnStartElement()}). */
    protected function withTurnStartElementFirst(AIConversation $conversation, ?int $turnInitiatorIndex, AgentActivityLines $elements): AgentActivityLines
    {
        $turnStartElement = $this->buildTurnStartElement($conversation, $turnInitiatorIndex, $elements);
        if ($turnStartElement === null) {
            return $elements;
        }
        $elementsWithTurnStart = new AgentActivityLines();
        $elementsWithTurnStart->add($turnStartElement);
        foreach ($elements->getElements() as $element) {
            $elementsWithTurnStart->add($element);
        }
        return $elementsWithTurnStart;
    }

    /**
     * The turn's opening element: present when the turn-start decision of the turn's owner message
     * said the assistant works before it answers ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartLineDecision}).
     * Its identity is the owner message's id; it is `running` until the turn's first step or text exists, `finished`
     * after. The narrator writes its line from the owner's request (what the assistant looks at or does now); null when
     * the turn opens with none. Pure over the loaded messages and the working state.
     */
    public function buildTurnStartElement(AIConversation $conversation, ?int $turnInitiatorIndex, AgentActivityLines $turnElements): ?AgentActivityLine
    {
        $messages = $conversation->messages ?? null;
        if (!$messages instanceof AIConversationMessages) {
            return null;
        }
        $turnInitiatorIndex ??= $messages->getNewestInitiatorMessageIndex();
        if ($turnInitiatorIndex === null
            || $conversation->getAgentWorkingState()?->turnStartLineDecision?->announcesTheTurnOfOwnerMessage($turnInitiatorIndex) !== true
        ) {
            return null;
        }
        $initiatorMessage = null;
        $turnHasAssistantText = false;
        foreach ($messages->buildTurnStartingAtMessageIndex($turnInitiatorIndex)->getRows() as $message) {
            if ((int)($message->indexInConversation ?? -1) === $turnInitiatorIndex) {
                $initiatorMessage = $message;
            } elseif ($message->isAssistantText() && trim((string)($message->message ?? '')) !== '') {
                $turnHasAssistantText = true;
            }
        }
        if ($initiatorMessage === null || !isset($initiatorMessage->id)) {
            return null;
        }
        $element = new AgentActivityLine();
        $element->toolCallMessageId = (int)$initiatorMessage->id;
        $element->kind = AgentActivityLine::KIND_TURN_START;
        $element->state = $turnElements->count() > 0 || $turnHasAssistantText ? AgentActivityLine::STATE_FINISHED : AgentActivityLine::STATE_RUNNING;
        $element->narratorToolName = 'start on the request: say what you look at or do now';
        return $element;
    }

    /**
     * The elements of a turn: one per TOOL_CALL row from the turn's initiator on (the newest owner message or work
     * signal when $turnInitiatorIndex is null), silent tools skipped, a delegate call as one element with its child
     * count. Pure over the loaded messages; the caller loads them.
     */
    public function buildElementsForTurn(AIConversation $conversation, ?int $turnInitiatorIndex = null): AgentActivityLines
    {
        $elements = new AgentActivityLines();
        $messages = $conversation->messages ?? null;
        if (!$messages instanceof AIConversationMessages) {
            return $elements;
        }
        $turnRows = $turnInitiatorIndex === null
            ? $messages->buildCurrentTurn()->getRows()
            : $messages->buildTurnStartingAtMessageIndex($turnInitiatorIndex)->getRows();
        foreach ($turnRows as $message) {
            $element = $this->buildElement($message);
            if ($element !== null) {
                $elements->add($element);
            }
        }
        return $elements;
    }

    /** One element for a TOOL_CALL row, or null for a silent tool or a non-tool row. */
    public function buildElement(AIConversationMessage $message): ?AgentActivityLine
    {
        if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL || !isset($message->toolCall)) {
            return null;
        }
        $toolCall = $message->toolCall;
        $toolName = (string)($toolCall->name ?? '');
        if ($toolName === '' || in_array($toolName, self::SILENT_TOOL_NAMES, true)) {
            return null;
        }
        $element = new AgentActivityLine();
        $element->toolCallMessageId = (int)($message->id ?? 0);
        $element->batchId = $toolCall->emittedBatchId;
        $element->state = $this->stateOf($message);
        $element->kind = AgentActivityLine::KIND_TOOL;
        $element->narratorToolName = $this->describeToolName($toolName);
        $element->narratorArguments = $this->cutArguments($toolCall->arguments ?? []);
        if ($toolName === DelegateTool::NAME) {
            $delegationResults = isset($message->delegationResults) ? $message->delegationResults : null;
            if ($delegationResults !== null && !$delegationResults->isLegacyMap()) {
                // one sub-agent per row; the platform's own work notifies its parent without resuming it
                $element->childCount = 1;
                $element->kind = $delegationResults->subAgent->notifiesParentWithoutResuming ? AgentActivityLine::KIND_BACKGROUND : AgentActivityLine::KIND_DELEGATION;
            } else {
                $tasks = is_array($toolCall->arguments['tasks'] ?? null) ? $toolCall->arguments['tasks'] : [];
                $element->childCount = max(1, count($tasks));
                $element->kind = $this->delegationIsBackground($tasks) ? AgentActivityLine::KIND_BACKGROUND : AgentActivityLine::KIND_DELEGATION;
            }
        }
        if ($element->state === AgentActivityLine::STATE_FINISHED || $element->state === AgentActivityLine::STATE_FAILED) {
            $rawHead = $toolName === DelegateTool::NAME
                ? $message->getToolResultText()
                : $toolCall->getResultTextHead(self::MAX_RESULT_HEAD_CHARS * 3);
            $element->narratorResultHead = $element->state === AgentActivityLine::STATE_FAILED
                ? null
                : mb_substr($this->withoutAgentDirections($rawHead), 0, self::MAX_RESULT_HEAD_CHARS);
        }
        return $element;
    }

    /**
     * Narrates every element that owes a line, in batches, with one narrator call per batch. Lines already on the
     * elements ride along as the "already told" history. Returns the same set with the lines filled where the model
     * answered; an element the model skipped keeps `line = null`.
     */
    public function narrate(
        AIConversation $conversation,
        AgentActivityLines $elements,
        ?int $turnInitiatorIndex = null,
        ?string $modelNameOverride = null,
        ?string $reasoningEffortOverride = null,
        array $alreadyTold = [],
    ): AgentActivityLines {
        $customerMessage = $this->customerMessageOfTurn($conversation, $turnInitiatorIndex);
        $assistantSample = $this->assistantReplyBeforeTurn($conversation, $turnInitiatorIndex);
        $languageCode = $conversation->resolveReplyLanguageCode();
        $pending = $elements->withoutLine()->getElements();
        $this->lastInputTokens = 0;
        $this->lastOutputTokens = 0;
        $this->lastCostInUSD = 0.0;
        $this->lastDurationSeconds = 0.0;
        $this->lastUnparsedResults = [];
        foreach (array_chunk($pending, max(1, $this->elementsPerCall)) as $batch) {
            $narrator = new ArgusAgentActivityLines();
            foreach ($batch as $element) {
                $narrator->activityLines->add($element);
            }
            $narrator->customerMessage = $customerMessage;
            $narrator->assistantSample = $assistantSample;
            $narrator->language = $languageCode;
            $narrator->alreadyToldLines = array_slice(array_merge($alreadyTold, $elements->linesTold()), -self::MAX_ALREADY_TOLD_LINES);
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $narrator->billingEntity = $agentSpacesService->findBillingEntityOfConversation($conversation);
            $narrator->aiModelNameOverride = $modelNameOverride;
            $narrator->reasoningEffortOverride = $reasoningEffortOverride;
            $this->lastPayloadSample = "=== PROMPT ===\n" . $narrator->getAIPromptWithParametersApplied()->getPromptTextWithParametersApplied()
                . "\n=== USER CONTENT ===\n" . $narrator->getUserContent();
            $startedAt = microtime(true);
            $narrator->argusLoad();
            $this->lastDurationSeconds += microtime(true) - $startedAt;
            $this->lastInputTokens += $narrator->getLastInputTokens() ?? 0;
            $this->lastOutputTokens += $narrator->getLastOutputTokens() ?? 0;
            $this->lastCostInUSD += $narrator->getLastCostInUSD() ?? 0.0;
            $this->lastModelName = $narrator->getAIModel()?->name;
            if ($narrator->unparsedResult !== null) {
                $this->lastUnparsedResults[] = $narrator->unparsedResult;
            }
        }
        return $elements;
    }

    protected function stateOf(AIConversationMessage $message): string
    {
        $toolCall = $message->toolCall;
        if ($toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate) {
            return AgentActivityLine::STATE_SKIPPED;
        }
        return match ($toolCall->status ?? null) {
            AIConversationMessage::STATUS_COMPLETED => AgentActivityLine::STATE_FINISHED,
            AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED => AgentActivityLine::STATE_FAILED,
            AIConversationMessage::STATUS_RUNNING => AgentActivityLine::STATE_RUNNING,
            default => AgentActivityLine::STATE_QUEUED,
        };
    }

    /**
     * A tool result carries directions for the agent (a "Note:" telling it what to do next, backticked ids to pass
     * on); the narrator narrates facts, so those go before the head is cut, and the cut keeps the facts.
     */
    protected function withoutAgentDirections(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^(Note|Hint|Next|Rule|Remember)\b\s*:/i', $trimmed)) {
                continue;
            }
            $lines[] = preg_replace('/\s*[·—-]?\s*`[^`]*`/u', '', $line);
        }
        return implode("\n", $lines);
    }

    /** `searchServiceAreas` reads as "search service areas": the narrator gets words, never the identifier. */
    protected function describeToolName(string $toolName): string
    {
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', $toolName) ?? $toolName;
        return strtolower(str_replace('_', ' ', $words));
    }

    protected function cutArguments(array $arguments): string
    {
        $kept = [];
        foreach ($arguments as $key => $value) {
            if (in_array($key, self::DROPPED_ARGUMENT_KEYS, true) || (is_string($key) && str_ends_with($key, 'Id')) || $key === 'id') {
                continue;
            }
            if (is_string($value)) {
                $value = mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
            }
            $kept[$key] = $value;
        }
        $json = (string)json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strlen($json) > self::MAX_ARGUMENTS_CHARS ? mb_substr($json, 0, self::MAX_ARGUMENTS_CHARS) . '…' : $json;
    }

    /** @param array<int, mixed> $tasks */
    protected function delegationIsBackground(array $tasks): bool
    {
        foreach ($tasks as $task) {
            $taskText = is_array($task) ? (string)($task['task'] ?? '') : (string)$task;
            if (stripos($taskText, 'background job') === 0 || str_contains($taskText, BackgroundMechanicalOperationPurpose::class)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The assistant's newest text reply before the turn's initiator, cut: the narrator mirrors how the assistant
     * addresses this customer (du or Sie, tu or vous) without a field that records it.
     */
    protected function assistantReplyBeforeTurn(AIConversation $conversation, ?int $turnInitiatorIndex): string
    {
        $messages = $conversation->messages ?? null;
        if (!$messages instanceof AIConversationMessages) {
            return '';
        }
        foreach (array_reverse($messages->getElements()) as $message) {
            if ($turnInitiatorIndex !== null && (int)($message->indexInConversation ?? 0) >= $turnInitiatorIndex) {
                continue;
            }
            if ($message->isAssistantText()
                && trim((string)($message->message ?? '')) !== ''
            ) {
                $text = trim((string)$message->message);
                return mb_strlen($text) > self::MAX_ASSISTANT_SAMPLE_CHARS ? mb_substr($text, 0, self::MAX_ASSISTANT_SAMPLE_CHARS) . '…' : $text;
            }
        }
        return '';
    }

    /** The customer's newest text message at or before the turn's initiator (the whole conversation when null). */
    protected function customerMessageOfTurn(AIConversation $conversation, ?int $turnInitiatorIndex): string
    {
        $messages = $conversation->messages ?? null;
        if (!$messages instanceof AIConversationMessages) {
            return '';
        }
        foreach (array_reverse($messages->getElements()) as $message) {
            if ($turnInitiatorIndex !== null && (int)($message->indexInConversation ?? 0) > $turnInitiatorIndex) {
                continue;
            }
            if ($message->isUserRoleText()
                && trim((string)($message->message ?? '')) !== ''
            ) {
                $text = trim((string)$message->message);
                return mb_strlen($text) > self::MAX_CUSTOMER_MESSAGE_CHARS ? mb_substr($text, 0, self::MAX_CUSTOMER_MESSAGE_CHARS) . '…' : $text;
            }
        }
        return '';
    }
}
