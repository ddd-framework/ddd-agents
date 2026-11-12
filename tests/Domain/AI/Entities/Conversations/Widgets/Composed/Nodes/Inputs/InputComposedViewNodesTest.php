<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\ButtonComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\CheckboxComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\DatePickerComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\InputComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\RadioGroupComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\SelectComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\SliderComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\TabsComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs\TextareaComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The nine input nodes of the kit: each is found by folder under its wire type, is an `input` node,
 * keeps a valid node with its state binding and its `{"set": …}` / `{"action": …}` event, and drops what does not fit
 * (an undeclared prop, a value outside an enum, an event to a name that is no identifier, a missing required prop) with the
 * reader's finding code.
 */
class InputComposedViewNodesTest extends UnitTestCase
{
    /** @var array<string, class-string> wire type → node class */
    protected const array INPUT_NODE_CLASSES = [
        'Tabs' => TabsComposedViewNode::class,
        'Select' => SelectComposedViewNode::class,
        'RadioGroup' => RadioGroupComposedViewNode::class,
        'Checkbox' => CheckboxComposedViewNode::class,
        'Slider' => SliderComposedViewNode::class,
        'Input' => InputComposedViewNode::class,
        'Textarea' => TextareaComposedViewNode::class,
        'DatePicker' => DatePickerComposedViewNode::class,
        'Button' => ButtonComposedViewNode::class,
    ];

    /** A computed value that reads every state of the trees below — an input whose state nothing reads is flagged UNREAD_STATE. */
    protected const array COMPUTED_READING_EVERY_STATE = ['readsEveryState' => 'concat(visitors, period, benchmark, asOf, note)'];

    public function testEveryInputNodeIsRegisteredByFolderAsAnInputNodeWithASummary(): void
    {
        $nodeClassesByNodeType = ComposedViewNodeClasses::getNodeClassesByNodeType();
        foreach (self::INPUT_NODE_CLASSES as $nodeType => $nodeClass) {
            self::assertSame($nodeClass, $nodeClassesByNodeType[$nodeType] ?? null, $nodeType);
            self::assertSame([ComposedViewNodeCategory::INPUT], $nodeClass::CATEGORIES, $nodeType);
            self::assertNotSame('', $nodeClass::SUMMARY, $nodeType);
            self::assertNotNull($nodeClass::findPropDeclaration($nodeType === 'Button' ? 'onClick' : 'onChange'), $nodeType);
        }
    }

    public function testTheRevenueSimulatorInputsAreKeptWithTheirBindingsAndEvents(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['visitors' => 35000, 'period' => '7d', 'benchmark' => true, 'asOf' => '2026-10-25', 'note' => ''],
            'computed' => self::COMPUTED_READING_EVERY_STATE,
            'actions' => ['copyResult' => ['type' => 'copy', 'value' => 'x'], 'reset' => ['type' => 'resetState']],
            'view' => ['type' => 'Slider', 'label' => 'Visitors / month', 'min' => 0, 'max' => 100000, 'step' => 1000,
                'value' => ['$state' => 'visitors'], 'onChange' => ['set' => 'visitors'], 'unit' => '', 'decimals' => 0, 'showValue' => true],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['label', 'min', 'max', 'step', 'value', 'onChange', 'unit', 'decimals', 'showValue'], array_keys($composedViewTreeReading->view->props));

