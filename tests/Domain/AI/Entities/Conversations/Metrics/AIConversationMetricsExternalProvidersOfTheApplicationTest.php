<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Metrics;

use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A failed tool call counts as the failure of an external provider when its result names one. Which providers an
 * application's tools call is the application's knowledge: the module lists none, and an application lists them on a
 * metrics class of the same path.
 */
class AIConversationMetricsExternalProvidersOfTheApplicationTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testTheModuleListsNoExternalProvider(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationMetrics::class] = AIConversationMetrics::class;

        self::assertSame([], AIConversationMetrics::EXTERNAL_PROVIDER_MARKERS);
        self::assertSame(
            AIConversationMetrics::FAILURE_KIND_EXCEPTION,
            AIConversationMetrics::classifyToolCallFailure($this->buildFailedToolCallRow('The parcel carrier API answered 503'))
        );
    }

    public function testAFailureThatNamesAProviderOfTheApplicationCountsAsExternal(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationMetrics::class] = AIConversationMetricsOfAnApplicationWithAParcelCarrier::class;

        self::assertSame(
            AIConversationMetrics::FAILURE_KIND_EXTERNAL,
            AIConversationMetrics::classifyToolCallFailure($this->buildFailedToolCallRow('The parcel carrier API answered 503'))
        );
        self::assertSame(
            AIConversationMetrics::FAILURE_KIND_EXCEPTION,
            AIConversationMetrics::classifyToolCallFailure($this->buildFailedToolCallRow('The stock table is locked'))
        );
    }

    public function testTheModuleBuildsTheMetricsOfTheApplication(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversationMetrics::class] = AIConversationMetricsOfAnApplicationWithAParcelCarrier::class;

        $metrics = AIConversationMetrics::buildFromRows([['i' => 0, 'role' => 'user', 'c' => '2026-01-05 10:00:00']]);

        self::assertInstanceOf(AIConversationMetricsOfAnApplicationWithAParcelCarrier::class, $metrics);
    }

    /** @return array<string, mixed> */
    protected function buildFailedToolCallRow(string $resultHead): array
    {
        return ['tool' => 'bookParcel', 'toolStatus' => AIConversationMetrics::TOOL_STATUS_FAILED, 'resultHead' => $resultHead];
    }
}

/** An application whose tools call a parcel carrier. */
class AIConversationMetricsOfAnApplicationWithAParcelCarrier extends AIConversationMetrics
{
    public const array EXTERNAL_PROVIDER_MARKERS = ['parcel carrier API'];
}
