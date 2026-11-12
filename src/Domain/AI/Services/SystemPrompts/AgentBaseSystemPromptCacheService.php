<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\SystemPrompts;

use DDD\Domain\AI\Entities\Conversations\Agent\SystemPrompts\AgentBaseSystemPromptInputsVersionBump;
use DDD\Domain\AI\Listeners\Conversations\AgentBaseSystemPromptTransactionEndListener;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDeliveryBaseValues;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Cache\Predis\Client;
use DDD\Infrastructure\Cache\Redis;
use DDD\Infrastructure\Cache\RedisSentinel;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Events;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Throwable;
use WeakMap;

/**
 * The agent's BASE system prompt (Agentic.md + the context text of the space, the product name, the owner
 * addressing — everything {@see ArgusAIAgentConversation::getAIPromptWithParametersApplied()} fills in) cached in Redis
 * so a turn never builds it on the critical path. Building it is slow: it loads the records of the space and of the
 * account that the context text is composed from.
 *
 * The cache key carries what the prompt is built from instead of a short expiry:
 *  - the conversation's account and space (a conversation without a space works at account level: its prompt is
 *    keyed by its account alone) and the plan-dynamics mode of the conversation,
 *  - an INPUTS VERSION per space and per account — a random token that the write paths of the prompt's inputs
 *    replace ({@see bumpInputsVersionOfAgentSpace()} / {@see bumpInputsVersionForAccountId()}), so the next run reads
 *    a fresh prompt; a bump inside an open database transaction waits for its end, so no reader caches pre-commit
 *    rows under the new token,
 *  - a fingerprint of the inputs no typed write path of the module bumps for: the product name (written by the
 *    application's own systems), the feature flag that switches the agent on and the owner's register
 *    setting, and what the application names for the conversation's space
 *    ({@see buildPromptInputsFingerprint()}, {@see findUnversionedPromptInputsOfAgentSpace()}),
 *  - a fingerprint of the two prompt files (a deploy that changes them changes the key),
 *  - {@see BUILD_FORMAT_VERSION}, raised by hand when the PHP that composes the prompt changes its text.
 * A lost version token is replaced by a new one, never read as an old value: a prompt cached under a token is always
 * younger than the token.
 *
 * The postMessage request builds the prompt after its response was sent when the current key has none
 * ({@see queueBaseSystemPromptWarmAfterResponse()}, run by the host's warm-up subscriber
 * on kernel.terminate) — after a version bump or a changed fingerprint the turn then reads it instead of building it. A
 * prompt cached under the current key is younger than the newest bump by construction, so it is never rebuilt; a write
 * that neither covers is stale for {@see BASE_SYSTEM_PROMPT_TTL_SECONDS} at most. A turn worker that misses while that
 * build runs waits for it ({@see WAIT_FOR_WARM_MAX_MILLISECONDS}) instead of building the same prompt a second time.
 *
 * With `stateChangeDelivery = onceWhenChanged` the conversation's base prompt is a TEMPLATE without the data of its
 * space — `{%AGENT_SPACE_CONTEXT%}` and `{%OWNER_ADDRESSING%}` stay placeholders the epoch record fills per call —
 * under its own key ({@see TEMPLATE_BUILD_FORMAT_VERSION}: no inputs version of the space, none of the space's
 * unversioned inputs, no addressing in the fingerprint), and the `{%AGENT_SPACE_CONTEXT%}` text of a space is cached under
 * its own key ({@see buildAgentSpaceContextCacheKey()}), read at every run start for the change comparison
 * ({@see resolveStateDeliveryBaseValues()}). `everyCall` keeps the key above, byte for byte.
 */
class AgentBaseSystemPromptCacheService extends Service
{
    public const string CACHE_KEY_PREFIX = 'agent.baseSystemPrompt.';

    public const string INPUTS_VERSION_KEY_PREFIX = 'agent.baseSystemPromptInputsVersion.';

    /** The level of an inputs version that belongs to one space ({@see bumpInputsVersionOfAgentSpace()}); the id next to it is the space's. */
    public const string INPUTS_VERSION_LEVEL_AGENT_SPACE = 'agentSpace';

    /** The level of an inputs version that belongs to one account ({@see bumpInputsVersionForAccountId()}); the id next to it is the account's. */
    public const string INPUTS_VERSION_LEVEL_ACCOUNT = 'account';

    /** Raised by hand when the PHP that composes the base prompt changes the text it produces (the inputs did not change). */
    public const string BUILD_FORMAT_VERSION = '2';

