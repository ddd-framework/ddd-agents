<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes\TaskRefusalExposingDelegationSpawnService;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationSummary;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRefusal;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\ScheduleRunPurpose;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationSearchResultsConversationWidget;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerWordsAsEvidence;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSendsMessageToOwner;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolWritesAgentMemoryOnly;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\AgentConversationsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMandatesMCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReceivesCurrentConversationId;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinition;
use DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes\FixedOutputMCPSchema;
use DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes\ToolDeclarationsProbeMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * What the loop reads from a tool's declaration instead of a list of tool names: the id of the calling conversation
 * as an argument, a writer of agent memory rows beside forked calls and in the key of the standing directives, a result
 * the budget never stubs, a call without an activity line, the tools a run of a task schedule blocks, and the tools a
 * delegated run is never offered and a `delegate` tool run refuses (the owner's own words as evidence). The tools of
 * {@see ToolDeclarationsProbeMCPPackage} are added to the catalog for the test; the application binds none of them.
 * Also: the two texts the model reads that are built in code name no tool of a skill the module does not bind.
 */
class ToolDeclarationsReadByTheLoopTest extends UnitTestCase
{
    /** @var array<string, mixed> the memo of each catalog before the test, by `class::property` */
    protected array $catalogMemosBeforeTest = [];

    protected function setUp(): void
    {
        parent::setUp();
        // an entity reads the default lazy-load repo type on construction; the borrowed test environment may not set it
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $toolMethodsByToolName = MCPToolAttributeCatalog::getToolMethodsByToolName();
        foreach ((new ReflectionClass(ToolDeclarationsProbeMCPPackage::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->getAttributes(MCPTool::class) !== []) {
                $toolMethodsByToolName[$reflectionMethod->getName()] = $reflectionMethod;
            }
        }
        $this->replaceCatalogMemo(MCPToolAttributeCatalog::class, 'toolMethodsByToolName', $toolMethodsByToolName);
        $this->replaceCatalogMemo(MCPToolAttributeCatalog::class, 'attributesByToolName', []);
        $this->replaceCatalogMemo(MCPToolAttributeCatalog::class, 'toolNamesByAttributeClass', []);
        $this->replaceCatalogMemo(MCPToolEffectCatalog::class, 'effectsByToolName', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->catalogMemosBeforeTest as $classAndProperty => $memoBeforeTest) {
            [$catalogClass, $propertyName] = explode('::', $classAndProperty);
            (new ReflectionProperty($catalogClass, $propertyName))->setValue(null, $memoBeforeTest);
        }
        $this->catalogMemosBeforeTest = [];
        parent::tearDown();
    }

    protected function replaceCatalogMemo(string $catalogClass, string $propertyName, mixed $memo): void
    {
        $reflectionProperty = new ReflectionProperty($catalogClass, $propertyName);
        $this->catalogMemosBeforeTest[$catalogClass . '::' . $propertyName] = $reflectionProperty->getValue();
        $reflectionProperty->setValue(null, $memo);
    }

    protected function toolCall(string $toolName, array $arguments = [], string $toolCallId = 'call_1'): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = $toolCallId;
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        return $toolCall;
    }

    protected function toolCallMessage(int $indexInConversation, string $toolName, ?string $result = null): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->id = 7000 + $indexInConversation;
        $message->indexInConversation = $indexInConversation;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->toolCall = $this->toolCall($toolName, [], 'call_' . $indexInConversation);
        if ($result !== null) {
            $message->toolCall->result = $result;
            $message->toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        }
        return $message;
    }

    protected function textMessage(int $indexInConversation, string $role, string $authorType, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->id = 7000 + $indexInConversation;
        $message->indexInConversation = $indexInConversation;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->message = $text;
        return $message;
    }

    public function testTheDeclaringToolReceivesTheIdOfTheCallingConversation(): void
    {
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->id = 4711;

        self::assertSame(
            ['query' => 'opening hours', 'conversationId' => 4711],
            $this->toolCall('probeSearchOfThisConversation', ['query' => 'opening hours'])->buildExecutionArguments($conversation)
        );
        self::assertSame(
            ['conversationId' => 4711],
            $this->toolCall('probeSearchOfThisConversation', ['conversationId' => 0])->buildExecutionArguments($conversation),
            'the calling conversation\'s id always wins over a value the model wrote'
        );
        $conversationWithoutId = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        self::assertSame(
            ['conversationId' => 12],
            $this->toolCall('probeSearchOfThisConversation', ['conversationId' => 12])->buildExecutionArguments($conversationWithoutId),
            'a conversation without an id keeps the value the call carries'
        );
        self::assertSame(['query' => 'opening hours'], $this->toolCall('probeRecordChange', ['query' => 'opening hours'])->buildExecutionArguments($conversation));
        self::assertSame([], $this->toolCall('aToolNoPackageDeclares')->buildExecutionArguments($conversation));
    }

    public function testTheDeclaringToolIsOfferedWithoutTheConversationIdAndOtherToolsKeepIt(): void
    {
        $declaringParameters = $this->buildConversationIdProbeToolDefinition('probeSearchOfThisConversation')->toOpenAiToolSchema()['function']['parameters'];
        self::assertSame(['query'], array_keys($declaringParameters['properties']), 'the loop always fills the id: the model is not offered it');
        self::assertSame(['query'], $declaringParameters['required']);

        $otherParameters = $this->buildConversationIdProbeToolDefinition('probeRecordChange')->toOpenAiToolSchema()['function']['parameters'];
        self::assertSame(['query', MCPToolReceivesCurrentConversationId::ARGUMENT_NAME], array_keys($otherParameters['properties']));
        self::assertSame(['query', MCPToolReceivesCurrentConversationId::ARGUMENT_NAME], $otherParameters['required']);
    }

    /** A tool definition whose schema has `query` and the conversation id, both required. */
    protected function buildConversationIdProbeToolDefinition(string $toolName): ToolDefinition
    {
        $inputSchema = new FixedOutputMCPSchema();
        $inputSchema->serializedSchema = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                MCPToolReceivesCurrentConversationId::ARGUMENT_NAME => ['type' => 'integer', 'description' => 'The conversation'],
            ],
            'required' => ['query', MCPToolReceivesCurrentConversationId::ARGUMENT_NAME],
        ];
        $toolDefinition = new ToolDefinition();
        $toolDefinition->name = $toolName;
        $toolDefinition->description = 'A probe.';
        $toolDefinition->inputSchema = $inputSchema;
        return $toolDefinition;
    }

    public function testAWriterOfAgentMemoryOnlyBesideForkedCallsDoesNotMakeThemWait(): void
    {
        $agentToolCallForkJoinService = new class extends AgentToolCallForkJoinService {
            public function __construct()
            {
            }
        };
        $forkedCalls = [$this->toolCall('probePageRead', [], 'call_fork_1'), $this->toolCall('probePageRead', [], 'call_fork_2')];
        $memoryWrite = $this->toolCall('probeStoreMemory', [], 'call_memory');
        $recordWrite = $this->toolCall('probeRecordChange', [], 'call_record');

        self::assertFalse($agentToolCallForkJoinService->responseHasInlineWriteBesideTheFork([...$forkedCalls, $memoryWrite], $forkedCalls));
        self::assertTrue($agentToolCallForkJoinService->responseHasInlineWriteBesideTheFork([...$forkedCalls, $recordWrite], $forkedCalls));
        self::assertTrue($agentToolCallForkJoinService->responseHasInlineWriteBesideTheFork([...$forkedCalls, $memoryWrite, $recordWrite], $forkedCalls));
    }

    public function testTheKeyOfTheStandingDirectivesCountsTheCompletedCallsOfTheDeclaringTools(): void
    {
        $toolNamesWritingAgentMemory = MCPToolAttributeCatalog::getToolNamesDeclaringAttribute(MCPToolWritesAgentMemoryOnly::class);
        self::assertSame(['probeStoreMemory'], $toolNamesWritingAgentMemory);

        $messages = new AIConversationMessages();
        $ownerMessage = $this->textMessage(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'remember that we close on Mondays');
        $messages->add($ownerMessage);
        $recordWrite = $this->toolCallMessage(1, 'probeRecordChange', 'saved');
        $messages->add($recordWrite);
        self::assertSame(0, $messages->buildToolCallHistory()->countCompletedToolCallsNamed($toolNamesWritingAgentMemory));

        $completedMemoryWrite = $this->toolCallMessage(2, 'probeStoreMemory', 'stored');
        $messages->add($completedMemoryWrite);
        $failedMemoryWrite = $this->toolCallMessage(3, 'probeStoreMemory', 'no text given');
        $failedMemoryWrite->toolCall->status = AIConversationMessage::STATUS_FAILED;
        $messages->add($failedMemoryWrite);
        self::assertSame(1, $messages->buildToolCallHistory()->countCompletedToolCallsNamed($toolNamesWritingAgentMemory), 'the key moves with a completed write, not with a failed one');
    }

    public function testTheStandingDirectivesAreReadAgainOnlyAfterACompletedCallOfADeclaringTool(): void
    {
        $conversation = new AIConversation();
        $conversation->accountId = 5;
        $conversation->messages = new AIConversationMessages();
        $ownerMessage = $this->textMessage(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'remember that we close on Mondays');
        $conversation->messages->add($ownerMessage);
        $memoKeyOfTheDirectives = new ReflectionProperty(AIConversation::class, 'memoryDirectivesMemoKey');

        $conversation->findMemoryDirectivesBlock();
        self::assertSame('5|0|0', $memoKeyOfTheDirectives->getValue($conversation));

        $recordWrite = $this->toolCallMessage(1, 'probeRecordChange', 'saved');
        $conversation->messages->add($recordWrite);
        $conversation->findMemoryDirectivesBlock();
        self::assertSame('5|0|0', $memoKeyOfTheDirectives->getValue($conversation), 'a write without the declaration leaves the key, so the directives are not read again');

        $memoryWrite = $this->toolCallMessage(2, 'probeStoreMemory', 'stored');
        $conversation->messages->add($memoryWrite);
        $conversation->findMemoryDirectivesBlock();
        self::assertSame('5|0|1', $memoKeyOfTheDirectives->getValue($conversation), 'a completed write of agent memory moves the key, so the directives are read again');
    }

    public function testTheBudgetNeverStubsTheResultOfADeclaringTool(): void
    {
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $contextRetentionSettings = new ContextRetentionSettings();
        $contextRetentionSettings->enabled = true;
        $contextRetentionSettings->keepToolResultsRecentTurns = 1;
        // room for one result of 4,000 characters, not for two
        $contextRetentionSettings->toolResultTokenBudget = 1500;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        $resultOf4000Characters = str_repeat('a', 4000);
        $tailMessages = [
            $this->textMessage(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'compare the two pages'),
            $this->toolCallMessage(1, 'probePageRead', $resultOf4000Characters),
            $this->toolCallMessage(2, 'probeRecordChange', $resultOf4000Characters),
            $this->toolCallMessage(3, 'probeRecordChange', $resultOf4000Characters),
            $this->textMessage(4, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Both pages are read.'),
        ];

        $toolResultRetention = $conversation->resolveToolResultRetention($tailMessages);

        self::assertSame(1, $toolResultRetention->fromIndex, 'the declared page read is the oldest result kept');
        self::assertSame([2 => true], $toolResultRetention->stubbedIndexes, 'the result without the declaration between the two kept ones does not fit');

        $tailMessages[1] = $this->toolCallMessage(1, 'probeRecordChange', $resultOf4000Characters);
        $toolResultRetentionWithoutTheDeclaration = $conversation->resolveToolResultRetention($tailMessages);
        self::assertSame(3, $toolResultRetentionWithoutTheDeclaration->fromIndex, 'without the declaration only the newest result is kept');
        self::assertSame([], $toolResultRetentionWithoutTheDeclaration->stubbedIndexes);
    }

    public function testTheResultsOfADeclaringToolTakeNoRoomFromTheOthers(): void
    {
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $contextRetentionSettings = new ContextRetentionSettings();
        $contextRetentionSettings->enabled = true;
        $contextRetentionSettings->keepToolResultsRecentTurns = 1;
        $contextRetentionSettings->toolResultTokenBudget = 1500;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        $pageOf8000Characters = str_repeat('p', 8000);
        $tailMessages = [
            $this->textMessage(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'draft the profile'),
            $this->toolCallMessage(1, 'probeRecordChange', str_repeat('s', 2000)),
            $this->toolCallMessage(2, 'probePageRead', $pageOf8000Characters),
            $this->toolCallMessage(3, 'probePageRead', $pageOf8000Characters),
            $this->textMessage(4, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Both pages are read.'),
        ];

        $toolResultRetention = $conversation->resolveToolResultRetention($tailMessages);

        self::assertSame([], $toolResultRetention->stubbedIndexes, 'the small result of the same round still fits beside two declared page reads');
        self::assertLessThanOrEqual(1, $toolResultRetention->fromIndex);
    }

    public function testACallOfADeclaringToolHasNoActivityLine(): void
    {
        $agentActivityLinesService = new class extends AgentActivityLinesService {
            public function __construct()
            {
            }
        };

        self::assertNull($agentActivityLinesService->buildElement($this->toolCallMessage(1, 'probeStoreMemory')));
        self::assertNotNull($agentActivityLinesService->buildElement($this->toolCallMessage(2, 'probeRecordChange')));
        self::assertNull($agentActivityLinesService->buildElement($this->toolCallMessage(3, 'load_skills')), 'the loop\'s own tools stay silent without a declaration');
    }

    public function testARunOfATaskScheduleBlocksTheToolsThatSendTheOwnerAMessage(): void
    {
        self::assertSame(['probeEmailToOwner'], MCPToolAttributeCatalog::getToolNamesDeclaringAttribute(MCPToolSendsMessageToOwner::class));
        self::assertSame(['probeEmailToOwner'], (new ScheduleRunPurpose())->getToolNamesBlockedByDeclaration());
        self::assertSame([], (new AIConversationPurpose())->getToolNamesBlockedByDeclaration());

        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $scheduleRunPurpose = $aIConversationPurposesService->getPurposeByName('Schedule.Run');

        self::assertInstanceOf(ScheduleRunPurpose::class, $scheduleRunPurpose);
        self::assertSame(['probeEmailToOwner'], $scheduleRunPurpose->blockedToolNames);
        self::assertStringContainsString(
            "## Tools not available in this conversation\n\n`probeEmailToOwner` — never call them here; a call is refused: the platform delivers this run's report",
            $scheduleRunPurpose->getDirectiveBlock()
        );
    }

    public function testTheNamesOfThePurposeFileComeFirstAndADeclaredNameIsNotRepeated(): void
    {
        $scheduleRunPurpose = new ScheduleRunPurpose();

        $scheduleRunPurpose->blockedToolNames = ['aToolThePurposeFileNames'];
        self::assertSame(['aToolThePurposeFileNames', 'probeEmailToOwner'], $scheduleRunPurpose->buildBlockedToolNamesWithTheDeclaredOnes());

        $scheduleRunPurpose->blockedToolNames = ['probeEmailToOwner', 'aToolThePurposeFileNames'];
        self::assertSame(['probeEmailToOwner', 'aToolThePurposeFileNames'], $scheduleRunPurpose->buildBlockedToolNamesWithTheDeclaredOnes());

        $purposeWithoutDeclaredBlocks = new AIConversationPurpose();
        $purposeWithoutDeclaredBlocks->blockedToolNames = ['aToolThePurposeFileNames'];
        self::assertSame(['aToolThePurposeFileNames'], $purposeWithoutDeclaredBlocks->buildBlockedToolNamesWithTheDeclaredOnes());
    }

    public function testWithoutADeclaringToolARunOfATaskScheduleBlocksNothing(): void
    {
        $toolMethodsByToolName = MCPToolAttributeCatalog::getToolMethodsByToolName();
        unset($toolMethodsByToolName['probeEmailToOwner']);
        (new ReflectionProperty(MCPToolAttributeCatalog::class, 'toolMethodsByToolName'))->setValue(null, $toolMethodsByToolName);
        (new ReflectionProperty(MCPToolAttributeCatalog::class, 'toolNamesByAttributeClass'))->setValue(null, []);

        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $scheduleRunPurpose = $aIConversationPurposesService->getPurposeByName('Schedule.Run');

        self::assertNotNull($scheduleRunPurpose);
        self::assertSame([], $scheduleRunPurpose->blockedToolNames);
        self::assertStringNotContainsString('Tools not available in this conversation', $scheduleRunPurpose->getDirectiveBlock());
    }

    public function testADelegatedRunIsNeverOfferedAToolThatNeedsTheOwnersWords(): void
    {
        // the module's own grant tool (the mandates skill) and the probe's
        self::assertSame(['grantMandate', 'probeGrantFromOwnerWords'], MCPToolAttributeCatalog::getToolNamesDeclaringAttribute(MCPToolRequiresOwnerWordsAsEvidence::class));

        $delegatedConversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $delegatedConversation->delegatedFromMessageId = 88;
        self::assertSame(['grantMandate', 'probeGrantFromOwnerWords'], $delegatedConversation->findToolNamesLeftOutOfADelegatedRun());
        self::assertSame(['grantMandate', 'probeGrantFromOwnerWords'], $delegatedConversation->findBlockedToolNamesOfConversationTree(), 'off the wire and never named as callable');

        $rootConversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        self::assertSame([], $rootConversation->findToolNamesLeftOutOfADelegatedRun(), 'the conversation with the owner keeps the tool');
        self::assertSame([], $rootConversation->findBlockedToolNamesOfConversationTree());
    }

    public function testTheOfferOfADelegatedRunLeavesOutTheGrantToolOfTheLoadedMandatesSkill(): void
    {
        foreach ([ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND] as $skillLoadingStrategy) {
            self::assertContains('grantMandate', $this->conversationWithTheMandatesSkillLoaded(null, $skillLoadingStrategy)->buildOfferedTools(false)->getToolNames(), $skillLoadingStrategy);

            $offeredToolNamesOfTheDelegatedRun = $this->conversationWithTheMandatesSkillLoaded(88, $skillLoadingStrategy)->buildOfferedTools(false)->getToolNames();
            self::assertNotContains('grantMandate', $offeredToolNamesOfTheDelegatedRun, $skillLoadingStrategy);
            self::assertContains('listMandates', $offeredToolNamesOfTheDelegatedRun, $skillLoadingStrategy . ': the other tools of the skill stay');
        }
    }

    /**
     * A conversation whose one row carries the module's mandates skill with its grant and list tools resident, loading
     * skills by `$skillLoadingStrategy`; `$delegatedFromMessageId` set = a delegated run.
     */
    protected function conversationWithTheMandatesSkillLoaded(?int $delegatedFromMessageId, string $skillLoadingStrategy): AIConversation
    {
        $ownerRow = $this->textMessage(0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'allow the weekly post');
        $ownerRow->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Agent.Mandates' => ['name' => 'Agent.Mandates', 'type' => LoadedCapability::TYPE_SKILL],
            'grantMandate' => ['name' => 'grantMandate', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']],
            'listMandates' => ['name' => 'listMandates', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']],
        ]);
        $messages = new AIConversationMessages();
        $messages->add($ownerRow);
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->messages = $messages;
        $conversation->delegatedFromMessageId = $delegatedFromMessageId;
        $contextRetentionSettings = new ContextRetentionSettings();
        $contextRetentionSettings->skillLoadingStrategy = $skillLoadingStrategy;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        return $conversation;
    }

    public function testADelegateToolRunOfAToolThatNeedsTheOwnersWordsIsRefused(): void
    {
        self::assertTrue(AgentDelegationToolRunTask::forToolCall('probeGrantFromOwnerWords', [])->callsToolRequiringOwnerWords());
        self::assertFalse(AgentDelegationToolRunTask::forToolCall('probeRecordChange', [])->callsToolRequiringOwnerWords());
        self::assertFalse(AgentDelegationToolRunTask::forToolCall('aToolNoPackageDeclares', [])->callsToolRequiringOwnerWords());

        $delegationSubAgentRefusal = DelegationSubAgentRefusal::forToolRequiringOwnerWords('probeGrantFromOwnerWords', false);
        self::assertSame(DelegationSubAgentRun::FAILURE_REASON_REFUSED, $delegationSubAgentRefusal->failureReason);
        self::assertSame(
            'delegate REJECTED — `probeGrantFromOwnerWords` needs the owner\'s own words as evidence, and a sub-agent sees only its task, '
                . 'never your conversation with the owner. Make this call yourself in this conversation before you delegate. Nothing was started.',
            $delegationSubAgentRefusal->corrective
        );
        self::assertSame(
            'delegate REJECTED — `probeGrantFromOwnerWords` needs the owner\'s own words as evidence, and a sub-agent sees only its task, '
                . 'never the conversation with the owner. You are a sub-agent too: the main conversation with the owner makes this call. '
                . 'Nothing was started.',
            DelegationSubAgentRefusal::forToolRequiringOwnerWords('probeGrantFromOwnerWords', true)->corrective,
            'a delegated run that delegates cannot make the call either'
        );

        // the spawn asks it before it asks anything else of the parent: no offer is built, no child is seeded
        $agentSubAgentRequest = AgentSubAgentRequest::forModelCall(DelegateToolArguments::fromToolCallArguments(['toolName' => 'grantMandate', 'toolArguments' => '{}']));
        self::assertNotNull($agentSubAgentRequest);
        $spawnRefusal = (new ReflectionClass(TaskRefusalExposingDelegationSpawnService::class))->newInstanceWithoutConstructor()->callFindRefusalOfModelRequestedTask(
            AgentRun::forTurnEntry(),
            (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor(),
            $agentSubAgentRequest,
            $this->toolCall(DelegateTool::NAME),
            true
        );
        self::assertEquals(DelegationSubAgentRefusal::forToolRequiringOwnerWords('grantMandate', false), $spawnRefusal);

        // a delegated run that delegates the grant is told that the main conversation makes the call
        $delegatedParentConversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $delegatedParentConversation->delegatedFromMessageId = 88;
        $spawnRefusalOfADelegatedRun = (new ReflectionClass(TaskRefusalExposingDelegationSpawnService::class))->newInstanceWithoutConstructor()->callFindRefusalOfModelRequestedTask(
            AgentRun::forTurnEntry(),
            $delegatedParentConversation,
            $agentSubAgentRequest,
            $this->toolCall(DelegateTool::NAME),
            true
        );
        self::assertEquals(DelegationSubAgentRefusal::forToolRequiringOwnerWords('grantMandate', true), $spawnRefusalOfADelegatedRun);
        self::assertStringContainsString('You are a sub-agent too: the main conversation with the owner makes this call.', (string)$spawnRefusalOfADelegatedRun?->corrective);
    }

    public function testTheGrantToolOfTheMandatesPackageNeedsTheOwnersWords(): void
    {
        $toolNamesNeedingOwnerWords = [];
        foreach ((new ReflectionClass(AgentMandatesMCPPackage::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->getAttributes(MCPTool::class) !== [] && $reflectionMethod->getAttributes(MCPToolRequiresOwnerWordsAsEvidence::class) !== []) {
                $toolNamesNeedingOwnerWords[] = $reflectionMethod->getName();
            }
        }
        self::assertSame(['grantMandate'], $toolNamesNeedingOwnerWords, 'a list or a revoke needs no statement of the owner');
    }

    public function testTheEarlierSessionsTextEndsWithASentenceAnApplicationCanReplace(): void
    {
        $conversation = new AIConversation();
        $conversation->id = 31;
        $conversation->lastInboundMessageAt = DateTime::fromString('2026-03-04 10:30:00');
        $aIConversationSummary = new AIConversationSummary();
        $aIConversationSummary->title = 'Opening hours';
        $aIConversationSummary->outcome = 'Monday is closed';
        $conversation->summary = $aIConversationSummary;
        $aIConversations = new AIConversations();
        $aIConversations->add($conversation);

        $episodicHistoryText = $aIConversations->buildEpisodicHistoryText();

        self::assertStringEndsWith("\n\nRead any of these sessions with readConversation (skill Agent.Conversations) and the session's #conversationId.", $episodicHistoryText);
        self::assertStringContainsString('(#31)', $episodicHistoryText);

        $aIConversationsOfAnApplication = new class extends AIConversations {
            public function getSentenceOnReadingASessionInFull(): string
            {
                return 'Read a session with `readSessionOfTheApplication`.';
            }
        };
        $aIConversationsOfAnApplication->add($conversation);
        self::assertStringEndsWith("\n\nRead a session with `readSessionOfTheApplication`.", $aIConversationsOfAnApplication->buildEpisodicHistoryText());
    }

    public function testTheCatalogTextsOfTheSearchResultsWidgetNameOnlyThePackagesSearchTools(): void
    {
        self::assertStringNotContainsString('`', ConversationSearchResultsConversationWidget::SUMMARY);
        // a tool name is a camel-case word in backticks; the widget's own fields (`display`, `groupsFromToolCallId`,
        // `groups`) are none of the package's tools
        preg_match_all('/`([a-z]+[A-Z][A-Za-z]*)`/', ConversationSearchResultsConversationWidget::WHEN_TO_USE, $namedWords);
        $namedToolNames = array_values(array_diff(array_unique($namedWords[1]), ['groupsFromToolCallId']));
        sort($namedToolNames);
        self::assertSame(['searchConversations', 'searchThisConversation'], $namedToolNames);
        foreach ($namedToolNames as $namedToolName) {
            self::assertTrue(method_exists(AgentConversationsMCPPackage::class, $namedToolName), $namedToolName . ' is a tool of the package');
        }
    }
}
