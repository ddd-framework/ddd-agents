<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The light per-call snapshot records a hash per system segment and one of the tools, so the cache diff runs on all traffic. */
class RequestSnapshotWireHashesTest extends UnitTestCase
{
    public function testOneTypedHashPerSegmentInOrderAndOneToolsHash(): void
    {
        $requestSnapshot = new RequestSnapshot();
        $tools = [['type' => 'function', 'function' => ['name' => 'load_skills']]];

        $requestSnapshot->recordSystemSegmentAndToolsHashes([
            AgentContextSegment::of(AgentContextSegmentType::BASE_PROMPT, 'BASE'),
            AgentContextSegment::of(AgentContextSegmentType::TIME_RULE, "\n\n# Time\nTimes are the business's local time: UTC."),
        ], $tools);

        self::assertSame([
            'BASE_PROMPT:' . hash('xxh64', 'BASE'),
            'TIME_RULE:' . hash('xxh64', "\n\n# Time\nTimes are the business's local time: UTC."),
        ], $requestSnapshot->systemSegmentHashes);
        self::assertSame(hash('xxh64', (string)json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $requestSnapshot->toolsHash);

        $requestSnapshot->recordSystemSegmentAndToolsHashes([], null);
        self::assertSame([], $requestSnapshot->systemSegmentHashes);
        self::assertNull($requestSnapshot->toolsHash, 'no tools sent — no hash');
    }
}
