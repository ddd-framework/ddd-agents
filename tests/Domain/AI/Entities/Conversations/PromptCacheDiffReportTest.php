<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheDiffReport;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The prompt-cache diff classifies each call's first difference against the previous call. */
class PromptCacheDiffReportTest extends UnitTestCase
{
    /** @param array<string, mixed> $body */
    protected function call(int $index, array $body, int $input, int $cached): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->inputTokens = $input;
        $message->cachedInputTokens = $cached;
        $snapshot = new RequestSnapshot();
        $snapshot->sentPayloadJson = (string)json_encode(['body' => $body], JSON_UNESCAPED_UNICODE);
        $snapshot->rawResponseJson = (string)json_encode(['provider' => 'OpenAI']);
        $message->requestSnapshot = $snapshot;
        return $message;
    }

    public function testCausesAreClassifiedPerFirstDifference(): void
    {
        $tools = [['type' => 'function', 'function' => ['name' => 'load_skills']]];
        $system = ['role' => 'system', 'content' => 'BASE'];
        $owner = ['role' => 'user', 'content' => '[2026-09-26 16:44] zieg mir mal mein profil'];
        $assistant = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'getProfileSummary']]]];
        $result = ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'a long result'];
        $messages = new AIConversationMessages();
        $messages->add($this->call(1, ['tools' => $tools, 'messages' => [$system, $owner]], 1000, 0));
        $messages->add($this->call(2, ['tools' => $tools, 'messages' => [$system, $owner, $assistant, $result]], 1200, 1000));                         // append
        $messages->add($this->call(4, ['tools' => $tools, 'messages' => [$system, ['role' => 'user', 'content' => '[reply in the language] zieg mir mal mein profil'], $assistant, $result]], 1200, 0)); // owner rewrite
        $messages->add($this->call(5, ['tools' => $tools, 'messages' => [['role' => 'system', 'content' => 'BASE + skill'], $owner, $assistant, $result]], 1300, 0)); // system
        $messages->add($this->call(7, ['tools' => $tools, 'messages' => [['role' => 'system', 'content' => 'BASE + skill'], $owner, $assistant, ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '[earlier tool result cleared to save context — getProfileSummary]']]], 1300, 1100)); // stub

        $report = PromptCacheDiffReport::fromMessages($messages);

        self::assertSame(5, $report->calls);
        self::assertSame([
            PromptCacheDiffReport::CAUSE_FIRST_CALL,
            PromptCacheDiffReport::CAUSE_APPEND,
            PromptCacheDiffReport::CAUSE_OWNER_MESSAGE_REWRITE,
            PromptCacheDiffReport::CAUSE_SYSTEM,
            PromptCacheDiffReport::CAUSE_RESULT_STUB,
        ], array_column($report->rows, 'cause'));
        self::assertSame(3, $report->zeroCreditCalls);
        self::assertSame((int)round(100 * 2100 / 6000), $report->hitPercent());
        self::assertSame('OpenAI', $report->rows[0]['provider']);
        self::assertSame(1, $report->callsByCause[PromptCacheDiffReport::CAUSE_SYSTEM]);
    }

    public function testAMovedBreakpointAndSystemContentPartsAreNoChange(): void
    {
        $breakpoint = ['mode' => 'explicit'];
        $tools = [['type' => 'function', 'function' => ['name' => 'load_skills']]];
        $systemParts = [['type' => 'text', 'text' => 'BASE', 'prompt_cache_breakpoint' => $breakpoint], ['type' => 'text', 'text' => ' + skill', 'prompt_cache_breakpoint' => $breakpoint]];
        $owner = ['role' => 'user', 'content' => 'zieg mir mal mein profil'];
        $ownerWithBreakpoint = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'zieg mir mal mein profil', 'prompt_cache_breakpoint' => $breakpoint]]];
        $assistant = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'getProfileSummary']]]];
        $resultWithBreakpoint = ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => [['type' => 'text', 'text' => 'a long result', 'prompt_cache_breakpoint' => $breakpoint]]];
        $messages = new AIConversationMessages();
        $call = $this->call(1, ['tools' => $tools, 'messages' => [['role' => 'system', 'content' => $systemParts], $ownerWithBreakpoint]], 1000, 0);
        $messages->add($call);
        $call = $this->call(2, ['tools' => $tools, 'messages' => [['role' => 'system', 'content' => 'BASE + skill'], $owner, $assistant, $resultWithBreakpoint]], 1200, 1000);
        $messages->add($call);

        $report = PromptCacheDiffReport::fromMessages($messages);

        self::assertFalse($report->rows[1]['systemChanged'], 'the system parts read as their joined text');
        self::assertSame(PromptCacheDiffReport::CAUSE_APPEND, $report->rows[1]['cause'], 'the owner message that carried the breakpoint last time is unchanged');
        self::assertSame(1, $report->rows[1]['firstDiff']['position'], 'the first difference is the appended assistant entry');
    }

    public function testTheLightSnapshotHashesNameTheFirstChangedSegmentWithoutAPayload(): void
    {
        $messages = new AIConversationMessages();
        $call = $this->hashedCall(1, ['BASE_PROMPT:aa', 'TIME_RULE:bb', 'LOADED_SKILLS:cc'], 'tools1', 1000, 0);
        $messages->add($call);
        $call = $this->hashedCall(3, ['BASE_PROMPT:aa', 'TIME_RULE:bb', 'LOADED_SKILLS:cc'], 'tools1', 1100, 1000);
        $messages->add($call);
        $call = $this->hashedCall(5, ['BASE_PROMPT:aa', 'TIME_RULE:bb', 'LOADED_SKILLS:dd'], 'tools1', 1200, 0);
        $messages->add($call);
        $call = $this->hashedCall(7, ['BASE_PROMPT:aa', 'TIME_RULE:bb', 'LOADED_SKILLS:dd'], 'tools2', 1300, 0);
        $messages->add($call);

        $report = PromptCacheDiffReport::fromMessages($messages);

        self::assertSame([
            PromptCacheDiffReport::CAUSE_FIRST_CALL,
            PromptCacheDiffReport::CAUSE_SYSTEM_AND_TOOLS_UNCHANGED,
            PromptCacheDiffReport::CAUSE_SYSTEM,
            PromptCacheDiffReport::CAUSE_TOOLS,
        ], array_column($report->rows, 'cause'));
        self::assertSame([null, null, 'LOADED_SKILLS', null], array_column($report->rows, 'firstChangedSystemSegment'));
        self::assertNull($report->rows[2]['firstDiff'], 'no payload — no wire-message diff');
    }

    /** @param string[] $systemSegmentHashes */
    protected function hashedCall(int $index, array $systemSegmentHashes, string $toolsHash, int $input, int $cached): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->inputTokens = $input;
        $message->cachedInputTokens = $cached;
        $snapshot = new RequestSnapshot();
        $snapshot->systemSegmentHashes = $systemSegmentHashes;
        $snapshot->toolsHash = $toolsHash;
        $message->requestSnapshot = $snapshot;
        return $message;
    }

    public function testMessagesWithoutASnapshotAreSkipped(): void
    {
        $messages = new AIConversationMessages();
        $plain = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $plain->indexInConversation = 0;
        $messages->add($plain);
        self::assertSame(0, PromptCacheDiffReport::fromMessages($messages)->calls);
    }

    /**
     * A Responses body is read from its `input` (the first developer message is the system content), and an
     * appended `additional_tools` item with the prefix unchanged is the `toolsAppended` cause.
     */
    public function testAResponsesSkillRowAppendIsToolsAppended(): void
    {
        $tools = [['type' => 'function', 'name' => 'load_skills']];
        $developer = ['type' => 'message', 'role' => 'developer', 'content' => [['type' => 'input_text', 'text' => 'BASE', 'prompt_cache_breakpoint' => ['mode' => 'explicit']]]];
        $owner = ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'zeig mir meine rankings']]];
        $loadCall = ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'load_skills', 'arguments' => '{}'];
        $loadResult = ['type' => 'function_call_output', 'call_id' => 'c1', 'output' => 'loaded'];
        $skillRow = ['type' => 'message', 'role' => 'developer', 'content' => [['type' => 'input_text', 'text' => "# Skills loaded now\nSeo.Rankings"]]];
        $appendedTools = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => [['type' => 'function', 'name' => 'getRankings']]];
        $messages = new AIConversationMessages();
        $firstCall = $this->call(1, ['tools' => $tools, 'input' => [$developer, $owner]], 1000, 0);
        $messages->add($firstCall);
        $secondCall = $this->call(4, ['tools' => $tools, 'input' => [$developer, $owner, $loadCall, $loadResult, $skillRow, $appendedTools]], 1400, 1000);
        $secondCall->requestSnapshot->recordAppendedToolsHash([$developer, $owner, $loadCall, $loadResult, $skillRow, $appendedTools]);
        $messages->add($secondCall);

        $report = PromptCacheDiffReport::fromMessages($messages);

        self::assertFalse($report->rows[1]['systemChanged'], 'the developer message is the unchanged system content');
        self::assertFalse($report->rows[1]['toolsChanged']);
        self::assertTrue($report->rows[1]['toolsAppended']);
        self::assertSame(PromptCacheDiffReport::CAUSE_TOOLS_APPENDED, $report->rows[1]['cause']);
        self::assertSame('function_call', $report->rows[1]['firstDiff']['role'], 'a role-less item reads as its type');
    }

    /**
     * The previous call's input ended on the volatile tail (the clock signal); this call has the
     * load's items at that position — the first difference is a CHANGE, and with the system content and the top-level
     * tools unchanged it is still the `toolsAppended` cause.
     */
    public function testALoadAfterTheVolatileTailIsToolsAppended(): void
    {
        $tools = [['type' => 'function', 'name' => 'load_skills']];
        $developer = ['type' => 'message', 'role' => 'developer', 'content' => [['type' => 'input_text', 'text' => 'BASE']]];
        $owner = ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'zeig mir meine rankings']]];
        $clockTail = ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => '[SIGNAL:clock] 09:15']]];
        $loadCall = ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'load_skills', 'arguments' => '{}'];
        $loadResult = ['type' => 'function_call_output', 'call_id' => 'c1', 'output' => 'loaded'];
        $appendedTools = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => [['type' => 'function', 'name' => 'getRankings']]];
        $messages = new AIConversationMessages();
        $firstCall = $this->call(1, ['tools' => $tools, 'input' => [$developer, $owner, $clockTail]], 1000, 0);
        $messages->add($firstCall);
        $secondInput = [$developer, $owner, $loadCall, $loadResult, $appendedTools, $clockTail];
        $secondCall = $this->call(4, ['tools' => $tools, 'input' => $secondInput], 1400, 1000);
        $secondCall->requestSnapshot->recordAppendedToolsHash($secondInput);
        $messages->add($secondCall);

        $report = PromptCacheDiffReport::fromMessages($messages);

        self::assertSame('change', $report->rows[1]['firstDiff']['kind']);
        self::assertSame(PromptCacheDiffReport::CAUSE_TOOLS_APPENDED, $report->rows[1]['cause']);
    }
}
