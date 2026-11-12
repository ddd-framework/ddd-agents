<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Channels\AgentCallTaskTexts;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * What the agent core asks of the voice channel (live calls with the owner) — the interim seam of the optional voice
 * component: the channel of a live answered call, which call channels reach the owner right now, and the task texts of a
 * conversation's calls.
 *
 * Every default answers "no voice channel". An application with a voice channel puts a same-path subclass under its own
 * root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentVoiceChannelService::class)`.
 */
class AgentVoiceChannelService extends Service
{
    /**
     * The channel type of the open, answered call of conversation `$conversationId` (the root conversation of a
     * delegation tree), or null when no call is live. The default: null.
     *
     * @param int $conversationId
     * @return string|null e.g. {@see \DDD\Domain\AI\Entities\Conversations\AIConversationOrigin} live-call channel values
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findLiveAnsweredCallChannelType(int $conversationId): ?string
    {
        return null;
    }

    /**
     * Puts the dimensions "which call channel reaches the owner right now" into `$variants` (a copy of the rendered
     * variants, never the working state) when a loaded skill keys sections on them (`$loadedSkillDimensionNames`).
     * Returns true when `$variants` changed. The default changes nothing.
     *
     * @param AIConversation $conversation
     * @param string[] $loadedSkillDimensionNames the dimension names the loaded skills' sections key on
     * @param AIConversationVariants $variants
     * @return bool
     */
    public function putCallReachVariantsInto(AIConversation $conversation, array $loadedSkillDimensionNames, AIConversationVariants $variants): bool
    {
        return false;
    }

    /**
     * Whether `$conversation` has calls and the task texts of those calls. The default: no calls.
     *
     * @param AIConversation $conversation
     * @return AgentCallTaskTexts
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallTaskTextsOfConversation(AIConversation $conversation): AgentCallTaskTexts
    {
        return new AgentCallTaskTexts();
    }
}
