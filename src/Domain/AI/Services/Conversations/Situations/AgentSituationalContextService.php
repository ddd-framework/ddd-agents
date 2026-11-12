<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Situations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The situational texts an application composes for a conversation — the day context (the application's personal
 * text for the day) and the local situation (what happens around the space today) — and the research that fills the
 * latter on the space's first contact of the day. The core persists the texts on the conversation's working state and
 * renders them; composing them is the application's.
 *
 * Every default answers "no situation". An application puts a same-path subclass under its own root namespace; the core
 * resolves this service only through `DDDService::instance()->getService(AgentSituationalContextService::class)`.
 */
class AgentSituationalContextService extends Service
{
    /**
     * The day context text for a NEW conversation. The default: null (no segment).
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function composeDayContextTextForNewConversation(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The local situation text as it stands now. The default: null (no segment).
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function composeLocalSituationText(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * Starts the local-situation research of the space on its first contact of the day (claim-fenced in the
     * application, so a second call of the same day starts nothing). The default does nothing.
     *
     * @param AIConversation $conversation
     * @return void
     */
    public function startLocalSituationResearchOnFirstContact(AIConversation $conversation): void
    {
    }
}
