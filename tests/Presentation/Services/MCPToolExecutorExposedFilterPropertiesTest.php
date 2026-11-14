<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetRequestDto;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeConversationListMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\TargetAgentSpaceObservingToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A list tool that names its filter properties (#[MCPExposedFilterProperties]) answers a call whose `filters` or
 * `orderBy` names any other property, or carries an empty `orderBy` entry, with ONE refusal that lists the tool's
 * allowed properties — on the first call, also when the entity does not know the property. A `filters` string that
 * does not parse is left to hydration; a tool without the attribute accepts every filter of the entity.
 */
class MCPToolExecutorExposedFilterPropertiesTest extends UnitTestCase
{
    protected const string TOOL_NAME = 'probeListConversations';

    protected const string TOOL_NAME_WITHOUT_ALLOW_LIST = 'probeListConversationsOnEveryProperty';

    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        static::authenticate(null);
        ProbeConversationListMCPPackage::forgetRuns();
    }

    protected function tearDown(): void
    {
        ProbeConversationListMCPPackage::forgetRuns();
        static::authenticate($this->accountBeforeTest);
        parent::tearDown();
    }

    public function testAFilterOnAPropertyTheEntityDoesNotKnowIsRefusedWithTheToolsAllowedPropertiesOnTheFirstCall(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['filters' => "unknownProperty eq 'x'"]);

        $this->assertRefusalNamingTheAllowedProperties($response, 'unknownProperty');
    }

    public function testAFilterOnAPropertyTheEntityKnowsButTheToolDoesNotExposeIsRefused(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['filters' => "runState eq 'IDLE' and runToken eq 'abc'"]);

        $this->assertRefusalNamingTheAllowedProperties($response, 'runToken');
    }

    public function testANestedFilterOnAPropertyTheEntityDoesNotKnowIsRefusedWithTheToolsAllowedProperties(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['filters' => "(runState eq 'IDLE' or unknownProperty eq 'x')"]);

        $this->assertRefusalNamingTheAllowedProperties($response, 'unknownProperty');
    }

    public function testTheRefusalComesAfterTheEligibilityGateAndBeforeTheMandateGate(): void
    {
        $executor = $this->createExecutor();

        $response = $executor->callDispatchTool(
            self::TOOL_NAME,
            ['filters' => "unknownProperty eq 'x'"],
            [ProbeConversationListMCPPackage::class],
            null
        );

        $this->assertRefusalNamingTheAllowedProperties($response, 'unknownProperty');
        self::assertArrayHasKey('refuseToolCallForIneligibleScope', $executor->targetAgentSpaceHandedToGate);
        self::assertArrayNotHasKey('refuseActionLackingMandate', $executor->targetAgentSpaceHandedToGate);
    }

    public function testAnEarlierGateAnswersBeforeTheFilterRefusal(): void
    {
        $executor = $this->createExecutor();
        $executor->refusalOfTargetAgentSpace = new ForbiddenException('not usable');

        $response = $executor->callDispatchTool(
            self::TOOL_NAME,
            ['filters' => "unknownProperty eq 'x'"],
            [ProbeConversationListMCPPackage::class],
            null
        );

        self::assertTrue($response->isError);
        self::assertSame(
            [['type' => 'text', 'text' => self::invokeMethod($executor, 'buildAgentSpaceNotUsableRefusalText', [self::TOOL_NAME])]],
            $response->content
        );
        self::assertSame([], ProbeConversationListMCPPackage::$toolNamesOfRuns);
    }

    public function testAnOrderOutsideTheAllowListIsRefused(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['orderBy' => 'unknownProperty desc']);

        $this->assertRefusalNamingTheAllowedProperties($response, 'unknownProperty');
    }

    public function testAnOrderOnAPropertyTheEntityKnowsButTheToolDoesNotExposeIsRefused(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['filters' => "runState eq 'IDLE'", 'orderBy' => 'scheduleId asc, resumeAttempts desc']);

        $this->assertRefusalNamingTheAllowedProperties($response, 'resumeAttempts');
    }

    public function testAnOrderWithATrailingCommaIsRefusedWithTheToolsAllowedProperties(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['orderBy' => 'scheduleId desc,']);

        $this->assertRefusalNamingTheAllowedProperties($response, '(empty entry)');
    }

    public function testAnOrderWithALeadingCommaIsRefusedWithTheToolsAllowedProperties(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['orderBy' => ', scheduleId']);

        $this->assertRefusalNamingTheAllowedProperties($response, '(empty entry)');
    }

    public function testAFilterAndAnOrderInsideTheAllowListRunTheTool(): void
    {
        $response = $this->callTool(
            self::TOOL_NAME,
            ['filters' => "runState eq 'IDLE' and scheduleId eq 4", 'orderBy' => 'scheduleId desc, id asc']
        );

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame([['type' => 'text', 'text' => ProbeConversationListMCPPackage::RESULT_TEXT]], $response->content);
        self::assertSame([self::TOOL_NAME], ProbeConversationListMCPPackage::$toolNamesOfRuns);
    }

    public function testAFilterThatDoesNotParseIsLeftToHydration(): void
    {
        $arguments = ['filters' => "unknownProperty eq 'x"];

        self::assertNull(
            $this->refuseRawArguments(self::TOOL_NAME, $arguments),
            'no refusal from the allow-list for a string that does not parse'
        );
        $this->assertTheSameAnswerAsAToolWithoutTheAttribute($arguments);
    }

    public function testAFilterThatDoesNotParseLeavesAnOrderOutsideTheAllowListToHydration(): void
    {
        $arguments = ['filters' => "runState eq 'IDLE", 'orderBy' => 'unknownProperty desc'];

        self::assertNull($this->refuseRawArguments(self::TOOL_NAME, $arguments));
        $responseText = $this->assertTheSameAnswerAsAToolWithoutTheAttribute($arguments);
        foreach (['runToken', 'resumeAttempts', 'purposeName'] as $propertyNameOfTheEntity) {
            self::assertStringNotContainsString($propertyNameOfTheEntity, $responseText, 'the entity\'s property list is absent');
        }
    }

    public function testAnOrderThatDoesNotParseKeepsTheRefusalOfAFilterOutsideTheAllowList(): void
    {
        $response = $this->callTool(self::TOOL_NAME, ['filters' => "unknownProperty eq 'x'", 'orderBy' => 'scheduleId down']);

        $this->assertRefusalNamingTheAllowedProperties($response, 'unknownProperty');
    }

    public function testArgumentsThatAreNoStringNameNoProperty(): void
    {
        self::assertNull($this->refuseRawArguments(self::TOOL_NAME, ['filters' => ['unknownProperty' => 'x'], 'orderBy' => 7]));
        self::assertNotNull($this->refuseRawArguments(self::TOOL_NAME, ['filters' => ['unknownProperty' => 'x'], 'orderBy' => 'unknownProperty desc']));
    }

    public function testEmptyAndWhitespaceArgumentsNameNoProperty(): void
    {
        self::assertNull($this->refuseRawArguments(self::TOOL_NAME, ['filters' => '', 'orderBy' => '']));
        self::assertNull($this->refuseRawArguments(self::TOOL_NAME, ['filters' => '   ', 'orderBy' => '   ']));
        self::assertNotNull(
            $this->refuseRawArguments(self::TOOL_NAME, ['filters' => '', 'orderBy' => 'unknownProperty desc']),
            'an empty filters string leaves the order to the allow-list'
        );
        self::assertNotNull(
            $this->refuseRawArguments(self::TOOL_NAME, ['filters' => "unknownProperty eq 'x'", 'orderBy' => '   ']),
            'a whitespace order names no property and keeps the refusal of the filter'
        );
    }

    public function testTheHydratedRequestObjectsAreCheckedAgainstTheAllowList(): void
    {
        $requestDto = new AIConversationsGetRequestDto();
        $requestDto->filters = FiltersOptions::fromString("runState eq 'IDLE' and runToken eq 'abc'");

        $response = self::invokeMethod(
            $this->createExecutor(),
            'refuseQueryOptionsOutsideExposedFilterProperties',
            [self::TOOL_NAME, $this->findToolMethod(self::TOOL_NAME), [$requestDto]]
        );

        self::assertInstanceOf(MCPToolResponse::class, $response);
        $this->assertRefusalNamingTheAllowedProperties($response, 'runToken');
    }

    public function testTheCallPathChecksTheHydratedRequestObjectsWhateverTheRawCheckLetsThrough(): void
    {
        $executorWithoutRawCheck = new class () extends TargetAgentSpaceObservingToolExecutor {
            protected function refuseRawQueryOptionArgumentsOutsideExposedFilterProperties(
                string $toolName,
                ReflectionMethod $reflectionMethod,
                array $arguments
            ): ?MCPToolResponse {
                return null;
            }
        };
        $executorWithoutRawCheck->answersWithStubbedTargetAgentSpace = true;

        $response = $executorWithoutRawCheck->callDispatchTool(
            self::TOOL_NAME,
            ['filters' => "runState eq 'IDLE' and runToken eq 'abc'", 'orderBy' => 'resumeAttempts desc'],
            [ProbeConversationListMCPPackage::class],
            null
        );

        $this->assertRefusalNamingTheAllowedProperties($response, 'runToken, resumeAttempts');
    }

    public function testAToolWithoutTheAttributeAcceptsAFilterOnAnyPropertyOfTheEntity(): void
    {
        self::assertNull(
            $this->refuseRawArguments(self::TOOL_NAME_WITHOUT_ALLOW_LIST, ['filters' => "unknownProperty eq 'x'", 'orderBy' => 'unknownProperty desc'])
        );

        $response = $this->callTool(self::TOOL_NAME_WITHOUT_ALLOW_LIST, ['filters' => "runToken eq 'abc'", 'orderBy' => 'resumeAttempts desc']);

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame([self::TOOL_NAME_WITHOUT_ALLOW_LIST], ProbeConversationListMCPPackage::$toolNamesOfRuns);
    }

    public function testAToolWithoutTheAttributeAnswersAnUnknownPropertyWithTheEntitysOwnError(): void
    {
        $response = $this->callTool(self::TOOL_NAME_WITHOUT_ALLOW_LIST, ['filters' => "unknownProperty eq 'x'"]);

        self::assertTrue($response->isError);
        $responseText = (string)$response->content[0]['text'];
        self::assertStringNotContainsString('does not filter or sort on', $responseText);
        self::assertStringContainsString('unknownProperty', $responseText);
        self::assertSame([], ProbeConversationListMCPPackage::$toolNamesOfRuns);
    }

    protected function createExecutor(): TargetAgentSpaceObservingToolExecutor
    {
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $executor->answersWithStubbedTargetAgentSpace = true;
        return $executor;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callTool(string $toolName, array $arguments): MCPToolResponse
    {
        return $this->createExecutor()->callDispatchTool($toolName, $arguments, [ProbeConversationListMCPPackage::class], null);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function refuseRawArguments(string $toolName, array $arguments): ?MCPToolResponse
    {
        return self::invokeMethod(
            $this->createExecutor(),
            'refuseRawQueryOptionArgumentsOutsideExposedFilterProperties',
            [$toolName, $this->findToolMethod($toolName), $arguments]
        );
    }

    protected function findToolMethod(string $toolName): ReflectionMethod
    {
        return new ReflectionMethod(ProbeConversationListMCPPackage::class, $toolName);
    }

    /**
     * The call is answered by hydration exactly as a tool without the attribute answers it, and the tool did not run.
     *
     * @param array<string, mixed> $arguments
     * @return string the text of the answer
     */
    protected function assertTheSameAnswerAsAToolWithoutTheAttribute(array $arguments): string
    {
        $response = $this->callTool(self::TOOL_NAME, $arguments);
        $responseOfToolWithoutAllowList = $this->callTool(self::TOOL_NAME_WITHOUT_ALLOW_LIST, $arguments);

        self::assertTrue($response->isError);
        $responseText = (string)$response->content[0]['text'];
        self::assertStringNotContainsString('does not filter or sort on', $responseText);
        self::assertSame(
            str_replace(self::TOOL_NAME_WITHOUT_ALLOW_LIST, self::TOOL_NAME, (string)$responseOfToolWithoutAllowList->content[0]['text']),
            $responseText,
            'the same answer as a tool without the attribute gives'
        );
        self::assertSame([], ProbeConversationListMCPPackage::$toolNamesOfRuns);
        return $responseText;
    }

    protected function assertRefusalNamingTheAllowedProperties(MCPToolResponse $response, string $refusedPropertyName): void
    {
        self::assertTrue($response->isError);
        self::assertSame(
            [
                [
                    'type' => 'text',
                    'text' => "Tool 'probeListConversations' does not filter or sort on {$refusedPropertyName}"
                        . ' — allowed properties: scheduleId, runState (and id for sorting).',
                ],
            ],
            $response->content
        );
        self::assertSame([], ProbeConversationListMCPPackage::$toolNamesOfRuns, 'the tool did not run');
    }

    protected static function authenticate(?Account $account): void
    {
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, $account);
    }
}
