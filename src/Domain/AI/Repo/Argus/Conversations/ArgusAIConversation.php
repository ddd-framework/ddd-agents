<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use IntlDateFormatter;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @method AIConversation|AIConversationMessage getParent()
 * @method AIConversationMessage toEntity(array $callPath = [], DefaultObject|null &$entityInstance = null)
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: 0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT6_LUNA,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_GENERAL,
    temperature: 0.6,
    systemPromptName: AgentAIPromptNames::APP_AI_SYSTEM_PROMPT_PUBLIC_FACING,
    aiUsageScope: AgentAIUsageScope::AI_CONVERSATION_SEND_MESSAGE,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    // `low`: a chat answers a person who waits; the agent's own turns run at this level
    reasoningEffort: ArgusOpenAIReasoningEffort::LOW,
)]
class ArgusAIConversation extends AIConversation
{
    use ArgusTrait, ArgusAILanguageModelTrait {
        ArgusTrait::fromEntity as fromEntityOfAnAncestorClass;
    }

    protected ?DateTime $aiGenerationStartTime;

    /**
     * Copies the conversation into this Argus object. The Argus classes extend the CORE conversation; an application's
     * same-path subclass of the conversation (the runtime class there) is no ancestor of this class, which
     * the Argus import refuses — so such a conversation is copied through a core view: the properties the core class
     * declares, as far as they are initialized on it (an unset lazy relation stays unloaded, as the import skips it
     * anyway); the application's own relations stay behind (the core asks about the conversation's space through
     * {@see AgentSpacesService}). The parent / children wiring follows the real conversation.
     *
     * @param DefaultObject $entity
     * @return static
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function fromEntity(DefaultObject &$entity): static
    {
        if (!$entity instanceof AIConversation || $this instanceof $entity) {
            return $this->fromEntityOfAnAncestorClass($entity);
        }
        $coreConversationView = $this->buildCoreConversationView($entity);
        $this->fromEntityOfAnAncestorClass($coreConversationView);
        foreach ($this->getCoreConversationReflectionClass()->getProperties() as $coreProperty) {
            $propertyName = $coreProperty->getName();
            if ($coreProperty->isStatic() || !$coreProperty->isInitialized($entity)) {
                continue;
            }
            $propertyValue = $coreProperty->getValue($entity);
            if ($propertyValue instanceof DefaultObject && $propertyValue->getParent() === $entity && isset($this->$propertyName) && $this->$propertyName instanceof DefaultObject) {
                // the import wires children against the view; they are the real conversation's children
                $childOfThisCopy = $this->$propertyName;
                $this->addChildren($childOfThisCopy);
            }
        }
        return $this;
    }

    /**
     * A core-class instance carrying every core property that is initialized on `$conversation` (all visibilities),
     * and its parent.
     *
     * @param AIConversation $conversation
     * @return AIConversation
     * @throws ReflectionException
     */
    protected function buildCoreConversationView(AIConversation $conversation): AIConversation
    {
        $coreConversationReflectionClass = $this->getCoreConversationReflectionClass();
        /** @var AIConversation $coreConversationView */
        $coreConversationView = $coreConversationReflectionClass->newInstanceWithoutConstructor();
        foreach ($coreConversationReflectionClass->getProperties() as $coreProperty) {
            if ($coreProperty->isStatic() || !$coreProperty->isInitialized($conversation)) {
                continue;
            }
            $coreProperty->setValue($coreConversationView, $coreProperty->getValue($conversation));
        }
        if ($conversationParent = $conversation->getParent()) {
            $coreConversationView->setParent($conversationParent);
        }
        return $coreConversationView;
    }

    /** The native reflection of the core conversation class (the class this Argus class extends). */
    protected function getCoreConversationReflectionClass(): \ReflectionClass
    {
        return new \ReflectionClass(AIConversation::class);
    }

