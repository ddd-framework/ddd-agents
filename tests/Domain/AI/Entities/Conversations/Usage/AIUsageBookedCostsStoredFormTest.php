<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Usage;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\AI\Entities\Conversations\Usage\AIUsageBookedCostsStoredForm;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Booked costs stored before the per-scope amounts became a set (the keyed form) and after it (the object form) read
 * back with the same totals and per-scope amounts: a tool call's execution costs from its stored column, a nested
 * tool call, a recorded tool result, and the conversation's overhead column form.
 */
class AIUsageBookedCostsStoredFormTest extends UnitTestCase
{
    protected const string SCOPE_WEB_SEARCH = 'AI.TOOL.WEB_SEARCH';
    protected const string SCOPE_PAGE_READ = 'AI.TOOL.PAGE_READ';

    /** The keyed form exactly as rows written before the object form hold it. */
    protected const string KEYED_EXECUTION_COSTS_JSON = '{"totalCostInUSD":0.035,"bookingCount":3,"costInUSDByUsageScope":{"AI.TOOL.WEB_SEARCH":0.025,"AI.TOOL.PAGE_READ":0.01}}';

    protected function buildBookedCosts(): AIUsageBookedCosts
    {
        $aIUsageBookedCosts = new AIUsageBookedCosts();
        $aIUsageBookedCosts->addBookedCost(self::SCOPE_WEB_SEARCH, 0.025);
        $aIUsageBookedCosts->addBookedCost(self::SCOPE_PAGE_READ, 0.004);
        $aIUsageBookedCosts->addBookedCost(self::SCOPE_PAGE_READ, 0.006);
        return $aIUsageBookedCosts;
    }

    protected function assertSameBookedCosts(?AIUsageBookedCosts $aIUsageBookedCosts, string $message): void
    {
        self::assertNotNull($aIUsageBookedCosts, $message);
        self::assertSame(0.035, $aIUsageBookedCosts->totalCostInUSD, $message . ': total');
        self::assertSame(3, $aIUsageBookedCosts->bookingCount, $message . ': booking count');
        self::assertSame(2, $aIUsageBookedCosts->scopeBookedCosts->count(), $message . ': scope count');
        self::assertSame(0.025, $aIUsageBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope(self::SCOPE_WEB_SEARCH), $message . ': web search');
        self::assertSame(0.01, $aIUsageBookedCosts->scopeBookedCosts->getCostInUSDForUsageScope(self::SCOPE_PAGE_READ), $message . ': page read');
    }

    /** The stored `toolCall` column of a row: the keyed form (old row) and the object form (new row). */
    protected function buildStoredToolCallColumns(): array
    {
        $newToolCall = new ToolCall();
        $newToolCall->toolCallId = 'call_new';
        $newToolCall->name = 'searchTheWeb';
        $newToolCall->applyExecutionCosts($this->buildBookedCosts());
        $newToolCallColumn = (string)json_encode($newToolCall->mapToRepository());

        $oldToolCallColumn = '{"toolCallId":"call_old","name":"searchTheWeb","executionCosts":' . self::KEYED_EXECUTION_COSTS_JSON . '}';
        return [$oldToolCallColumn, $newToolCallColumn];
    }

    public function testAnOldAndANewStoredToolCallReadBackWithTheSameExecutionCosts(): void
    {
        [$oldToolCallColumn, $newToolCallColumn] = $this->buildStoredToolCallColumns();
        self::assertStringContainsString('"scopeBookedCosts"', $newToolCallColumn, 'new writes use the object form');
        self::assertStringNotContainsString('costInUSDByUsageScope', $newToolCallColumn);

        $oldToolCall = new ToolCall();
        $oldToolCall->mapFromRepository($oldToolCallColumn);
        $newToolCall = new ToolCall();
        $newToolCall->mapFromRepository($newToolCallColumn);

        $this->assertSameBookedCosts($oldToolCall->executionCosts, 'old row');
        $this->assertSameBookedCosts($newToolCall->executionCosts, 'new row');
        self::assertSame($oldToolCall->getExecutionCostInUSD(), $newToolCall->getExecutionCostInUSD());
    }

    public function testAToolCallNestedInAMessageReadsTheKeyedFormToo(): void
    {
        [$oldToolCallColumn] = $this->buildStoredToolCallColumns();
        $storedMessage = json_decode('{"type":"' . AIConversationMessage::MESSAGE_TOOL_CALL . '","toolCall":' . $oldToolCallColumn . '}');

        $aIConversationMessage = new AIConversationMessage();
        $aIConversationMessage->setPropertiesFromObject($storedMessage, false);

        $this->assertSameBookedCosts($aIConversationMessage->toolCall->executionCosts, 'nested old tool call');
        self::assertObjectHasProperty('costInUSDByUsageScope', $storedMessage->toolCall->executionCosts, 'the stored form given is not changed');
    }

    public function testAnOldAndANewRecordingPlayTheSameBookedCosts(): void
    {
        $recording = ToolResultRecording::forRecording();
        $recording->recordResult('searchTheWeb', ['query' => 'bakery'], new ToolResult('3 hits'), $this->buildBookedCosts());
        // a recording file is JSON: the new entry as it is read back from the file
        $newEntry = json_decode((string)json_encode($recording->entries[0]->toStoredEntry()), true);
        self::assertArrayHasKey('scopeBookedCosts', $newEntry['bookedCosts']);

        $oldEntry = $newEntry;
        $oldEntry['bookedCosts'] = json_decode(self::KEYED_EXECUTION_COSTS_JSON, true);

        $newPlayer = ToolResultRecording::forPlaying([$newEntry]);
        $oldPlayer = ToolResultRecording::forPlaying([$oldEntry]);
        $this->assertSameBookedCosts($newPlayer->playResult('searchTheWeb', ['query' => 'bakery'])->recordedBookedCosts, 'new recording');
        $this->assertSameBookedCosts($oldPlayer->playResult('searchTheWeb', ['query' => 'bakery'])->recordedBookedCosts, 'old recording');
    }

    public function testTheOverheadColumnKeepsTheKeyedFormAndReadsItBack(): void
    {
        $keyedForm = AIUsageBookedCostsStoredForm::buildKeyedForm($this->buildBookedCosts());
        $keyedFormJson = (string)json_encode($keyedForm);
        self::assertJsonStringEqualsJsonString(self::KEYED_EXECUTION_COSTS_JSON, $keyedFormJson);

        // the column comes back from the json column type as an array
        $readBack = AIUsageBookedCostsStoredForm::buildBookedCostsFromStoredForm(json_decode($keyedFormJson, true));
        $this->assertSameBookedCosts($readBack, 'overhead column');

        $emptyKeyedForm = AIUsageBookedCostsStoredForm::buildKeyedForm(new AIUsageBookedCosts());
        self::assertSame('{"totalCostInUSD":0,"bookingCount":0,"costInUSDByUsageScope":{}}', (string)json_encode($emptyKeyedForm));
        self::assertNull(AIUsageBookedCostsStoredForm::buildBookedCostsFromStoredForm($emptyKeyedForm), 'no booking reads as none');
        self::assertNull(AIUsageBookedCostsStoredForm::buildBookedCostsFromStoredForm(null));
    }
}
