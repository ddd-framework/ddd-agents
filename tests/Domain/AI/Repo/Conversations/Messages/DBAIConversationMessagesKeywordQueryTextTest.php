<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Conversations\Messages;

use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The keyword query as PostgreSQL reads it ({@see DBAIConversationMessages::buildKeywordQueryTextForPostgres()}):
 * the words of the query joined with `or`, so a message matches when it holds any of them.
 */
class DBAIConversationMessagesKeywordQueryTextTest extends UnitTestCase
{
    public function testTheWordsAreJoinedWithOrInLowerCaseEachOnce(): void
    {
        self::assertSame(
            'bakery or invoice or december',
            DBAIConversationMessages::buildKeywordQueryTextForPostgres('Bakery invoice, BAKERY December')
        );
    }

    public function testPunctuationSeparatesWordsAndCarriesNoMeaning(): void
    {
        self::assertSame(
            'brien or said or pay or invoice or 2026 or not_yet',
            DBAIConversationMessages::buildKeywordQueryTextForPostgres('O\'Brien said: "pay -invoice 2026" (not_yet)!'),
            'a quote would start a phrase and a leading minus would exclude a word in the query language'
        );
    }

    public function testWordsShorterThanTheMinimumLengthAndTheOperatorWordAreLeftOut(): void
    {
        self::assertSame(
            'send or 42nd or reminder',
            DBAIConversationMessages::buildKeywordQueryTextForPostgres('send it to me OR a 42nd reminder at 9')
        );
    }

    public function testLettersOfEveryScriptCountAsWordCharacters(): void
    {
        self::assertSame(
            'bäckerei or öffnet or früher',
            DBAIConversationMessages::buildKeywordQueryTextForPostgres('Bäckerei öffnet früher')
        );
    }

    public function testAQueryWithoutASearchableWordGivesTheEmptyText(): void
    {
        self::assertSame('', DBAIConversationMessages::buildKeywordQueryTextForPostgres(''));
        self::assertSame('', DBAIConversationMessages::buildKeywordQueryTextForPostgres('is a -- or ?'));
    }
}
