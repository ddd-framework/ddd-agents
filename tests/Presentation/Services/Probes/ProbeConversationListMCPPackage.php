<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPExposedFilterProperties;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetRequestDto;

/**
 * A package with two ungated list tools on the conversation set that only record their runs: one names the properties
 * it filters and sorts on, the other names none and accepts every filter of the entity.
 */
class ProbeConversationListMCPPackage extends MCPPackage
{
    /** The text both tools answer with */
    public const string RESULT_TEXT = 'conversations listed';

    /** @var array<int, string> the name of the tool of each run, in order — one entry per run */
    public static array $toolNamesOfRuns = [];

    public static function forgetRuns(): void
    {
        static::$toolNamesOfRuns = [];
    }

    /** Lists conversations, filtered and sorted on the schedule and the run state only. */
    #[MCPTool]
    #[MCPExposedFilterProperties('scheduleId', 'runState')]
    public function probeListConversations(AIConversationsGetRequestDto $requestDto): string
    {
        static::$toolNamesOfRuns[] = __FUNCTION__;
        return static::RESULT_TEXT;
    }

    /** Lists conversations, filtered and sorted on any property of the entity. */
    #[MCPTool]
    public function probeListConversationsOnEveryProperty(AIConversationsGetRequestDto $requestDto): string
    {
        static::$toolNamesOfRuns[] = __FUNCTION__;
        return static::RESULT_TEXT;
    }
}