    /** The build format of the state-delivery TEMPLATE (its key holds no inputs version of the space); '5' = the `{%AGENT_SPACE_CONTEXT%}` placeholder. */
    public const string TEMPLATE_BUILD_FORMAT_VERSION = '5';

    /** The `{%AGENT_SPACE_CONTEXT%}` text of a space, cached apart from the template. */
    public const string AGENT_SPACE_CONTEXT_CACHE_KEY_PREFIX = 'agent.spaceContext.';

    /** Raised by hand when the PHP that composes the context text of a space changes its text. */
    public const string AGENT_SPACE_CONTEXT_BUILD_FORMAT_VERSION = '1';

    /**
     * A cached prompt lives one hour: a write that neither a version bump nor the fingerprint covers (an edit made
     * outside the module's write paths) is that stale at most.
     */
    public const int BASE_SYSTEM_PROMPT_TTL_SECONDS = Cache::CACHE_TTL_ONE_HOUR;

    /** Longer than the prompt's own lifetime, so a token never expires under a prompt cached with it. */
    public const int INPUTS_VERSION_TTL_SECONDS = Cache::CACHE_TTL_ONE_MONTH;

    /**
     * The marker a running rebuild sets on its key, holding that rebuild's own token; it expires on its own when the
     * rebuilding process dies.
     */
    public const int WARM_MARKER_TTL_SECONDS = 5;

    public const string WARM_MARKER_SUFFIX = '.warming';

    /** A miss waits while a rebuild's marker exists — at most the marker's lifetime, longer than the build's p90. */
    public const int WAIT_FOR_WARM_MAX_MILLISECONDS = self::WARM_MARKER_TTL_SECONDS * 1000;

    public const int WAIT_FOR_WARM_POLL_MILLISECONDS = 50;

    /**
     * Compare-and-set in one Redis step: write the prompt (KEYS[2]) only while the warm marker (KEYS[1]) holds this
     * rebuild's token. ARGV holds the token and the prompt as the cache stores them (serialized), then the TTL.
     */
    public const string REDIS_SCRIPT_WRITE_WHILE_THE_MARKER_HOLDS_THE_TOKEN = "if redis.call('GET', KEYS[1]) == ARGV[1] then redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[3]) return 1 end return 0";

    /** Compare-and-delete in one Redis step: delete the warm marker (KEYS[1]) only while it holds this rebuild's token (ARGV[1]). */
    public const string REDIS_SCRIPT_DELETE_WHILE_THE_MARKER_HOLDS_THE_TOKEN = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0";

    /** @var AIConversation[] the conversations whose base prompt this request rebuilds after its response, by id */
    protected array $conversationsQueuedForWarm = [];

    /** @var AgentBaseSystemPromptInputsVersionBump[] The inputs version bumps waiting for the open transaction's end, keyed by `level.id` so each version is renewed once */
    protected array $inputsVersionBumpsWaitingForTheTransactionEnd = [];

    /**
     * @var WeakMap<Connection, true>|null the connections the transaction-end listener is registered on — weak, so a
     * replacement connection that reuses a freed one's object id is never taken for it
     */
    protected ?WeakMap $connectionsWithTransactionEndListener = null;

    /** The native Redis connection the compare-and-set scripts run on (a phpredis `\Redis` or a Predis {@see Client}). */
    protected ?object $nativeRedisConnection = null;

    /** True once {@see findNativeRedisConnection()} tried to open the connection (it is not retried after a failure to open). */
    protected bool $nativeRedisConnectionOpeningTried = false;

    /**
     * The base system prompt of this conversation: the cached one under the current key, else the one a running
     * rebuild is writing, else built here and cached. A conversation without a database id (an ephemeral or eval
     * conversation) is always built fresh.
     */
    public function resolveBaseSystemPrompt(AIConversation $conversation): string
    {
        // a `onceWhenChanged` conversation's base prompt is the template the epoch record fills
        if ($conversation->deliversStateChangesOnce()) {
            return $this->resolveBaseSystemPromptTemplate($conversation);
        }
        if (!isset($conversation->id)) {
            return $this->buildBaseSystemPrompt($conversation);
        }
        try {
            $cacheKey = $this->buildCacheKey($conversation);
        } catch (Throwable) {
            return $this->buildBaseSystemPrompt($conversation);
        }
        $cachedBaseSystemPrompt = $this->readCachedBaseSystemPrompt($cacheKey) ?? $this->waitForBaseSystemPromptBuiltElsewhere($cacheKey);
        if ($cachedBaseSystemPrompt !== null) {
            return $cachedBaseSystemPrompt;
        }
        $baseSystemPrompt = $this->buildBaseSystemPrompt($conversation);
        $this->writeCacheValue($cacheKey, $baseSystemPrompt, self::BASE_SYSTEM_PROMPT_TTL_SECONDS);
        return $baseSystemPrompt;
    }

