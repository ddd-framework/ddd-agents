<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentOwnerAddressingArguments;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceToolArgument;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceConsoleScopeOptions;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaces;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Languages\AgentLanguageNames;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaces;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\Attributes\DatabaseTranslation;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * What the agent core asks about the thing a conversation works for (see {@see AgentSpace}): its time zone, its
 * language, its owner and billing entity, the prompt parameters and segments it contributes, and whether an account
 * may use it.
 *
 * THE ONE PLACE THE CORE ASKS. The core never reads a fact from an application entity and never from a space
 * directly. It asks here and passes the conversation, or the space where no conversation exists yet. Each default
 * hands the question to the space ({@see AgentSpace::findTimezone()} and its siblings).
 *
 * WITHOUT A SPACE. A conversation without a space works for the account as a whole. That is a regular case — the only
 * one in an application without spaces, and one next to the spaces in an application that has them. Every method
 * here has an answer for it ("not known", or the account's), and none throws because the space is missing; whether
 * such work may start at all is the application's decision in
 * {@see self::requireConversationStartWithoutAgentSpaceAllowed()}.
 *
 * WHERE AN APPLICATION ANSWERS. A fact its space reads off its own relations: override the method of its
 * {@see AgentSpace} subclass. A fact that needs a query or another service, a prompt segment, or an answer for a
 * conversation without a space: override the method here, in a same-path subclass under its own root namespace. The
 * core resolves this service only through the container, which returns the subclass.
 *
 * The package never creates, copies or deletes a space; the application does.
 *
 * @method AgentSpace|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentSpaces findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentSpace getEntityRepoClassInstance()
 * @method DBAgentSpaces getEntitySetRepoClassInstance()
 */
class AgentSpacesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = AgentSpace::class;

    /** @var string The refusal text when the account may not use the space */
    public const string NO_ACCESS_TO_AGENT_SPACE_MESSAGE = 'No access to the requested space';

    /** @var string The refusal text when a schedule without a space is created in an application that allows none */
    public const string SCHEDULE_WITHOUT_AGENT_SPACE_NOT_ALLOWED_MESSAGE = 'A schedule needs a space.';

    /** @var string The prefix of the prompt-cache routing key of a conversation of a space; the id of the space follows */
    public const string PROMPT_CACHE_ROUTING_KEY_PREFIX_OF_AGENT_SPACE = 'agent-space-';

    /** @var string The prefix of the prompt-cache routing key of a conversation without a space; the id of its account follows */
    public const string PROMPT_CACHE_ROUTING_KEY_PREFIX_OF_ACCOUNT = 'agent-account-';

    /** @var string The tool argument by which a tool of the package names the space a call targets */
    public const string TOOL_ARGUMENT_AGENT_SPACE_ID = 'agentSpaceId';

    /**
     * @var string The value of a filled argument ({@see TOOL_ARGUMENT_AGENT_SPACE_ID}) that says "do not narrow": the
     * executor leaves the argument out instead of filling it from the conversation's space, so the tool runs as it
     * runs without the argument (at account level, or across what the tool's own filter allows). Only a positive id
     * names a space; 0 and an empty value still mean "the conversation's space".
     */
    public const string TOOL_ARGUMENT_VALUE_NOT_NARROWED_TO_AGENT_SPACE = 'none';

    /**
     * @var string The description prefix of a filled argument that also offers {@see TOOL_ARGUMENT_VALUE_NOT_NARROWED_TO_AGENT_SPACE}.
     * Not used by default (the default prefix stays as it is); an application whose tools read across spaces returns
     * it from {@see getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpaceOfConversation()} for those arguments.
     */
    public const string DESCRIPTION_PREFIX_OF_TOOL_ARGUMENT_FILLED_FROM_AGENT_SPACE_OR_NOT_NARROWED = 'OPTIONAL — omit it: it defaults to what this conversation works for. Pass "none" to not narrow to one; pass an id ONLY to target another one.';

    /** @var string What the model reads in front of the description of a tool argument that is filled from the conversation's space */
    public const string DESCRIPTION_PREFIX_OF_TOOL_ARGUMENT_FILLED_FROM_AGENT_SPACE = 'OPTIONAL — omit it: it defaults to what this conversation works for. Pass it ONLY to target another one.';

    /**
     * Throws when `$account` may not use `$agentSpace`; returns quietly when it may. Asked before a conversation, a
     * schedule or a mandate is stored with the space (and before its space is set), never only in an endpoint: the
     * database does not enforce that the space of a row is one its account may use.
     *
     * The default: the space belongs to the account, or the account reads every space
     * ({@see AgentAccountRightsService::isAccountReaderOfAllAgentSpaces()}). An
     * application whose spaces are anchored on an entity of its own decides by its rights on that entity.
     *
     * @param AgentSpace $agentSpace
     * @param Account $account the account the row is stored for
     * @return void
     * @throws ForbiddenException
     */
    public function requireAgentSpaceUsableByAccount(AgentSpace $agentSpace, Account $account): void
    {
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        if ($agentAccountRightsService->isAccountReaderOfAllAgentSpaces($account)) {
            return;
        }
        if (isset($agentSpace->accountId) && isset($account->id) && (int)$agentSpace->accountId === (int)$account->id) {
            return;
        }
        throw new ForbiddenException(static::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
    }

    /**
     * The time zone of the conversation's space, read now (the conversation keeps its zone as an in-memory snapshot).
     *
     * @param AIConversation $conversation
     * @return AgentSpaceTimezone|null null = not known (the conversation keeps its own fields, else UTC)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findTimezoneOfConversation(AIConversation $conversation): ?AgentSpaceTimezone
    {
        $agentSpace = $conversation->agentSpace ?? null;
        return $agentSpace !== null ? $agentSpace->findTimezone() : null;
    }

    /**
     * Throws when a conversation WITHOUT a space may not be started; returns quietly when it may. Asked before such a
     * conversation is created, with what is known of it then.
     *
     * A conversation without a space is a regular case, also in an application that has spaces (work for the account
     * as a whole, across the things it owns). The default allows it. An application whose checks hang on its space
     * (a product, a subscription) refuses here until it can answer them for the account, and opens it per purpose or
     * entry point.
     *
     * @param Account $account the account the conversation is started for
     * @param string|null $purposeName the purpose of the conversation, null for a conversation without one
     * @param string|null $entryPointKey the entry point it is started from, null when none is named
     * @return void
     * @throws BadRequestException
     * @throws ForbiddenException
     */
    public function requireConversationStartWithoutAgentSpaceAllowed(Account $account, ?string $purposeName, ?string $entryPointKey): void
    {
    }

    /**
     * The prompt-cache routing key of `$conversation`: the value sent with every model request of the conversation,
     * by which the model provider groups the cached beginnings of prompts. One key per space; a conversation without
     * a space shares the key of its account; null without either. The default builds it from the two ids the
     * conversation carries and loads nothing.
     *
     * An application that sent keys before its conversations worked for a space overrides this and keeps sending
     * the same characters: a changed key starts every prompt cache cold, and a recorded request no longer equals its
     * replay.
     *
     * @param AIConversation $conversation
     * @return string|null
     */
    public function findPromptCacheRoutingKeyOfConversation(AIConversation $conversation): ?string
    {
        $agentSpaceId = $conversation->agentSpaceId ?? null;
        if ($agentSpaceId !== null) {
            return static::PROMPT_CACHE_ROUTING_KEY_PREFIX_OF_AGENT_SPACE . $agentSpaceId;
        }
        $accountId = $conversation->accountId ?? null;
        return $accountId !== null ? static::PROMPT_CACHE_ROUTING_KEY_PREFIX_OF_ACCOUNT . $accountId : null;
    }

    /**
     * Whether `$schedule`, which has no space, may exist: be created, and run when its occurrence is due. The default
     * allows it. A run of such a schedule is a conversation without a space, so an application that refuses those
     * ({@see self::requireConversationStartWithoutAgentSpaceAllowed()}) answers false here: the creation is refused
     * with {@see self::SCHEDULE_WITHOUT_AGENT_SPACE_NOT_ALLOWED_MESSAGE}, and a stored schedule without a space is
     * skipped and closed when its occurrence is due instead of starting a run that would be refused.
     *
     * @param AIConversationSchedule $schedule the schedule about to be stored (its account set), or a stored one
     * @return bool
     */
    public function isScheduleWithoutAgentSpaceAllowed(AIConversationSchedule $schedule): bool
    {
        return true;
    }

    /**
     * Throws when `$schedule`, which has no space, may not be created; returns quietly when it may
     * ({@see self::isScheduleWithoutAgentSpaceAllowed()}).
     *
     * @param AIConversationSchedule $schedule the schedule about to be stored, its account set
     * @return void
     * @throws BadRequestException
     */
    public function requireScheduleWithoutAgentSpaceAllowed(AIConversationSchedule $schedule): void
    {
        if (!$this->isScheduleWithoutAgentSpaceAllowed($schedule)) {
            throw new BadRequestException(static::SCHEDULE_WITHOUT_AGENT_SPACE_NOT_ALLOWED_MESSAGE);
        }
    }

    /**
     * The time zone a NEW schedule is created in (its provenance included): the zone of its space.
     *
     * @param AIConversationSchedule $schedule the schedule about to be stored
     * @return AgentSpaceTimezone|null null = not known, or the schedule has no space (it runs in UTC unless its
     *                                 creator named a zone); an application may answer from the account
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findTimezoneForNewSchedule(AIConversationSchedule $schedule): ?AgentSpaceTimezone
    {
        $agentSpace = $schedule->agentSpace ?? null;
        return $agentSpace !== null ? $agentSpace->findTimezone() : null;
    }

    /**
     * The account that owns `$agentSpace`. A caller that needs the owner only when nothing else decides the account
     * loads the space first and asks this when it gets there.
     *
     * @param AgentSpace $agentSpace
     * @return Account|null null = no owner known (callers refuse or fall back as for a conversation without a space)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findOwnerAccountOfAgentSpace(AgentSpace $agentSpace): ?Account
    {
        return $agentSpace->findOwnerAccount();
    }

    /**
     * The console options by which an application names the space of a console command in its own words (the id of
     * its own entity a space stands for), declared on every package command that takes a space beside the package's
     * own `--agentSpaceId`. Each option takes a value; the command passes the values given to
     * {@see self::findAgentSpaceForConsoleScopeOptionValues()}. The default declares none.
     *
     * @return AgentSpaceConsoleScopeOptions
     */
    public function getConsoleScopeOptions(): AgentSpaceConsoleScopeOptions
    {
        return new AgentSpaceConsoleScopeOptions();
    }

    /**
     * The space the values of the application's console options name ({@see self::getConsoleScopeOptions()}). Asked
     * only when at least one of those options has a value and `--agentSpaceId` has none. A space that does not exist
     * yet is not created here: null makes the command refuse the values. The default declares no option and answers
     * null.
     *
     * @param array<string, string> $optionValuesByName the non-empty values, by option name
     * @return AgentSpace|null null = no space for these values
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAgentSpaceForConsoleScopeOptionValues(array $optionValuesByName): ?AgentSpace
    {
        return null;
    }

    /**
     * Loads and attaches what the space of `$conversation` needs for a run (the relations its facts are read from).
     * The default attaches nothing: the space loads lazily, and only the application knows the relations of its
     * subclass.
     *
     * @param AIConversation $conversation
     * @param bool $bypassRegistry true = a fresh read past the entity registry
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function attachAgentSpaceRelations(AIConversation $conversation, bool $bypassRegistry): void
    {
    }

    /**
     * The entity within the account a model call of `$conversation` is billed to (it may load it).
     *
     * @param AIConversation $conversation
     * @return Entity|null null = the caller bills the account
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findBillingEntityOfConversation(AIConversation $conversation): ?Entity
    {
        $agentSpace = $conversation->agentSpace ?? null;
        return $agentSpace !== null ? $agentSpace->findBillingEntity() : null;
    }

    /**
     * The billing entity of `$conversation` ONLY when its space is already loaded and has it loaded — never a query
     * (the decision and guard builders call it on every model call).
     *
     * @param AIConversation $conversation
     * @return Entity|null
     */
    public function findLoadedBillingEntityOfConversation(AIConversation $conversation): ?Entity
    {
        // isset() does not trigger the lazy load: a space that is not loaded yet answers "none" here
        if (!isset($conversation->agentSpace)) {
            return null;
        }
        return $conversation->agentSpace->findLoadedBillingEntity();
    }

    /**
     * The language code the conversation's user works in (the interface language — the agent's REPLY language is
     * decided separately): the language the conversation confirmed (working state), else the language of its space,
     * else the application's default language.
     *
     * @param AIConversation $conversation
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolveUserLanguageCode(AIConversation $conversation): string
    {
        $agentWorkingState = $conversation->getAgentWorkingState();
        $confirmedLanguageCode = $agentWorkingState !== null ? $agentWorkingState->interfaceLanguageConfirmedCode : null;
        if ($confirmedLanguageCode !== null) {
            return $confirmedLanguageCode;
        }
        $agentSpace = $conversation->agentSpace ?? null;
        $agentSpaceLanguageCode = $agentSpace !== null ? $agentSpace->findLanguageCode() : null;
        return $agentSpaceLanguageCode ?? DatabaseTranslation::getDefaultLanguageCode();
    }

    /**
     * The ICU locale string dates of the conversation are formatted in (e.g. `de_DE`): the locale of its space, else
     * the user's language code.
     *
     * @param AIConversation $conversation
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolveLocaleString(AIConversation $conversation): string
    {
        $agentSpace = $conversation->agentSpace ?? null;
        $agentSpaceLocaleString = $agentSpace !== null ? $agentSpace->findLocaleString() : null;
        return $agentSpaceLocaleString ?? $this->resolveUserLanguageCode($conversation);
    }

    /**
     * The instruction how the agent addresses the owner (register, formality) in `$spokenLanguageCode` (null = the
     * written conversation). The default gives none. An application's answer may throw (the caller's contract: the
     * instruction or an exception it catches).
     *
     * @param AIConversation $conversation
     * @param string|null $spokenLanguageCode
     * @return string|null
     * @throws Throwable when the application cannot build the instruction
     */
    public function buildOwnerAddressingInstruction(AIConversation $conversation, ?string $spokenLanguageCode = null): ?string
    {
        return null;
    }

    /**
     * The two inputs of the owner addressing for the conversation: the register the settings of its space ask for,
     * and whether the space runs the agent with the informal register as its default. ONE resolver for the chat
     * prompt, a voice briefing and an addressing tool; the prompt cache fingerprints the answer. The default knows no
     * such settings: no register, formal by default.
     *
     * @param AIConversation $conversation
     * @return AgentOwnerAddressingArguments
     */
    public function resolveOwnerAddressingArguments(AIConversation $conversation): AgentOwnerAddressingArguments
    {
        return AgentOwnerAddressingArguments::forWritingStyleAndInformalDefault(null, false);
    }

    /**
     * What the prompt build reads of the conversation's space that changes WITHOUT a write path bumping the inputs
     * version of the space — the values the prompt cache keys fingerprint, so that a change gives a new key. The
     * default has none: the package knows no such input. An application whose prompt names a fact of its space that
     * another system writes returns it here, read as its prompt build reads it. Asked for every key (on every turn),
     * so it must stay cheap; it gets a conversation without a space too and answers what it reads at account level,
     * else nothing.
     *
     * @param AIConversation $conversation
     * @return array<string, mixed> the values by a name of the application's choice; [] = none
     */
    public function findUnversionedPromptInputsOfConversation(AIConversation $conversation): array
    {
        return [];
    }

    /**
     * The stored facts about the conversation's space the decision questions read, one line each. The default has
     * none.
     *
     * @param AIConversation $conversation
     * @return string[]
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildStoredFactLines(AIConversation $conversation): array
    {
        return [];
    }

    /**
     * Whether the agent may recall the owner's other conversations. The default allows it.
     *
     * @param AIConversation $conversation
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function isCrossConversationRecallEnabled(AIConversation $conversation): bool
    {
        return true;
    }

    /**
     * Whether the channel `$connectedChannel` of `$agentSpace` is connected. The default does not know (null) — never
     * "connected".
     *
     * @param AgentSpace $agentSpace
     * @param string $connectedChannel the application's channel name
     * @return bool|null null = unknown
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findChannelConnectionState(AgentSpace $agentSpace, string $connectedChannel): ?bool
    {
        return null;
    }

    /**
     * Sets the parameters the conversation's space contributes to its prompt. The default: `AGENT_SPACE_ID` (the id
     * the package's tools take as {@see self::TOOL_ARGUMENT_AGENT_SPACE_ID}; empty for a conversation without a
     * space), `USER_LANGUAGE` (the English name and the code of {@see resolveUserLanguageCode()}) and
     * `USER_LANGUAGE_CODE` (the code). `$withSpaceContext` = the prompt also carries the context segment of the space
     * as `AGENT_SPACE_CONTEXT` ({@see buildAgentSpaceContextPromptSegment()}; empty in the default, so the model never
     * reads a literal placeholder). An application whose prompt names the space by an id of its own (the id of its
     * own entity) sets that parameter in its override.
     *
     * @param AIConversation $conversation
     * @param AIPrompt $prompt
     * @param bool $withSpaceContext
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function applyAgentSpacePromptParameters(AIConversation $conversation, AIPrompt $prompt, bool $withSpaceContext): void
    {
        // the scalar id, no load of the space
        $agentSpaceId = $conversation->agentSpaceId ?? null;
        $prompt->setParameter('AGENT_SPACE_ID', $agentSpaceId !== null ? (string)$agentSpaceId : '');
        $userLanguageCode = $this->resolveUserLanguageCode($conversation);
        $prompt->setParameter(
            'USER_LANGUAGE',
            (AgentLanguageNames::englishNameForLanguageCode($userLanguageCode) ?? strtoupper($userLanguageCode)) . ' ("' . $userLanguageCode . '")'
        );
        $prompt->setParameter('USER_LANGUAGE_CODE', $userLanguageCode);
        if ($withSpaceContext) {
            $prompt->setParameter('AGENT_SPACE_CONTEXT', (string)$this->buildAgentSpaceContextPromptSegment($conversation));
        }
    }

    /**
     * The context segment the space contributes to the conversation's prompt (`{%AGENT_SPACE_CONTEXT%}`). The default
     * has none.
     * Called on a cache miss of {@see \DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService::resolveAgentSpaceContext()},
     * which is the first read after a write: an override reads the host's records fresh in here, never an object
     * loaded earlier in the process.
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildAgentSpaceContextPromptSegment(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The context segment the conversation SUMMARY prompt carries (`{%AGENT_SPACE_CONTEXT%}` of the summary) — its own
     * shape, which may differ from the conversation's prompt segment. The default has none.
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildConversationSummaryAgentSpaceContextPromptSegment(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The model the conversation asks for instead of `$baseModelName`. The default keeps `$baseModelName`.
     *
     * @param AIConversation $conversation
     * @param string $baseModelName
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findDefaultModelNameOfConversation(AIConversation $conversation, string $baseModelName): string
    {
        return $baseModelName;
    }

    /**
     * The alias of the conversation's prompt extension (the usage recording names the prompt variant by it). The
     * default has none.
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findPromptExtensionAliasOfConversation(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The product name the owner knows the platform by (a white label). The default has none.
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findProductNameOfConversation(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The name of the company operating the product the owner uses. The default has none.
     *
     * @param AIConversation $conversation
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findProductOperatorNameOfConversation(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The space a tool call TARGETS: the one an argument of the call names, else the space of the conversation the
     * call runs in; null = the call works at account level (no argument, and a conversation without a space or no
     * conversation at all). The executor's checks (automation eligibility, mandate, feature flags) and the tools of
     * the package ask this, so that an argument that names another space is checked like the conversation's own.
     *
     * The default reads the package's own argument {@see self::TOOL_ARGUMENT_AGENT_SPACE_ID}. A space named by an
     * argument must be usable by the account the call acts for (the account of the conversation, else the
     * authenticated one) — the read rights alone do not decide it: they follow the authenticated account, which is
     * not always the account the call acts for (an admin who drives a customer's conversation reads every space). An
     * application whose tools name the target by an id of its own entity resolves that argument here, with the same
     * check.
     *
     * @param array<array-key, mixed> $toolArguments the arguments of the call as the model passed them
     * @param AgentToolCallProvenance|null $agentToolCallProvenance null on the HTTP-MCP path
     * @return AgentSpace|null
     * @throws BadRequestException
     * @throws ForbiddenException when the argument names a space that does not exist or that the account may not use
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAgentSpaceOfToolCall(array $toolArguments, ?AgentToolCallProvenance $agentToolCallProvenance): ?AgentSpace
    {
        $agentSpaceOfConversation = $agentToolCallProvenance !== null ? $agentToolCallProvenance->agentSpace : null;
        $requestedAgentSpaceId = $this->findRequestedAgentSpaceIdOfToolArguments($toolArguments);
        if ($requestedAgentSpaceId <= 0) {
            return $agentSpaceOfConversation;
        }
        if ($agentSpaceOfConversation !== null && isset($agentSpaceOfConversation->id) && (int)$agentSpaceOfConversation->id === $requestedAgentSpaceId) {
            return $agentSpaceOfConversation;
        }
        $requestedAgentSpace = $this->find($requestedAgentSpaceId);
        $conversation = $agentToolCallProvenance !== null ? $agentToolCallProvenance->conversation : null;
        $account = ($conversation !== null ? ($conversation->account ?? null) : null) ?? AuthService::instance()->getAccount();
        if (!$requestedAgentSpace instanceof AgentSpace || $account === null) {
            throw new ForbiddenException(static::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        }
        $this->requireAgentSpaceUsableByAccount($requestedAgentSpace, $account);
        return $requestedAgentSpace;
    }

    /**
     * The space id the arguments of a call name: the argument under the application's name
     * ({@see AgentSpaceToolArgument}, `AI.agentSpaceToolArgument`) when it holds a positive id, else
     * {@see TOOL_ARGUMENT_AGENT_SPACE_ID}; 0 = none named. An application whose argument holds an id of its own entity
     * overrides {@see findAgentSpaceOfToolCall()} and reads the argument there.
     *
     * @param array<array-key, mixed> $toolArguments
     */
    public function findRequestedAgentSpaceIdOfToolArguments(array $toolArguments): int
    {
        $agentSpaceToolArgument = AgentSpaceToolArgument::current();
        if ($agentSpaceToolArgument->isRenamed()) {
            $requestedAgentSpaceId = $toolArguments[$agentSpaceToolArgument->name] ?? null;
            if (is_numeric($requestedAgentSpaceId) && (int)$requestedAgentSpaceId > 0) {
                return (int)$requestedAgentSpaceId;
            }
        }
        $requestedAgentSpaceId = $toolArguments[static::TOOL_ARGUMENT_AGENT_SPACE_ID] ?? null;
        return is_numeric($requestedAgentSpaceId) ? max(0, (int)$requestedAgentSpaceId) : 0;
    }

    /**
     * `$toolArguments` with the arguments the conversation's space fills when the model left them out. Called in the
     * agent loop before the entitlement check and before the request DTOs of the tool are filled; never on the
     * HTTP-MCP path (no conversation, the client names its target itself). An argument the model passed always wins.
     *
     * The default fills {@see self::TOOL_ARGUMENT_AGENT_SPACE_ID} when a request DTO of the tool declares it. An
     * application fills the argument of its own tools the same way (its entity's id, read off `$agentSpace`).
     *
     * @param array<array-key, mixed> $toolArguments
     * @param string[] $parameterClassNames the classes of the tool method's parameters (its request DTOs among them)
     * @param AgentSpace $agentSpace the space of the conversation the call runs in
     * @return array<array-key, mixed>
     */
    public function applyAgentSpaceDefaultsToToolArguments(array $toolArguments, array $parameterClassNames, AgentSpace $agentSpace): array
    {
        if ($this->findRequestedAgentSpaceIdOfToolArguments($toolArguments) > 0 || !isset($agentSpace->id)) {
            return $toolArguments;
        }
        foreach ($parameterClassNames as $parameterClassName) {
            if (property_exists($parameterClassName, static::TOOL_ARGUMENT_AGENT_SPACE_ID)) {
                $toolArguments[static::TOOL_ARGUMENT_AGENT_SPACE_ID] = (int)$agentSpace->id;
                break;
            }
        }
        return $toolArguments;
    }

    /**
     * The arguments of a call in the agent loop as the tool receives them: an argument the space fills that the model
     * passed as {@see TOOL_ARGUMENT_VALUE_NOT_NARROWED_TO_AGENT_SPACE} ({@see isToolArgumentValueNotNarrowedToAgentSpace()})
     * is left out and never filled; every other argument goes through {@see applyAgentSpaceDefaultsToToolArguments()}
     * when the conversation has a space. Called by the executor before the gates and the hydration.
     *
     * @param array<array-key, mixed> $toolArguments
     * @param string[] $parameterClassNames the classes of the tool method's parameters
     * @param AgentSpace|null $agentSpace the space of the conversation the call runs in; null = none
     * @return array<array-key, mixed>
     */
    public function applyAgentSpaceDefaultsToToolArgumentsOfCall(array $toolArguments, array $parameterClassNames, ?AgentSpace $agentSpace): array
    {
        $argumentNamesNotNarrowed = [];
        foreach (array_keys($this->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()) as $argumentNameFilledFromAgentSpace) {
            if (array_key_exists($argumentNameFilledFromAgentSpace, $toolArguments)
                && $this->isToolArgumentValueNotNarrowedToAgentSpace($toolArguments[$argumentNameFilledFromAgentSpace])
            ) {
                $argumentNamesNotNarrowed[] = $argumentNameFilledFromAgentSpace;
                unset($toolArguments[$argumentNameFilledFromAgentSpace]);
            }
        }
        if ($agentSpace === null) {
            return $toolArguments;
        }
        $filledToolArguments = $this->applyAgentSpaceDefaultsToToolArguments($toolArguments, $parameterClassNames, $agentSpace);
        foreach ($argumentNamesNotNarrowed as $argumentNameNotNarrowed) {
            unset($filledToolArguments[$argumentNameNotNarrowed]);
            // the application's name of the space argument stands for the DTO property: "none" leaves both unfilled
            if ($argumentNameNotNarrowed === AgentSpaceToolArgument::current()->name) {
                unset($filledToolArguments[static::TOOL_ARGUMENT_AGENT_SPACE_ID]);
            }
        }
        return $filledToolArguments;
    }

    /**
     * Override point: whether `$argumentValue` of a filled argument says "do not narrow to a space". The default: the
     * text {@see TOOL_ARGUMENT_VALUE_NOT_NARROWED_TO_AGENT_SPACE}, in any case.
     */
    public function isToolArgumentValueNotNarrowedToAgentSpace(mixed $argumentValue): bool
    {
        return is_string($argumentValue) && strtolower(trim($argumentValue)) === static::TOOL_ARGUMENT_VALUE_NOT_NARROWED_TO_AGENT_SPACE;
    }

    /**
     * What the model reads in front of the description of each tool argument that
     * {@see self::applyAgentSpaceDefaultsToToolArguments()} fills, by argument name. In a conversation with a space
     * the tool schema drops these arguments from its required list and puts the text in front of their description;
     * in a conversation without a space the schema stays as declared (nothing would fill them).
     *
     * @return string[] description prefix by argument name
     */
    public function getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace(): array
    {
        // keyed by the name on the wire: the application's name of the space argument ({@see AgentSpaceToolArgument})
        return [AgentSpaceToolArgument::current()->name => static::DESCRIPTION_PREFIX_OF_TOOL_ARGUMENT_FILLED_FROM_AGENT_SPACE];
    }

    /**
     * Override point: the description prefixes of the tool arguments the space of `$conversation` fills, by argument
     * name — what a conversation's tool schema reads ({@see AIConversation::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()}).
     * An application whose spaces fill different arguments by kind decides per conversation and space here. The
     * default: {@see getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()}, the same for every space.
     *
     * @param AIConversation $conversation the conversation whose tool schema is built
     * @param AgentSpace|null $agentSpace its space as far as it is loaded; null when only its id is known
     * @return string[] description prefix by argument name
     */
    public function getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpaceOfConversation(AIConversation $conversation, ?AgentSpace $agentSpace): array
    {
        return $this->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace();
    }

    /**
     * Override point: the tool arguments the read dedup line leaves out
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord}): the arguments that name the target
     * of a call rather than the question, so two reads of the same question on the conversation's space count as one.
     * The default: the argument names of {@see getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()}.
     *
     * @return string[] argument names
     */
    public function getToolArgumentNamesLeftOutOfTheReadDedupLine(): array
    {
        return array_keys($this->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace());
    }

    /**
     * Override point: how a text for the model names the space `$agentSpaceId` — the scope of a memory line
     * ({@see \DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory::toLlmMarkdown()}). An application that
     * knows the space under its own term and its own id answers with those (the id its tools take). The other texts
     * that carry the id of a space keep their own override points: the id in the base prompt
     * ({@see applyAgentSpacePromptParameters()}), the coverage of a mandate line, the scope sentence of the memory
     * curation, the space column of the operator run lists. Asked once per line: answer from what is loaded, or from
     * one indexed read the entity registry keeps. The default: `space:<id>`.
     *
     * @param int $agentSpaceId the id of the space
     * @return string
     */
    public function buildAgentSpaceLabelForTheModel(int $agentSpaceId): string
    {
        return 'space:' . $agentSpaceId;
    }

    /**
     * Override point, the inverse of {@see buildAgentSpaceLabelForTheModel()}: the space whose label names the number
     * `$numberInLabel` — for a tool argument the model copies from a label (the space filter `onlySpaceId` of
     * `searchConversations`). The default label names the space id, so the number is the id. An application whose
     * label names the id of its own entity answers with that entity's space. Null = no space has that number; never
     * creates a space.
     *
     * @param int $numberInLabel the number after the label's prefix
     * @return int|null the id of the space
     */
    public function findAgentSpaceIdOfLabelNumber(int $numberInLabel): ?int
    {
        return $numberInLabel > 0 ? $numberInLabel : null;
    }

    /**
     * The key the widget blocks of `$conversation` hydrate themselves from
     * ({@see AgentSpace::getWidgetSelfHydrationKey()}).
     *
     * @param AIConversation $conversation
     * @return int|null null = no key: the conversation has no space, or its space gives none
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findWidgetSelfHydrationKeyOfConversation(AIConversation $conversation): ?int
    {
        $agentSpace = $conversation->agentSpace ?? null;
        return $agentSpace !== null ? $agentSpace->getWidgetSelfHydrationKey() : null;
    }

    /**
     * Sets the process-wide translation settings (language, writing style) to those of `$agentSpace`. The default
     * changes nothing.
     *
     * @param AgentSpace $agentSpace
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function applyTranslationSettingsOfAgentSpace(AgentSpace $agentSpace): void
    {
    }

    /**
     * The language codes the application's interface is offered in. The default has none.
     *
     * @return string[]
     */
    public function getActiveInterfaceLanguageCodes(): array
    {
        return [];
    }
}
