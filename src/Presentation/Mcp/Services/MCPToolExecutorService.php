<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Services;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\MCPPackages\MCPEmptyStringIsValue;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAdminWithAccessToAllResources;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEntitlement;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresMandate;
use DDD\Domain\AI\Entities\MCPPackages\MCPExposedFilterProperties;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use DDD\Domain\AI\Exceptions\Conversations\OwnerConsentMissingException;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandateFirstUseBinding;
use DDD\Infrastructure\Exceptions\Exception as FrameworkException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponseDictatedWidgetTypeProvider;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolImageBudget;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageStore;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImageRejection;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Common\Services\CacheScopeInvalidationsService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolResultFactsService;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Dtos\JsonRpcError;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\DDDBundle;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Infrastructure\Services\Service;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Infrastructure\Traits\Serializer\Serializer;
use DDD\Infrastructure\Traits\Serializer\SerializerRegistry;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Request;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResult;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResultRecord;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\ListsEntityReferenceRows;
use Throwable;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolCallServiceThrowErrorsSnapshot;

/**
 * Shared MCP tool execution core — extracted out of {@see \DDD\Presentation\Mcp\MCPController}.
 *
 * One execution path, two callers:
 *  - {@see \DDD\Presentation\Mcp\MCPController} (HTTP adapter): JSON-RPC tools/call → here, with the bundle's packages.
 *  - the in-process agent loop ({@see \DDD\Domain\AI\Entities\Skills\AISkill} → packages): same call,
 *    with the loaded skills' packages, under the request's already-resolved auth context.
 *
 * The candidate package list is a parameter, so the listed/callable surface is whatever the caller
 * passes (bundle for HTTP, loaded skills for the agent) — the execution logic cannot diverge.
 */
class MCPToolExecutorService extends Service
{
    /** The name an empty entry of a raw `orderBy` argument carries in the refusal of a property outside the allow-list */
    public const string EMPTY_ORDER_BY_ENTRY_LABEL = '(empty entry)';

    /**
     * The tool→loop fields a markdown-rendered result keeps in its control-only `structuredContent`
     * (every key {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationRequestFromToolResult} reads).
     */
    public const array LOOP_CONTROL_FIELD_NAMES = [
        'backgroundOperations',
        'delegationTasks',
        'delegationTaskLabels',
        'delegationSkillNames',
        'delegationMaxStepsPerTurn',
        'delegationModelName',
        'delegationNextStepWhenAllSubAgentsEnded',
        'delegationAwaitedConversationIds',
        'delegationNotifiesParentWithoutResuming',
        'turnMinimumSteps',
        'outcome',
    ];

    /**
     * @var int Per-tool-call DEFAULT cap on images split from one result — excess degrades to a text
     * note. A tool whose honest output is more (several composed sheets) raises it with #[MCPToolImageBudget], up
     * to {@see MAX_IMAGES_PER_TOOL_CALL_CEILING}.
     */
    public const int DEFAULT_MAX_IMAGES_PER_TOOL_CALL = 4;

    /** @var int The hard ceiling no #[MCPToolImageBudget] can exceed — the flood guard stays a guard. */
    public const int MAX_IMAGES_PER_TOOL_CALL_CEILING = 12;

    /** @var int Aggregate DECODED-byte budget of all images in one result (the per-image ceiling is on ToolResultImage) — excess degrades to a text note. */
    public const int MAX_IMAGE_BYTES_PER_TOOL_CALL = 6_291_456; // 6 MB

    /**
     * @deprecated read the tool's own budget via {@see imageBudgetForTool()}; kept as the default's alias for callers
     *             that compose their attachments against the cap.
     */
    public const int MAX_IMAGES_PER_TOOL_CALL = self::DEFAULT_MAX_IMAGES_PER_TOOL_CALL;

    /**
     * Execute a tool and return the layer-neutral {@see ToolResult} the agent loop feeds back. Wraps
     * {@see callTool()} and maps the Presentation MCPToolResponse → ToolResult, so no Presentation type
     * leaks into the result the domain consumes.
     *
     * @param array<string, mixed>|null $arguments
     * @param array<int, class-string> $packageClasses
     * @throws JsonRpcError when the tool is not found in any candidate package
     */
    /**
     * The recorded-run comparison's tool results: while set, every tool call of this process is recorded or answered
     * from the recording ({@see ToolResultRecording}). Null in every normal run.
     */
    public static ?ToolResultRecording $toolResultRecording = null;

    public function execute(string $toolName, ?array $arguments, array $packageClasses, ?array $logContext = null, ?MCPServerProvidedInput $serverProvidedInput = null): ToolResult
    {
        if (self::$toolResultRecording !== null) {
            /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
            $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
            if (self::$toolResultRecording->mode === ToolResultRecording::MODE_PLAY) {
                $playedToolResult = self::$toolResultRecording->playResult($toolName, $arguments);
                // the tool did not run, so nothing was booked: the costs the recorded call booked go on the open recording
                if ($playedToolResult->recordedBookedCosts !== null) {
                    $aIUsageEnvelopesService->recordBookedCostsOf($playedToolResult->recordedBookedCosts);
                }
                return $playedToolResult;
            }
            // the Argus calls the tool makes are its own business: a replay plays the tool's result, never those calls
            $transportRecording = ArgusApiOperations::$transportRecording;
            if ($transportRecording !== null) {
                $transportRecording->insideToolExecution = true;
            }
            // the AI costs THIS tool books, kept with its result and then handed whole to the enclosing recording — the
            // same hand-over the replay makes, so the recorded and the replayed tool call carry identical costs
            $toolBookedCosts = $aIUsageEnvelopesService->startRecordingBookedCosts();
            try {
                $recordedToolResult = $this->executeTool($toolName, $arguments, $packageClasses, $logContext, $serverProvidedInput);
            } finally {
                $aIUsageEnvelopesService->stopRecordingBookedCosts($toolBookedCosts);
                $aIUsageEnvelopesService->recordBookedCostsOf($toolBookedCosts);
                if ($transportRecording !== null) {
                    $transportRecording->insideToolExecution = false;
                }
            }
            self::$toolResultRecording->recordResult($toolName, $arguments, $recordedToolResult, $toolBookedCosts);
            return $recordedToolResult;
        }
        return $this->executeTool($toolName, $arguments, $packageClasses, $logContext, $serverProvidedInput);
    }

    /** The tool call itself — {@see execute()} without the recorded-run comparison's recording. */
    protected function executeTool(string $toolName, ?array $arguments, array $packageClasses, ?array $logContext = null, ?MCPServerProvidedInput $serverProvidedInput = null): ToolResult
    {
        // Every agent tool call decides cache-scope bypasses from CURRENT invalidation rows: the service's
        // per-process snapshot statics are never refreshed on their own, so in the long-running worker a
        // snapshot taken before an earlier write HID that write's invalidation and the next read served the
        // stale cache (a field was written, the summary built from it still showed the old value).
        // on the RESOLVED instance (late static binding): an application's same-path service resets its own statics too
        /** @var CacheScopeInvalidationsService $cacheScopeInvalidationsService */
        $cacheScopeInvalidationsService = DDDService::instance()->getService(CacheScopeInvalidationsService::class);
        $cacheScopeInvalidationsService::resetSnapshotStatics();
        $response = $this->callTool($toolName, $arguments, $packageClasses, null, false, $serverProvidedInput);
        // Split the MCP content parts: image parts leave the text into $toolResult->images, everything else
        // (text/resource) stays in the json-encoded content. Without this the tool's base64 is json_encoded
        // into the tool TEXT — tens of thousands of tokens billed, no image decoded (the armed
        // trap). Validation runs per image; a rejected image degrades to a text note, never a failed call.
        $toolResultImages = [];
        $nonImageContentParts = [];
        $imageBudget = $this->imageBudgetForTool($toolName, $packageClasses);
        $imageBytesUsed = 0;
        foreach ($response->content as $contentPart) {
            if (!is_array($contentPart) || ($contentPart['type'] ?? null) !== 'image') {
                $nonImageContentParts[] = $contentPart;
                continue;
            }
            if (count($toolResultImages) >= $imageBudget) {
                $nonImageContentParts[] = ['type' => 'text', 'text' => "[an image was dropped: more than $imageBudget images in one tool result]"];
                continue;
            }
            $toolResultImage = ToolResultImage::decodeMcpImageContentPart($contentPart);
            if ($toolResultImage instanceof ToolResultImageRejection) {
                $nonImageContentParts[] = ['type' => 'text', 'text' => "[an image was dropped: {$toolResultImage->reason}]"];
                continue;
            }
            // Aggregate budget: many valid images can still be too many bytes for one result (a result-count limit alone
            // is not a bound).
            if ($imageBytesUsed + $toolResultImage->byteSize > self::MAX_IMAGE_BYTES_PER_TOOL_CALL) {
                $nonImageContentParts[] = ['type' => 'text', 'text' => '[an image was dropped: the images of this result exceed ' . intdiv(self::MAX_IMAGE_BYTES_PER_TOOL_CALL, 1_048_576) . ' MB together]'];
                continue;
            }
            $imageBytesUsed += $toolResultImage->byteSize;
            $toolResultImages[] = $toolResultImage;
        }
        $responseWithoutImages = new MCPToolResponse($nonImageContentParts, $response->structuredContent, $response->isError);
        $responseWithoutImages->preservedFacts = $response->preservedFacts;
        $toolResult = new ToolResult(
            json_encode($responseWithoutImages->jsonSerialize(), JSON_THROW_ON_ERROR),
            $response->isError,
        );
        // the `ComposedView` record of a bindable result rides BESIDE the result, never inside it
        $toolResult->bindableResult = $response->isError ? null : $response->getBindableResultRecord()?->toStoredArray();
        $toolResult->images = $toolResultImages;
        $toolResult->refusalKind = $response->refusalKind;
        $toolResult->platformNoteForModel = $response->getPlatformNoteForModel();
        $toolResult->dictatedWidgetType = $response->isError ? null : $response->getDictatedWidgetType();
        if ($toolResult->isError) {
            $this->logToolFailure($toolName, $arguments ?? [], $toolResult->content, null, $logContext);
        }
        return $toolResult;
    }

    /**
     * The image cap for $toolName: its own #[MCPToolImageBudget] (method before package class, bounded by
     * {@see MAX_IMAGES_PER_TOOL_CALL_CEILING}) when one of the candidate packages declares it, else
     * {@see DEFAULT_MAX_IMAGES_PER_TOOL_CALL}. Reflection only — the tool is resolved to its package exactly like the
     * dispatch does; an unknown tool gets the default.
     *
     * @param array<int, class-string> $packageClasses
     */
    public function imageBudgetForTool(string $toolName, array $packageClasses): int
    {
        foreach ($packageClasses as $packageClass) {
            try {
                $packageReflection = new ReflectionClass($packageClass);
                if (!$packageReflection->hasMethod($toolName)) {
                    continue;
                }
                $method = $packageReflection->getMethod($toolName);
                if ($method->getAttributes(MCPTool::class) === []) {
                    continue;
                }
                $budgetAttributes = $method->getAttributes(MCPToolImageBudget::class) ?: $packageReflection->getAttributes(MCPToolImageBudget::class);
                if ($budgetAttributes === []) {
                    return self::DEFAULT_MAX_IMAGES_PER_TOOL_CALL;
                }
                /** @var MCPToolImageBudget $budgetAttribute */
                $budgetAttribute = $budgetAttributes[0]->newInstance();
                return max(1, min(self::MAX_IMAGES_PER_TOOL_CALL_CEILING, $budgetAttribute->images));
            } catch (\ReflectionException) {
                continue;
            }
        }
        return self::DEFAULT_MAX_IMAGES_PER_TOOL_CALL;
    }