    /**
     * The state-delivery TEMPLATE of this conversation's base prompt: cached under
     * {@see buildTemplateCacheKey()}, else built here and cached; an id-less conversation is always built fresh.
     */
    public function resolveBaseSystemPromptTemplate(AIConversation $conversation): string
    {
        if (!isset($conversation->id)) {
            return $this->buildBaseSystemPromptTemplate($conversation);
        }
        try {
            $cacheKey = $this->buildTemplateCacheKey($conversation);
        } catch (Throwable) {
            return $this->buildBaseSystemPromptTemplate($conversation);
        }
        $cachedBaseSystemPromptTemplate = $this->readCachedBaseSystemPrompt($cacheKey);
        if ($cachedBaseSystemPromptTemplate !== null) {
            return $cachedBaseSystemPromptTemplate;
        }
        $baseSystemPromptTemplate = $this->buildBaseSystemPromptTemplate($conversation);
        $this->writeCacheValue($cacheKey, $baseSystemPromptTemplate, self::BASE_SYSTEM_PROMPT_TTL_SECONDS);
        return $baseSystemPromptTemplate;
    }

    /**
     * The template's key: account, space (its id is in the prompt; 0 for a conversation without a space, whose
     * template is keyed by its account), plan-dynamics mode, the ACCOUNT inputs version, the prompt files and the
     * product name — no inputs version of the space, none of its unversioned inputs, no addressing (the epoch record
     * carries those).
     */
    public function buildTemplateCacheKey(AIConversation $conversation): string
    {
        $accountId = (int)($conversation->accountId ?? 0);
        $agentSpaceId = (int)($conversation->agentSpaceId ?? 0);
        $planDynamicsMode = AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $conversation);
        $account = $conversation->account ?? null;
        return self::CACHE_KEY_PREFIX . self::TEMPLATE_BUILD_FORMAT_VERSION
            . '.' . $accountId
            . '.' . $agentSpaceId
            . '.' . $planDynamicsMode
            . '.' . ($accountId > 0 ? $this->findInputsVersion(self::INPUTS_VERSION_LEVEL_ACCOUNT, $accountId) : '-')
            . '.' . $this->buildPromptFilesFingerprint()
            . '.' . substr(md5((string)json_encode(['productName' => $account->customProductName ?? null])), 0, 12);
    }

    /** The template built from the conversation's account and prompt files (no model call, no data of its space). */
    public function buildBaseSystemPromptTemplate(AIConversation $conversation): string
    {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->fromEntity($conversation);
        $argusAIAgentConversation->buildsStateDeliveryTemplate = true;
        return $argusAIAgentConversation->getSystemPromptContent();
    }

    /**
     * The two values the template leaves to the epoch record, as of now: the context text of the space from
     * its cache (built 0.8 s median uncached), the owner addressing built here (the account's name and register). Each
     * is resolved on its own: a value whose read fails is marked unknown and logged
     * ({@see AgentStateDeliveryBaseValues::$unknownKinds}), never handed on as "none".
     */
    public function resolveStateDeliveryBaseValues(AIConversation $conversation): AgentStateDeliveryBaseValues
    {
        $argusAIAgentConversation = $this->buildArgusAgentConversationFor($conversation);
        $unknownKinds = [];
        $agentSpaceContext = null;
        try {
            $agentSpaceContext = $this->resolveAgentSpaceContext($conversation, $argusAIAgentConversation);
        } catch (Throwable $agentSpaceContextError) {
            $unknownKinds[] = AgentStateDelta::getKindOfAgentSpaceContext();
            $this->logUnresolvedStateDeliveryBaseValue($conversation, AgentStateDelta::getKindOfAgentSpaceContext(), $agentSpaceContextError);
        }
        $ownerAddressing = '';
        try {
            $ownerAddressing = $this->buildOwnerAddressing($argusAIAgentConversation);
        } catch (Throwable $ownerAddressingError) {
            $unknownKinds[] = AgentStateDelta::KIND_OWNER_ADDRESSING;
            $this->logUnresolvedStateDeliveryBaseValue($conversation, AgentStateDelta::KIND_OWNER_ADDRESSING, $ownerAddressingError);
        }
        $agentStateDeliveryBaseValues = AgentStateDeliveryBaseValues::of($agentSpaceContext, $ownerAddressing);
        $agentStateDeliveryBaseValues->unknownKinds = $unknownKinds;
        return $agentStateDeliveryBaseValues;
    }

    /** One warning per value the state delivery could not resolve (the epoch then omits it — never a placeholder). */
    protected function logUnresolvedStateDeliveryBaseValue(AIConversation $conversation, string $kind, Throwable $throwable): void
    {
        try {
            DDDService::instance()->getLogger()->warning(
                'AgentBaseSystemPromptCacheService: the ' . $kind . ' of conversation ' . ($conversation->id ?? 'transient') . ' could not be resolved',
                ['error' => mb_substr($throwable->getMessage(), 0, 500)]
            );
        } catch (Throwable) {
            // a logger that fails costs only the log line
        }
    }

    /** The agent egress over `$conversation` — the prompt build's own reads (space, account, settings). */
    protected function buildArgusAgentConversationFor(AIConversation $conversation): ArgusAIAgentConversation
    {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->fromEntity($conversation);
        return $argusAIAgentConversation;
    }

    /**
     * The owner addressing of the conversation, built now (cheap: the account and the register setting); '' = the
     * account carries no name.
     *
     * @throws Throwable the resolution failed
     */
    protected function buildOwnerAddressing(ArgusAIAgentConversation $argusAIAgentConversation): string
    {
        return $argusAIAgentConversation->buildOwnerAddressingInstructionTextOrFail();
    }

    /**
     * The `{%AGENT_SPACE_CONTEXT%}` text of the conversation, built now: the context segment its space contributes
     * ({@see ArgusAIAgentConversation::buildAgentSpaceContextPromptSegment()}); null when there is none.
     */
    protected function buildAgentSpaceContext(ArgusAIAgentConversation $argusAIAgentConversation): ?string
    {
        return $argusAIAgentConversation->buildAgentSpaceContextPromptSegment();
    }

    /**
     * The `{%AGENT_SPACE_CONTEXT%}` text of the conversation. For a conversation of a space: cached under
     * {@see buildAgentSpaceContextCacheKey()}, else built and cached. A conversation without a space has no key of a
     * space — its account-level text is built on every read and never cached (a key without a space id would be
     * shared by every account).
     */
    protected function resolveAgentSpaceContext(AIConversation $conversation, ArgusAIAgentConversation $argusAIAgentConversation): ?string
    {
        if ((int)($conversation->agentSpaceId ?? 0) <= 0) {
            return $this->buildAgentSpaceContext($argusAIAgentConversation);
        }
        try {
            $cacheKey = $this->buildAgentSpaceContextCacheKey($conversation);
        } catch (Throwable) {
            return $this->buildAgentSpaceContext($argusAIAgentConversation);
        }
        $cachedValue = $this->readCacheValue($cacheKey);
        $cachedAgentSpaceContext = $cachedValue !== null ? json_decode($cachedValue, true) : null;
        if (is_array($cachedAgentSpaceContext) && array_key_exists('agentSpaceContext', $cachedAgentSpaceContext)) {
            return is_string($cachedAgentSpaceContext['agentSpaceContext']) ? $cachedAgentSpaceContext['agentSpaceContext'] : null;
        }
        $agentSpaceContext = $this->buildAgentSpaceContext($argusAIAgentConversation);
        $this->writeCacheValue($cacheKey, (string)json_encode(['agentSpaceContext' => $agentSpaceContext], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::BASE_SYSTEM_PROMPT_TTL_SECONDS);
        return $agentSpaceContext;
    }

    /**
     * The key of the `{%AGENT_SPACE_CONTEXT%}` text of a conversation OF A SPACE: the space, its inputs version and the
     * fingerprint of its unversioned inputs ({@see findUnversionedPromptInputsOfAgentSpace()}). Never asked for a
     * conversation without a space ({@see resolveAgentSpaceContext()} builds that text uncached).
     */
    public function buildAgentSpaceContextCacheKey(AIConversation $conversation): string
    {
        $agentSpaceId = (int)($conversation->agentSpaceId ?? 0);
        return self::AGENT_SPACE_CONTEXT_CACHE_KEY_PREFIX . self::AGENT_SPACE_CONTEXT_BUILD_FORMAT_VERSION
            . '.' . $agentSpaceId
            . '.' . $this->findInputsVersion(self::INPUTS_VERSION_LEVEL_AGENT_SPACE, $agentSpaceId)
            . '.' . substr(md5((string)json_encode($this->findUnversionedPromptInputsOfAgentSpace($conversation))), 0, 12);
    }

    /**
     * Build the base prompt of this conversation under the current key when that key has none yet — a prompt cached
     * under it was built after the newest version bump (the key carries the versions), so it is kept. The warm
     * marker carries this rebuild's own token for the time of the build, so a turn worker that misses meanwhile waits
     * for this result. A younger rebuild of the same key replaces the marker: this one then writes nothing (its inputs
     * were read earlier) and leaves the younger one's marker alone — the marker check and the write, and the marker
     * check and the delete, are one Redis step each. Never throws: a failed rebuild leaves the cache as it was.
     */
    public function warmBaseSystemPrompt(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        // a `onceWhenChanged` conversation warms its template and the context text of its space
        if ($conversation->deliversStateChangesOnce()) {
            try {
                $this->resolveBaseSystemPromptTemplate($conversation);
                $this->resolveStateDeliveryBaseValues($conversation);
            } catch (Throwable) {
                // the cache stays as it was; the next turn builds them itself
            }
            return;
        }
        $warmMarkerKey = null;
        $warmToken = $this->generateInputsVersionToken();
        try {
            $cacheKey = $this->buildCacheKey($conversation);
            if ($this->readCachedBaseSystemPrompt($cacheKey) !== null) {
                return;
            }
            $warmMarkerKey = $cacheKey . self::WARM_MARKER_SUFFIX;
            $this->writeCacheValue($warmMarkerKey, $warmToken, self::WARM_MARKER_TTL_SECONDS);
            $baseSystemPrompt = $this->buildBaseSystemPrompt($conversation);
            $this->writeCacheValueWhileTheWarmMarkerHoldsTheToken($warmMarkerKey, $warmToken, $cacheKey, $baseSystemPrompt, self::BASE_SYSTEM_PROMPT_TTL_SECONDS);
        } catch (Throwable) {
            // the cache stays as it was; the next turn builds the prompt itself
        } finally {
            if ($warmMarkerKey !== null) {
                $this->deleteTheWarmMarkerWhileItHoldsTheToken($warmMarkerKey, $warmToken);
            }
        }
    }

    /** Remember this conversation for a rebuild of its base prompt once the current HTTP response was sent. */
    public function queueBaseSystemPromptWarmAfterResponse(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $this->conversationsQueuedForWarm[(int)$conversation->id] = $conversation;
    }

    /** Build the base prompts queued during this request that are not cached yet (kernel.terminate, after the response was sent). */
    public function warmQueuedBaseSystemPrompts(): void
    {
        $conversationsQueuedForWarm = $this->conversationsQueuedForWarm;
        $this->conversationsQueuedForWarm = [];
        foreach ($conversationsQueuedForWarm as $conversation) {
            $this->warmBaseSystemPrompt($conversation);
        }
    }

    /**
     * A write changed what the base prompts of the conversations of `$agentSpace` are built from (whatever the
     * application's prompt segments read of the space): the next run builds them fresh. Never throws — a cache problem
     * must not fail the write that called it; a space that is not stored yet has no cached prompt and is ignored.
     */
    public function bumpInputsVersionOfAgentSpace(AgentSpace $agentSpace): void
    {
        $this->bumpInputsVersion(self::INPUTS_VERSION_LEVEL_AGENT_SPACE, isset($agentSpace->id) ? (int)$agentSpace->id : 0);
    }

    /**
     * A write changed what the base prompts of this account are built from (the owner's name, the register the owner
     * is addressed in): the next run builds them fresh. Never throws.
     */
    public function bumpInputsVersionForAccountId(int $accountId): void
    {
        $this->bumpInputsVersion(self::INPUTS_VERSION_LEVEL_ACCOUNT, $accountId);
    }

    /**
     * The cache key of this conversation's base prompt: account, space, plan-dynamics mode, both inputs versions,
     * the prompt files' fingerprint and the build format. A conversation without a space (account level) has 0 in
     * the place of the space id and no inputs version of a space: its prompt is keyed by its account.
     */
    public function buildCacheKey(AIConversation $conversation): string
    {
        $accountId = (int)($conversation->accountId ?? 0);
        $agentSpaceId = (int)($conversation->agentSpaceId ?? 0);
        $planDynamicsMode = AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $conversation);
        return self::CACHE_KEY_PREFIX . self::BUILD_FORMAT_VERSION
            . '.' . $accountId
            . '.' . $agentSpaceId
            . '.' . $planDynamicsMode
            . '.' . ($agentSpaceId > 0 ? $this->findInputsVersion(self::INPUTS_VERSION_LEVEL_AGENT_SPACE, $agentSpaceId) : '-')
            . '.' . ($accountId > 0 ? $this->findInputsVersion(self::INPUTS_VERSION_LEVEL_ACCOUNT, $accountId) : '-')
            . '.' . $this->buildPromptFilesFingerprint()
            . '.' . $this->buildPromptInputsFingerprint($conversation);
    }

    /**
     * Fingerprint of the prompt inputs that change without a write path this app bumps for — read exactly as the build
     * reads them: the account's product name (`customProductName`: partner settings and the subscriptions), the owner
     * addressing arguments, and what the application names for the conversation's space
     * ({@see findUnversionedPromptInputsOfAgentSpace()}).
     */
    public function buildPromptInputsFingerprint(AIConversation $conversation): string
    {
        $account = $conversation->account ?? null;
        return substr(md5((string)json_encode([
            'productName' => $account->customProductName ?? null,
            'ownerAddressing' => $conversation->resolveOwnerAddressingArguments()->toFingerprintFields(),
            'agentSpace' => $this->findUnversionedPromptInputsOfAgentSpace($conversation),
        ])), 0, 12);
    }

    /**
     * What the prompt build reads of the conversation's space that changes WITHOUT a write path bumping the inputs
     * version of the space ({@see bumpInputsVersionOfAgentSpace()}) — the values the cache keys fingerprint, so that
     * a change gives a new key. The application names them where it answers everything about its space
     * ({@see AgentSpacesService::findUnversionedPromptInputsOfConversation()}; the default has none).
     *
     * @param AIConversation $conversation
     * @return array<string, mixed> the values by a name of the application's choice; [] = none
     */
    protected function findUnversionedPromptInputsOfAgentSpace(AIConversation $conversation): array
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findUnversionedPromptInputsOfConversation($conversation);
    }

    /**
     * The current inputs version token of one space or account; a missing token is created (never read as an old one).
     *
     * @param string $level {@see INPUTS_VERSION_LEVEL_AGENT_SPACE} or {@see INPUTS_VERSION_LEVEL_ACCOUNT}
     * @param int $id the id of the space or of the account
     * @return string
     */
    public function findInputsVersion(string $level, int $id): string
    {
        $inputsVersionKey = self::INPUTS_VERSION_KEY_PREFIX . $level . '.' . $id;
        $inputsVersion = $this->readCacheValue($inputsVersionKey);
        if ($inputsVersion !== null && $inputsVersion !== '') {
            return $inputsVersion;
        }
        $inputsVersion = $this->generateInputsVersionToken();
        $this->writeCacheValue($inputsVersionKey, $inputsVersion, self::INPUTS_VERSION_TTL_SECONDS);
        return $inputsVersion;
    }

    /** Fingerprint of the prompt files the base prompt is made of (Agentic.md and the shared Character.md). */
    public function buildPromptFilesFingerprint(): string
    {
        return substr(md5(
            (string)Config::get(AIPromptsService::CONFIG_PROMPTS_PREFIX . AgentAIPromptNames::APP_AI_AGENT_AGENTIC)
            . "\n" . (string)Config::get(AIPromptsService::CONFIG_PROMPTS_PREFIX . AgentAIPromptNames::APP_AI_AGENT_CHARACTER)
        ), 0, 12);
    }

    /** The base prompt built from the conversation's current space, account and prompt files (no model call). */
    public function buildBaseSystemPrompt(AIConversation $conversation): string
    {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->fromEntity($conversation);
        return $argusAIAgentConversation->getSystemPromptContent();
    }

    /**
     * Renew one inputs version — at once, or, while a database transaction is open, once it ended: a reader that took
     * the new token before the commit would cache the old rows under it.
     */
    protected function bumpInputsVersion(string $level, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $connectionWithOpenTransaction = $this->findConnectionWithOpenTransaction();
        if ($connectionWithOpenTransaction !== null && $this->listenForTheEndOfTheTransaction($connectionWithOpenTransaction)) {
            $inputsVersionBump = AgentBaseSystemPromptInputsVersionBump::forLevelAndId($level, $id);
            $this->inputsVersionBumpsWaitingForTheTransactionEnd[$inputsVersionBump->buildLevelAndIdKey()] = $inputsVersionBump;
            return;
        }
        $this->writeInputsVersionToken($level, $id);
    }

    /**
     * The open transaction of `$connection` ended (committed or rolled back): renew the inputs versions its writes
     * bumped. Nothing while an outer transaction level is still open. Called by
     * {@see AgentBaseSystemPromptTransactionEndListener}.
     */
    public function bumpInputsVersionsWaitingForTheTransactionEnd(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            return;
        }
        $inputsVersionBumps = $this->inputsVersionBumpsWaitingForTheTransactionEnd;
        $this->inputsVersionBumpsWaitingForTheTransactionEnd = [];
        foreach ($inputsVersionBumps as $inputsVersionBump) {
            $this->writeInputsVersionToken($inputsVersionBump->level, $inputsVersionBump->id);
        }
    }

    protected function writeInputsVersionToken(string $level, int $id): void
    {
        $this->writeCacheValue(self::INPUTS_VERSION_KEY_PREFIX . $level . '.' . $id, $this->generateInputsVersionToken(), self::INPUTS_VERSION_TTL_SECONDS);
    }

    /** The database connection the prompt's inputs are written on, while a transaction is open on it; null otherwise. */
    protected function findConnectionWithOpenTransaction(): ?Connection
    {
        try {
            $connection = EntityManagerFactory::getInstance()->getConnection();
            return $connection->isTransactionActive() ? $connection : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Register {@see AgentBaseSystemPromptTransactionEndListener} on the connection's commit and rollback events (once
     * per connection). False when it cannot be registered — the caller then bumps at once.
     */
    protected function listenForTheEndOfTheTransaction(Connection $connection): bool
    {
        $this->connectionsWithTransactionEndListener ??= new WeakMap();
        if (isset($this->connectionsWithTransactionEndListener[$connection])) {
            return true;
        }
        try {
            $connection->getEventManager()->addEventListener([Events::onTransactionCommit, Events::onTransactionRollBack], new AgentBaseSystemPromptTransactionEndListener());
        } catch (Throwable) {
            return false;
        }
        $this->connectionsWithTransactionEndListener[$connection] = true;
        return true;
    }

    /**
     * Write `$value` under `$key` only while the warm marker holds `$warmToken` — atomically by a Redis script; where
     * the script cannot run (no native Redis connection with `eval`, a script error) by a read of the marker and a
     * write, which a younger rebuild can slip between.
     */
    protected function writeCacheValueWhileTheWarmMarkerHoldsTheToken(string $warmMarkerKey, string $warmToken, string $key, string $value, int $ttlSeconds): void
    {
        $scriptResult = $this->runRedisScript(
            self::REDIS_SCRIPT_WRITE_WHILE_THE_MARKER_HOLDS_THE_TOKEN,
            [$this->buildRedisKey($warmMarkerKey), $this->buildRedisKey($key)],
            [serialize($warmToken), serialize($value), (string)$ttlSeconds]
        );
        if ($scriptResult === null && $this->readCacheValue($warmMarkerKey) === $warmToken) {
            $this->writeCacheValue($key, $value, $ttlSeconds);
        }
    }

    /** Delete the warm marker only while it holds `$warmToken` — atomically by a Redis script, else by a read and a delete. */
    protected function deleteTheWarmMarkerWhileItHoldsTheToken(string $warmMarkerKey, string $warmToken): void
    {
        $scriptResult = $this->runRedisScript(self::REDIS_SCRIPT_DELETE_WHILE_THE_MARKER_HOLDS_THE_TOKEN, [$this->buildRedisKey($warmMarkerKey)], [serialize($warmToken)]);
        if ($scriptResult === null && $this->readCacheValue($warmMarkerKey) === $warmToken) {
            $this->deleteCacheValue($warmMarkerKey);
        }
    }

    /**
     * Run a Lua script on the native Redis connection: its integer result, or null when it could not run (no native
     * connection, a client without `eval`, an error) — the caller then takes the non-atomic way.
     *
     * @param string[] $redisKeys
     * @param string[] $scriptArguments
     */
    protected function runRedisScript(string $script, array $redisKeys, array $scriptArguments): ?int
    {
        $nativeRedisConnection = $this->findNativeRedisConnection();
        if ($nativeRedisConnection === null) {
            return null;
        }
        try {
            if ($nativeRedisConnection instanceof Client) {
                $error = false;
                $scriptResult = $nativeRedisConnection->executeRaw(['EVAL', $script, (string)count($redisKeys), ...$redisKeys, ...$scriptArguments], $error);
                return !$error && is_int($scriptResult) ? $scriptResult : null;
            }
            if (!method_exists($nativeRedisConnection, 'eval')) {
                return null;
            }
            $scriptResult = $nativeRedisConnection->eval($script, [...$redisKeys, ...$scriptArguments], count($redisKeys));
            return is_int($scriptResult) ? $scriptResult : null;
        } catch (Throwable) {
            $this->nativeRedisConnection = null;
            $this->nativeRedisConnectionOpeningTried = false;
            return null;
        }
    }

    /**
     * The native connection to the Redis of {@see Cache::CACHE_GROUP_REDIS} (the cache adapter does not expose
     * `eval`), opened once per process — a phpredis `\Redis` or a Predis {@see Client}; null when that cache is no
     * Redis or the connection cannot be opened.
     */
    protected function findNativeRedisConnection(): ?object
    {
        if ($this->nativeRedisConnectionOpeningTried) {
            return $this->nativeRedisConnection;
        }
        $this->nativeRedisConnectionOpeningTried = true;
        try {
            $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
            $cacheConfig = Cache::getCacheConfig(Cache::CACHE_GROUP_REDIS);
            if ($cache instanceof RedisSentinel) {
                $this->nativeRedisConnection = new Client($cacheConfig['sentinels'], [
                    'replication' => 'redis-sentinel',
                    'service' => 'mymaster',
                    'parameters' => ['database' => $cacheConfig['database'], 'password' => $cacheConfig['password']],
                    'ttl' => $cache->ttl,
                ]);
            } elseif ($cache instanceof Redis) {
                $nativeRedisConnection = RedisAdapter::createConnection(sprintf(
                    'redis://%s@%s:%d/%d', $cacheConfig['password'], $cacheConfig['host'], $cacheConfig['port'], $cacheConfig['database']
                ));
                $this->nativeRedisConnection = $nativeRedisConnection instanceof \Redis ? $nativeRedisConnection : null;
            }
        } catch (Throwable) {
            $this->nativeRedisConnection = null;
        }
        return $this->nativeRedisConnection;
    }

    /** The key the cache adapter stores `$cacheKey` under in Redis: the cache namespace, a colon, the escaped key. */
    protected function buildRedisKey(string $cacheKey): string
    {
        return self::buildRedisKeyInNamespace((string)(Cache::getCacheConfig(Cache::CACHE_GROUP_REDIS)['namespace'] ?? ''), $cacheKey);
    }

    public static function buildRedisKeyInNamespace(string $namespace, string $cacheKey): string
    {
        return ($namespace === '' ? '' : $namespace . ':') . Cache::convertKeyToValidCacheKey($cacheKey);
    }

    protected function generateInputsVersionToken(): string
    {
        return bin2hex(random_bytes(6));
    }

    protected function readCachedBaseSystemPrompt(string $cacheKey): ?string
    {
        $cachedBaseSystemPrompt = $this->readCacheValue($cacheKey);
        return $cachedBaseSystemPrompt !== null && $cachedBaseSystemPrompt !== '' ? $cachedBaseSystemPrompt : null;
    }

    /**
     * While a rebuild of this key runs elsewhere (its warm marker is set), poll for its result — as long as a marker
     * exists, at most its lifetime; null = build it here.
     */
    protected function waitForBaseSystemPromptBuiltElsewhere(string $cacheKey): ?string
    {
        $waitedMilliseconds = 0;
        while ($waitedMilliseconds < self::WAIT_FOR_WARM_MAX_MILLISECONDS && $this->readCacheValue($cacheKey . self::WARM_MARKER_SUFFIX) !== null) {
            $this->sleepMilliseconds(self::WAIT_FOR_WARM_POLL_MILLISECONDS);
            $waitedMilliseconds += self::WAIT_FOR_WARM_POLL_MILLISECONDS;
            $cachedBaseSystemPrompt = $this->readCachedBaseSystemPrompt($cacheKey);
            if ($cachedBaseSystemPrompt !== null) {
                return $cachedBaseSystemPrompt;
            }
        }
        return null;
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Redis is shared by every worker and FPM process and survives worker recycles (APCu is per process). Null on a
     * miss ({@see Cache::get()} answers a miss with false) and on a cache error.
     */
    protected function readCacheValue(string $key): ?string
    {
        try {
            $cachedValue = Cache::instance(Cache::CACHE_GROUP_REDIS)->get($key);
            return is_string($cachedValue) ? $cachedValue : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function writeCacheValue(string $key, string $value, int $ttlSeconds): void
    {
        try {
            Cache::instance(Cache::CACHE_GROUP_REDIS)->set($key, $value, $ttlSeconds);
        } catch (Throwable) {
            // a cache that cannot be written only costs the next turn a build
        }
    }

    protected function deleteCacheValue(string $key): void
    {
        try {
            Cache::instance(Cache::CACHE_GROUP_REDIS)->delete($key);
        } catch (Throwable) {
            // the marker expires on its own
        }
    }
}
