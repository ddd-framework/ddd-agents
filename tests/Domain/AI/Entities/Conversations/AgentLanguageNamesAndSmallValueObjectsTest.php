<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentBackgroundOperationOutcome;
use DDD\Domain\AI\Entities\Conversations\Languages\AgentLanguageNames;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTarget;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use PHPUnit\Framework\TestCase;

/** The language-name helper and the small value objects of the space's zone, the background operations and the memory repair. */
class AgentLanguageNamesAndSmallValueObjectsTest extends TestCase
{
    public function testALanguageCodeIsNormalisedToItsBareCode(): void
    {
        self::assertSame('de', AgentLanguageNames::normalizeLanguageCode(' DE-de '));
        self::assertSame('pt', AgentLanguageNames::normalizeLanguageCode('pt_BR'));
        self::assertNull(AgentLanguageNames::normalizeLanguageCode('  '));
        self::assertNull(AgentLanguageNames::normalizeLanguageCode(null));
    }

    public function testTheConfiguredTableDecidesAndAnUnknownCodeHasNoName(): void
    {
        $languageNames = ['de' => ['englishName' => 'German', 'nativeName' => 'Deutsch']];
        self::assertSame('German', AgentLanguageNames::findEnglishNameForLanguageCode('de_AT', $languageNames));
        self::assertNull(AgentLanguageNames::findEnglishNameForLanguageCode('fr', $languageNames), 'a table without the code: no ICU fallback');
        self::assertNull(AgentLanguageNames::findEnglishNameForLanguageCode('', $languageNames));
    }

    public function testWithoutATableTheIcuNameIsUsed(): void
    {
        if (!class_exists(\Locale::class)) {
            self::markTestSkipped('ext-intl is not installed');
        }
        self::assertSame('German', AgentLanguageNames::findEnglishNameForLanguageCode('de', null));
    }

    public function testTheTimezoneOfASpaceGivesItsZone(): void
    {
        $agentSpaceTimezone = new AgentSpaceTimezone();
        $agentSpaceTimezone->identifier = 'Europe/Vienna';
        self::assertSame('Europe/Vienna', $agentSpaceTimezone->getTimezone()->getName());
        self::assertTrue($agentSpaceTimezone->unverified, 'unverified until the space confirms its zone');
    }

    public function testTheBackgroundOperationOutcomes(): void
    {
        $completed = AgentBackgroundOperationOutcome::completed('done');
        $failed = AgentBackgroundOperationOutcome::failed('no');
        self::assertSame([false, 'done'], [$completed->operationFailed, $completed->verdictText]);
        self::assertSame([true, 'no'], [$failed->operationFailed, $failed->verdictText]);
    }

    public function testAnEmbeddingRepairTargetNamesItsColumns(): void
    {
        $target = AgentEmbeddingRepairTarget::forModel(DBAIConversationCompactionNodeModel::class, 'summaryText', 'summaryEmbedding');
        self::assertSame(
            [DBAIConversationCompactionNodeModel::class, 'summaryText', 'summaryEmbedding', DBAIConversationCompactionNodeModel::getTableName()],
            [$target->modelClassName, $target->textColumnName, $target->vectorColumnName, $target->getTableName()]
        );
    }
}