    /**
     * Re-resolve the bytes of a persisted tool-call image through the loaded package that declares its store
     * ({@see ToolCallImageStore}) — the loop calls this per model call for every image-bearing result inside the wire
     * window whose bytes this process does not hold (a worker chunk yielded, a turn boundary, a resumed worker). Null
     * for an inline image (never re-resolvable), an unknown store, or a picture that no longer exists; a store
     * failure is swallowed — a picture must never break a model call.
     *
     * @param array<int, class-string> $packageClasses
     */
    public function resolveToolCallImage(ToolCallImageReference $toolCallImageReference, array $packageClasses): ?ToolResultImage
    {
        if (!$toolCallImageReference->isReResolvable()) {
            return null;
        }
        foreach ($packageClasses as $packageClass) {
            if (!is_a($packageClass, ToolCallImageStore::class, true)
                || $packageClass::getToolCallImageStoreName() !== $toolCallImageReference->sourceStore) {
                continue;
            }
            try {
                $package = $this->resolveFromContainer($packageClass) ?? new $packageClass();
                if (!$package instanceof ToolCallImageStore) {
                    continue;
                }
                return $package->resolveToolCallImage($toolCallImageReference);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * Append a tool-call FAILURE to `tool-errors.jsonl` in {@see getToolFailureLogDirectory()} with full detail (tool, args, error,
     * exception class + stack) so the TOOL layer is debuggable across interactive / eval / production agent runs —
     * independent of whether a conversation trace is persisted, and with more than the truncated message the model
     * sees. Lives HERE, the universal tool-execution point, so every failed tool is logged ONCE for all callers
     * (rather than bloating the agent loop). One JSON object per line (greppable). Best-effort: a logging failure must
     * never break the caller. $logContext folds in optional caller context (conversationId / isDelegation / model).
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $logContext
     */
    public function logToolFailure(string $toolName, array $arguments, string $error, ?\Throwable $exception, ?array $logContext = null): void
    {
        try {
            $dir = $this->getToolFailureLogDirectory();
            if ($dir === null) {
                return;
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $logEntry = array_merge(
                [
                    'ts' => date('Y-m-d H:i:s'),
                    'tool' => $toolName,
                    'args' => $arguments,
                    'error' => mb_substr($error, 0, 2000),
                    'exceptionClass' => $exception !== null ? $exception::class : null,
                    'stack' => $exception !== null ? mb_substr($exception->getTraceAsString(), 0, 4000) : null,
                ],
                $logContext ?? []
            );
            @file_put_contents(
                $dir . '/tool-errors.jsonl',
                (string)json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // best-effort: never break the caller on a logging error.
        }
    }

    /**
     * Override point: the directory {@see logToolFailure()} appends `tool-errors.jsonl` to. Base: null = no file is
     * written (the failure still reaches the model and the conversation trace); an application that wants the file
     * returns a writable directory.
     *
     * @return string|null
     */
    protected function getToolFailureLogDirectory(): ?string
    {
        return null;
    }

    /**
     * @var string[] Tool names REFUSED at execution time — the eval harness's hard safety guard (a write-boundary
     * eval case must be structurally unable to publish: `forbiddenTools` in `expect` only FAILS the case after the
     * fact, this blocks the call itself). Process-static, set per eval case in a try/finally
     * (AgentEvalRunner); empty in production (nothing sets it outside the eval path).
     */
    public static array $blockedToolNames = [];

    /**
     * Optional per-tool SIMULATED error text for a blocked tool (eval only): the hard guard intercepts BEFORE the
     * real dispatch, so without this the model sees a generic refusal instead of the ground-truth failure the case
     * is about (found via multistep.taskseed: the generic text reads as a technical/temporary error → models never
     * set requiresUserAction). Keyed by tool name; falls back to the generic refusal.
     */
    public static array $blockedToolSimulatedErrors = [];

    /**
     * Eval only: refuse EVERY tool that is not a declared read ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog::isReadOnlyToolName()}).
     * Set for a case that may only read the live data it runs on — a read case that drifts into a write (a publish, a
     * schedule, an edit) must never change what other cases read at the same time. Process-static,
     * false in production (nothing sets it outside the eval path); the harness's own calls are exempt.
     */
    public static bool $everyWriteToolBlocked = false;

    /** @var string[][] Per-process cache: root conversation id → the purpose's blocked tool names. */
    protected static array $purposeBlockedToolNamesByRootConversationId = [];

    /** @var array<int, string|null> Per-process cache: root conversation id → the purpose's own refusal text for a blocked tool. */
    protected static array $purposeBlockedToolsRefusalTextByRootConversationId = [];

    /** @var array<int, RunSettings|null> Per-process cache: root conversation id → its run settings (the persisted eval tool block). */
    protected static array $evalRunSettingsByRootConversationId = [];

    /**
     * A new worker job starts without tool blocks: the eval guards are set per case inside one console process and
     * must never reach a customer job on a worker, and the purpose block cache re-reads the purpose per job. Called
     * by the handler base's job reset only — never between the turns of one eval case, which keeps its guards.
     */
    public static function resetToolBlocksForNewJob(): void
    {
        self::$blockedToolNames = [];
        self::$blockedToolSimulatedErrors = [];
        self::$everyWriteToolBlocked = false;
        self::$purposeBlockedToolNamesByRootConversationId = [];
        self::$purposeBlockedToolsRefusalTextByRootConversationId = [];
        self::$evalRunSettingsByRootConversationId = [];
    }

    /**
     * The refusal text when the eval tool block persisted on the ROOT conversation's run settings refuses $toolName
     * ({@see RunSettings::describeEvalToolBlock()}), else null. The live root conversation of the provenance is read
     * directly; a sub-agent's root is loaded once per root conversation per process. Never throws — an unresolvable
     * conversation blocks nothing.
     */
    protected function describeConversationEvalToolBlock(string $toolName, ?MCPServerProvidedInput $serverProvidedInput): ?string
    {
        if (!$serverProvidedInput instanceof AgentToolCallProvenance) {
            return null;
        }
        $rootConversationId = $serverProvidedInput->rootConversationId ?? $serverProvidedInput->conversationId;
        if ($rootConversationId === null) {
            return null;
        }
        $liveConversation = $serverProvidedInput->conversation;
        if ($liveConversation !== null && (int)($liveConversation->id ?? 0) === (int)$rootConversationId) {
            return isset($liveConversation->runSettings) ? $liveConversation->runSettings->describeEvalToolBlock($toolName) : null;
        }
        if (!array_key_exists($rootConversationId, self::$evalRunSettingsByRootConversationId)) {
            // read past the caller's rights (a sub-user's auth would filter the root away) — a failed read is not
            // cached, so the next tool call of the job reads again instead of the block staying open for the job
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $rootConversation = AIConversation::getService()->find((int)$rootConversationId);
            } catch (\Throwable $rootReadError) {
                error_log('Eval tool block: root conversation ' . $rootConversationId . ' not readable — ' . $rootReadError->getMessage());
                return null;
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
            self::$evalRunSettingsByRootConversationId[$rootConversationId] = $rootConversation !== null && isset($rootConversation->runSettings)
                ? $rootConversation->runSettings
                : null;
        }
        return self::$evalRunSettingsByRootConversationId[$rootConversationId]?->describeEvalToolBlock($toolName);
    }

    /**
     * The refusal text when the ROOT conversation's purpose blocks $toolName (frontmatter `blockedToolNames`), else
     * null. Resolved once per root conversation per process; a child of a purposed conversation inherits the block
     * through `rootConversationId`. Never throws — an unresolvable conversation blocks nothing.
     */
    protected function describePurposeToolBlock(string $toolName, ?MCPServerProvidedInput $serverProvidedInput): ?string
    {
        if (!$serverProvidedInput instanceof \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance) {
            return null;
        }
        $rootConversationId = $serverProvidedInput->rootConversationId ?? $serverProvidedInput->conversationId;
        if ($rootConversationId === null) {
            return null;
        }
        if (!array_key_exists($rootConversationId, self::$purposeBlockedToolNamesByRootConversationId)) {
            $blockedToolNames = [];
            $blockedToolsRefusalText = null;
            try {
                $rootConversation = $serverProvidedInput->conversation !== null && (int)($serverProvidedInput->conversation->id ?? 0) === (int)$rootConversationId
                    ? $serverProvidedInput->conversation
                    : \DDD\Domain\AI\Entities\Conversations\AIConversation::getService()->find((int)$rootConversationId);
                $purposeName = $rootConversation?->purposeName ?? null;
                if ($purposeName !== null && $purposeName !== '') {
                    /** @var \DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService $aIConversationPurposesService */
                    $aIConversationPurposesService = DDDService::instance()->getService(\DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService::class);
                    $rootConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
                    $blockedToolNames = $rootConversationPurpose?->blockedToolNames ?? [];
                    $blockedToolsRefusalText = $rootConversationPurpose?->blockedToolsRefusalText;
                }
            } catch (\Throwable) {
                $blockedToolNames = [];
            }
            self::$purposeBlockedToolNamesByRootConversationId[$rootConversationId] = $blockedToolNames;
            self::$purposeBlockedToolsRefusalTextByRootConversationId[$rootConversationId] = $blockedToolsRefusalText;
        }
        if (!in_array($toolName, self::$purposeBlockedToolNamesByRootConversationId[$rootConversationId], true)) {
            return null;
        }
        $purposeRefusalText = self::$purposeBlockedToolsRefusalTextByRootConversationId[$rootConversationId] ?? null;
        return "Tool '{$toolName}' is not available inside this purposed conversation"
            . ($purposeRefusalText !== null ? ': ' . $purposeRefusalText : '.');
    }

    /**
     * Find the package exposing $toolName among $candidatePackageClasses, hydrate its arguments, invoke it. A call of
     * an admin-audience tool is recorded ({@see recordAdminAudienceToolCall()}).
     *
     * @param string $toolName
     * @param array<string, mixed>|null $arguments
     * @param array<int, class-string> $candidatePackageClasses
     * @param Request|null $requestContext Original request, for header/cookie/file context on RequestDto hydration
     * @param bool $errorsAsJson When true, rethrow tool exceptions (debug path) instead of an isError response; the
     * caller decides who may ask for it
     * @param MCPServerProvidedInput|null $serverProvidedInput What the server knows about the call (the in-process
     * agent loop passes its provenance; a call over HTTP passes none)
     * @return MCPToolResponse
     * @throws JsonRpcError when the tool is not found in any candidate package
     */
    public function callTool(
        string $toolName,
        ?array $arguments,
        array $candidatePackageClasses,
        ?Request $requestContext = null,
        bool $errorsAsJson = false,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): MCPToolResponse {
        // THE ADMIN AUDIT: every call of an admin-audience tool leaves one row — refusals and
        // exceptions included — for whichever caller (the admin MCP mount, a CLI, a later in-process admin agent).
        if (!$this->isAdminAudienceTool($toolName, $candidatePackageClasses)) {
            return $this->callToolWithoutAudit($toolName, $arguments, $candidatePackageClasses, $requestContext, $errorsAsJson, $serverProvidedInput);
        }
        $startedAtNanoseconds = hrtime(true);
        $mcpToolResponse = null;
        $exceptionClass = null;
        try {
            $mcpToolResponse = $this->callToolWithoutAudit($toolName, $arguments, $candidatePackageClasses, $requestContext, $errorsAsJson, $serverProvidedInput);
            return $mcpToolResponse;
        } catch (Throwable $throwable) {
            $exceptionClass = $throwable::class;
            throw $throwable;
        } finally {
            $this->recordAdminAudienceToolCall(
                $toolName,
                is_array($arguments) ? $arguments : [],
                $candidatePackageClasses,
                (int)((hrtime(true) - $startedAtNanoseconds) / 1000000),
                $mcpToolResponse,
                $exceptionClass
            );
        }
    }

    /**
     * True when the tool belongs to a package of the admin audience ({@see MCPRequiresAdminWithAccessToAllResources}).
     *
     * @param array<int, class-string> $candidatePackageClasses
     */
    protected function isAdminAudienceTool(string $toolName, array $candidatePackageClasses): bool
    {
        foreach ($candidatePackageClasses as $candidatePackageClass) {
            if (!method_exists($candidatePackageClass, $toolName)) {
                continue;
            }
            if ((new ReflectionClass($candidatePackageClass))->getAttributes(MCPRequiresAdminWithAccessToAllResources::class) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Override point: records one tool call made on the admin audience (an application's audit trail). Called in a
     * `finally` block after every call, successful or not. Base: records nothing.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<int, class-string> $candidatePackageClasses
     */
    protected function recordAdminAudienceToolCall(
        string $toolName,
        array $arguments,
        array $candidatePackageClasses,
        int $durationMs,
        ?MCPToolResponse $mcpToolResponse,
        ?string $exceptionClass
    ): void {
    }

    /**
     * The call itself: the eval and purpose blocks, then the dispatch inside an AI-usage envelope.
     *
     * @param array<array-key, mixed>|null $arguments
     * @param array<int, class-string> $candidatePackageClasses
     */
    protected function callToolWithoutAudit(
        string $toolName,
        ?array $arguments,
        array $candidatePackageClasses,
        ?Request $requestContext = null,
        bool $errorsAsJson = false,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): MCPToolResponse {
        if (!is_array($arguments)) {
            $arguments = [];
        }
        $isEvalHarnessCall = $serverProvidedInput instanceof AgentToolCallProvenance && $serverProvidedInput->isEvalHarnessCall;
        $processEvalBlockRefusal = RunSettings::describeEvalToolRefusal(
            $toolName,
            self::$blockedToolNames,
            self::$blockedToolSimulatedErrors,
            self::$everyWriteToolBlocked && !$isEvalHarnessCall
        );
        if ($processEvalBlockRefusal !== null) {
            return MCPToolResponse::error($processEvalBlockRefusal);
        }
        // The eval tool block PERSISTED on the conversation: the same refusal in every process, the worker that resumes
        // the conversation included (the process guards above live only in the console that drives the case).
        $conversationEvalBlockRefusal = $isEvalHarnessCall ? null : $this->describeConversationEvalToolBlock($toolName, $serverProvidedInput);
        if ($conversationEvalBlockRefusal !== null) {
            return MCPToolResponse::error($conversationEvalBlockRefusal);
        }
        // PURPOSE-LEVEL block (structural, for the whole delegation tree): the ROOT conversation's purpose may forbid
        // tools — a run that builds one thing must not edit other data of the space to make its result fit.
        $purposeBlockRefusal = $this->describePurposeToolBlock($toolName, $serverProvidedInput);
        if ($purposeBlockRefusal !== null) {
            return MCPToolResponse::error($purposeBlockRefusal);
        }
        // Every MCP tool call runs inside an AI-usage envelope, so a tool that triggers a billable LLM op (an
        // #[ArgusLanguageModel] load — e.g. a generated summary or a drafted reply) is always cost-attributed,
        // across ALL entry points (HTTP MCP server, in-process agent, the application's console). The envelope wraps
        // the WHOLE dispatch incl. the LlmMarkdownRenderable render below (which can lazy-load such an op). Open one ONLY
        // if none is active: the agent loop's per-turn envelope already wraps this (nesting supported) and is reused; the
        // envelope-less paths get this generic ENVELOPE_MCP_TOOL_CALL fallback. Per-tool #[WithAIEnvelope] still works as
        // a nested precision override where a specific scope is wanted.
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
            return $this->dispatchTool($toolName, $arguments, $candidatePackageClasses, $requestContext, $errorsAsJson, $serverProvidedInput);
        }
        // The MCP / agent path ALWAYS runs in an account context, so the envelope is attributed to that account for
        // usage-logging + budget — not null.
        $toolCallEnvelope = $aIUsageEnvelopesService->openEnvelope(
            AuthService::instance()->getAccount()->id,
            AgentAIUsageScope::ENVELOPE_MCP_TOOL_CALL
        );
        try {
            return $this->dispatchTool($toolName, $arguments, $candidatePackageClasses, $requestContext, $errorsAsJson, $serverProvidedInput);
        } finally {
            $aIUsageEnvelopesService->closeEnvelope($toolCallEnvelope);
        }
    }

    /**
     * Find the matching #[MCPTool] method across the candidate packages, invoke it, and render its response
     * (LLM-markdown by default, structured on outputFormat=structured). Always runs inside an AI-usage envelope
     * (see {@see callTool()}).
     *
     * @param array<array-key, mixed> $arguments
     * @param array<int, class-string> $candidatePackageClasses
     */
    protected function dispatchTool(
        string $toolName,
        array $arguments,
        array $candidatePackageClasses,
        ?Request $requestContext,
        bool $errorsAsJson,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): MCPToolResponse {
        // Resolve the tool to exactly ONE package BEFORE executing — never first-match-wins: with a name collision a
        // silent misroute would execute the wrong domain's tool (worst failure class: wrong data, wrong writes).
        // Global uniqueness is enforced at build time (by a test of the application); this guard covers dynamically
        // assembled candidate lists as defense in depth.
        $matchingPackageClasses = [];
        foreach ($candidatePackageClasses as $candidatePackageClass) {
            try {
                $candidateReflection = new ReflectionClass($candidatePackageClass);
                if ($candidateReflection->hasMethod($toolName)
                    && $candidateReflection->getMethod($toolName)->getAttributes(MCPTool::class) !== []) {
                    $matchingPackageClasses[] = $candidatePackageClass;
                }
            } catch (\ReflectionException) {
                continue;
            }
        }
        if (count($matchingPackageClasses) > 1) {
            throw new JsonRpcError(
                JsonRpcError::JSON_RPC_CODE_INVALID_PARAMS,
                "Tool '$toolName' is AMBIGUOUS — exposed by more than one package: " . implode(', ', $matchingPackageClasses)
                . '. Tool names must be globally unique (rename per the eineindeutig naming rule).'
            );
        }

        foreach ($matchingPackageClasses as $packageClass) {
            try {
                $reflection = new ReflectionClass($packageClass);
                $method = $reflection->getMethod($toolName);

                // THE ADMIN GATE: a package of the admin audience runs only for an admin with access to
                // all resources — whoever calls it (the admin MCP mount, a CLI, a later in-process admin agent).
                $isAdminAudiencePackage = $reflection->getAttributes(MCPRequiresAdminWithAccessToAllResources::class) !== [];
                if ($isAdminAudiencePackage && !$this->isCallerAdminWithAccessToAllResources()) {
                    return MCPToolResponse::gateRefusal(
                        "Tool '{$toolName}' was NOT executed: it belongs to the admin audience and needs an admin account "
                        . 'with access to all resources.',
                        MCPToolResponse::REFUSAL_ADMIN_ACCESS_REQUIRED
                    );
                }

                // no first-use mandate binding of an earlier call survives into this one: a failure below (the target
                // resolution included) releases only what THIS call bound
                $this->firstUseMandateBinding = null;

                // THE ARGUMENTS THE CONVERSATION'S SPACE FILLS — BEFORE the gates and the DTO hydration, so both see
                // the filled arguments.
                $arguments = $this->applyAgentSpaceDefaultsToToolArguments($method, $arguments, $serverProvidedInput);

                // THE TARGET OF THE CALL, asked ONCE and handed to every gate below: the space an argument names,
                // else the space of the conversation the call runs in, else none (the call works at account level).
                // A named space the account may not use refuses the call here: it never runs for the conversation's
                // space or "without a space" instead.
                try {
                    $targetAgentSpace = $this->findAgentSpaceOfToolCall($arguments, $serverProvidedInput);
                } catch (ForbiddenException) {
                    return MCPToolResponse::error($this->buildAgentSpaceNotUsableRefusalText($toolName));
                }

                // THE HARD ENTITLEMENT GATE. The offer side filters a gated tool out of the turn
                // payload, but only the agent loop sees that filtered offer — the HTTP MCP server and
                // an application's console command reach this method directly, and inside the loop a tool that was
                // resident when the entitlement still held survives a mid-conversation downgrade. Refusing here is
                // what makes the gate hard for all three.
                $entitlementRefusal = $this->refuseUnentitledToolCall($toolName, $reflection, $method, $targetAgentSpace, $serverProvidedInput);
                if ($entitlementRefusal !== null) {
                    return $entitlementRefusal;
                }

                // THE ELIGIBILITY GATE of the executor: a run whose account, or what it works for, became deleted /
                // locked / erased MID-RUN gets every further tool call refused deterministically. Not for an admin
                // package: the admin inspects an ended customer exactly when something went wrong there, and an
                // admin tool that targets no space would otherwise be judged by the admin's own account.
                $eligibilityRefusal = $isAdminAudiencePackage
                    ? null
                    : $this->refuseToolCallForIneligibleScope($toolName, $targetAgentSpace, $serverProvidedInput);
                if ($eligibilityRefusal !== null) {
                    return $eligibilityRefusal;
                }

                // A list tool that names its filter properties (#[MCPExposedFilterProperties]) accepts ONLY those in
                // `filters` and `orderBy`. The model's raw strings are read BEFORE hydration: hydration validates a
                // filter against the entity's own definitions and its error lists every filterable property of the
                // entity, names the tool never exposed. Placed before the mandate gate: the check reads only the method
                // and the arguments, and a refusal here leaves no first-use mandate binding behind.
                $rawQueryOptionArgumentsRefusal = $this->refuseRawQueryOptionArgumentsOutsideExposedFilterProperties($toolName, $method, $arguments);
                if ($rawQueryOptionArgumentsRefusal !== null) {
                    return $rawQueryOptionArgumentsRefusal;
                }

                // THE MANDATE GATE for consequential outward actions: a
                // #[MCPToolRequiresMandate] tool executes in the agent loop ONLY under an ACTIVE mandate
                // for its action class — granted from the customer's own verified statement, never a claim.
                $mandateRefusal = $this->refuseActionLackingMandate($toolName, $method, $targetAgentSpace, $serverProvidedInput);
                if ($mandateRefusal !== null) {
                    return $mandateRefusal;
                }

                // THE OWNER'S CONSENT for a #[MCPToolRequiresOwnerConfirmation] tool: checked inside the tool
                // body, after its own refusals and before its write ({@see OwnerConsentsService::requireOwnerConsentForStep()});
                // a missing consent comes back as OwnerConsentMissingException → the gate refusal in the catch below.

                // Prefer Symfony DI to create the package (so constructor DI works), fallback to plain instantiation.
                $package = $this->resolveFromContainer($packageClass) ?? $reflection->newInstance();

                // the model's clock is the space's local zone; the CONVERSION happens here and nowhere
                // else. Region 1: the model's arguments are hydrated with the input zone set, so a DateTime argument
                // written as local wall-clock time becomes a UTC instant (an explicit offset wins; Date untouched) and
                // the DTO's temporal filter literals are normalized in the same pass. Null zone (HTTP-MCP, a legacy
                // conversation) = no registry value = byte-for-byte today's hydration.
                $conversationTimezone = $serverProvidedInput instanceof AgentToolCallProvenance
                    ? $serverProvidedInput->getTimezone()
                    : null;
                $params = SerializerRegistry::withInputTimezone(
                    $conversationTimezone,
                    fn (): array => $this->buildMethodArguments($method, $arguments, $requestContext, $serverProvidedInput)
                );

                // The same allow-list on the hydrated request objects, whatever filled their query options.
                $exposedFilterPropertiesRefusal = $this->refuseQueryOptionsOutsideExposedFilterProperties($toolName, $method, $params);
                if ($exposedFilterPropertiesRefusal !== null) {
                    return $exposedFilterPropertiesRefusal;
                }

                // The injected services are DI-shared: a `throwErrors` the tool sets must not outlive this call
                // ({@see MCPToolCallServiceThrowErrorsSnapshot}). The rendering below stays inside, as the tool left it.
                $injectedServicesThrowErrorsSnapshot = MCPToolCallServiceThrowErrorsSnapshot::takeFromToolMethodArguments($params);
                try {
                    // The tool body runs with NO registry value set: its DB loads, Argus calls and persists see UTC as today.
                    $result = $method->invokeArgs($package, $params);
                    // A tool that ALREADY built its own MCPToolResponse — the way to return IMAGE content parts via
                    // addImageContent (e.g. viewPageScreenshot) — passes through verbatim. Without this it would
                    // fall through to the json_encode below and be double-wrapped, losing its image parts; the image split
                    // in execute() then lifts those parts to the multimodal model.
                    if ($result instanceof MCPToolResponse) {
                        if ($result->isError) {
                            $this->releaseFirstUseMandateBindingOfFailedCall();
                        }
                        return $result;
                    }
                    // LLM-optimized output is the MCP DEFAULT (generalized across ALL packages): unless the caller
                    // EXPLICITLY asked for the structured shape (`outputFormat=structured`), any response that can
                    // render itself compactly (implements LlmMarkdownRenderable) returns that Markdown — the model
                    // reads a relay-ready table instead of a heavy structured tree. A response that doesn't implement
                    // the interface is unaffected (still structured). `outputFormat` stays optional/nullable so we can
                    // tell "caller didn't specify" (→ LLM default) from "caller chose structured".
                    $explicitStructured = (($arguments['outputFormat'] ?? null) === RequestDto::OUTPUT_FORMAT_STRUCTURED);
                    // Region 2: ONLY the rendering of the result for the model runs with the presentation zone —
                    // a DateTime (never a Date) renders as local time with its offset, but solely under the
                    // MODEL_FACING_DATETIME flag, so a persist or an Argus call a lazy load triggers in here (forPersistence,
                    // no flags) can never render local digits into storage. The markdown renderers read the same zone.
                    return SerializerRegistry::withModelFacingTimezone(
                        $conversationTimezone,
                        fn (): MCPToolResponse => $this->renderToolResultForModel($result, $toolName, $explicitStructured)
                    );
                } finally {
                    $injectedServicesThrowErrorsSnapshot->restore();
                }
            } catch (ReflectionException $e) {
                continue;
            } catch (AgentRunClaimLostException $claimLost) {
                // the turn's one claim-lost exit — never a failed tool result: another worker drives now
                $this->releaseFirstUseMandateBindingOfFailedCall();
                throw $claimLost;
            } catch (OwnerConsentMissingException $ownerConsentMissing) {
                // the tool wrote nothing — a gate refusal the loop recognizes, never a failed execution
                $this->releaseFirstUseMandateBindingOfFailedCall();
                return MCPToolResponse::gateRefusal($ownerConsentMissing->getMessage(), MCPToolResponse::REFUSAL_OWNER_CONFIRMATION_REQUIRED);
            } catch (Throwable $e) {
                $this->releaseFirstUseMandateBindingOfFailedCall();
                // errors_as_json: let the real exception (+ stack) propagate to JsonErrorExceptionSubscriber
                // instead of swallowing it — same debug mechanism as the regular endpoints.
                if ($errorsAsJson) {
                    throw $e;
                }
                return new MCPToolResponse(
                    $this->buildToolFailureText($toolName, $e, $serverProvidedInput),
                    $e instanceof FrameworkException && $this->isToolFailureMessageWrittenForTheCaller($e) ? $e->jsonSerialize() : null,
                    true
                );
            }
        }
        throw new JsonRpcError(
            JsonRpcError::JSON_RPC_CODE_INVALID_PARAMS,
            "Tool '$toolName' not found in this domain"
        );
    }

    /**
     * Override point: the text a failed tool call answers with, for every caller (a call over HTTP and the in-process
     * agent loop, which passes its provenance as `$serverProvidedInput`).
     *
     * A failure whose message is written for the caller ({@see isToolFailureMessageWrittenForTheCaller()}) keeps it. Any other
     * throwable is an internal error: its message may name tables, hosts or paths, so it is written to the issues log
     * and the answer carries the issue id only.
     *
     * @param string $toolName
     * @param Throwable $throwable What the tool call threw
     * @param MCPServerProvidedInput|null $serverProvidedInput The in-process caller's provenance; null for a call over HTTP
     * @return string
     */
    protected function buildToolFailureText(string $toolName, Throwable $throwable, ?MCPServerProvidedInput $serverProvidedInput): string
    {
        if ($this->isToolFailureMessageWrittenForTheCaller($throwable)) {
            return "Execution failed for Tool '$toolName': {$throwable->getMessage()}";
        }
        $issueId = $this->logInternalToolFailure($toolName, $throwable);
        return "Execution failed for Tool '$toolName': an internal error occurred"
            . ($issueId !== null ? " (issue id $issueId)" : '')
            . '. The error was logged; retrying with the same arguments will most likely fail again.';
    }

    /**
     * True for a throwable whose message is written for the caller: an exception of the framework with a client error
     * status (400–499: bad request, unauthorized, forbidden, not found and their subclasses), and the loop's own
     * execution timeout.
     *
     * @param Throwable $throwable
     * @return bool
     */
    protected function isToolFailureMessageWrittenForTheCaller(Throwable $throwable): bool
    {
        if ($throwable instanceof AgentToolExecutionTimeoutException) {
            return true;
        }
        return $throwable instanceof FrameworkException && $throwable->getCode() >= 400 && $throwable->getCode() <= 499;
    }

    /**
     * Writes an internal tool failure to the issues log and returns its issue id (the group hash an operator finds it
     * by); null when the log has no id for it. Never throws: a failed log write must not replace the tool's answer.
     *
     * @param string $toolName
     * @param Throwable $throwable
     * @return string|null
     */
    protected function logInternalToolFailure(string $toolName, Throwable $throwable): ?string
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable($throwable, additionalContext: ['mcpToolName' => $toolName]);
            return $issuesLogService->getGroupHashForThrowable($throwable);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The result of a tool call as the model receives it — the tail of {@see dispatchTool()}, split out so the
     * model-facing time zone scopes exactly this rendering: markdown by default, the structured shape on
     * request, both with DateTime properties in the conversation's zone.
     */
    protected function renderToolResultForModel(mixed $result, string $toolName, bool $explicitStructured): MCPToolResponse
    {
        if (!$explicitStructured && $result instanceof LlmMarkdownRenderable) {
            // The markdown rendering must NOT silence the tool→loop control fields: a DTO that declares
            // fire-and-forget background work (`backgroundOperations`), requested sub-agents (`delegationTasks` with
            // their labels, the child profile, the next step, awaited conversations, the no-resume flag)
            // or a turn step floor (`turnMinimumSteps`)
            // carries them in a control-only structuredContent NEXT TO the markdown — the agent loop reads
            // exactly `structuredContent.<field>` off the result, so without this every markdown-rendered
            // producer's declaration silently no-ops. The model-visible wire
            // text strips `backgroundOperations` again ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::toOpenAiToolResultArray()}).
            $loopControlFields = [];
            foreach (self::LOOP_CONTROL_FIELD_NAMES as $loopControlFieldName) {
                $loopControlFieldValue = $result->$loopControlFieldName ?? null;
                if ((is_array($loopControlFieldValue) && $loopControlFieldValue !== []) || is_int($loopControlFieldValue) || $loopControlFieldValue === true
                    || (is_string($loopControlFieldValue) && $loopControlFieldValue !== '')) {
                    $loopControlFields[$loopControlFieldName] = $loopControlFieldValue;
                }
            }
            // `applied: false` = the call was refused before any write (an owner consent missing): the same call after
            // the owner's yes is a retry, never an identical repeat ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::resultDeclaresIncompleteOutcome()})
            if (($result->applied ?? null) === false) {
                $loopControlFields['applied'] = false;
            }
            $response = new MCPToolResponse($result->toLlmMarkdown(), $loopControlFields !== [] ? $loopControlFields : null);
            if ($result instanceof MCPToolResponsePlatformNoteProvider) {
                $response->setPlatformNoteForModel($result->buildPlatformNoteForModel());
            }
            if ($result instanceof MCPToolResponseDictatedWidgetTypeProvider) {
                $response->setDictatedWidgetType($result->getDictatedWidgetType());
            }
            if ($result instanceof RestResponseDto || $result instanceof DefaultObject) {
                // The facts of the result ride PERSISTED beside the markdown for the application's account record:
                // the model reads the prose, the platform keeps the numbers; the wire strips this field.
                $serializedResult = null;
                try {
                    $serializedResult = $this->serializeForModel($result);
                    /** @var AgentToolResultFactsService $agentToolResultFactsService */
                    $agentToolResultFactsService = DDDService::instance()->getService(AgentToolResultFactsService::class);
                    $preservedFacts = $agentToolResultFactsService->extractPreservedFacts($serializedResult);
                    $response->preservedFacts = $preservedFacts !== [] ? $preservedFacts : null;
                } catch (Throwable) {
                    $response->preservedFacts = null;
                }
                // a bindable result keeps its structured shape and its entity rows for `ComposedView`
                $response->setBindableResultRecord($this->buildBindableResultRecord($result, $serializedResult, false));
            }
            return $response;
        }
        if ($result instanceof RestResponseDto) {
            $structuredResponse = new MCPToolResponse("Successful response for '$toolName'", $this->serializeForModel($result));
            $structuredResponse->setBindableResultRecord($this->buildBindableResultRecord($result, null, true));
            return $structuredResponse;
        }
        // Any DDD domain object (Entity / ValueObject / Set) carries the SerializerTrait and serializes itself
        // via its OWN recursion-safe jsonSerialize() → toObject(): it honors the Hide attributes / static-hide /
        // TOON config AND breaks parent back-reference cycles via the *RECURSION* guard. A bare domain object
        // must NEVER fall through to the raw json_encode() below — that walks the live object graph and throws
        // "Recursion detected" on any back-reference (e.g. Booking->workshop->bookings->Booking). Tools
        // should return a RestResponseDto, but this guards every domain object regardless.
        if ($result instanceof DefaultObject) {
            return new MCPToolResponse("Successful response for '$toolName'", $this->serializeForModel($result));
        }
        if (is_object($result) && method_exists($result, 'getContent')) {
            return new MCPToolResponse((string)$result->getContent());
        }
        return new MCPToolResponse(is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * The `ComposedView` record of a bindable result, or null for a result that is not bindable
     * ({@see ComposedViewBindableResult}): the serialized shape (not stored twice for a structured result, whose
     * `structuredContent` is the shape) and the entity rows of a result that lists entities. A failing row listing
     * leaves the rows out, never the result.
     */
    protected function buildBindableResultRecord(mixed $result, mixed $serializedResult, bool $dataIsStructuredContent): ?ComposedViewBindableResultRecord
    {
        if (!$result instanceof ComposedViewBindableResult) {
            return null;
        }
        $entityReferenceRows = [];
        if ($result instanceof ListsEntityReferenceRows) {
            try {
                $entityReferenceRows = $result->getEntityReferenceRows();
            } catch (Throwable) {
                $entityReferenceRows = [];
            }
        }
        return ComposedViewBindableResultRecord::forResult($serializedResult, $dataIsStructuredContent, $entityReferenceRows);
    }

    /**
     * The structured shape of a response for the model: the same serialization `jsonSerialize()` performs
     * (cache cleared, forPersistence as before) plus the MODEL_FACING_DATETIME flag, which renders DateTime
     * properties in {@see SerializerRegistry::$modelFacingTimezone} when one is set and does nothing otherwise.
     */
    protected function serializeForModel(RestResponseDto|DefaultObject $result): mixed
    {
        SerializerRegistry::$toOjectCache = [];
        // OUTPUT for the model, not persistence: #[DontPersistProperty] fields stay, like on the REST response.
        return $result->toObject(forPersistence: false, flags: Serializer::MODEL_FACING_DATETIME);
    }

    /**
     * True when the authenticated account is an admin with access to ALL resources — the account-level check the
     * repository rules require for admin capabilities (`isCurrentSessionAdmin()` also says yes for a cookie admin and
     * an impersonation token). The framework account answers it.
     */
    protected function isCallerAdminWithAccessToAllResources(): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount !== null
            && $authAccount->isAdminWithAccessToAllResources();
    }

    /**
     * The refusal for a tool call whose space or account is not ELIGIBLE for automated AI work, or null when the
     * call may proceed. What is asked: the space the call targets (`$targetAgentSpace`, resolved once by
     * {@see dispatchTool()}: the space an argument names, else the space of the conversation); without a target
     * space the authenticated account. A call with neither is not gated here (the model-call gate still covers
     * every spend). Cached read — this runs per tool call.
     *
     * @param string $toolName
     * @param AgentSpace|null $targetAgentSpace the space the call targets; null = the call works at account level
     * @param MCPServerProvidedInput|null $serverProvidedInput
     * @return MCPToolResponse|null
     */
    protected function refuseToolCallForIneligibleScope(
        string $toolName,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput
    ): ?MCPToolResponse {
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        $logContext = $serverProvidedInput instanceof AgentToolCallProvenance
            ? AgentEligibilityLogContext::forToolCall($toolName, $serverProvidedInput->conversationId)
            : AgentEligibilityLogContext::forToolCall($toolName);
        if ($targetAgentSpace !== null) {
            $refuses = $agentAutomationEligibilityService->refusesAutomationForAgentSpace($targetAgentSpace, 'toolCall', $logContext);
        } else {
            // No target space (a conversation that works for no space, or a call over HTTP that names none): the
            // authenticated account is what the call works for.
            $authAccount = AuthService::instance()->getAccount();
            if ($authAccount === null || !isset($authAccount->id)) {
                return null;
            }
            $refuses = $agentAutomationEligibilityService->refusesAutomationForAccountId((int)$authAccount->id, 'toolCall', $logContext);
        }
        if (!$refuses) {
            return null;
        }
        // The audited SUPPORT OVERRIDE exempts the tool gate exactly as it exempts the model-call gate:
        // an admin-initiated run on an ended space or account — a cancellation review — reads
        // with its tools under the override persisted on the run, logged on every consultation.
        if ($agentAutomationEligibilityService->isSupportOverrideActive()) {
            DDDService::instance()->getLogger()->warning('eligibility.supportOverride.consulted', $logContext->buildLogFields() + [
                'note' => $agentAutomationEligibilityService->getActiveSupportOverrideNote(),
                'operation' => 'toolCall',
            ]);
            return null;
        }
        return MCPToolResponse::gateRefusal($this->buildIneligibleScopeRefusalText($toolName), MCPToolResponse::REFUSAL_SCOPE_NOT_ELIGIBLE);
    }

    /**
     * What the model reads when the eligibility gate refuses a call: nothing ran, nothing further may run, and the
     * owner is told in one sentence.
     *
     * @param string $toolName
     * @return string
     */
    protected function buildIneligibleScopeRefusalText(string $toolName): string
    {
        return "Tool '{$toolName}' was NOT executed: the account, or what this call works for, is no longer active "
            . '(locked, cancelled, deleted or erased), so no automated work may run for it. Do not retry and do not '
            . 'call other tools for it; tell the owner in one short sentence that it must be active again first.';
    }

    /**
     * The space the call targets ({@see AgentSpacesService::findAgentSpaceOfToolCall()}): the space an argument
     * names, else the space of the conversation the call runs in. Asked ONCE per call by {@see dispatchTool()},
     * which hands the answer to the entitlement, eligibility and mandate gates.
     *
     * @param array<array-key, mixed> $arguments the arguments of the call, those the conversation's space fills included
     * @param MCPServerProvidedInput|null $serverProvidedInput the provenance of the agent loop; null for a call over HTTP
     * @return AgentSpace|null null = the call works at account level
     * @throws BadRequestException
     * @throws ForbiddenException when an argument names a space that does not exist or that the account may not use
     * @throws InternalErrorException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findAgentSpaceOfToolCall(array $arguments, ?MCPServerProvidedInput $serverProvidedInput): ?AgentSpace
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findAgentSpaceOfToolCall(
            $arguments,
            $serverProvidedInput instanceof AgentToolCallProvenance ? $serverProvidedInput : null
        );
    }

    /**
     * What the model reads when a call names a space that does not exist or that the account may not use: nothing
     * ran, and the same call will be refused again.
     *
     * @param string $toolName
     * @return string
     */
    protected function buildAgentSpaceNotUsableRefusalText(string $toolName): string
    {
        return "Tool '{$toolName}' was NOT executed: the space this call names does not exist or is not one this "
            . 'account may use. Do not retry with it. Leave the argument out to work for what this conversation works '
            . 'for, or name a space of this account.';
    }

    /**
     * The account a call WITHOUT a target space is checked for: the account of the conversation the call runs in,
     * else the authenticated account.
     *
     * @param MCPServerProvidedInput|null $serverProvidedInput
     * @return Account|null null = no conversation and nobody authenticated
     */
    protected function findAccountOfToolCallWithoutAgentSpace(?MCPServerProvidedInput $serverProvidedInput): ?Account
    {
        $conversation = $serverProvidedInput instanceof AgentToolCallProvenance ? $serverProvidedInput->conversation : null;
        $accountOfConversation = $conversation !== null ? ($conversation->account ?? null) : null;
        return $accountOfConversation ?? AuthService::instance()->getAccount();
    }

    /**
     * THE MANDATE GATE: a tool
     * marked `#[MCPToolRequiresMandate(actionClass)]` executes in the AGENT LOOP only when an ACTIVE, time-valid
     * {@see AgentMandate} covers (account, target space, action class): a mandate bound to the space the call targets
     * or an account-wide one; for a call without a target space ONLY an account-wide one — a mandate bound to a space
     * never authorizes a call in another space or at account level
     * ({@see AgentMandatesService::findActiveMandateFor()}). Mandates are granted from the CUSTOMER's own
     * statement, verified server-side against the transcript window
     * ({@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::grantMandateFromDialogue()}) — the model cannot
     * forge one, because the transcript is pulled from the conversation's own messages, never from tool arguments.
     *
     * A ONE_INSTRUCTION mandate is NEVER consumed per call: it covers
     * every call of the tool it is first used with — and of the tools sharing that tool's binding key
     * ({@see MCPToolRequiresMandate::$bindingKey}) — belonging to the ONE customer request it was granted from; the
     * gate evaluates the pure request predicate {@see AgentMandate::authorizesRequest()} (root conversation +
     * turn-initiator ids + first-use tool binding via
     * {@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::bindOneInstructionMandateToTool()}). The first
     * gated call from a LATER request closes the mandate out
     * ({@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::consumeOneInstructionMandate()}, idempotent CAS)
     * and demands a fresh grant — the new customer message is that grant's consent. Nothing is consumed on
     * pass, so a failed execution burns nothing and there is no refund path.
     *
     * NO-provenance paths are deliberately ungated, mirroring the confirmation gate: the HTTP-MCP server (the
     * authenticated owner IS the consent-giver) and executor-direct calls (admin tooling). The eval harness's own
     * calls carry a provenance marked {@see AgentToolCallProvenance::$isEvalHarnessCall} and are ungated too.
     * Fail-closed on a missing account: a gated call whose account cannot be resolved is refused.
     *
     * @param string $toolName
     * @param ReflectionMethod $reflectionMethod
     * @param AgentSpace|null $targetAgentSpace the space the call targets (resolved once by {@see dispatchTool()});
     *        null = the call works at account level
     * @param MCPServerProvidedInput|null $serverProvidedInput
     * @return MCPToolResponse|null
     */
    protected function refuseActionLackingMandate(
        string $toolName,
        ReflectionMethod $reflectionMethod,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput
    ): ?MCPToolResponse {
        $mandateAttributes = $reflectionMethod->getAttributes(MCPToolRequiresMandate::class);
        if ($mandateAttributes === []) {
            return null;
        }
        // an eval-harness call (a case's restore) is no agent action for a customer: a refused restore would leave
        // what the case changed in place for every later case
        if (!$serverProvidedInput instanceof AgentToolCallProvenance || $serverProvidedInput->isEvalHarnessCall) {
            return null;
        }
        /** @var MCPToolRequiresMandate $mandateAttribute */
        $mandateAttribute = $mandateAttributes[0]->newInstance();
        $actionClass = $mandateAttribute->actionClass;
        // the name the ONE_INSTRUCTION binds to: the tool's own, or the key tools of one intent share
        $bindingKey = $mandateAttribute->bindingKeyForTool($toolName);
        $actionClassDescription = AgentMandate::getActionClassDescription($actionClass);
        $authAccountId = AuthService::instance()->getAccount()?->id;
        if ($authAccountId === null) {
            return MCPToolResponse::error(
                "Tool '{$toolName}' requires a mandate ({$actionClass}) and no account could be resolved to check "
                . 'it against. Do not retry.'
            );
        }
        /** @var AgentMandatesService $agentMandatesService */
        $agentMandatesService = DDDService::instance()->getService(AgentMandatesService::class);
        // with a target space: its own mandates and the account-wide ones; without: the account-wide ones only
        $activeAgentMandate = $agentMandatesService->findActiveMandateFor((int)$authAccountId, $targetAgentSpace, $actionClass);
        // The platform checks the customer's consent itself — the model never has to obtain a
        // ONE_INSTRUCTION through `grantMandate` (a yes to a shown draft cost a refusal, a skill load and a grant).
        $callRunsInDelegationChild = ($serverProvidedInput->conversationId ?? null) !== null
            && ($serverProvidedInput->rootConversationId ?? null) !== null
            && $serverProvidedInput->conversationId !== $serverProvidedInput->rootConversationId;
        if ($activeAgentMandate === null) {
            if (($serverProvidedInput->scheduleId ?? null) !== null || $callRunsInDelegationChild) {
                return $this->buildMandateRequiredRefusal($toolName, $actionClass, $actionClassDescription);
            }
            $grantedOrRefused = $this->grantOneInstructionMandateFromTheCurrentRequest(
                $toolName,
                $actionClass,
                (int)$authAccountId,
                $targetAgentSpace,
                $serverProvidedInput
            );
            if ($grantedOrRefused instanceof MCPToolResponse) {
                return $grantedOrRefused;
            }
            $activeAgentMandate = $grantedOrRefused;
        }
        if ($activeAgentMandate->hasOneInstructionScope()) {
            // THE REQUEST PREDICATE: a ONE_INSTRUCTION covers every call of its bound tool
            // (or binding key) belonging to the ONE customer request it was granted from — identified by root
            // conversation + turn-initiator message, a pure id comparison (nothing is consumed per call, so
            // a crash/redelivery re-passes and there is nothing to refund). A call from a LATER request
            // closes the mandate out (idempotent CAS) and asks for a fresh grant — the new customer message
            // IS the fresh consent the approval check verifies.
            $rootConversationId = $serverProvidedInput->rootConversationId ?? null;
            $turnInitiatorMessageId = $serverProvidedInput->getSourceUserMessageId();
            // A delegation child executes the ROOT thread's current request under a child-local message set —
            // detected structurally, never model-supplied.
            if (!$activeAgentMandate->authorizesRequest($rootConversationId, $turnInitiatorMessageId, $bindingKey, $callRunsInDelegationChild)) {
                $agentMandatesService->consumeOneInstructionMandate($activeAgentMandate);
                if (($serverProvidedInput->scheduleId ?? null) !== null) {
                    // Scheduled run: no customer is present, so "grant fresh and retry" is impossible — a
                    // deferred/scheduled order needs a TIME_WINDOW mandate covering its run time.
                    return MCPToolResponse::error(
                        "Tool '{$toolName}' was NOT executed: this scheduled run has no active mandate for "
                        . "{$actionClass}. A one-instruction mandate never covers a later scheduled run — a deferred "
                        . 'order needs a TIME_WINDOW mandate covering the planned run time, granted while the '
                        . 'customer was still in the conversation. Do NOT retry; report in your run summary '
                        . 'that this action needs the customer\'s permission.'
                    );
                }
                if ($callRunsInDelegationChild) {
                    return $this->buildMandateRequiredRefusal($toolName, $actionClass, $actionClassDescription);
                }
                // the customer's CURRENT request is checked for its own consent (their yes to a shown draft)
                $grantedOrRefused = $this->grantOneInstructionMandateFromTheCurrentRequest(
                    $toolName,
                    $actionClass,
                    (int)$authAccountId,
                    $targetAgentSpace,
                    $serverProvidedInput
                );
                if ($grantedOrRefused instanceof MCPToolResponse) {
                    return $grantedOrRefused;
                }
                $activeAgentMandate = $grantedOrRefused;
            }
            // First-use tool binding: stamp the first tool that rides this mandate; a DIFFERENT tool of the
            // same class in the same request loses the CAS and is refused (deletePost consent ≠ publishPost).
            $bindingWasFree = ($activeAgentMandate->coveredToolName ?? null) === null;
            if (!$agentMandatesService->bindOneInstructionMandateToTool($activeAgentMandate, $bindingKey)) {
                return MCPToolResponse::error(
                    "Tool '{$toolName}' was NOT executed: the one-instruction mandate for {$actionClass} is bound to "
                    . "`{$activeAgentMandate->coveredToolName}` for this request — a different action needs its "
                    . 'own consent. Tell the customer what this request covers and ask whether they also want this.'
                );
            }
            if ($bindingWasFree) {
                // first use: remembered so a call that FAILS hands the binding back (see the release below)
                $this->firstUseMandateBinding = AgentMandateFirstUseBinding::forMandateAndBindingKey($activeAgentMandate, $bindingKey);
            }
        }
        return null;
    }

    /**
     * The customer's consent to the action of the current call, checked by the platform: the same
     * server-side approval check `grantMandate` runs ({@see AgentMandatesService::grantMandateFromDialogue()}), over
     * the live dialogue up to the current customer message, for a ONE_INSTRUCTION. Approved → the granted mandate (the
     * call goes on). No consent → the refusal that sends the model to the customer, never to `grantMandate`.
     *
     * The mandate is bound to the space the call targets; a call without a target space grants an account-wide one,
     * as the mandate lookup of such a call accepts no other.
     *
     * @param string $toolName
     * @param string $actionClass
     * @param int $accountId
     * @param AgentSpace|null $targetAgentSpace the space the call targets; null = the call works at account level
     * @param AgentToolCallProvenance $agentToolCallProvenance
     * @return AgentMandate|MCPToolResponse
     */
    protected function grantOneInstructionMandateFromTheCurrentRequest(
        string $toolName,
        string $actionClass,
        int $accountId,
        ?AgentSpace $targetAgentSpace,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentMandate|MCPToolResponse {
        $actionClassDescription = AgentMandate::getActionClassDescription($actionClass);
        if (AgentMandate::isStandingGrantNeededForActionClass($actionClass)) {
            return MCPToolResponse::gateRefusal(
                "Tool '{$toolName}' was NOT executed: it requires a STANDING mandate for {$actionClass} "
                . "({$actionClassDescription}) — the action runs on later without the customer. When the customer "
                . 'asked for it, load the `Assistant.Mandates` skill and call `grantMandate` with the scope their words '
                . 'name (for a period → TIME_WINDOW, from now on → UNLIMITED), then retry; otherwise ask them plainly '
                . 'whether they want it and for how long.',
                MCPToolResponse::REFUSAL_MANDATE_REQUIRED
            );
        }
        if ($agentToolCallProvenance->conversationMessages === null) {
            return $this->buildMandateRequiredRefusal($toolName, $actionClass, $actionClassDescription);
        }
        /** @var AgentMandatesService $agentMandatesService */
        $agentMandatesService = DDDService::instance()->getService(AgentMandatesService::class);
        try {
            $agentMandateGrantOutcome = $agentMandatesService->grantMandateFromDialogue(
                $accountId,
                $targetAgentSpace,
                AgentMandateGrantArguments::build($actionClass, AgentMandate::SCOPE_ONE_INSTRUCTION, null, null),
                $agentToolCallProvenance->conversationMessages,
                $agentToolCallProvenance->sourceUserMessage,
                $agentToolCallProvenance->rootConversationId ?? $agentToolCallProvenance->conversationId,
                $agentToolCallProvenance->getTimezone(),
            );
        } catch (ForbiddenException) {
            // the account the mandate would be granted for may not use the target space: no mandate, no call
            return MCPToolResponse::error($this->buildAgentSpaceNotUsableRefusalText($toolName));
        } catch (BadRequestException $noConsent) {
            return MCPToolResponse::gateRefusal(
                "Tool '{$toolName}' was NOT executed: the customer has not asked for or agreed to this action "
                . "({$actionClassDescription}) in the conversation — {$noConsent->getMessage()} Ask the customer "
                . 'plainly whether you should do it; when they asked to see it first, show it and wait for their yes. '
                . 'Their yes is checked by the platform on your next call of this tool — do not call `grantMandate` '
                . 'for it. Only a STANDING permission (from now on, or for a period) is granted with `grantMandate` '
                . '(skill `Assistant.Mandates`), and only when the customer asked for one.',
                MCPToolResponse::REFUSAL_MANDATE_REQUIRED
            );
        } catch (InternalErrorException) {
            return MCPToolResponse::gateRefusal(
                "Tool '{$toolName}' was NOT executed: the platform's check of the customer's consent did not complete. "
                . 'Call the tool once more with the same arguments; if it fails again, tell the customer it could not '
                . 'be done right now and offer to try again later.',
                MCPToolResponse::REFUSAL_MANDATE_REQUIRED
            );
        }
        return $agentMandateGrantOutcome->agentMandate;
    }

    /** The refusal where the platform cannot check consent itself (a scheduled run, a delegation child, no dialogue). */
    protected function buildMandateRequiredRefusal(string $toolName, string $actionClass, string $actionClassDescription): MCPToolResponse
    {
        return MCPToolResponse::gateRefusal(
            $this->buildMandateRequiredRefusalText($toolName, $actionClass, $actionClassDescription),
            MCPToolResponse::REFUSAL_MANDATE_REQUIRED
        );
    }

    /**
     * What the model reads when no active mandate covers the call and the platform cannot check the customer's
     * consent itself: nothing ran, and who has to obtain the consent.
     *
     * @param string $toolName
     * @param string $actionClass
     * @param string $actionClassDescription
     * @return string
     */
    protected function buildMandateRequiredRefusalText(string $toolName, string $actionClass, string $actionClassDescription): string
    {
        return "Tool '{$toolName}' was NOT executed: it requires an active MANDATE for the action class "
            . "{$actionClass} ({$actionClassDescription}), and this account has none that covers this call. A "
            . 'mandate is the customer\'s authorization, granted from their OWN statement in the conversation with '
            . 'them. In a sub-agent: end your run and report that the main conversation has to make the first call '
            . 'of this tool itself (that call checks the customer\'s consent and then covers you). In a scheduled run: '
            . 'report that this action needs the customer\'s permission. Do not retry here.';
    }

    /**
     * The ONE_INSTRUCTION mandate the current call bound on first use, with its key — null when the call bound
     * nothing. Set by {@see refuseActionLackingMandate()} per call, read by the failure paths of execute().
     *
     * @var AgentMandateFirstUseBinding|null
     */
    protected ?AgentMandateFirstUseBinding $firstUseMandateBinding = null;

    /**
     * A gated call that threw or answered with an error did no outward work: the first-use binding it stamped on
     * the customer's one-instruction mandate is released, so the NEXT tool of the same request (a different
     * mechanics for the same intent, or a retry) rides the grant instead of being refused as "bound to a different
     * action" and closing the mandate. Fail-soft: a failed release leaves the old behaviour.
     */
    protected function releaseFirstUseMandateBindingOfFailedCall(): void
    {
        if ($this->firstUseMandateBinding === null) {
            return;
        }
        $firstUseMandateBinding = $this->firstUseMandateBinding;
        $this->firstUseMandateBinding = null;
        try {
            /** @var AgentMandatesService $agentMandatesService */
            $agentMandatesService = DDDService::instance()->getService(AgentMandatesService::class);
            $agentMandatesService->releaseOneInstructionMandateBinding($firstUseMandateBinding->agentMandate, $firstUseMandateBinding->bindingKey);
        } catch (Throwable) {
            // the binding stays — the pre-existing behaviour
        }
    }

    /**
     * THE ARGUMENTS THE CONVERSATION'S SPACE FILLS: in the agent loop the conversation already knows what it works
     * for — an argument a request of the tool declares for it and the model left out (or sent as 0/'') is filled
     * server-side from the space of the conversation
     * ({@see AgentSpacesService::applyAgentSpaceDefaultsToToolArguments()} decides which arguments those are). An
     * argument the model passed always wins, so work on another space stays fully possible. Nothing is filled in a
     * conversation WITHOUT a space and on the HTTP-MCP path (no provenance): the arguments stay as they are, and a
     * required argument stays a required input of the caller. This removes the dead end of an omitted argument for
     * every tool that works on the conversation's space.
     *
     * MULTI-DTO tools are fully covered: the class names of ALL parameters are handed over, and because
     * {@see buildMethodArguments()} hydrates EVERY RequestDto from the SAME flat arguments map, a filled argument
     * serves every request that declares it. The classes of what the server provides
     * ({@see MCPServerProvidedInput}) are left out: they are never filled from the arguments, so a property of
     * theirs names no argument.
     *
     * @param ReflectionMethod $toolMethod
     * @param array<array-key, mixed> $arguments
     * @param MCPServerProvidedInput|null $serverProvidedInput
     * @return array<array-key, mixed>
     */
    protected function applyAgentSpaceDefaultsToToolArguments(
        ReflectionMethod $toolMethod,
        array $arguments,
        ?MCPServerProvidedInput $serverProvidedInput
    ): array {
        if (!$serverProvidedInput instanceof AgentToolCallProvenance || $serverProvidedInput->agentSpace === null) {
            return $arguments;
        }
        $parameterClassNames = [];
        foreach ($toolMethod->getParameters() as $reflectionParameter) {
            $reflectionParameterType = $reflectionParameter->getType();
            if (!$reflectionParameterType instanceof ReflectionNamedType || $reflectionParameterType->isBuiltin()) {
                continue;
            }
            $parameterClassName = $reflectionParameterType->getName();
            if (is_a($parameterClassName, MCPServerProvidedInput::class, true)) {
                continue;
            }
            $parameterClassNames[] = $parameterClassName;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->applyAgentSpaceDefaultsToToolArguments($arguments, $parameterClassNames, $serverProvidedInput->agentSpace);
    }

    /**
     * The refusal for a tool the caller is not entitled to run, or null when the call may proceed.
     *
     * A refusal, never an exception: the agent reads it as a deterministic corrective and stops, the HTTP and CLI
     * callers get a clean isError response instead of a 500.
     *
     * The two entitlement axes do not resolve the same way. Feature flags belong to what the call works for: the
     * space the call targets (`$targetAgentSpace`, resolved once by {@see dispatchTool()}), else the account. The
     * account-rights axis needs no such resolution — the account is already established ({@see callTool()} opens
     * the usage envelope on it).
     *
     * A tool that declares NO flag gate returns immediately and asks nothing, so the ungated majority pays nothing.
     * A tool that DOES declare one is checked against the feature flags of its target space; without a target space
     * (a conversation that works for no space, or a call over HTTP that names none) against those of the account.
     * When the application has no answer the call is REFUSED — failing closed stops a gated tool from slipping past
     * the gate.
     *
     * @param string $toolName
     * @param ReflectionClass $mcpPackageReflectionClass
     * @param ReflectionMethod $reflectionMethod
     * @param AgentSpace|null $targetAgentSpace the space the call targets; null = the call works at account level
     * @param MCPServerProvidedInput|null $serverProvidedInput
     * @return MCPToolResponse|null
     */
    protected function refuseUnentitledToolCall(
        string $toolName,
        ReflectionClass $mcpPackageReflectionClass,
        ReflectionMethod $reflectionMethod,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): ?MCPToolResponse {
        $mcpToolEntitlement = MCPToolEntitlement::createFromReflectionMethod($mcpPackageReflectionClass, $reflectionMethod);

        // Flags before rights, deliberately: the first question is whether the plan includes the capability at all,
        // the second whether THIS user may use it. Reversed, a user would be told to ask their administrator for a
        // right that would not help, because the plan does not include the capability either way.
        $featureFlagRefusal = $this->refuseToolCallLackingFeatureFlags($toolName, $mcpToolEntitlement, $targetAgentSpace, $serverProvidedInput);
        if ($featureFlagRefusal !== null) {
            return $featureFlagRefusal;
        }
        return $this->refuseToolCallLackingAccountRights($toolName, $mcpToolEntitlement);
    }

    /**
     * Refuses a call whose `filters` or `orderBy` names a property the tool does not expose
     * ({@see MCPExposedFilterProperties}); null when the tool declares no allow-list or the call stays inside it.
     * The refusal names the allowed properties, so the model corrects the call instead of guessing again.
     *
     * @param array<array-key, mixed> $methodArguments The hydrated arguments the tool method is about to receive
     */
    protected function refuseQueryOptionsOutsideExposedFilterProperties(
        string $toolName,
        \ReflectionMethod $reflectionMethod,
        array $methodArguments
    ): ?MCPToolResponse {
        $exposedFilterProperties = $this->findExposedFilterPropertiesOfToolMethod($reflectionMethod);
        if ($exposedFilterProperties === null) {
            return null;
        }
        $usedPropertyNames = [];
        foreach ($methodArguments as $methodArgument) {
            if (!$methodArgument instanceof RequestDto) {
                continue;
            }
            if (isset($methodArgument->filters) && $methodArgument->filters instanceof FiltersOptions) {
                $usedPropertyNames = array_merge($usedPropertyNames, $this->collectFilterPropertyNames($methodArgument->filters));
            }
            if (isset($methodArgument->orderBy) && $methodArgument->orderBy instanceof OrderByOptions) {
                $usedPropertyNames = array_merge($usedPropertyNames, $this->collectOrderByPropertyNames($methodArgument->orderBy));
            }
        }
        return $this->refusePropertyNamesOutsideExposedFilterProperties($toolName, $exposedFilterProperties, $usedPropertyNames);
    }

    /**
     * Refuses a call whose raw `filters` or `orderBy` argument names a property the tool does not expose
     * ({@see MCPExposedFilterProperties}); null when the tool declares no allow-list or no name read from the two
     * arguments lies outside it. An argument that is no string contributes no name and is left to hydration; a
     * `filters` string that does not parse or does not validate leaves the whole call to hydration, which reports
     * the error. An empty entry of an `orderBy` string that parses is refused under the name
     * {@see self::EMPTY_ORDER_BY_ENTRY_LABEL}. Runs before the arguments are hydrated, so a property outside the
     * allow-list is answered with the tool's allowed properties instead of the entity's full list of filterable
     * properties that a failed hydration names.
     *
     * @param array<array-key, mixed> $arguments The model's arguments of the call, not yet hydrated
     */
    protected function refuseRawQueryOptionArgumentsOutsideExposedFilterProperties(
        string $toolName,
        ReflectionMethod $reflectionMethod,
        array $arguments
    ): ?MCPToolResponse {
        $exposedFilterProperties = $this->findExposedFilterPropertiesOfToolMethod($reflectionMethod);
        if ($exposedFilterProperties === null) {
            return null;
        }
        $filterPropertyNames = $this->readPropertyNamesOfFiltersArgument($arguments['filters'] ?? null);
        if ($filterPropertyNames === null) {
            return null;
        }
        $usedPropertyNames = array_merge(
            $filterPropertyNames,
            $this->readPropertyNamesOfOrderByArgument($arguments['orderBy'] ?? null)
        );
        return $this->refusePropertyNamesOutsideExposedFilterProperties($toolName, $exposedFilterProperties, $usedPropertyNames);
    }

    /**
     * The allow-list a tool method declares with {@see MCPExposedFilterProperties}; null when it declares none.
     */
    protected function findExposedFilterPropertiesOfToolMethod(ReflectionMethod $reflectionMethod): ?MCPExposedFilterProperties
    {
        $exposedFilterPropertiesAttributes = $reflectionMethod->getAttributes(MCPExposedFilterProperties::class);
        if ($exposedFilterPropertiesAttributes === []) {
            return null;
        }
        return $exposedFilterPropertiesAttributes[0]->newInstance();
    }

    /**
     * The refusal of a call that filters or sorts on a property outside the tool's allow-list, naming the allowed
     * properties so the model corrects the call instead of guessing again; null when every used name is allowed.
     * Empty names are ignored (the raw `orderBy` reader names an empty entry {@see self::EMPTY_ORDER_BY_ENTRY_LABEL}).
     *
     * @param string[] $usedPropertyNames The property names the call's `filters` and `orderBy` compare or sort on
     */
    protected function refusePropertyNamesOutsideExposedFilterProperties(
        string $toolName,
        MCPExposedFilterProperties $exposedFilterProperties,
        array $usedPropertyNames
    ): ?MCPToolResponse {
        $refusedPropertyNames = array_values(array_unique(array_filter(
            $usedPropertyNames,
            static fn (string $usedPropertyName): bool => $usedPropertyName !== '' && !$exposedFilterProperties->exposes($usedPropertyName)
        )));
        if ($refusedPropertyNames === []) {
            return null;
        }
        return MCPToolResponse::error(
            "Tool '{$toolName}' does not filter or sort on " . implode(', ', $refusedPropertyNames)
            . ' — allowed properties: ' . implode(', ', $exposedFilterProperties->propertyNames) . ' (and id for sorting).'
        );
    }

    /**
     * The property names a raw `filters` argument compares, operation nodes walked recursively, parsed with
     * {@see FiltersOptions::fromString()}; empty when the value is no string or an empty one (`''`, `'0'`), null
     * when the string does not parse or does not validate.
     *
     * @return string[]|null
     */
    protected function readPropertyNamesOfFiltersArgument(mixed $filtersArgument): ?array
    {
        if (!is_string($filtersArgument)) {
            return [];
        }
        try {
            $filtersOptions = FiltersOptions::fromString($filtersArgument);
        } catch (Throwable) {
            return null;
        }
        return $filtersOptions === null ? [] : $this->collectFilterPropertyNames($filtersOptions);
    }

    /**
     * The property names a raw `orderBy` argument sorts on, parsed with {@see OrderByOptions::fromString()}, an empty
     * entry named {@see self::EMPTY_ORDER_BY_ENTRY_LABEL}; empty when the value is no string or does not parse.
     *
     * @return string[]
     */
    protected function readPropertyNamesOfOrderByArgument(mixed $orderByArgument): array
    {
        if (!is_string($orderByArgument)) {
            return [];
        }
        try {
            $orderByOptions = OrderByOptions::fromString($orderByArgument);
        } catch (Throwable) {
            return [];
        }
        if ($orderByOptions === null) {
            return [];
        }
        return array_map(
            static fn (string $propertyName): string => $propertyName === '' ? self::EMPTY_ORDER_BY_ENTRY_LABEL : $propertyName,
            $this->collectOrderByPropertyNames($orderByOptions)
        );
    }

    /**
     * The property names an order-by list sorts on, in order.
     *
     * @return string[]
     */
    protected function collectOrderByPropertyNames(OrderByOptions $orderByOptions): array
    {
        $propertyNames = [];
        foreach ($orderByOptions->getElements() as $orderByOption) {
            $propertyNames[] = (string)($orderByOption->propertyName ?? '');
        }
        return $propertyNames;
    }

    /**
     * The property names a filter tree compares, operation nodes walked recursively.
     *
     * @return string[]
     */
    protected function collectFilterPropertyNames(FiltersOptions $filtersOptions): array
    {
        if (($filtersOptions->type ?? null) === FiltersOptions::TYPE_OPERATION) {
            $propertyNames = [];
            foreach ($filtersOptions->getElements() as $childFiltersOptions) {
                $propertyNames = array_merge($propertyNames, $this->collectFilterPropertyNames($childFiltersOptions));
            }
            return $propertyNames;
        }
        return isset($filtersOptions->property) ? [$filtersOptions->property] : [];
    }

    /**
     * The feature-flag half of {@see refuseUnentitledToolCall()} — "does the plan include the capability".
     *
     * The flags asked: those of the space the call targets (the space an argument names, else the space of the
     * conversation — a gated tool that carries no such argument in its schema is checked against the conversation's
     * space); without a target space those of the account of the conversation, else of the authenticated account.
     * No answer (null) keeps the refusal: the gate fails closed.
     *
     * @param string $toolName
     * @param MCPToolEntitlement $mcpToolEntitlement
     * @param AgentSpace|null $targetAgentSpace the space the call targets; null = the call works at account level
     * @param MCPServerProvidedInput|null $serverProvidedInput
     * @return MCPToolResponse|null
     */
    protected function refuseToolCallLackingFeatureFlags(
        string $toolName,
        MCPToolEntitlement $mcpToolEntitlement,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): ?MCPToolResponse {
        if (!$mcpToolEntitlement->hasFeatureFlagGate()) {
            return null;
        }

        // required flags as one requirement, alternatives as "one of" — never a list that reads as "all of these"
        $gatingFeatureFlagRequirement = $mcpToolEntitlement->describeFeatureFlagRequirement();
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        if ($targetAgentSpace !== null) {
            $featureFlags = $aIConversationEntitlementsService->findFeatureFlagsOfAgentSpace($targetAgentSpace);
            if ($featureFlags === null) {
                return MCPToolResponse::error($this->buildFeatureFlagsNotCheckableForAgentSpaceRefusalText($toolName, $gatingFeatureFlagRequirement));
            }
        } else {
            $account = $this->findAccountOfToolCallWithoutAgentSpace($serverProvidedInput);
            $featureFlags = $account !== null ? $aIConversationEntitlementsService->findFeatureFlagsOfAccount($account) : null;
            if ($featureFlags === null) {
                return MCPToolResponse::error($this->buildFeatureFlagsNotCheckableWithoutAgentSpaceRefusalText($toolName, $gatingFeatureFlagRequirement));
            }
        }

        if ($mcpToolEntitlement->isSatisfiedByFeatureFlags($featureFlags)) {
            return null;
        }

        return MCPToolResponse::error($this->buildFeatureFlagsMissingRefusalText($toolName, $gatingFeatureFlagRequirement));
    }

    /**
     * What the model reads when a gated tool was called without a target space and the feature flags cannot be
     * checked for the account alone: the call has to name the space it applies to.
     *
     * @param string $toolName
     * @param string $gatingFeatureFlagRequirement the gate in words
     * @return string
     */
    protected function buildFeatureFlagsNotCheckableWithoutAgentSpaceRefusalText(string $toolName, string $gatingFeatureFlagRequirement): string
    {
        return "Tool '{$toolName}' is gated on the product features {$gatingFeatureFlagRequirement} and they cannot be "
            . 'checked without a space. Call it for the space it applies to. Do not retry without one.';
    }

    /**
     * What the model reads when the feature flags of the space a gated call targets cannot be read.
     *
     * @param string $toolName
     * @param string $gatingFeatureFlagRequirement the gate in words
     * @return string
     */
    protected function buildFeatureFlagsNotCheckableForAgentSpaceRefusalText(string $toolName, string $gatingFeatureFlagRequirement): string
    {
        return "Tool '{$toolName}' is gated on the product features {$gatingFeatureFlagRequirement}, and they could not "
            . 'be checked for the space this call targets. Do not retry.';
    }

    /**
     * What the model reads when the plan does not include the product features a tool requires: no retry, and the
     * customer hears that the capability is part of a higher plan, never a technical failure.
     *
     * @param string $toolName
     * @param string $gatingFeatureFlagRequirement the gate in words
     * @return string
     */
    protected function buildFeatureFlagsMissingRefusalText(string $toolName, string $gatingFeatureFlagRequirement): string
    {
        return "Tool '{$toolName}' is not available here — it requires the product features "
            . "{$gatingFeatureFlagRequirement}, which this plan does not include. Do not retry. Tell the customer the "
            . 'capability is part of a higher plan instead of reporting a technical failure.';
    }

    /**
     * The account-rights half of {@see refuseUnentitledToolCall()} — "may THIS user do it".
     *
     * Needs no target space: rights are account-scoped and the account is already established on every path
     * ({@see callTool()} opens the usage envelope on it). The refusal deliberately does NOT read as an upsell — a
     * right is granted by the account administrator, never bought, so pointing the customer at a plan upgrade would
     * send them into a checkout that cannot unlock anything.
     */
    protected function refuseToolCallLackingAccountRights(
        string $toolName,
        MCPToolEntitlement $mcpToolEntitlement
    ): ?MCPToolResponse {
        if (!$mcpToolEntitlement->hasAccountRightsGate()) {
            return null;
        }

        $requiredAccountRightNames = implode(', ', $mcpToolEntitlement->requiredAccountRightNames);
        // the authenticated account's rights (the host: plain `->` on the lazy `settings` — the load is wanted here)
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        $accountRightsSetting = $aIConversationEntitlementsService->findAccountRightsOfCurrentAccount();
        if ($accountRightsSetting === null) {
            return MCPToolResponse::error(
                "Tool '{$toolName}' requires the account rights {$requiredAccountRightNames}, and no account rights "
                . 'could be resolved for the current user to check them against. Do not retry.'
            );
        }

        if ($mcpToolEntitlement->isSatisfiedByAccountRights($accountRightsSetting)) {
            return null;
        }

        return MCPToolResponse::error(
            "Tool '{$toolName}' is not permitted for this user account — it requires the account rights "
            . "{$requiredAccountRightNames}. Do not retry. Tell the customer their account administrator has to grant "
            . 'that permission; this is NOT a plan upgrade and NOT a technical failure.'
        );
    }

    /**
     * Resolve a service from the Symfony container if available (enables DI for packages + tool params).
     */
    public function resolveFromContainer(string $serviceId): ?object
    {
        $container = DDDBundle::getContainer();
        if (!$container->has($serviceId)) {
            return null;
        }
        return $container->get($serviceId);
    }

    /**
     * The arguments without the PLACEHOLDERS a model sends for parameters it does not need: a model that fills every
     * parameter of a schema passes `""` (or null) where it means "nothing". On an OPTIONAL parameter — a request
     * property that allows null or has a default — such a value counts as not passed: the property keeps its default,
     * a `#[Choice]` validation never sees the empty string, a stored value is not overwritten with nothing. A
     * property whose empty string IS a value says so with {@see MCPEmptyStringIsValue}. `false` and `0` are values
     * and stay; an argument that names no property of the request stays as it is.
     *
     * @param class-string $requestClassName
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    public function withoutPlaceholderArguments(string $requestClassName, array $arguments): array
    {
        foreach ($arguments as $argumentName => $argumentValue) {
            if (!is_string($argumentName) || ($argumentValue !== '' && $argumentValue !== null)) {
                continue;
            }
            if (!property_exists($requestClassName, $argumentName)) {
                continue;
            }
            $reflectionProperty = new \ReflectionProperty($requestClassName, $argumentName);
            $reflectionType = $reflectionProperty->getType();
            $isOptional = $reflectionType === null || $reflectionType->allowsNull() || $reflectionProperty->hasDefaultValue();
            if (!$isOptional) {
                continue;
            }
            if ($argumentValue === '' && $reflectionProperty->getAttributes(MCPEmptyStringIsValue::class) !== []) {
                continue;
            }
            unset($arguments[$argumentName]);
        }
        return $arguments;
    }

    /**
     * Build method arguments from request data based on the method signature.
     *
     * @param ReflectionMethod $method
     * @param array<array-key, mixed> $data
     * @param Request|null $requestContext
     * @return array<int, mixed> Positional parameters ready for invocation
     * @throws InternalErrorException for a parameter class that is neither a request DTO, a framework object nor a
     * public container service
     */
    public function buildMethodArguments(
        ReflectionMethod $method,
        array $data,
        ?Request $requestContext = null,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): array {
        $params = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();

            // Builtin / untyped / union / intersection → fill from the named argument (or default)
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $paramName = $param->getName();
                $params[] = $data[$paramName] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
                continue;
            }

            $className = $type->getName();

            // Server-provided input: filled from the agent-loop call context threaded into callTool(), NEVER from the
            // model's $data (it is excluded from the schema, see MCPSchema). MUST come before the fromArray/RequestDto
            // branches (the carrier is a ValueObject that may match those). Inject the passed instance when it matches
            // the declared type; otherwise an empty instance (the HTTP-MCP path provides nothing → null fields).
            if (is_a($className, MCPServerProvidedInput::class, true)) {
                $params[] = ($serverProvidedInput instanceof $className) ? $serverProvidedInput : new $className();
                continue;
            }

            if (is_a($className, DefaultObject::class, true) && method_exists($className, 'fromArray')) {
                $params[] = $className::fromArray($data);
            } elseif (is_a($className, RequestDto::class, true)) {
                /** @var RequestDto $dto */
                $dto = new $className();
                // the harness of an eval writes captured originals back — an empty original is a value there
                if (!($serverProvidedInput instanceof AgentToolCallProvenance && $serverProvidedInput->isEvalHarnessCall)) {
                    $data = $this->withoutPlaceholderArguments($className, $data);
                }

                // Synthetic Request so RequestDto::setPropertiesFromRequest() works: mirror the
                // arguments into query/request/attributes and provide them as JSON body.
                $dtoRequest = new Request(
                    $data,
                    $data,
                    $data,
                    $requestContext?->cookies->all() ?? [],
                    $requestContext?->files->all() ?? [],
                    $requestContext?->server->all() ?? [],
                    json_encode($data, JSON_THROW_ON_ERROR)
                );

                // Preserve original headers (e.g. Authorization) so DTOs can read HEADER params.
                if ($requestContext) {
                    $dtoRequest->headers->replace($requestContext->headers->all());
                }

                // Expose scalar arguments as headers (by property name) without overriding existing ones.
                foreach ($data as $key => $value) {
                    if (!is_string($key) || $dtoRequest->headers->has($key)) {
                        continue;
                    }
                    if (is_scalar($value) || $value === null) {
                        $dtoRequest->headers->set($key, $value === null ? '' : (string)$value);
                    }
                }

                $dto->setPropertiesFromRequest($dtoRequest);
                $params[] = $dto;
            } else {
                $service = $this->resolveFromContainer($className);
                if ($service) {
                    $params[] = $service;
                    continue;
                }
                // The model's arguments reach a request DTO or an object of the framework only: any other class
                // is a service the container must provide, never an instance built from the arguments.
                if (!is_a($className, DefaultObject::class, true)) {
                    throw new InternalErrorException(
                        "Tool parameter \${$param->getName()} of {$method->class}::{$method->getName()}() is typed {$className}: "
                        . 'neither a request DTO, nor an object of the framework, nor a public service of the container.'
                    );
                }
                try {
                    $params[] = new $className($data);
                } catch (Exception $e) {
                    $params[] = new $className();
                }
            }
        }

        return $params;
    }
}
