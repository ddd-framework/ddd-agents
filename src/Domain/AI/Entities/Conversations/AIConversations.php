<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AIConversation[] $elements;
 * @method AIConversation|null first()
 * @method AIConversation|null getByUniqueKey(string $uniqueKey)
 * @method AIConversation[] getElements()
 * @method static AIConversationsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversations::class)]
class AIConversations extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AIConversationsService::class;

    /** Recent-window size of the episodic-history narrative: sessions rendered as fine per-line entries. */
    public const int EPISODIC_RECENT_WINDOW_SIZE = 5;

    /** Minimum {@see AIConversationSummary::$importance} for a session OUTSIDE the recent window to survive as its own line. */
    public const int EPISODIC_SURVIVOR_MIN_IMPORTANCE = 4;

    /** Cap on importance-survivor lines (newest first). */
    public const int EPISODIC_SURVIVOR_CAP = 3;

    /** Per-line cap on the outcome/text part, so one verbose summary can never bloat the narrative segment. */
    public const int EPISODIC_LINE_CHAR_CAP = 220;

    /**
     * Render the EPISODIC-HISTORY narrative BODY from this set's conversations — expected loaded
     * newest-activity-first ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::findRecentConversationsWithSummariesOfAgentSpace()}):
     * the RECENT WINDOW (first {@see EPISODIC_RECENT_WINDOW_SIZE} sessions with a usable summary) as fine lines
     * `[YYYY-MM-DD] {title} — {outcome, else text} (#conversationId)`, then the IMPORTANCE SURVIVORS
     * (importance ≥ {@see EPISODIC_SURVIVOR_MIN_IMPORTANCE} among the remainder, newest first, capped) under their
     * own divider, then the one closing line on reading a session in full
     * ({@see getSentenceOnReadingASessionInFull()}). '' when nothing is renderable. The FINE
     * resolution near the present + importance-gated survival of older sessions is the owner's narrative model
     * (more recent ⇒ more detail, more important ⇒ more detail); episode/epoch lines are not part of it yet.
     * Pure over the loaded elements — no repo, container-less testable.
     */
    public function buildEpisodicHistoryText(?int $excludeConversationId = null): string
    {
        $recentLines = [];
        $survivorLines = [];
        foreach ($this->getElements() as $conversation) {
            if ($excludeConversationId !== null && (int)($conversation->id ?? 0) === $excludeConversationId) {
                continue;
            }
            $narrativeLine = $this->buildEpisodicLine($conversation);
            if ($narrativeLine === null) {
                continue;
            }
            if (count($recentLines) < self::EPISODIC_RECENT_WINDOW_SIZE) {
                $recentLines[] = $narrativeLine;
                continue;
            }
            if (count($survivorLines) < self::EPISODIC_SURVIVOR_CAP
                && ($conversation->summary->importance ?? 0) >= self::EPISODIC_SURVIVOR_MIN_IMPORTANCE) {
                $survivorLines[] = $narrativeLine;
            }
        }
        if ($recentLines === []) {
            return '';
        }
        $narrative = implode("\n", $recentLines);
        if ($survivorLines !== []) {
            $narrative .= "\n\nImportant earlier sessions:\n" . implode("\n", $survivorLines);
        }
        return $narrative . "\n\n" . $this->getSentenceOnReadingASessionInFull();
    }

    /**
     * The closing line of the earlier-sessions text: how the model reads one of the listed sessions in full. The
     * default names no tool, because which skill carries the recall tools is the application's binding; an application
     * that binds them overrides this in its set class at the same path and names them.
     */
    public function getSentenceOnReadingASessionInFull(): string
    {
        return 'Where one of your skills has a recall tool, it reads any of these sessions in full by its #conversationId.';
    }

    /**
     * The sessions of this set as one line each, newest first, with the TIME of the last activity on the reader's
     * clock — what a look-up of the day's sessions reports: `[YYYY-MM-DD HH:MM, today] {title} — {outcome, else text}
     * (#id, started by …)`. A session of the reader's local today is marked, so "what happened today" needs no date
     * arithmetic. '' when no session has a usable summary. Pure over the loaded elements.
     *
     * A conversation whose purpose the caller names in `$sessionLineNoteByPurposeName` is marked with that note after
     * who started it (` · {note}`): what the application knows about a conversation of that purpose which its summary
     * alone does not say — a one-time process that is complete is not to be continued, only what it deferred is open
     * work. Without a note for its purpose a line carries none.
     *
     * @param \DateTimeZone $readerTimezone
     * @param \DateTimeInterface|null $now
     * @param array<string, string> $sessionLineNoteByPurposeName the note of a session line, by purpose name
     * @return string
     */
    public function buildSessionLinesText(\DateTimeZone $readerTimezone, ?\DateTimeInterface $now = null, array $sessionLineNoteByPurposeName = []): string
    {
        $todayLocalDate = (new \DateTimeImmutable('@' . ($now?->getTimestamp() ?? time())))->setTimezone($readerTimezone)->format('Y-m-d');
        $sessionLines = [];
        foreach ($this->getElements() as $conversation) {
            if (!isset($conversation->summary) || !isset($conversation->id)) {
                continue;
            }
            $title = trim((string)($conversation->summary->title ?? ''));
            $resultText = trim((string)(($conversation->summary->outcome ?? null) ?: ($conversation->summary->text ?? '')));
            if ($title === '' && $resultText === '') {
                continue;
            }
            $lastActivity = ($conversation->lastInboundMessageAt ?? null) ?? ($conversation->getChangeHistory()->createdTime ?? null);
            $lastActivityLocal = $lastActivity !== null
                ? (new \DateTimeImmutable('@' . $lastActivity->getTimestamp()))->setTimezone($readerTimezone)
                : null;
            $startedBy = match ($conversation->initiatorType ?? null) {
                null => 'the person',
                AIConversation::INITIATOR_TYPE_PHONE_CALL => 'the person, by phone',
                AIConversation::INITIATOR_TYPE_SCHEDULE => 'a scheduled task',
                AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT => 'an external software agent on the person\'s behalf',
                default => 'the platform',
            };
            $sessionLineNote = $sessionLineNoteByPurposeName[$conversation->purposeName ?? ''] ?? '';
            $sessionLines[] = '[' . ($lastActivityLocal?->format('Y-m-d H:i') ?? 'unknown time')
                . ($lastActivityLocal?->format('Y-m-d') === $todayLocalDate ? ', today' : '') . '] ' . $title
                . ($resultText !== '' ? ' — ' . mb_substr($resultText, 0, self::EPISODIC_LINE_CHAR_CAP) : '')
                . ' (#' . $conversation->id . ', started by ' . $startedBy . ($sessionLineNote !== '' ? ' · ' . $sessionLineNote : '') . ')';
        }
        return implode("\n", $sessionLines);
    }

    /**
     * One narrative line for a conversation — `[YYYY-MM-DD] {title} — {outcome, else text} (#id)` from its persisted
     * summary; null when the conversation has no usable summary (never fires the Argus generator: single-level isset).
     * The date is the LAST ACTIVITY (`lastInboundMessageAt`, falling back to the ChangeHistory created time) — the
     * narrative is a story of when things HAPPENED, not when chats were opened.
     */
    protected function buildEpisodicLine(AIConversation $conversation): ?string
    {
        if (!isset($conversation->summary) || !isset($conversation->id)) {
            return null;
        }
        $summary = $conversation->summary;
        $title = trim((string)($summary->title ?? ''));
        $resultText = trim((string)(($summary->outcome ?? null) ?: ($summary->text ?? '')));
        if ($title === '' && $resultText === '') {
            return null;
        }
        $activityDate = ($conversation->lastInboundMessageAt ?? null)?->format('Y-m-d')
            ?? ($conversation->getChangeHistory()->createdTime ?? null)?->format('Y-m-d')
            ?? '';
        return '[' . $activityDate . '] ' . $title
            . ($resultText !== '' ? ' — ' . mb_substr($resultText, 0, self::EPISODIC_LINE_CHAR_CAP) : '')
            . ' (#' . $conversation->id . ')';
    }
}
