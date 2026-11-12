<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;

/** A package with one ungated tool that only records its runs: what a test of the executor's steps before the tool body calls. */
class ProbeNoteMCPPackage extends MCPPackage
{
    /** The text the tool answers with */
    public const string RESULT_TEXT = 'note recorded';

    /** @var array<int, int|null> the `agentSpaceId` each run of the tool received, in order — one entry per run */
    public static array $agentSpaceIdsOfRuns = [];

    public static function forgetRuns(): void
    {
        static::$agentSpaceIdsOfRuns = [];
    }

    /** Records a note. */
    #[MCPTool]
    public function probeRecordNote(ProbeNoteRequestDto $requestDto): string
    {
        static::$agentSpaceIdsOfRuns[] = $requestDto->agentSpaceId;
        return static::RESULT_TEXT;
    }
}
