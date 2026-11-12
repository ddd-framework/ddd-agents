<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Who started a conversation and who is present in it: the initiator type, the
 * purpose, the delegation anchor and tree root, the schedule / supervision link and the frozen zone — copied from the
 * conversation's loaded columns by {@see AIConversation::getOrigin()}. Reads nothing; the two questions that need the
 * world stay on the conversation (the live-call probe of {@see AIConversation::getOwnerPresence()} and the root read
 * of {@see AIConversation::isHiddenFromCustomer()}).
 */
class AIConversationOrigin extends ValueObject
{
    /** The channel type of a live call over the phone network (the value the voice channel stores on its calls). */
    public const string LIVE_CALL_CHANNEL_PHONE = 'PHONE';

    /** The channel type of a live voice call inside the app. */
    public const string LIVE_CALL_CHANNEL_APP = 'APP';

    /** Why a conversation is not the owner's own conversation: it is a delegation child (the platform's helper). */
    public const string NOT_THE_OWNER_DELEGATION_CHILD = 'DELEGATION_CHILD';

    /** … it is the supervisor's conversation about another conversation. */
    public const string NOT_THE_OWNER_SUPERVISOR = 'SUPERVISOR';

    /** … its purpose is the platform's own hidden work ({@see AIConversationPurpose::CUSTOMER_HIDDEN_PURPOSE_NAMES}). */
    public const string NOT_THE_OWNER_PLATFORM_RUN = 'PLATFORM_RUN';

    /** … the platform started it as a user-absent run ({@see AIConversation::getOwnerAbsentInitiatorTypes()}). */
    public const string NOT_THE_OWNER_USER_ABSENT_RUN = 'USER_ABSENT_RUN';

    /** @var string|null {@see AIConversation::$initiatorType}; null = a dialog with the owner. */
    public ?string $initiatorType = null;

    /** @var string|null {@see AIConversation::$purposeName}. */
    public ?string $purposeName = null;

    /** @var int|null The conversation's own id; null while it is not persisted. */
    public ?int $conversationId = null;

    /** @var int|null {@see AIConversation::$delegatedFromMessageId}: the delegate call that started this child. */
    public ?int $delegatedFromMessageId = null;

    /** @var int|null {@see AIConversation::$rootConversationId}: the root of the delegation tree; null on a root. */
    public ?int $rootConversationId = null;

    /** @var int|null {@see AIConversation::$scheduleId}: the schedule whose firing started this run. */
    public ?int $scheduleId = null;

    /** @var int|null {@see AIConversation::$supervisedConversationId}: the conversation this supervisor watches. */
    public ?int $supervisedConversationId = null;

    /** @var string|null {@see AIConversation::$timezone}: the zone a schedule-bound conversation froze. */
    public ?string $timezone = null;

    /** Whether this conversation is a sub-agent delegation (vs a root dialog with a human): it has a delegation anchor. */
    public function isDelegation(): bool
    {
        return $this->delegatedFromMessageId !== null;
    }

    /** Whether this conversation sits BELOW the root of its delegation tree: a stored root that is not its own id. */
    public function isDelegationChild(): bool
    {
        return $this->rootConversationId !== null && $this->rootConversationId !== (int)($this->conversationId ?? 0);
    }

    /**
     * Whether this conversation thinks in its OWN frozen zone: a stored zone together with the schedule provenance (a
     * schedule run, the supervisor tandem of one, or a delegation child of one).
     */
    public function isScheduleBound(): bool
    {
        return $this->timezone !== null
            && ($this->scheduleId !== null || $this->supervisedConversationId !== null || $this->isDelegation());
    }

    /** Whether a live owner is in this run: a dialog (no initiator) or a phone call ({@see AIConversation::isUserPresentRun()}). */
    public function isUserPresentRun(): bool
    {
        return $this->initiatorType === null || $this->initiatorType === AIConversation::INITIATOR_TYPE_PHONE_CALL;
    }

    /** Whether a CUSTOMER is present in this run: never under a hidden purpose, else {@see isUserPresentRun()}. */
    public function runsWithCustomerPresent(): bool
    {
        if (AIConversationPurpose::isCustomerHiddenPurposeName($this->purposeName)) {
            return false;
        }
        return $this->isUserPresentRun();
    }