        foreach ($this->buildValidInputNodes() as $nodeType => $wireNode) {
            $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
                'state' => ['visitors' => 35000, 'period' => '7d', 'benchmark' => true, 'asOf' => '2026-10-25', 'note' => ''],
                'computed' => self::COMPUTED_READING_EVERY_STATE,
                'actions' => ['copyResult' => ['type' => 'copy', 'value' => 'x'], 'reset' => ['type' => 'resetState']],
                'view' => $wireNode,
            ]);
            self::assertSame([], $composedViewTreeReading->findings->getCodes(), $nodeType);
            self::assertInstanceOf(self::INPUT_NODE_CLASSES[$nodeType], $composedViewTreeReading->view, $nodeType);
            self::assertSame(array_diff_key($wireNode, ['type' => true]), $composedViewTreeReading->view->props, $nodeType);
        }
    }

    public function testAnOptionsListOfLabelsOrIdObjectsIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['scenario' => 'base'],
            'computed' => ['picked' => 'scenario'],
            'view' => ['type' => 'Tabs', 'options' => ['Bear', ['id' => 'base', 'label' => 'Base'], ['id' => 'bull', 'label' => 'Bull', 'disabled' => true]],
                'value' => ['$state' => 'scenario'], 'onChange' => ['set' => 'scenario']],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertCount(3, $composedViewTreeReading->view->props['options']);
    }

    public function testInvalidPropsAreDroppedAndReported(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['period' => '7d'],
            'view' => ['type' => 'Select', 'options' => ['7 days', '30 days'], 'value' => ['$state' => 'period'],
                'onChange' => ['set' => '1notAName'], 'multiple' => true],
        ]);
        self::assertSame(['UNKNOWN_BINDING', 'INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['options', 'value'], array_keys($composedViewTreeReading->view->props));

        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'Input', 'inputType' => 'password', 'maxLength' => '20'],
        ]);
        self::assertSame(['INVALID_PROP', 'INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
        self::assertSame([], $composedViewTreeReading->view->props);

        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['period' => '7d'],
            'view' => ['type' => 'Tabs', 'options' => ['7 days'], 'value' => ['$state' => 'missing']],
        ]);
        self::assertSame(['UNKNOWN_BINDING'], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['options'], array_keys($composedViewTreeReading->view->props));
    }

    public function testANodeWithoutARequiredPropIsDropped(): void
    {
        foreach ([
            ['type' => 'Slider', 'min' => 0],
            ['type' => 'Checkbox'],
            ['type' => 'Tabs'],
            ['type' => 'Button', 'label' => 'Copy result'],
        ] as $wireNode) {
            $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => $wireNode]);
            self::assertNull($composedViewTreeReading->view, $wireNode['type']);
            self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes(), $wireNode['type']);
        }
    }

    public function testAButtonRunsAnActionOfTheTreeOnly(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'actions' => ['reset' => ['type' => 'resetState']],
            'view' => ['type' => 'Button', 'label' => 'Ask the agent', 'onClick' => ['action' => 'sendChatMessage']],
        ]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['UNKNOWN_BINDING', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    /**
     * One valid wire node per input type, against the state and actions of the tests above.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildValidInputNodes(): array
    {
        $periodOptions = [['id' => '7d', 'label' => '7 days'], ['id' => '30d', 'label' => '4 weeks']];
        return [
            'Tabs' => ['type' => 'Tabs', 'options' => $periodOptions, 'value' => ['$state' => 'period'], 'onChange' => ['set' => 'period'], 'label' => 'Period'],
            'Select' => ['type' => 'Select', 'options' => $periodOptions, 'value' => ['$state' => 'period'], 'onChange' => ['set' => 'period'], 'label' => 'Period', 'placeholder' => 'Pick one'],
            'RadioGroup' => ['type' => 'RadioGroup', 'options' => $periodOptions, 'value' => ['$state' => 'period'], 'onChange' => ['set' => 'period'], 'direction' => 'row'],
            'Checkbox' => ['type' => 'Checkbox', 'label' => 'Show comparison values', 'checked' => ['$state' => 'benchmark'], 'onChange' => ['set' => 'benchmark'], 'description' => '90 % of the visitors'],
            'Slider' => ['type' => 'Slider', 'min' => 1, 'max' => 10, 'step' => 0.1, 'value' => ['$state' => 'visitors'], 'onChange' => ['set' => 'visitors'], 'unit' => '%'],
            'Input' => ['type' => 'Input', 'value' => ['$state' => 'visitors'], 'onChange' => ['set' => 'visitors'], 'inputType' => 'number', 'maxLength' => 12, 'placeholder' => '0'],
            'Textarea' => ['type' => 'Textarea', 'value' => ['$state' => 'note'], 'onChange' => ['set' => 'note'], 'rows' => 4, 'maxLength' => 280],
            'DatePicker' => ['type' => 'DatePicker', 'value' => ['$state' => 'asOf'], 'onChange' => ['set' => 'asOf'], 'label' => 'Reference date', 'min' => '2026-01-01', 'max' => '2026-12-31'],
            'Button' => ['type' => 'Button', 'label' => 'Copy result', 'onClick' => ['action' => 'copyResult'], 'variant' => 'primary', 'icon' => 'copy', 'fullWidth' => true],
        ];
    }
}
