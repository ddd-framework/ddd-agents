<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewBinding;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewValidationFinding;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The tree-state rules over the real node types: a `set` on an undeclared name declares it with null; a choice
 * state outside its choices, a set state nothing reads and an `$item` field the resolved `$from` rows lack are kept
 * with a note (`ComposedViewValidationFinding::NOTE_CODES`). The frontend twin is held equal by the parity fixture.
 */
class ComposedViewTreeStateRulesTest extends UnitTestCase
{
    /** The round-10 form: inputs with a label and a `set` each, no `state` block, a submit label — read without a finding. */
    public function testAFormWhoseInputsOnlySetTheirNamesReadsWithoutAFinding(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'Form', 'name' => 'callbackRequest', 'title' => 'Rückruf', 'submitLabel' => 'Absenden', 'children' => [
                ['type' => 'Input', 'label' => 'Name', 'onChange' => ['set' => 'name']],
                ['type' => 'Input', 'label' => 'Telefon', 'inputType' => 'text', 'onChange' => ['set' => 'phone']],
                ['type' => 'Select', 'label' => 'Zeitfenster', 'options' => ['Vormittag', 'Nachmittag'], 'onChange' => ['set' => 'slot']],
            ]],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['name' => null, 'phone' => null, 'slot' => null], $composedViewTreeReading->state);
        self::assertCount(3, $composedViewTreeReading->view->getNodesOfSlot('children'), 'no onChange is dropped');
    }

    /** The round-10 dashboard: Tabs whose state starts as a label instead of an option id, and that nothing reads. */
    public function testTabsWhoseStateIsNoChoiceAndThatNothingReadsAreKeptWithTheirNotes(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['period' => '3 Monate'],
            'view' => ['type' => 'Stack', 'children' => [
                ['type' => 'Tabs', 'options' => [['id' => '3m', 'label' => '3 Monate (Q3)'], ['id' => '12m', 'label' => '12 Monate']], 'value' => ['$state' => 'period'], 'onChange' => ['set' => 'period']],
                ['type' => 'Text', 'text' => 'Bewertungen'],
            ]],
        ]);
        self::assertSame([ComposedViewValidationFinding::CODE_INVALID_INITIAL_CHOICE, ComposedViewValidationFinding::CODE_UNREAD_STATE], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['view.children[0]', 'state.period'], array_map(static fn (ComposedViewValidationFinding $finding): string => $finding->path, $composedViewTreeReading->findings->getElements()));
        self::assertStringContainsString('(3m, 3 Monate (Q3), 12m, 12 Monate)', $composedViewTreeReading->findings->first()->message, 'the finding names the choices');
        self::assertCount(2, $composedViewTreeReading->view->getNodesOfSlot('children'), 'the Tabs stay');
        foreach ($composedViewTreeReading->findings->getCodes() as $findingCode) {
            self::assertContains($findingCode, ComposedViewValidationFinding::NOTE_CODES);
        }
    }

    /** A computed value that reads the state, and an initial value that is a choice's id, give no note. */
    public function testTabsReadThroughAComputedValueGiveNoNote(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['period' => '3m'],
            'data' => ['short' => [['m' => 'Okt', 'n' => 4]], 'long' => [['m' => 'Okt', 'n' => 4]]],
            'computed' => ['rows' => "period == '3m' ? short : long"],
            'view' => ['type' => 'Stack', 'children' => [
                ['type' => 'Tabs', 'options' => [['id' => '3m', 'label' => '3 Monate'], ['id' => '12m', 'label' => '12 Monate']], 'value' => ['$state' => 'period'], 'onChange' => ['set' => 'period']],
                ['type' => 'Chart', 'kind' => 'bar', 'data' => ['$computed' => 'rows'], 'x' => 'm', 'series' => [['field' => 'n', 'label' => 'Reviews']], 'source' => ['name' => 'listReviews']],
            ]],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    /**
     * The round-10 repeat: an `$item` over a resolved `$from` list reads a dotted field of a nested object; a field no
     * row has is kept with UNKNOWN_ITEM_KEY, which lists the rows' fields.
     */
    public function testAnItemFieldTheResolvedRowsLackIsKeptWithTheRowFields(): void
    {
        $reviewRows = [
            ['externalId' => 'r1', 'text' => 'Super', 'rating' => 5, 'author' => ['name' => 'Anna'], 'changeHistory' => ['createdTime' => '2026-10-01']],
            ['externalId' => 'r2', 'text' => 'Gut', 'rating' => 4, 'author' => ['name' => 'Ben'], 'changeHistory' => ['createdTime' => '2026-10-02']],
        ];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['reviews' => ['$from' => ['toolCall' => 'call_3', 'path' => '$.reviews.elements[*]', 'state' => 'resolved', 'value' => $reviewRows, 'rowCount' => 2, 'resolvedAt' => '2026-10-26T09:00:00Z']]],
            'view' => ['type' => 'Table', 'children' => [['type' => 'Repeat', 'items' => ['$data' => 'reviews'], 'children' => [['type' => 'TableRow', 'children' => [
                ['type' => 'TableCell', 'value' => ['$item' => 'author.name']],
                ['type' => 'TableCell', 'value' => ['$item' => 'changeHistory.createdTime']],
                ['type' => 'TableCell', 'value' => ['$item' => 'created']],
            ]]]]]],
        ]);
        self::assertSame([ComposedViewValidationFinding::CODE_UNKNOWN_ITEM_KEY], $composedViewTreeReading->findings->getCodes());
        $finding = $composedViewTreeReading->findings->first();
        self::assertSame('view.children[0].children[0].children[2].value', $finding->path);
        self::assertStringContainsString('"created"', $finding->message);
        self::assertStringContainsString('externalId, text, rating, author.name, changeHistory.createdTime', $finding->message);
        self::assertCount(3, $composedViewTreeReading->view->getNodesOfSlot('children')[0]->getNodesOfSlot('children')[0]->getNodesOfSlot('children'), 'every cell stays');
    }

    public function testARowFieldIsReadAsWrittenThenAsADottedPath(): void
    {
        $row = ['author' => ['name' => 'Anna', 'photo' => null], 'a.b' => 'literal', 'list' => [['x' => 1]]];
        $found = false;
        self::assertSame('Anna', ComposedViewBinding::readRowField($row, 'author.name', $found));
        self::assertTrue($found);
        self::assertNull(ComposedViewBinding::readRowField($row, 'author.photo', $found));
        self::assertTrue($found, 'a field that holds null is found');
        self::assertSame('literal', ComposedViewBinding::readRowField($row, 'a.b', $found), 'a key with a dot as written wins');
        ComposedViewBinding::readRowField($row, 'author.missing', $found);
        self::assertFalse($found);
        ComposedViewBinding::readRowField($row, 'list.x', $found);
        self::assertFalse($found, 'a list is no object to walk into');
    }
}
