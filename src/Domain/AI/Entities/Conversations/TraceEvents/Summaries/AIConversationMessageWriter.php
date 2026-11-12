<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * WHO wrote one conversation row — derived on read, never stored on the 46 GB messages table. A voice
 * row is identified by the provenance event its voice worker wrote; every other row by an ordered
 * classifier: signal (supervisor / platform) → tool result → agent model → caller over the phone → owner in the
 * chat → unknown. A voice-looking row without provenance (rows written before provenance was recorded, or a lost provenance write)
 * is classified by the same heuristic and marked {@see $inferred}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessageWriter extends ValueObject
{
    public const string WRITER_AGENT_MODEL = 'AGENT_MODEL';
    public const string WRITER_OWNER_CHAT = 'OWNER_CHAT';
    public const string WRITER_VOICE_CALLER_TRANSCRIPT = 'VOICE_CALLER_TRANSCRIPT';
    public const string WRITER_VOICE_AGENT_TRANSCRIPT = 'VOICE_ADO_TRANSCRIPT';
    public const string WRITER_VOICE_TASK_TEXT = 'VOICE_TASK_TEXT';
    public const string WRITER_PLATFORM_SIGNAL = 'PLATFORM_SIGNAL';
    public const string WRITER_SUPERVISOR = 'SUPERVISOR';
    public const string WRITER_TOOL_RESULT = 'TOOL_RESULT';
    /** The task a call was started with, seeded as the conversation's first row (the "Call me" task). */
    public const string WRITER_CALL_TASK = 'CALL_TASK';
    public const string WRITER_UNKNOWN = 'UNKNOWN';

    public const array WRITERS = [
        self::WRITER_AGENT_MODEL, self::WRITER_OWNER_CHAT, self::WRITER_VOICE_CALLER_TRANSCRIPT,
        self::WRITER_VOICE_AGENT_TRANSCRIPT, self::WRITER_VOICE_TASK_TEXT, self::WRITER_PLATFORM_SIGNAL,
        self::WRITER_SUPERVISOR, self::WRITER_TOOL_RESULT, self::WRITER_CALL_TASK, self::WRITER_UNKNOWN,
    ];

    /** @var int|null The row's `indexInConversation`. */
    public ?int $messageIndex = null;

    /** @var string|null One of the WRITER_* constants. */
    #[Choice(choices: self::WRITERS)]
    public ?string $writer = null;

    /** @var string|null What further identifies the writer: the model name, the signal source, the owner's device kind. */
    public ?string $writerDetail = null;

    /** @var bool True when the writer was derived by heuristic, not proven by a provenance event. */
    public bool $inferred = false;

    /** @var int|null The voice call the row was spoken on, from its provenance event. */
    public ?int $agentVoiceCallId = null;

    /** @var int|null Start of the spoken span on the call's session clock, in ms (voice rows with provenance). */
    public ?int $spokenFromMs = null;

    /** @var int|null End of the spoken span on the call's session clock, in ms. */
    public ?int $spokenUntilMs = null;

    /**
     * Classify one row. `$provenanceEvent` is the `transcript.sentence_persisted` / `task.started` trace event that
     * names this row, when one exists. `$conversationHasVoiceCalls` licenses the voice heuristics: only on a
     * conversation that carried a call is a model-less assistant line the agent's speech. `$callTaskTexts` are the tasks the
     * conversation's calls were started with — the seeded task row carries the phone device like caller speech and is
     * told apart by its text.
     *
     * @param string[] $callTaskTexts
     */
    public static function classify(
        AIConversationMessage $aIConversationMessage,
        ?AIConversationTraceEvent $provenanceEvent = null,
        bool $conversationHasVoiceCalls = false,
        array $callTaskTexts = []
    ): static {
        $messageWriter = new static();
        $messageWriter->messageIndex = isset($aIConversationMessage->indexInConversation)
            ? (int)$aIConversationMessage->indexInConversation
            : null;
        if ($provenanceEvent !== null) {
            $provenancePayload = $provenanceEvent->getPayloadArray();
            $provenanceWriter = (string)($provenancePayload['writer'] ?? '');
            $messageWriter->writer = in_array($provenanceWriter, self::WRITERS, true)
                ? $provenanceWriter
                : ($provenanceEvent->eventType === AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED ? self::WRITER_VOICE_TASK_TEXT : self::WRITER_UNKNOWN);
            $messageWriter->agentVoiceCallId = $provenanceEvent->agentVoiceCallId ?? null;
            $messageWriter->spokenFromMs = isset($provenancePayload['spokenFromMs']) ? (int)$provenancePayload['spokenFromMs'] : null;
            $messageWriter->spokenUntilMs = isset($provenancePayload['spokenUntilMs']) ? (int)$provenancePayload['spokenUntilMs'] : null;

            return $messageWriter;
        }

        $messageType = $aIConversationMessage->messageType ?? null;
        $signalSource = $aIConversationMessage->signalSource ?? null;
        if ($messageType === AIConversationMessage::MESSAGE_SIGNAL) {
            $isSupervisorSignal = ($aIConversationMessage->supervisorTurnMessageId ?? null) !== null
                || $aIConversationMessage->getSignalSource()?->isSupervisorSignal() === true;
            $messageWriter->writer = $isSupervisorSignal ? self::WRITER_SUPERVISOR : self::WRITER_PLATFORM_SIGNAL;
            $messageWriter->writerDetail = $signalSource;

            return $messageWriter;
        }
        if (($aIConversationMessage->authorType ?? null) === AIConversationMessage::AUTHOR_TOOL) {
            $messageWriter->writer = self::WRITER_TOOL_RESULT;
            $messageWriter->writerDetail = $signalSource;

            return $messageWriter;
        }
        $aiModelName = $aIConversationMessage->aiModelName ?? null;
        if (($aiModelName !== null && $aiModelName !== '') || isset($aIConversationMessage->requestSnapshot)) {
            $messageWriter->writer = self::WRITER_AGENT_MODEL;
            $messageWriter->writerDetail = $aiModelName;

            return $messageWriter;
        }
        $role = $aIConversationMessage->role ?? null;
        $ownerDeviceKind = isset($aIConversationMessage->ownerDevice) ? $aIConversationMessage->ownerDevice->kind : null;
        if ($role === AIConversationMessage::ROLE_USER) {
            if ($callTaskTexts !== [] && in_array(trim((string)($aIConversationMessage->message ?? '')), $callTaskTexts, true)) {
                $messageWriter->writer = self::WRITER_CALL_TASK;

                return $messageWriter;
            }
            if ($ownerDeviceKind === OwnerDevice::KIND_PHONE_CALL) {
                $messageWriter->writer = self::WRITER_VOICE_CALLER_TRANSCRIPT;
                $messageWriter->inferred = true;

                return $messageWriter;
            }
            $messageWriter->writer = self::WRITER_OWNER_CHAT;
            $messageWriter->writerDetail = $ownerDeviceKind;

            return $messageWriter;
        }
        if ($aIConversationMessage->isAssistantText()
            && $conversationHasVoiceCalls
        ) {
            // On a conversation that carried a call, an assistant TEXT row with no model behind it is what a voice
            // worker writes for the agent's speech.
            $messageWriter->writer = self::WRITER_VOICE_AGENT_TRANSCRIPT;
            $messageWriter->inferred = true;

            return $messageWriter;
        }
        if ($role === AIConversationMessage::ROLE_ASSISTANT) {
            $messageWriter->writer = self::WRITER_UNKNOWN;
            $messageWriter->writerDetail = 'assistant row without a model';

            return $messageWriter;
        }
        $messageWriter->writer = self::WRITER_UNKNOWN;
        $messageWriter->writerDetail = $role;

        return $messageWriter;
    }
}
