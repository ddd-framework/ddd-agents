<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Entitlements;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentProductAwareness;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The two entitlement axes the agent core gates skills, tools and purposes by — the feature flags of the space and the
 * rights of the acting account — and what the model is told about the product. The flag and right names are opaque
 * strings only the application interprets.
 *
 * Every default answers "ungated": no flags (null), no rights (null), an empty product awareness, texts unchanged. An
 * application puts a same-path subclass under its own root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AIConversationEntitlementsService::class)`.
 */
class AIConversationEntitlementsService extends Service
{
    /**
     * The feature flags that hold for the conversation (those of its space, or of its account without one). The default: null (ungated).
     *
     * @param AIConversation $conversation
     * @return AgentFeatureFlagsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findFeatureFlagsOfConversation(AIConversation $conversation): ?AgentFeatureFlagsInterface
    {
        return null;
    }

    /**
     * The feature flags of `$agentSpace`, asked where no conversation is at hand (a tool call that targets a space,
     * a purposed start). The default: null (ungated).
     *
     * @param AgentSpace $agentSpace
     * @return AgentFeatureFlagsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findFeatureFlagsOfAgentSpace(AgentSpace $agentSpace): ?AgentFeatureFlagsInterface
    {
        return null;
    }

    /**
     * The feature flags that hold for work of `$account` WITHOUT a space (account level), asked where no conversation
     * is at hand. The default: null (ungated). A caller that gates a tool on a flag refuses when this answers null:
     * a gate that cannot be checked is never passed.
     *
     * @param Account $account
     * @return AgentFeatureFlagsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findFeatureFlagsOfAccount(Account $account): ?AgentFeatureFlagsInterface
    {
        return null;
    }

    /**
     * The rights of the account the conversation acts for. The default: null (ungated).
     *
     * @param AIConversation $conversation
     * @return AgentAccountRightsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAccountRightsOfConversation(AIConversation $conversation): ?AgentAccountRightsInterface
    {
        return null;
    }

    /**
     * The rights of the authenticated account of the current request. The default: null (ungated).
     *
     * @return AgentAccountRightsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAccountRightsOfCurrentAccount(): ?AgentAccountRightsInterface
    {
        return null;
    }

    /**
     * Whether the model may be told the exact AI budget figures of the conversation. The default: true.
     *
     * @param AIConversation $conversation
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function isExactAIBudgetReportable(AIConversation $conversation): bool
    {
        return true;
    }

    /**
     * Whether the conversation's account may see and change billing (a skill or purpose that requires billing access is
     * offered only then). The default: null (unknown — the account model of the framework carries no billing right).
     *
     * @param AIConversation $conversation
     * @return bool|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAccountBillingAccessOfConversation(AIConversation $conversation): ?bool
    {
        return null;
    }

    /**
     * What the model is told about the customer's product (capabilities, restricted capabilities, AI budget policy). The
     * default: an empty awareness (every segment omitted).
     *
     * @param AIConversation $conversation
     * @return AgentProductAwareness
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildProductAwareness(AIConversation $conversation): AgentProductAwareness
    {
        return new AgentProductAwareness();
    }

    /**
     * `$text` with the product names replaced by the names the conversation's owner knows them by. The default returns
     * the text unchanged.
     *
     * @param AIConversation $conversation
     * @param string $text
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function localizeProductNamesInText(AIConversation $conversation, string $text): string
    {
        return $text;
    }
}
