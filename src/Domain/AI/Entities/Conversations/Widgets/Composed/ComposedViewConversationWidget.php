<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\AnswerableConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Form\FormComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;

/**
 * The composable widget kit's one wire type: a component TREE the model composes from the
 * node types of `Widgets/Composed/Nodes/` — layout, text, data, media, inputs — with local `state`, `computed` values
 * over it, a closed list of `actions`, and data bound to tool results (`$from`), registered data sources (`$source`)
 * or written inline with a source. Wire form, one fence like every widget:
 *
 *     ```widget
 *     {"type":"ComposedView","title":"Visits","state":{"period":"7d"},"data":{"rows":[…]},"computed":{"total":"sum(rows, r => r.visits)"},
 *      "view":{"type":"Stack","children":[…]},"actions":{"reset":{"type":"resetState"}}}
 *     ```
 *
 * Restoring the class from a block READS AND CHECKS the tree ({@see ComposedViewTreeReading}): invalid nodes, props,
 * bindings and actions are dropped and kept as findings ({@see getValidationFindings()}); the rest renders. The
 * serializer ({@see getWidgetFields()}) writes back exactly what was kept, so parse → serialize → parse is stable.
 * Lives in `Widgets/Composed/` with its node classes (the kit is AI core).
 *
 * Answerable: a tree's `Form` node ({@see FormComposedViewNode}) posts a FRONTEND_WIDGET_CLICK signal
 * that names the widget type and the form — `ComposedView form "<name>" submitted:` with one `- <field>: <value>` line
 * per field (a line break inside a value written `\n`, a backslash `\\`), or `ComposedView form "<name>" declined.` The
 * form name is the identifying field ({@see isReactionToThisWidget()}); the contract is pinned by
 * `composed-view-widget-contract.json`.
 */
class ComposedViewConversationWidget extends ConversationWidget implements AnswerableConversationWidget
{
    public const string WIDGET_TYPE = 'ComposedView';

    /** @var bool Rendered as an image in the scheduled report mail ({@see ConversationWidget::EMAIL_RENDERABLE}). */
    public const bool EMAIL_RENDERABLE = true;

    public const string OWNER_CONTROL = 'inputs change the view only (tabs, sliders, selects); a form submits';

    public const string SUMMARY = 'The composable widget kit — a tree of layout, text, data, media and input components (KPI tiles, charts, tables, maps, tabs) with local state, computed values and bound data. The model composes it for any data shape no domain widget covers — a dashboard or overview of headline numbers, a comparison table, a chart over time, places on a map.';

    public const string WHEN_TO_USE = 'The answer has a data shape (a comparison, a development over time, shares, a few headline numbers, places) and no loaded skill offers a domain widget for it. Not for a single number, a yes or no, advice or small talk; never on the phone surface.';

    /** The kit is composed from what the model has — no skill brings it (`findWidgets` answers "usable directly"). */
    public const bool IS_WRITABLE_WITHOUT_A_SKILL = true;

    /** A tree awaits the owner when it holds a `Form` — the type itself awaits nothing. */
    public const bool AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT = true;

    public const string DIRECT_WIDGET_WHEN = 'the owner names a layout of their own for the answer (a form to fill in, a table with charts, tabs, places to browse under a map, a formula, a code sample) and asks to see it built — never an animation, a simulation or a free drawing (that is a CustomVisualization)';

    public const string DIRECT_WIDGET_SIGNAL = 'The owner named a layout: build it as ONE ComposedView.';

    /** @var string|null The tree's title (content the model writes, in the model's language). */
    public ?string $title = null;

    /** @var array<string, string|int|float|bool|null> State name → initial value. */
    public array $state = [];

    /** @var array<string, mixed> Dataset name → inline rows or one `$from` / `$source` binding. */
    public array $data = [];

    /** @var array<string, string> Computed name → expression source. */
    public array $computed = [];

    /** @var ComposedViewNode|null The root node of the view. */
    public ?ComposedViewNode $view = null;

    /** @var array<string, array<string, mixed>> Action name → action. */
    public array $actions = [];

    /** @var ComposedViewValidationFindings|null What reading the block dropped or flagged; empty for a widget built in code. */
    protected ?ComposedViewValidationFindings $validationFindings = null;

    public const string FORM_SIGNAL_PATTERN = '/^ComposedView form "([^"\r\n]{1,64})" (submitted:|declined\.)$/';

    /** @var string[] The `name`s of the `Form` nodes the kept tree carries ({@see FormComposedViewNode::getFormName()}). */
    protected array $formNames = [];

    public static function fromWidgetBlock(WidgetBlock $widgetBlock): static
    {
        $composedViewConversationWidget = parent::fromWidgetBlock($widgetBlock);
        if ($widgetBlock->outcome !== WidgetBlock::OUTCOME_VALID) {
            return $composedViewConversationWidget;
        }
        $widgetFields = $widgetBlock->getFieldsAsArray() ?? [];
        $sourceBytes = ComposedViewTreeReading::measureSourceBytes($widgetFields);
        $composedViewTreeReading = $sourceBytes > ComposedViewBudgets::MAX_SOURCE_BYTES
            ? ComposedViewTreeReading::forBlockOverTheSourceBudget($sourceBytes)
            : ComposedViewTreeReading::fromWidgetFields($widgetFields);
        $composedViewConversationWidget->applyTreeReading($composedViewTreeReading);
        return $composedViewConversationWidget;
    }

