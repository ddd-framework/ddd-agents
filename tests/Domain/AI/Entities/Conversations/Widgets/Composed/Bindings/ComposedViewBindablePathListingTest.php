<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindablePathListing;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResultRecord;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolResultPath;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `$from` paths a bindable result offers are generated from its STORED shape and
 * every listed path selects what it says in that shape.
 */
class ComposedViewBindablePathListingTest extends UnitTestCase
{
    public function testRowListsValuesAndNestedObjectsAreListedAsPathsThatSelect(): void
    {
        $resultData = [
            'objectType' => 'Dto',
            'competitors' => ['objectType' => 'Set', 'elements' => [['id' => 1, 'name' => 'A', 'objectType' => 'X'], ['id' => 2, 'name' => 'B', 'objectType' => 'X']]],
            'maxAllowed' => 5,
            'trackedKeywordNames' => ['pizza', 'pasta'],
            'empty' => [],
            'missing' => null,
        ];
        $listing = ComposedViewBindablePathListing::fromResultData($resultData);
        self::assertSame(
            '[ComposedView $from paths of toolCall "call_7": $.competitors.elements[*] (2 rows: id, name); $.maxAllowed; $.trackedKeywordNames (2 rows)]',
            $listing->toModelLine('call_7')
        );
        foreach ($listing->listedPaths as $listedPath) {
            $found = false;
            ComposedViewToolResultPath::fromPathString($listedPath->path)?->selectFrom($resultData, $found);
            self::assertTrue($found, $listedPath->path . ' selects something');
        }
    }

    /**
     * A row's scalar keys come first, its object keys as dotted leaves after them — a nested
     * `author.name` is named, and twelve keys fit before the `…`.
     */
    public function testARowListsItsScalarKeysFirstThenItsObjectLeavesAsDottedKeys(): void
    {
        $reviewRow = [
            'externalId' => 'r1',
            'author' => ['name' => 'Anna', 'objectType' => 'Author', 'photo' => ['url' => 'https://x', 'width' => 40]],
            'text' => 'Great',
            'rating' => 5,
            'replies' => [['text' => 'Thanks']],
            'changeHistory' => ['createdTime' => '2026-10-01', 'modifiedTime' => null],
            'canReply' => true,
        ];
        self::assertSame(
            ['externalId', 'text', 'rating', 'replies', 'canReply', 'author.name', 'author.photo.url', 'author.photo.width', 'changeHistory.createdTime', 'changeHistory.modifiedTime'],
            ComposedViewBindablePathListing::buildListedRowKeys($reviewRow)
        );
        self::assertSame(
            '[ComposedView $from paths of toolCall "call_4": $.reviews.elements[*] (2 rows: externalId, text, rating, replies, canReply, author.name, author.photo.url, author.photo.width, changeHistory.createdTime, changeHistory.modifiedTime)]',
            ComposedViewBindablePathListing::fromResultData(['reviews' => ['elements' => [$reviewRow, $reviewRow]]])->toModelLine('call_4')
        );
        $wideRow = [];
        for ($keyNumber = 0; $keyNumber < 14; $keyNumber++) {
            $wideRow['key' . $keyNumber] = $keyNumber;
        }
        $listedRowKeys = ComposedViewBindablePathListing::buildListedRowKeys($wideRow);
        self::assertCount(ComposedViewBindablePathListing::MAX_LISTED_ROW_KEYS + 1, $listedRowKeys);
        self::assertSame('…', end($listedRowKeys));
    }

    public function testATopLevelListAndTheCaps(): void
    {
        self::assertSame('[ComposedView $from paths of toolCall "c": $[*] (1 row: a)]', ComposedViewBindablePathListing::fromResultData([['a' => 1]])->toModelLine('c'));
        $manyKeys = [];
        for ($keyNumber = 0; $keyNumber < 14; $keyNumber++) {
            $manyKeys['key' . $keyNumber] = $keyNumber;
        }
        $listing = ComposedViewBindablePathListing::fromResultData($manyKeys);
        self::assertCount(ComposedViewBindablePathListing::MAX_LISTED_PATHS, $listing->listedPaths);
        self::assertStringEndsWith('; …]', (string)$listing->toModelLine('c'));
        self::assertNull(ComposedViewBindablePathListing::fromResultData('text')->toModelLine('c'), 'a scalar shape offers no path');
    }

    public function testTheToolCallRendersTheLineOnlyForAStoredBindableShape(): void
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_3';
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->result = (string)json_encode(['content' => [['type' => 'text', 'text' => 'Rankings …']], 'isError' => false]);
        self::assertNull($toolCall->buildBindablePathsLineForModel(), 'no record — not bindable');

        $toolCall->bindableResult = ComposedViewBindableResultRecord::forResult(['rankings' => [['keyword' => 'pizza', 'position' => 3]]], false, [])->toStoredArray();
        self::assertSame('[ComposedView $from paths of toolCall "call_3": $.rankings[*] (1 row: keyword, position)]', $toolCall->buildBindablePathsLineForModel());

        $toolCall->bindableResult = ComposedViewBindableResultRecord::forResult(['rows' => str_repeat('x', ComposedViewBindableResultRecord::MAX_DATA_BYTES + 1)], false, [])->toStoredArray();
        self::assertNull($toolCall->buildBindablePathsLineForModel(), 'a shape too large to bind offers no path');
    }
}
