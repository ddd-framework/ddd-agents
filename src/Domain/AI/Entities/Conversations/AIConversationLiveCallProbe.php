<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of one look-up "is a call answered on this conversation right now": when it was made and the channel
 * type of the call it found. {@see AIConversation} keeps the last one in memory so the steps of one turn share a
 * look-up instead of repeating it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationLiveCallProbe extends ValueObject
{
    /** @var int When the look-up was made, unix time */
    public int $probedAtUnixTime = 0;

    /**
     * @var string|null The live-call channel type ({@see AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE} /
     *      `…_APP`) of the answered call; null when no call was answered
     */
    public ?string $liveCallChannelType = null;

    /**
     * @param string|null $liveCallChannelType The channel type the look-up found, or null
     * @return static A probe made at this moment
     */
    public static function forChannelTypeProbedNow(?string $liveCallChannelType): static
    {
        return static::forChannelTypeProbedAtUnixTime($liveCallChannelType, time());
    }

    /**
     * @param string|null $liveCallChannelType The channel type the look-up found, or null
     * @param int $probedAtUnixTime When the look-up was made; a test sets a later time to keep the answer in use
     * @return static
     */
    public static function forChannelTypeProbedAtUnixTime(?string $liveCallChannelType, int $probedAtUnixTime): static
    {
        $liveCallProbe = new static();
        $liveCallProbe->probedAtUnixTime = $probedAtUnixTime;
        $liveCallProbe->liveCallChannelType = $liveCallChannelType;
        return $liveCallProbe;
    }

    /**
     * @param int $maximumAgeSeconds
     * @return bool Whether the look-up was made less than `$maximumAgeSeconds` ago
     */
    public function isYoungerThanSeconds(int $maximumAgeSeconds): bool
    {
        return time() - $this->probedAtUnixTime < $maximumAgeSeconds;
    }
}