    /**
     * The `name` of every `Form` node the kept view carries, tree order, each once — a form the reader dropped (a
     * slot that does not take it, a missing `name`) and a name the signal cannot carry answer nothing, as the frontend
     * renders no submit for them.
     *
     * @return string[]
     */
    protected function findFormNamesInKeptView(): array
    {
        $formNames = [];
        foreach ($this->view?->getNodesOfSubtree() ?? [] as $composedViewNode) {
            $formName = $composedViewNode instanceof FormComposedViewNode ? $composedViewNode->getFormName() : null;
            if ($formName !== null && !in_array($formName, $formNames, true)) {
                $formNames[] = $formName;
            }
        }
        return $formNames;
    }

    /** The kept tree holds a `Form` that can submit (a named one) — the owner's answer is awaited. */
    public function awaitsOwnerInput(): bool
    {
        return $this->formNames !== [];
    }

    /** @return string[] the `name`s of the tree's `Form` nodes */
    public function getFormNames(): array
    {
        return $this->formNames;
    }

    /**
     * The form name and verb of a form signal of THIS tree (`submitted:` / `declined.`), or null for any other row.
     *
     * @param ConversationWidgetReaction $conversationWidgetReaction
     * @return ComposedViewFormSignalHead|null
     */
    protected function readFormSignalHead(ConversationWidgetReaction $conversationWidgetReaction): ?ComposedViewFormSignalHead
    {
        if (!$conversationWidgetReaction->isWidgetClickSignal()) {
            return null;
        }
        $firstLine = trim((string)((preg_split('/\R/', trim($conversationWidgetReaction->text)) ?: [])[0] ?? ''));
        if (preg_match(self::FORM_SIGNAL_PATTERN, $firstLine, $headMatch) !== 1 || !in_array($headMatch[1], $this->formNames, true)) {
            return null;
        }
        return ComposedViewFormSignalHead::forFormNameAndVerb($headMatch[1], $headMatch[2]);
    }

    /** A form signal that names one of this tree's forms. */
    public function isReactionToThisWidget(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return $this->readFormSignalHead($conversationWidgetReaction) !== null;
    }

    /** The submitted fields of a `submitted:` signal of one of this tree's forms; null for a decline or another row. */
    public function readAnswer(ConversationWidgetReaction $conversationWidgetReaction): ?ComposedViewFormSubmission
    {
        $formSignalHead = $this->readFormSignalHead($conversationWidgetReaction);
        if ($formSignalHead === null || !$formSignalHead->isSubmitted()) {
            return null;
        }
        $composedViewFormSubmission = new ComposedViewFormSubmission();
        $composedViewFormSubmission->reactionIndexInConversation = $conversationWidgetReaction->indexInConversation;
        $composedViewFormSubmission->formName = $formSignalHead->formName;
        foreach (array_slice(preg_split('/\R/', trim($conversationWidgetReaction->text)) ?: [], 1) as $fieldLine) {
            if (preg_match('/^- ([A-Za-z_][A-Za-z0-9_]{0,63}): ?(.*)$/', trim($fieldLine), $fieldMatch) === 1) {
                // One pass, longest match first: `\\` is a backslash, `\n` a line break the value carried.
                $composedViewFormSubmission->fieldValues[$fieldMatch[1]] = strtr($fieldMatch[2], ['\\\\' => '\\', '\\n' => "\n"]);
            }
        }
        return $composedViewFormSubmission;
    }

    /** A `declined.` signal of one of this tree's forms. */
    public function isDeclined(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return $this->readFormSignalHead($conversationWidgetReaction)?->isDeclined() === true;
    }

    /** Takes over what `$composedViewTreeReading` kept and its findings. */
    public function applyTreeReading(ComposedViewTreeReading $composedViewTreeReading): void
    {
        $this->title = $composedViewTreeReading->title;
        $this->state = $composedViewTreeReading->state;
        $this->data = $composedViewTreeReading->data;
        $this->computed = $composedViewTreeReading->computed;
        $this->actions = $composedViewTreeReading->actions;
        $this->view = $composedViewTreeReading->view;
        $this->validationFindings = $composedViewTreeReading->findings;
        $this->formNames = $this->findFormNamesInKeptView();
    }

    /** What reading the block dropped or flagged (finding codes); empty when nothing was or the widget was built in code. */
    public function getValidationFindings(): ComposedViewValidationFindings
    {
        return $this->validationFindings ??= new ComposedViewValidationFindings();
    }

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'title' => $this->title,
            'state' => $this->state !== [] ? $this->state : null,
            'data' => $this->data !== [] ? $this->data : null,
            'computed' => $this->computed !== [] ? $this->computed : null,
            'view' => $this->view?->toWireArray(),
            'actions' => $this->actions !== [] ? $this->actions : null,
        ]);
    }
}
