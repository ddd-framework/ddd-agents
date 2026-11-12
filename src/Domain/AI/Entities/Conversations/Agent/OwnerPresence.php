<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

/**
 * Who the agent is talking to in a conversation (owner channel awareness) — derived per model
 * call by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::getOwnerPresence()} from the conversation's
 * initiator, purpose and the live phone call; never persisted. Rendered as the OWNER_PRESENCE system segment
 * ({@see Context\AgentContextSegmentType::OWNER_PRESENCE}) so the model knows whether a person reads the chat live,
 * is on the line, or whether a scheduled task, the platform or an external software agent drives the run.
 */
enum OwnerPresence: string
{
    /** A person reads the chat live (a normal dialog, a guided run in the chat, the admin console). */
    case PRESENT_IN_CHAT = 'PRESENT_IN_CHAT';
    /** The owner is on a call over the phone line right now — the text is spoken aloud and no screen is in reach. */
    case PRESENT_ON_THE_PHONE = 'PRESENT_ON_THE_PHONE';
    /**
     * The owner talks to the agent in the app — the text is spoken aloud, the app with the chat and its widgets is
     * open.
     */
    case PRESENT_IN_APP_VOICE_CALL = 'PRESENT_IN_APP_VOICE_CALL';
    /** An external software agent sends the requests (A2A); no person reads the chat live. */
    case WORKING_FOR_AN_EXTERNAL_AGENT = 'WORKING_FOR_AN_EXTERNAL_AGENT';
    /** A scheduled task or an admin-created unattended task; the owner reads the result later. */
    case ABSENT_SCHEDULED_RUN = 'ABSENT_SCHEDULED_RUN';
    /** The platform's own run (the supervisor, a mechanical operation, a run the application runs for itself); read by the platform. */
    case ABSENT_PLATFORM_RUN = 'ABSENT_PLATFORM_RUN';
    /** An initiator this code does not know — nobody is assumed present. */
    case ABSENT_UNKNOWN_ORIGIN = 'ABSENT_UNKNOWN_ORIGIN';

    /** Whether a person is there right now (chat, phone line or an in-app call). */
    public function isPersonPresent(): bool
    {
        return $this === self::PRESENT_IN_CHAT || $this === self::PRESENT_ON_THE_PHONE || $this === self::PRESENT_IN_APP_VOICE_CALL;
    }

    /**
     * The system segment's text — one fact, no rules (the rules live in the purposes and the base prompt); the one
     * exception is the phone line: its rule must hold for every skill and tool, also an unswept one.
     */
    public function toSystemSegmentText(): string
    {
        $header = "# Who you are talking to\n";
        return $header . match ($this) {
            self::PRESENT_IN_CHAT => 'A person is present and reads your messages in the chat as you write them.'
                . ' A message of theirs may carry a bracket [time | via …] that names the device it was sent from;'
                . ' read it there, never guess it from wording.',
            self::PRESENT_ON_THE_PHONE => 'The owner is on the phone with you: your text is spoken aloud — short'
                . ' sentences, no lists, no links, no markdown. The owner sees no screen and cannot click, read, sign in'
                . ' or upload during the call. Whatever needs the screen — connecting an account, picking or uploading'
                . ' pictures, reviewing a visual draft, confirming an irreversible action — is done in the app on their'
                . ' phone or computer: say so, and say where in the app they finish it. Ask every question in your own'
                . ' words and end it with a question mark. Promise to send something only when a tool you can call sends'
                . ' it.',
            self::PRESENT_IN_APP_VOICE_CALL => 'The owner talks to you in the app: your text is spoken aloud — short'
                . ' sentences, no lists, no markdown. The owner has the app open and sees the chat and its widgets.',
            self::WORKING_FOR_AN_EXTERNAL_AGENT => 'An external software agent sends these requests on behalf of the'
                . ' ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '; no person reads the chat live. Answer in plain text the agent can process; ask nothing'
                . ' that waits for a click.',
            self::ABSENT_SCHEDULED_RUN => 'Nobody is present. This is a scheduled task running on its own; the owner'
                . ' reads the result later.',
            self::ABSENT_PLATFORM_RUN => 'Nobody is present. This is the platform\'s own run; its result is read by the'
                . ' platform, not by a person.',
            self::ABSENT_UNKNOWN_ORIGIN => 'Nobody is present in a chat; the result is read later.',
        };
    }
}
