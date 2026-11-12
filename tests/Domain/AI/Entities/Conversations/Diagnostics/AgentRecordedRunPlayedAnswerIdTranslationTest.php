<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A played model answer of a live-tools replay: the ids the recording run created are translated
 * to the replay's ids of the same table and creation ordinal, inside tool-call arguments only; pre-existing ids and
 * business numbers stay; a created id the replay has no counterpart for yet stays and is reported.
 */
class AgentRecordedRunPlayedAnswerIdTranslationTest extends UnitTestCase
{
    /** A chat-completion answer whose one tool call carries $arguments, plus a text that mentions a created id. */
    protected static function answerWithToolCall(array $arguments): string
    {
        return (string)json_encode([
            'choices' => [[
                'message' => [
                    'content' => 'Ich entferne Keyword 7000102 und frage nach 312 Besuchern.',
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'removeKeywords', 'arguments' => json_encode($arguments)]]],
                ],
            ]],
        ]);
    }

    protected static function translation(): AgentRecordedRunPlayedAnswerIdTranslation
    {
        // the recording created keywords 7000101..7000103 and competitor 312 (a small table, declared for competitorId)
        return AgentRecordedRunPlayedAnswerIdTranslation::forRecordedCreatedIds([
            'keywords' => [7000103, 7000101, 7000102],
            'site_competitors' => [312],
        ]);
    }

    public function testCreatedIdsAreTranslatedByTableAndOrdinalAndPreExistingIdsAndBusinessNumbersStay(): void
    {
        $translation = self::translation();
        $readCount = 0;
        $translatedBody = $translation->translateResponseBody(
            self::answerWithToolCall(['keywordIds' => [7000102, '7000103', 6999000], 'competitorId' => 312, 'visitors' => 312, 'shopId' => 500553]),
            static function () use (&$readCount): array {
                $readCount++;
                return ['keywords' => [9000203, 9000201, 9000202], 'site_competitors' => [540]];
            },
            'recorded call #4'
        );

        $decodedAnswer = json_decode($translatedBody, true);
        $translatedArguments = json_decode($decodedAnswer['choices'][0]['message']['tool_calls'][0]['function']['arguments'], true);
        self::assertSame([9000202, '9000203', 6999000], $translatedArguments['keywordIds'], 'ordinal 2 → 2, ordinal 3 → 3, a pre-existing id stays');
        self::assertSame(540, $translatedArguments['competitorId'], 'a short id under an id key is translated');
        self::assertSame(312, $translatedArguments['visitors'], 'a business number equal to a created id is no id');
        self::assertSame(500553, $translatedArguments['shopId']);
        self::assertStringContainsString('Keyword 7000102', $decodedAnswer['choices'][0]['message']['content'], 'free text is never touched');
        self::assertSame(1, $readCount, 'the replay ids are read once per answer');
        self::assertSame([
            'recorded call #4: keywords #2 7000102 → 9000202',
            'recorded call #4: keywords #3 7000103 → 9000203',
            'recorded call #4: site_competitors #1 312 → 540',
        ], $translation->translations);
        self::assertSame([], $translation->missingCounterparts);
    }

    public function testAnAnswerWithoutACreatedIdStaysByteIdenticalAndReadsNothing(): void
    {
        $answer = self::answerWithToolCall(['keywordIds' => [6999000], 'visitors' => 312]);
        $translatedBody = self::translation()->translateResponseBody($answer, static function (): array {
            self::fail('no candidate — the database is not read');
        }, 'recorded call #1');

        self::assertSame($answer, $translatedBody);
    }

    public function testACreatedIdWithoutReplayCounterpartStaysAndIsReportedAsADifference(): void
    {
        $translation = self::translation();
        $translatedBody = $translation->translateResponseBody(
            self::answerWithToolCall(['keywordId' => 7000103]),
            static fn (): array => ['keywords' => [9000201]],
            'recorded call #7'
        );

        self::assertStringContainsString('7000103', $translatedBody);
        self::assertSame(['recorded call #7: played answer refers to created id 7000103 (keywords #3) with no replay counterpart — left unchanged'], $translation->missingCounterparts);

        $comparison = new AgentRecordedRunComparison();
        $comparison->addPlayedAnswerIdTranslationReport($translation->translations, $translation->missingCounterparts);
        self::assertFalse($comparison->isIdentical());
        self::assertSame($translation->missingCounterparts, $comparison->differences);
    }

    public function testAnIdKeyWithADeclaredTableTranslatesOnlyTheIdsItsOwnTableCreated(): void
    {
        $translation = AgentRecordedRunPlayedAnswerIdTranslationWithDeclaredIdKeys::forRecordedCreatedIds(['site_competitors' => [7000500], 'keywords' => [7000101]]);
        $translatedBody = $translation->translateResponseBody(
            self::answerWithToolCall(['keywordId' => 7000500, 'competitorId' => 7000500]),
            static fn (): array => ['site_competitors' => [9000500], 'keywords' => [9000101]],
            'recorded call #2'
        );

        $translatedArguments = json_decode(json_decode($translatedBody, true)['choices'][0]['message']['tool_calls'][0]['function']['arguments'], true);
        self::assertSame(7000500, $translatedArguments['keywordId'], 'a competitor id under a keyword key is no created keyword');
        self::assertSame(9000500, $translatedArguments['competitorId']);
    }

    public function testThePackageDeclaresNoIdKeySoAnIdKeyOfAnApplicationTranslatesTheIdsOfEveryTable(): void
    {
        self::assertSame([], AgentRecordedRunPlayedAnswerIdTranslation::TABLE_BY_ID_KEY);
        $translation = AgentRecordedRunPlayedAnswerIdTranslation::forRecordedCreatedIds(['site_competitors' => [7000500], 'keywords' => [7000101]]);
        $translatedBody = $translation->translateResponseBody(
            self::answerWithToolCall(['keywordId' => 7000500, 'competitorId' => 7000500]),
            static fn (): array => ['site_competitors' => [9000500], 'keywords' => [9000101]],
            'recorded call #2'
        );

        $translatedArguments = json_decode(json_decode($translatedBody, true)['choices'][0]['message']['tool_calls'][0]['function']['arguments'], true);
        self::assertSame(9000500, $translatedArguments['keywordId'], 'without a declared table the key takes the id of whichever table created it');
        self::assertSame(9000500, $translatedArguments['competitorId']);
    }

    public function testAConversationKeyNamesTheTableOfTheConversationModel(): void
    {
        $conversationTable = (string)DBAIConversationModel::getTableName();
        $translation = AgentRecordedRunPlayedAnswerIdTranslation::forRecordedCreatedIds([$conversationTable => [7000700], 'keywords' => [7000701]]);
        $translatedBody = $translation->translateResponseBody(
            self::answerWithToolCall(['conversationId' => 7000700, 'aiConversationId' => 7000701]),
            static fn (): array => [$conversationTable => [9000700], 'keywords' => [9000701]],
            'recorded call #3'
        );

        $translatedArguments = json_decode(json_decode($translatedBody, true)['choices'][0]['message']['tool_calls'][0]['function']['arguments'], true);
        self::assertSame(9000700, $translatedArguments['conversationId'], 'a conversation the recording created is translated at the ordinal of the model\'s table');
        self::assertSame(7000701, $translatedArguments['aiConversationId'], 'a keyword id under a conversation key is no created conversation');
        self::assertSame(["recorded call #3: {$conversationTable} #1 7000700 → 9000700"], $translation->translations);
    }
}

/** The translation of an application that declares the tables of the id keys its own tools take. */
class AgentRecordedRunPlayedAnswerIdTranslationWithDeclaredIdKeys extends AgentRecordedRunPlayedAnswerIdTranslation
{
    public const array TABLE_BY_ID_KEY = [
        'keywordId' => 'keywords', 'keywordIds' => 'keywords',
        'competitorId' => 'site_competitors', 'competitorIds' => 'site_competitors',
    ];
}