    /**
     * Why this conversation is NOT the owner's own conversation (one of the `NOT_THE_OWNER_*` constants), or null when
     * it is one (a chat, a call, a visible purpose): checked in this order — delegation child, supervisor, the
     * platform's hidden purpose, a user-absent initiator. An application asks it to decide whose words it takes as
     * the owner's.
     *
     * @return self::NOT_THE_OWNER_*|null
     */
    public function getReasonItIsNotTheOwnersConversation(): ?string
    {
        if ($this->isDelegation()) {
            return self::NOT_THE_OWNER_DELEGATION_CHILD;
        }
        if ($this->supervisedConversationId !== null) {
            return self::NOT_THE_OWNER_SUPERVISOR;
        }
        if (AIConversationPurpose::isCustomerHiddenPurposeName($this->purposeName)) {
            return self::NOT_THE_OWNER_PLATFORM_RUN;
        }
        if ($this->initiatorType !== null && in_array($this->initiatorType, AIConversation::getOwnerAbsentInitiatorTypes(), true)) {
            return self::NOT_THE_OWNER_USER_ABSENT_RUN;
        }
        return null;
    }

    /**
     * Whether the platform runs this conversation for itself: a hidden purpose, or an initiator type the application
     * declares as one of its own runs ({@see AIConversation::getPlatformOwnRunInitiatorTypes()}).
     */
    public function isPlatformOwnRun(): bool
    {
        return AIConversationPurpose::isCustomerHiddenPurposeName($this->purposeName)
            || ($this->initiatorType !== null && in_array($this->initiatorType, AIConversation::getPlatformOwnRunInitiatorTypes(), true));
    }

    /**
     * Whether the conversation itself is a phone call with the owner: its purpose is held as one
     * ({@see AIConversationPurpose::isPurposeNameHeldAsAPhoneCall()}) and nothing else initiated it.
     */
    public function isHeldAsAPhoneCallByItsPurpose(): bool
    {
        return $this->initiatorType === null && AIConversationPurpose::isPurposeNameHeldAsAPhoneCall($this->purposeName);
    }

    /**
     * Whether {@see buildOwnerPresence()} reads the live call: a delegation child always (its root's call), the
     * platform's own run and a conversation held as a phone call by its purpose never, every other conversation always.
     */
    public function readsTheLiveCallForOwnerPresence(): bool
    {
        if ($this->isDelegation()) {
            return true;
        }
        return !$this->isPlatformOwnRun() && !$this->isHeldAsAPhoneCallByItsPurpose();
    }

    /**
     * Who the agent is talking to, from the origin, the channel type of
     * the answered call on the conversation right now (`$liveCallChannelType`, null without one) and the device of the
     * newest owner text row in the context (`$newestOwnerDevice`, null when none carries one). The rules and their
     * order are documented on {@see AIConversation::getOwnerPresence()}. Pure.
     */
    public function buildOwnerPresence(?string $liveCallChannelType, ?OwnerDevice $newestOwnerDevice): ?OwnerPresence
    {
        if ($this->isDelegation()) {
            return $liveCallChannelType === self::LIVE_CALL_CHANNEL_PHONE
                ? OwnerPresence::PRESENT_ON_THE_PHONE
                : null;
        }
        if ($this->isPlatformOwnRun()) {
            return OwnerPresence::ABSENT_PLATFORM_RUN;
        }
        if ($this->isHeldAsAPhoneCallByItsPurpose()) {
            return OwnerPresence::PRESENT_ON_THE_PHONE;
        }
        $liveCallPresence = self::buildPresenceForLiveCallChannelType($liveCallChannelType);
        if ($this->initiatorType === AIConversation::INITIATOR_TYPE_PHONE_CALL) {
            if ($liveCallPresence !== null) {
                return $liveCallPresence;
            }
            return $newestOwnerDevice !== null && $newestOwnerDevice->kind !== OwnerDevice::KIND_PHONE_CALL && $newestOwnerDevice->kind !== OwnerDevice::KIND_UNKNOWN
                ? OwnerPresence::PRESENT_IN_CHAT
                : OwnerPresence::ABSENT_UNKNOWN_ORIGIN;
        }
        if ($this->initiatorType === AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT) {
            return OwnerPresence::WORKING_FOR_AN_EXTERNAL_AGENT;
        }
        if (in_array($this->initiatorType, [AIConversation::INITIATOR_TYPE_SCHEDULE, AIConversation::INITIATOR_TYPE_ADMIN_TASK], true)
            || ($this->purposeName === AIConversationPurpose::SCHEDULE_RUN && $this->initiatorType === null)) {
            return OwnerPresence::ABSENT_SCHEDULED_RUN;
        }
        if ($this->initiatorType === null) {
            return $liveCallPresence ?? OwnerPresence::PRESENT_IN_CHAT;
        }
        return OwnerPresence::ABSENT_UNKNOWN_ORIGIN;
    }

    /** The presence a live call of `$channelType` implies, or null without a live call. */
    public static function buildPresenceForLiveCallChannelType(?string $channelType): ?OwnerPresence
    {
        return match ($channelType) {
            null => null,
            self::LIVE_CALL_CHANNEL_APP => OwnerPresence::PRESENT_IN_APP_VOICE_CALL,
            default => OwnerPresence::PRESENT_ON_THE_PHONE,
        };
    }
}