    /**
     * Every model call of the conversation is billed to the billing entity of its space, else to its account — set on
     * the instance before the eligibility gate, so the gate never judges this conversation by the process-static
     * default another job left behind. The billing entity comes from
     * {@see AgentSpacesService::findBillingEntityOfConversation()}; a conversation without a space is billed to its
     * account unless the application names an entity for the account level.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function prepareBilledEntitiesBeforeGates(): void
    {
        if ($this->getEntityWithinAccountBilledForAICosts() !== null || $this->getAccountBilledForAICosts() !== null) {
            return;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $billingEntity = $agentSpacesService->findBillingEntityOfConversation($this);
        if ($billingEntity !== null) {
            $this->billAICostsTo(entityWithinAccount: $billingEntity);
            return;
        }
        $account = $this->account;
        if ($account !== null) {
            $this->billAICostsTo(account: $account);
        }
    }


    /**
     * The conversation's prompt with its parameters: the parameters its space contributes (the user's language, the
     * locale and whatever the application adds — {@see AgentSpacesService::applyAgentSpacePromptParameters()}, which
     * answers at account level for a conversation without a space) and the current date in the regional format of
     * the conversation's locale when the prompt asks for it. Bills the call to the billing entity of the space, then
     * to the account.
     *
     * @throws InvalidArgumentException
     * @throws BadRequestException
     * @throws ReflectionException
     * @throws InternalErrorException
     */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $prompt = $this->getAIPrompt();
        $billingEntity = $agentSpacesService->findBillingEntityOfConversation($this);
        if ($billingEntity !== null) {
            $this->billAICostsTo(entityWithinAccount: $billingEntity);
        }
        $account = $this->account;
        if ($account) {
            $this->billAICostsTo(account: $account);
        }
        // the agent's state-delivery template leaves {%AGENT_SPACE_CONTEXT%} for the epoch record to fill
        $agentSpacesService->applyAgentSpacePromptParameters($this, $prompt, $this->buildsAgentSpaceContextIntoThePrompt());
        // Current date in the regional format of the conversation's locale (e.g. USA 12.17.2025, DE 17.12.2025). Only computed when the
        // RESOLVED prompt actually references {%CURRENT_TIMESTAMP%} (General.md does). The agent's Agentic.md
        // deliberately has NO timestamp placeholder: a per-build date() in the system prefix changed the cached bytes
        // every minute and broke prompt caching across turns — the agent reads "now" from the cache-stable
        // [YYYY-MM-DD HH:MM] prefix on the newest user message instead (see ArgusAIAgentConversation::getAIPromptWithParametersApplied).
        if (str_contains($prompt->promptText ?? '', '{%CURRENT_TIMESTAMP%}')) {
            $currentDate = new DateTime();
            $localeString = $agentSpacesService->resolveLocaleString($this);
            $tempFormatter = new IntlDateFormatter(
                $localeString,
                IntlDateFormatter::SHORT,
                IntlDateFormatter::NONE
            );
            $tempPattern = $tempFormatter->getPattern();
            $formatter = new IntlDateFormatter(
                $localeString,
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                null,
                null,
                $tempPattern
            );
            $formattedDate = $formatter->format($currentDate);
            $prompt->setParameter('CURRENT_TIMESTAMP', $formattedDate);
        }
        return $prompt;
    }

    /**
     * The `{%AGENT_SPACE_CONTEXT%}` text of the conversation: the context segment its space contributes
     * ({@see AgentSpacesService::buildAgentSpaceContextPromptSegment()}, resolved through the container, so an
     * application's subclass answers). Shared by the prompt build and the agent's per-epoch state delivery.
     *
     * @return string|null null = the service gives none — its default, with or without a space
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildAgentSpaceContextPromptSegment(): ?string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->buildAgentSpaceContextPromptSegment($this);
    }

    /** Whether the prompt build fills `{%AGENT_SPACE_CONTEXT%}` (always, but for the agent's state-delivery template). */
    protected function buildsAgentSpaceContextIntoThePrompt(): bool
    {
        return true;
    }

    /**
     * @return AIModel|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function getAIModel(): ?\DDD\Domain\AI\Entities\Models\AIModel
    {
        if (isset($this->aiModel)) {
            return $this->aiModel;
        }

        // AIModelsService scope hook: a declared modelScope (e.g. AGENTIC) resolves the task-appropriate model centrally
        // (data-driven from the scored catalog) instead of the hardcoded defaultAIModelName. Takes precedence; the
        // attribute default remains the fallback when the scope resolves nothing eligible. (This override shadows the
        // trait's getAIModel(), so the scope branch must be re-asserted here — otherwise only the leaf summarizer,
        // which uses the trait directly, would be scope-routed and the AGENT would silently stay hardcoded.)
        $argusInstance = $this->getArgusLanguageModelInstance();
        if ($argusInstance !== null && isset($argusInstance->modelScope) && $argusInstance->modelScope !== '') {
            $modelManager = DDDService::instance()->getService(AIModelsService::class);
            $this->aiModel = $modelManager->getModelForScope($argusInstance->modelScope);
            if ($this->aiModel !== null) {
                return $this->aiModel;
            }
        }

        $modelName = $this->getDefaultAIModelNameFromAttribute();
        if ($modelName) {
            $this->aiModel = $this->findAIModelByNameOrThrow($modelName);
        }

        return $this->aiModel;
    }

    /**
     * @return string|null
     * @throws ReflectionException
     */
    public function getDefaultAIModelNameFromAttribute(): ?string
    {
        $argusLanguageModelInstance = $this->getArgusLanguageModelInstance();
        if (!$argusLanguageModelInstance || !isset($argusLanguageModelInstance->defaultAIModelName)) {
            return null;
        }

        $baseModelName = $argusLanguageModelInstance->defaultAIModelName;

        // The application may ask for another model for this conversation (e.g. by its prompt extension); the default keeps it.
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $modelName = $agentSpacesService->findDefaultModelNameOfConversation($this, $baseModelName);

        return $modelName ?: $baseModelName;
    }

    /**
     * @return string|null
     */
    public function getPromptExtensionAlias(): ?string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findPromptExtensionAliasOfConversation($this);
    }

    protected function getPromptVariantNameForUsageRecording(): ?string
    {
        return $this->getPromptExtensionAlias();
    }

    public function getUserContent(): string|array
    {
        $messagesArray = [];
        $hasSystemMessage = false;
        foreach ($this->messages->getElements() as $message) {
            $messagesArray[] = ['role' => strtolower($message->role), 'content' => $message->message];
            $hasSystemMessage = $hasSystemMessage || $message->role === AIConversationMessage::ROLE_SYSTEM;
        }
        // add system prompt message if it was not already added
        if (!$hasSystemMessage) {
            $systemMessage = AIConversationMessage::newInstance();
            $systemMessage->aiConversationId = $this->id;
            $systemMessage->role = AIConversationMessage::ROLE_SYSTEM;
            $systemMessage->message = $this->getAIPromptWithParametersAppliedCached(
            )->getPromptTextWithParametersApplied();
            $systemMessage->getChangeHistory()->createdTime = new DateTime();
            // System message shall have index 0
            $systemMessage->indexInConversation = 0;
            $this->messages->add($systemMessage);
            $systemMessageParams = [
                'role' => 'system',
                'content' => $this->getAIPromptWithParametersAppliedCached()->getPromptTextWithParametersApplied()
            ];
            // add system message as first element of array
            array_unshift($messagesArray, $systemMessageParams);
        }
        $this->aiGenerationStartTime = new DateTime();
        return $messagesArray;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $generatedVersions = null;
        if ($resultText) {
            $cleaned = $this->removeProgrammingLanguageMarkup($resultText);
        } else {
            return;
        }
        $reply = AIConversationMessage::newInstance();
        $reply->aiConversationId = $this->id;
        $reply->role = AIConversationMessage::ROLE_ASSISTANT;
        $reply->getChangeHistory()->createdTime = new DateTime();
        $reply->generationDurationInSeconds = $reply->getChangeHistory()->createdTime->getTimestamp() - $this->aiGenerationStartTime->getTimestamp();
        $reply->message = $cleaned;
        $this->messages->add($reply);
    }
}
