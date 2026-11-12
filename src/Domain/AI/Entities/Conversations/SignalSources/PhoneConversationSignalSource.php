<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\SignalSources;

/**
 * The phone channel's signal sources: the stored
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::$signalSource} values the voice channel
 * (`Domain/AI/Services/Channels/`) writes — kept out of the AI core enum so the channel can leave the core as one block.
 * Found by {@see ConversationSignalSources} through the folder convention; the cases answer the basic questions, the
 * derived ones (among them {@see ConversationSignalSource::isPhoneCallLifecycle()}) and the core vocabulary come from
 * {@see ConversationSignalSourceDefaultAnswers}.
 */
enum PhoneConversationSignalSource: string implements ConversationSignalSource
{
    use ConversationSignalSourceDefaultAnswers;

    /**
     * A phone VOICEMAIL from a caller whose number is not enabled for authentication: the doorman took a
     * message; the session worker posts its transcript as this signal into a fresh conversation of the called agent
     * number's OWNER account and dispatches a resume, so the agent surfaces the message proactively.
     */
    case PHONE_VOICEMAIL = 'PHONE_VOICEMAIL';

    /**
     * A phone call on THIS conversation was ANSWERED — the chat renders it as a call marker chip and the agent knows
     * structurally that a call is running. NON-turn-owing: the turn derivation sees through phone lifecycle signals
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::hasUnansweredInboundMessage()}).
     */
    case PHONE_CALL_STARTED = 'PHONE_CALL_STARTED';

    /**
     * A phone call on THIS conversation ENDED (the text carries duration and end reason — including `not_answered` when
     * the callee declined or never picked up). Chat marker chip + structural context for the agent (an a2a task
     * finishing after hang-up knows nobody is on the line). NON-turn-owing like {@see self::PHONE_CALL_STARTED}.
     */
    case PHONE_CALL_ENDED = 'PHONE_CALL_ENDED';

    /**
     * The voice model of a GPT-Live call DELEGATED to the backend: the caller's request is the transcript
     * itself — the USER rows the call persisted right before this signal — so the signal carries no copy of it, only the
     * instruction to act on it. TURN-OWING (deliberately not a lifecycle signal): the trailing signal is the owed work,
     * exactly like a signal-only start.
     */
    case PHONE_DELEGATION = 'PHONE_DELEGATION';

    /**
     * A call the agent ITSELF started (`startVoiceCall`) ended unanswered — the app ring EXPIRED on every device, or
     * the phone call was not answered or could not be connected: the platform starts ONE turn in the
     * conversation the agent started the call from, so the agent tells the owner they did not pick up and ASKS
     * whether to try another channel — never calls again by itself. Written once per call by
     * the host's voice call follow-up service behind the call's unanswered-end claim;
     * the text names the outcome and the channels that reach the owner now. TURN-OWING; a running turn takes it over
     * at its next model call instead of a second reaction. A DECLINED ring never writes it.
     */
    case PHONE_CALL_UNANSWERED_FOLLOW_UP = 'PHONE_CALL_UNANSWERED_FOLLOW_UP';

    // --- basic questions ---

    /** The call's start and end are the owner's own presence; the voicemail and the delegation are the platform's. */
    public function getSpeaker(): ConversationSignalSpeaker
    {
        return $this === self::PHONE_CALL_STARTED || $this === self::PHONE_CALL_ENDED ? ConversationSignalSpeaker::OWNER : ConversationSignalSpeaker::PLATFORM;
    }

    /** The call markers are chips the owed-turn walks see through; the voicemail, the delegation and the follow-up owe an answer. */
    public function owesWork(): bool
    {
        return $this !== self::PHONE_CALL_STARTED && $this !== self::PHONE_CALL_ENDED;
    }

    /** The unanswered-call follow-up reaches a running turn at its next model call — one reaction, never two. */
    public function isPickedUpByRunningTurn(): bool
    {
        return $this === self::PHONE_CALL_UNANSWERED_FOLLOW_UP;
    }

    /** An answered call is the owner being there (the metrics' owner origin). */
    public function marksOwnerPresence(): bool
    {
        return $this === self::PHONE_CALL_STARTED;
    }

    // --- presentation properties ---

    /** A conversation opened by an answered call counts as `phoneCall` in the scorecard (stored bytes). */
    public function getMetricsEntryPoint(): ?string
    {
        return $this === self::PHONE_CALL_STARTED ? 'phoneCall' : null;
    }

    /** The answered call's text is an instruction to the agent; the analysis shows only that the call started. */
    public function getAnalysisTranscriptLabel(): ?string
    {
        return $this === self::PHONE_CALL_STARTED ? 'PHONE CALL STARTED' : null;
    }
}
