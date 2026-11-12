<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The header of a supervisor checkpoint briefing — the first three lines of the tandem's briefing USER row:
 *
 *     # SUPERVISOR CHECKPOINT[ (delta since index S)]
 *     beat: step|settled · purpose: <name>|(none — free dialog) · asOfMessageIndex: N
 *     checkpoint trigger: <reason>
 *
 * ONE object renders the lines ({@see renderHeaderLines()}) and reads them back ({@see parseFromText()}), so the
 * history readers of the tandem stop re-parsing the text each with its own regex.
 *
 * The reading keeps today's leniency: the covered index and a step beat are read from ANY text that carries their
 * line, with or without the header prefix — a USER row with an `asOfMessageIndex:` line but no prefix counts for the
 * covered index and the step-beat count, never as a briefing ({@see isBriefing()}). A header without a beat line
 * (a tandem from before beat lines existed) reads as settled.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorCheckpointBriefingHeader extends ValueObject
{
    /** The prefix a briefing row opens with (after trimming). */
    public const string HEADER_PREFIX = '# SUPERVISOR CHECKPOINT';

    public const string BEAT_STEP = 'step';
    public const string BEAT_SETTLED = 'settled';

    /** What the purpose slot says for a conversation without a purpose. */
    public const string FREE_DIALOG_PURPOSE_LABEL = '(none — free dialog)';

    /** The beat: {@see BEAT_STEP} when a `beat: step` line stands in the text, else {@see BEAT_SETTLED}. */
    public string $beat = self::BEAT_SETTLED;

    /** The supervised conversation's purpose; null = free dialog (or no purpose slot in the text). */
    public ?string $purposeName = null;

    /** The supervised message index the checkpoint covers; null when the text carries no `asOfMessageIndex:` line. */
    public ?int $asOfMessageIndex = null;

    /** The index the delta starts after; null for a full (first) briefing. */
    public ?int $sinceMessageIndex = null;

    /** The beat's trigger reason; null when the text carries no `checkpoint trigger:` line. */
    public ?string $triggerReason = null;

    /** Whether the text opens with {@see HEADER_PREFIX} — only then is the row a briefing. */
    public bool $opensWithHeaderPrefix = false;

    /** The text's first non-empty line, trimmed, as it was read (the projection keeps it). */
    public string $firstLine = '';

    /** The text's `beat:` line as it was read ('' without one; the projection keeps it). */
    public string $beatLine = '';

    /** A header to write: the beat, the purpose (null = free dialog), the covered index, the delta start, the reason. */
    public static function create(string $beat, ?string $purposeName, int $asOfMessageIndex, ?int $sinceMessageIndex, string $triggerReason): static
    {
        $supervisorCheckpointBriefingHeader = new static();
        $supervisorCheckpointBriefingHeader->beat = $beat;
        $supervisorCheckpointBriefingHeader->purposeName = $purposeName;
        $supervisorCheckpointBriefingHeader->asOfMessageIndex = $asOfMessageIndex;
        $supervisorCheckpointBriefingHeader->sinceMessageIndex = $sinceMessageIndex;
        $supervisorCheckpointBriefingHeader->triggerReason = $triggerReason;
        $supervisorCheckpointBriefingHeader->opensWithHeaderPrefix = true;
        return $supervisorCheckpointBriefingHeader;
    }

    /** Whether this text is a briefing: it opens with {@see HEADER_PREFIX} once trimmed. */
    public static function isBriefingText(string $text): bool
    {
        return str_starts_with(trim($text), self::HEADER_PREFIX);
    }

    /** Whether this row is a briefing: a USER row whose text opens with {@see HEADER_PREFIX}. */
    public static function isBriefingMessage(AIConversationMessage $message): bool
    {
        return ($message->role ?? null) === AIConversationMessage::ROLE_USER && self::isBriefingText((string)($message->message ?? ''));
    }

    /** The header of a USER row (see {@see parseFromText()}); null for any other role. */
    public static function parseFrom(AIConversationMessage $message): ?self
    {
        if (($message->role ?? null) !== AIConversationMessage::ROLE_USER) {
            return null;
        }
        return self::parseFromText((string)($message->message ?? ''));
    }

    /**
     * Read a header from a text: null when the text carries none of the header prefix, an `asOfMessageIndex:` line
     * and a `beat: step` line. The covered index is the FIRST `asOfMessageIndex:` in the text, the beat is step when
     * any line starts with `beat: step`, the trigger is the first `checkpoint trigger:` line, trimmed.
     */
    public static function parseFromText(string $text): ?static
    {
        $opensWithHeaderPrefix = self::isBriefingText($text);
        $hasAsOfLine = preg_match('/asOfMessageIndex:\s*(\d+)/', $text, $asOfMatch) === 1;
        $isStepBeat = preg_match('/^beat:\s*step\b/m', $text) === 1;
        if (!$opensWithHeaderPrefix && !$hasAsOfLine && !$isStepBeat) {
            return null;
        }
        $supervisorCheckpointBriefingHeader = new static();
        $supervisorCheckpointBriefingHeader->opensWithHeaderPrefix = $opensWithHeaderPrefix;
        $supervisorCheckpointBriefingHeader->beat = $isStepBeat ? self::BEAT_STEP : self::BEAT_SETTLED;
        $supervisorCheckpointBriefingHeader->asOfMessageIndex = $hasAsOfLine ? (int)$asOfMatch[1] : null;
        if (preg_match('/^checkpoint trigger:\s*(.+)$/m', $text, $triggerMatch) === 1) {
            $supervisorCheckpointBriefingHeader->triggerReason = trim($triggerMatch[1]);
        }
        $supervisorCheckpointBriefingHeader->firstLine = trim((string)strtok($text, "\n"));
        $supervisorCheckpointBriefingHeader->beatLine = preg_match('/^beat:.*$/m', $text, $beatLineMatch) === 1 ? $beatLineMatch[0] : '';
        if ($opensWithHeaderPrefix && preg_match('/\(delta since index (\d+)\)/', $supervisorCheckpointBriefingHeader->firstLine, $sinceMatch) === 1) {
            $supervisorCheckpointBriefingHeader->sinceMessageIndex = (int)$sinceMatch[1];
        }
        if (preg_match('/purpose: (.*?) · asOfMessageIndex:/u', $text, $purposeMatch) === 1 && $purposeMatch[1] !== self::FREE_DIALOG_PURPOSE_LABEL) {
            $supervisorCheckpointBriefingHeader->purposeName = $purposeMatch[1];
        }
        return $supervisorCheckpointBriefingHeader;
    }

    /** Whether the row this header was read from is a briefing (it opens with the header prefix). */
    public function isBriefing(): bool
    {
        return $this->opensWithHeaderPrefix;
    }

    public function isStepBeat(): bool
    {
        return $this->beat === self::BEAT_STEP;
    }

    /** Whether the briefing is a delta (a since index of zero or more). */
    public function isDelta(): bool
    {
        return $this->sinceMessageIndex !== null && $this->sinceMessageIndex >= 0;
    }

    /** The three header lines, byte-identical to what the briefing has written since beat lines exist (no trailing newline). */
    public function renderHeaderLines(): string
    {
        return self::HEADER_PREFIX . ($this->isDelta() ? " (delta since index {$this->sinceMessageIndex})" : '')
            . "\nbeat: {$this->beat} · purpose: " . ($this->purposeName ?? self::FREE_DIALOG_PURPOSE_LABEL)
            . " · asOfMessageIndex: {$this->asOfMessageIndex}\ncheckpoint trigger: {$this->triggerReason}";
    }

    /**
     * What an EARLIER briefing keeps of its header when its delta is projected away for the model: the first line and
     * the `beat:` line as they were read (an empty second line for a header without one).
     */
    public function renderProjectedHead(): string
    {
        return $this->firstLine . "\n" . $this->beatLine;
    }
}
