<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockSerializer;
use ReflectionClass;
use ReflectionProperty;

/**
 * The GENERATED part of the widgets section of `Agentic.md`: the spec of the
 * composable widget kit and the field contracts of the widgets the model writes without a skill. It fills the base
 * prompt's `{%COMPOSED_VIEW_KIT%}` placeholder per model call, and only where the kit is taught
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::buildBasePromptForThisCall()}).
 *
 * Every list comes from its one source: the node types, props (and their item shapes, read from the prop
 * descriptions), slots, size classes, binding kinds, action types, finding codes, embeddable widgets, data sources and
 * entity types from {@see ComposedViewNodeSchema::build()} (the schema the frontend and both tree readers read); the
 * directly written widgets from {@see ConversationWidgetClasses} (every class with
 * {@see ConversationWidget::IS_WRITABLE_WITHOUT_A_SKILL}) and their public fields. How a block is written is
 * {@see JSON_FENCE_SPEC} — the ```widget fence with the JSON tree, the one written and stored form.
 * Two frontend lists the backend has no class for are constants here, each held equal to the frontend file by
 * a test of the application. Deterministic text (sorted inputs, no per-request data) — the prompt-cache prefix holds;
 * built once per process.
 */
class ComposedViewKitSpec
{
    /** The base prompt placeholder the section fills (`{%COMPOSED_VIEW_KIT%}` in `Agentic.md`). */
    public const string PROMPT_PARAMETER_NAME = 'COMPOSED_VIEW_KIT';

    /** How the model writes a `ComposedView`: ONE ```widget fence with the JSON tree, its example bound with `$from`. */
    public const string JSON_FENCE_SPEC = <<<'SPEC'
        Write a ComposedView as ONE ```widget fence holding one JSON object, after a one-line intro sentence:

        ```widget
        {"type":"ComposedView","title":"Visits per channel","state":{"period":"7d"},
         "data":{"d7":{"$from":{"toolCall":"call_7","path":"$.rows[*]"}},"d30":{"$from":{"toolCall":"call_8","path":"$.rows[*]"}}},
         "computed":{"rows":"period == '7d' ? d7 : d30"},
         "view":{"type":"Stack","children":[
           {"type":"Tabs","options":[{"id":"7d","label":"7 days"},{"id":"30d","label":"30 days"}],"value":{"$state":"period"},"onChange":{"set":"period"}},
           {"type":"Table","source":{"name":"getVisits"},"children":[{"type":"Repeat","items":{"$computed":"rows"},"children":[{"type":"TableRow","children":[
             {"type":"TableCell","value":{"$item":"channel"}},
             {"type":"TableCell","children":[{"type":"Chart","kind":"line","data":{"$item":"days"},"x":"day","series":[{"field":"visits","label":"Visits"}]}]}]}]}]}]}}
        ```

        - The block's fields: `title`, `state` (name → initial value), `data` (name → rows, `$from` or `$source`),
          `computed` (name → expression), `actions` (name → action) and `view` (the root node).
        - A node is `{"type":"<NodeType>", …props}`; its default slot is `"children":[…]`, a named slot
          `"slots":{"header":[…]}`. `type`, `id`, `children` and `slots` are reserved keys.
        - Bindings are objects, never `{…}` text; a Tab or input changes only what reads its state.
        - No string may contain three backticks.
        SPEC;

    /** The group a node type is listed under: its first category in this order ({@see ComposedViewNodeCategory}). */
    public const array NODE_GROUP_LABELS_BY_CATEGORY = [
        ComposedViewNodeCategory::LAYOUT => 'Layout',
        ComposedViewNodeCategory::BLOCK => 'Text',
        ComposedViewNodeCategory::INLINE => 'Inline',
        ComposedViewNodeCategory::DATA => 'Data',
        ComposedViewNodeCategory::MEDIA => 'Media',
        ComposedViewNodeCategory::INPUT => 'Inputs',
        ComposedViewNodeCategory::HEAVY => 'Heavy',
    ];

    /** The group of a node type without a category — it sits only in its parent's slot (TableRow, ListItem). */
    public const string PARTS_GROUP_LABEL = 'Parts (only inside their parent)';

    /**
     * The optional props a node line names besides the required ones — the props the usage rules rely on. Every node
     * line also names its text prop, its `source` prop and its event props, and an input its `value` (generic rules).
     */
    public const array KEY_OPTIONAL_PROPS_BY_NODE_TYPE = [
        'KpiTile' => ['delta', 'deltaLabel'],
        'Chart' => ['deltaField', 'deltaLabel'],
        'Table' => ['columns', 'rows'],
        'List' => ['items'],
        'Stack' => ['direction'],
        'Grid' => ['columns'],
        'Carousel' => ['items', 'visibleItems'],
        'Map' => ['pointAction', 'carousel'],
        'EntityLink' => ['action'],
        'Form' => ['submitLabel'],
        'Input' => ['label'],
        'Select' => ['label'],
        'DatePicker' => ['label'],
        'Textarea' => ['label'],
        'RadioGroup' => ['label'],
    ];

    /**
     * The word that marks an embeddable domain widget wherever the model meets one — `embeds: regular` on its
     * `findWidgets` hit and in the block note of the tool that dictates it; the kit names the word, never the widgets.
     */
    public const string EMBEDS_MARKER = 'embeds';

    /** The prop that carries a node's source line (researched values name their source here). */
    public const string SOURCE_PROP_NAME = 'source';

    /** A REQUIRED prop's enum is spelled out when it has at most this many values. */
    public const int MAX_SPELLED_OUT_ENUM_VALUES = 9;

    /** A listed OPTIONAL prop's enum is spelled out when it has at most this many values (`carousel: auto|hidden`). */
    public const int MAX_SPELLED_OUT_OPTIONAL_ENUM_VALUES = 3;

    /**
     * The render-report codes the FRONTEND adds to the schema's finding codes (`WidgetRenderReportCode` in
     * `conversation-markdown/render-report/widget-render-report.ts`).
     */
    public const array FRONTEND_RENDER_REPORT_CODES = ['UNRESOLVED_BINDING', 'UNRESOLVED_REF', 'HYDRATION_FAILED', 'RENDER_FAILED', 'NO_DATA'];

    /**
     * The functions of the `computed` expression language — its one parser is the frontend's
     * (`EXPRESSION_FUNCTION_NAMES` in `conversation-markdown/widget-tree/expression/parse-expression.ts`).
     */
    public const array FRONTEND_EXPRESSION_FUNCTION_NAMES = [
        'round', 'floor', 'ceil', 'abs', 'min', 'max', 'sum', 'avg', 'count', 'len', 'filter', 'map', 'sortBy', 'first',
        'last', 'concat', 'join', 'string', 'number', 'fixed', 'percent',
    ];

    /** The widget class the kit itself is — taught as the kit, not among the directly written widgets. */
    public const string KIT_WIDGET_CLASS = ComposedViewConversationWidget::class;

    /** Characters of a field's description in a direct widget's field line at most (a longer one is left out). */
    public const int MAX_FIELD_DESCRIPTION_LENGTH = 120;

    /** Characters of a direct widget's summary at most — its first sentence, or no summary at all. */
    public const int MAX_WIDGET_SUMMARY_LENGTH = 160;

    /** The line that introduces {@see INLINE_VALUES_EXAMPLE_FIELDS} — the kit's second example, beside the `$from` one of {@see JSON_FENCE_SPEC}. */
    public const string INLINE_VALUES_EXAMPLE_INTRO = '**Inline values** (researched; from a result without a `$from` line, `source` is `{"name":"<tool>"}`), numbers as JSON numbers:';

    /**
     * The kit's second example (data paths (3) and (4)): a headline number as a KPI tile and a series as a line Chart,
     * every value written inline with the node's `source`. It reads with no finding (checked by a test of the
     * application).
     */
    public const array INLINE_VALUES_EXAMPLE_FIELDS = [
        'view' => ['type' => 'Stack', 'children' => [
            ['type' => 'Grid', 'columns' => 2, 'children' => [
                ['type' => 'KpiTile', 'label' => 'Visitors 2025', 'value' => 1840, 'delta' => 12, 'deltaLabel' => 'vs. 2024', 'source' => ['name' => '<site>', 'url' => '<page>']],
            ]],
            ['type' => 'Chart', 'kind' => 'line', 'x' => 'year', 'series' => [['field' => 'visitors', 'label' => 'Visitors']], 'data' => [['year' => '2024', 'visitors' => 1640], ['year' => '2025', 'visitors' => 1840]], 'source' => ['name' => '<site>', 'url' => '<page>']],
        ]],
    ];

    /** @var array<string, string> The built sections by surface (`screen`, `emailReport`), each once per process. */
    protected static array $builtSectionsBySurface = [];

    /**
     * The section the model is taught, built once per process and surface. `$forEmailReport`: the variant of a run whose
     * report goes out by e-mail — the widgets render as static images there, so it teaches no input, names the mail's
     * limits and lists only the widgets that render in a mail ({@see ConversationWidget::EMAIL_RENDERABLE}).
     */
    public static function build(bool $forEmailReport = false): string
    {
        $surfaceKey = $forEmailReport ? 'emailReport' : 'screen';
        return static::$builtSectionsBySurface[$surfaceKey] ??= static::buildFromSchema(
            ComposedViewNodeSchema::build(),
            static::findDirectlyWrittenWidgetClasses(),
            $forEmailReport ? ConversationWidgetClasses::getEmailRenderableWidgetTypes() : null
        );
    }

    /** Forgets the built sections (a test that swaps the node, source or widget registries). */
    public static function forgetBuiltSections(): void
    {
        static::$builtSectionsBySurface = [];
    }

    /**
     * The key-figure rule of an e-mail report, the same words in the kit's e-mail line and in the result the report is
     * written after (`send_owner_report`): the figures the task asks for open the report as ONE `ComposedView` of
     * KpiTiles with their change — a figure written only in prose arrives in the mail as a sentence (scheduled runs
     * four runs of a weekly report put every count and position into prose although the task asked for the
     * key figures and their change).
     */
    public const string EMAIL_REPORT_KEY_FIGURES_RULE = 'When the task asks for figures (counts, averages, positions, totals), the report '
        . 'opens with ONE `ComposedView`: a `Grid` of `KpiTile`s, one per figure, with `delta` and `deltaLabel` (the change '
        . 'since the last report) and `source`; the other widgets and the interpretation follow.';

    /** The most widget blocks a report mail renders as images; a block beyond them is a text line. */
    public const int EMAIL_REPORT_MAX_WIDGET_IMAGES = 6;

    /**
     * The section from its inputs (pure).
     *
     * @param array<string, mixed> $schema {@see ComposedViewNodeSchema::build()}
     * @param array<string, class-string<ConversationWidget>> $directlyWrittenWidgetClassesByType
     * @param string[]|null $emailRenderableWidgetTypes the e-mail variant's widgets (null = the screen variant)
     */
    public static function buildFromSchema(array $schema, array $directlyWrittenWidgetClassesByType, ?array $emailRenderableWidgetTypes = null): string
    {
        $nodeTypes = self::readSchemaList($schema, 'nodeTypes');
        if ($emailRenderableWidgetTypes !== null) {
            $directlyWrittenWidgetClassesByType = array_intersect_key($directlyWrittenWidgetClassesByType, array_flip($emailRenderableWidgetTypes));
        }
        $parts = [
            '### The widget kit — `ComposedView`',
            'ONE widget; its `view` is a tree of the nodes below. **Data path, the first that fits:** (1) a domain widget (of a loaded skill, or found by `findWidgets`) → its own block; ' . static::buildEmbeddableWidgetClause(self::readSchemaList($schema, 'embeddableWidgets'))
            . ' (2) a registered source → `$source`' . static::buildDataSourceClause(self::readSchemaList($schema, 'dataSources'))
            . '; (3) a tool result of THIS conversation → `$from` where the result lists a path, never retyped; a value of a '
            . 'result without a `$from` line → copied exactly, with what the tool read as the node\'s `source` `{"name"}`; '
            . '(4) researched or derived values → plain values in props and `data` rows, the source in the node\'s `source` '
            . '`{"name","url"}` (' . implode(', ', static::findNodeTypesWithProp($nodeTypes, self::SOURCE_PROP_NAME)) . ') or a '
            . '`SourceRef` beside it; a derived value names its inputs in `caption` / `detail`.',
            static::buildDataSourceLines(self::readSchemaList($schema, 'dataSources')),
            '**`$from`** `{"toolCall":"<id>","path":"$.rows[*]"}` addresses the STORED result object, never its markdown: a '
            . 'bindable result ends with a `[ComposedView $from paths …]` line — copy a path from it (`[*]` = every row, '
            . '≤' . ComposedViewBudgets::MAX_REPEAT_ROWS . '). A row the result lists as an entity carries `entityRef`.',
            static::buildEntityReferenceLine(self::readSchemaList($schema, 'entityTypes')),
            static::buildBindingLine(self::readSchemaList($schema, 'bindingKinds'), self::readSchemaList($schema, 'actionTypes'), self::readSchemaList($schema, 'dataSources') !== []),
            '**Nodes** — `*` required; `[…]` child nodes (any but heavy), `[slot: accepts]` slots (`≤n` at most n; '
            . '`compact`: a chart is a sparkline, an image a thumbnail, never a heavy node).',
            static::buildNodeTypeLines($nodeTypes),
            $emailRenderableWidgetTypes !== null
                ? static::buildEmailReportLine($emailRenderableWidgetTypes)
                : '**A `Form` hands the turn to the owner** — end your turn; each input has a `label`; its submit returns as '
                    . '`ComposedView form "<name>" submitted:` + `- field: value` lines. A closed question is `askOwner`.',
            static::buildRenderReportLine(self::readSchemaList($schema, 'findingCodes')),
            self::JSON_FENCE_SPEC,
            static::buildInlineValuesExample(),
            static::buildDirectlyWrittenWidgetLines($directlyWrittenWidgetClassesByType),
        ];
        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    /**
     * The e-mail variant's rule line: the report is mailed with its widgets as static
     * images — nobody clicks, so no `Form`, input or action; the mail's image limit; and the widgets that render there,
     * generated from the classes. A block of any other widget becomes a text line in the mail.
     *
     * @param string[] $emailRenderableWidgetTypes
     */
    protected static function buildEmailReportLine(array $emailRenderableWidgetTypes): string
    {
        return '**This report goes out by e-mail** — every widget block becomes a static image: no `Form`, no input, no '
            . 'action (nobody clicks). At most ' . self::EMAIL_REPORT_MAX_WIDGET_IMAGES . ' widget blocks, the most '
            . 'important first; a block beyond them, or of a widget not listed here, arrives as a plain text line. '
            . 'Widgets that render in the e-mail: ' . implode(', ', array_map(static fn (string $widgetType): string => '`' . $widgetType . '`', $emailRenderableWidgetTypes)) . '. '
            . self::EMAIL_REPORT_KEY_FIGURES_RULE;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<mixed> the schema entry, or [] when it is no list or map (an empty map exports as `{}`)
     */
    protected static function readSchemaList(array $schema, string $key): array
    {
        return is_array($schema[$key] ?? null) ? $schema[$key] : [];
    }

    /**
     * The embedding rule, generic: the embeddable widgets are NOT listed here — the always-on kit
     * would grow with every flipped widget. Which widget embeds is said where the model meets it: the block note of the
     * tool that dictates it ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::buildDictatedBlockLayoutException()})
     * and its `findWidgets` hit ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool::answerCall()}),
     * both as `embeds: <size class>` ({@see EMBEDS_MARKER}).
     *
     * @param array<mixed> $embeddableWidgets the schema's `embeddableWidgets` (wire type → size class)
     */
    protected static function buildEmbeddableWidgetClause(array $embeddableWidgets): string
    {
        if ($embeddableWidgets === []) {
            return 'no domain widget sits inside a tree.';
        }
        return 'one marked `' . self::EMBEDS_MARKER . '` (its block note or hit) also fits in a tree: `{"type":"Widget","widget":"<name>","fields":{…}}`.';
    }

    /**
     * The node types that declare the prop `$propName` of the type `$propType`, in schema order (Diagram's string
     * `source` is no source line).
     *
     * @param array<mixed> $nodeTypes
     * @return string[]
     */
    protected static function findNodeTypesWithProp(array $nodeTypes, string $propName, string $propType = ComposedViewNodeProp::TYPE_OBJECT): array
    {
        $nodeTypesWithProp = [];
        foreach ($nodeTypes as $nodeType => $nodeSchema) {
            foreach (is_array($nodeSchema['props'] ?? null) ? $nodeSchema['props'] : [] as $prop) {
                if (is_array($prop) && ($prop['name'] ?? '') === $propName && in_array($propType, is_array($prop['types'] ?? null) ? $prop['types'] : [], true)) {
                    $nodeTypesWithProp[] = (string)$nodeType;
                    break;
                }
            }
        }
        return $nodeTypesWithProp;
    }

    /** @param array<mixed> $dataSources the schema's `dataSources` */
    protected static function buildDataSourceClause(array $dataSources): string
    {
        return $dataSources === [] ? ' (none is registered yet)' : ' (listed below)';
    }

    /** @param array<mixed> $dataSources the schema's `dataSources` — '' while none is registered */
    protected static function buildDataSourceLines(array $dataSources): string
    {
        if ($dataSources === []) {
            return '';
        }
        $sourceLines = ['**`$source` sources** (name — rows; params; columns a Table / Chart binds):'];
        foreach ($dataSources as $dataSourceName => $dataSource) {
            $params = [];
            foreach (is_array($dataSource['params'] ?? null) ? $dataSource['params'] : [] as $param) {
                $params[] = $param['name'] . (($param['isRequired'] ?? false) ? '*' : '');
            }
            $columns = [];
            foreach (is_array($dataSource['columns'] ?? null) ? $dataSource['columns'] : [] as $column) {
                $columns[] = (string)$column['name'];
            }
            $sourceLines[] = '- `' . $dataSourceName . '` — ' . ($dataSource['summary'] ?? '') . '; params: ' . ($params === [] ? '—' : implode(', ', $params))
                . '; columns: ' . ($columns === [] ? '—' : implode(', ', $columns));
        }
        return implode("\n", $sourceLines);
    }

    /** @param array<mixed> $entityTypes */
    protected static function buildEntityReferenceLine(array $entityTypes): string
    {
        return '**Entity references:** a result listing entities ends with `[entity references … "call_7:competitor:0" …]` '
            . '— COPY one into an `openEntity` `ref`, never build one; over a `$from` list the row\'s own: '
            . '`{"$item":"entityRef"}` (a Map: its `pointAction`). Types: ' . ($entityTypes === [] ? '—' : implode(', ', $entityTypes)) . '.';
    }

    /**
     * The binding forms, the expression functions, the events and the actions. The `$source` form is named only while a
     * data source is registered (`$isDataSourceRegistered`).
     *
     * @param array<mixed> $bindingKinds the schema's `bindingKinds`
     * @param array<mixed> $actionTypes the schema's `actionTypes`
     */
    protected static function buildBindingLine(array $bindingKinds, array $actionTypes, bool $isDataSourceRegistered): string
    {
        $bindingFormsByKind = [
            ComposedViewBinding::KIND_STATE => '`$state`',
            ComposedViewBinding::KIND_COMPUTED => '`$computed`',
            ComposedViewBinding::KIND_DATA => '`$data` (a `data` set)',
            ComposedViewBinding::KIND_ITEM => '`$item` (a field of the row in a Repeat / Carousel, `a.b` nested)',
            ComposedViewBinding::KIND_FROM => '`$from`',
            ComposedViewBinding::KIND_SOURCE => '`$source` `{"name":…,"params":{…}}`',
        ];
        $actionFormsByType = [
            ComposedViewTreeReading::ACTION_COPY => '`copy` (`value`)',
            ComposedViewTreeReading::ACTION_RESET_STATE => '`resetState`',
            ComposedViewTreeReading::ACTION_OPEN_ENTITY => '`openEntity` (`ref`)',
            ComposedViewTreeReading::ACTION_OPEN_URL => '`openUrl` (`url`, http(s))',
        ];
        $bindingForms = [];
        foreach ($bindingKinds as $bindingKind) {
            if ($bindingKind === ComposedViewBinding::KIND_SOURCE && !$isDataSourceRegistered) {
                continue;
            }
            $bindingForms[] = $bindingFormsByKind[$bindingKind] ?? '`{"$' . $bindingKind . '":…}`';
        }
        $actionForms = [];
        foreach ($actionTypes as $actionType) {
            $actionForms[] = $actionFormsByType[$actionType] ?? '`' . $actionType . '`';
        }
        return '**Bindings** (`{"$kind":…}` as a prop value): ' . implode(', ', $bindingForms) . '. `computed`: '
            . 'arithmetic, comparisons, `?:`, `r => r.x`, ' . implode(' ', self::FRONTEND_EXPRESSION_FUNCTION_NAMES)
            . '. Events: `{"set":"<state>"}` or `{"action":"<name>"}`; `actions`: ' . implode(', ', $actionForms) . '. Inputs change local state only.';
    }

    /** @param array<mixed> $nodeTypes the schema's `nodeTypes` */
    protected static function buildNodeTypeLines(array $nodeTypes): string
    {
        $nodeDescriptionsByGroup = [];
        foreach ($nodeTypes as $nodeType => $nodeSchema) {
            $categories = is_array($nodeSchema['categories'] ?? null) ? $nodeSchema['categories'] : [];
            $groupLabel = self::PARTS_GROUP_LABEL;
            foreach (self::NODE_GROUP_LABELS_BY_CATEGORY as $category => $categoryGroupLabel) {
                if (in_array($category, $categories, true)) {
                    $groupLabel = $categoryGroupLabel;
                    break;
                }
            }
            $nodeDescriptionsByGroup[$groupLabel][] = static::describeNodeType((string)$nodeType, $nodeSchema);
        }
        $groupLines = [];
        foreach ([...array_values(self::NODE_GROUP_LABELS_BY_CATEGORY), self::PARTS_GROUP_LABEL] as $groupLabel) {
            if (isset($nodeDescriptionsByGroup[$groupLabel])) {
                $groupLines[] = '- ' . $groupLabel . ': ' . implode(' · ', $nodeDescriptionsByGroup[$groupLabel]);
            }
        }
        return implode("\n", $groupLines);
    }

    /**
     * One node type: its listed props (required, the key ones, the text, source, event and input-value props) and its
     * slots — `Chart(kind*: line|bar…, data*, x*, series*: [{field,label,visible?}], source)`.
     *
     * @param array<string, mixed> $nodeSchema
     */
    protected static function describeNodeType(string $nodeType, array $nodeSchema): string
    {
        $categories = is_array($nodeSchema['categories'] ?? null) ? $nodeSchema['categories'] : [];
        $keyOptionalPropNames = self::KEY_OPTIONAL_PROPS_BY_NODE_TYPE[$nodeType] ?? [];
        $propDescriptions = [];
        foreach (is_array($nodeSchema['props'] ?? null) ? $nodeSchema['props'] : [] as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $propName = (string)($prop['name'] ?? '');
            if ($propName === 'tone') {
                continue;
            }
            $isRequired = (bool)($prop['isRequired'] ?? false);
            $isListed = $isRequired
                || in_array($propName, $keyOptionalPropNames, true)
                || $propName === ($nodeSchema['textContentProp'] ?? null)
                || $propName === self::SOURCE_PROP_NAME
                || in_array(ComposedViewNodeProp::TYPE_EVENT, is_array($prop['types'] ?? null) ? $prop['types'] : [], true)
                || ($propName === 'value' && in_array(ComposedViewNodeCategory::INPUT, $categories, true));
            if ($isListed) {
                // the item shape only where the rules rely on it: a required or a key prop of a non-input node (an input's
                // options may be plain labels; the `source` shape is said once)
                $namesItemShape = ($isRequired || in_array($propName, $keyOptionalPropNames, true)) && !in_array(ComposedViewNodeCategory::INPUT, $categories, true);
                $propDescriptions[] = static::describeProp($prop, $isRequired, $namesItemShape);
            }
        }
        $slotDescriptions = [];
        foreach (is_array($nodeSchema['slots'] ?? null) ? $nodeSchema['slots'] : [] as $slot) {
            $slotDescriptions[] = static::describeSlot($slot);
        }
        return $nodeType
            . ($propDescriptions !== [] ? '(' . implode(', ', $propDescriptions) . ')' : '')
            . ($slotDescriptions !== [] ? '[' . implode('; ', $slotDescriptions) . ']' : '');
    }

    /**
     * One prop: its name, `*` when required, its enum's values (a required one up to {@see MAX_SPELLED_OUT_ENUM_VALUES}, an
     * optional one up to {@see MAX_SPELLED_OUT_OPTIONAL_ENUM_VALUES}), and the item shape its description gives
     * (`[{"field","label"}]` → `[{field,label}]`) for a list or object prop.
     *
     * @param array<string, mixed> $prop
     */
    protected static function describeProp(array $prop, bool $isRequired, bool $namesItemShape): string
    {
        $allowedValues = is_array($prop['allowedValues'] ?? null) ? $prop['allowedValues'] : [];
        $propText = $prop['name'] . ($isRequired ? '*' : '');
        $maxSpelledOutEnumValues = $isRequired ? self::MAX_SPELLED_OUT_ENUM_VALUES : self::MAX_SPELLED_OUT_OPTIONAL_ENUM_VALUES;
        if ($allowedValues !== [] && count($allowedValues) <= $maxSpelledOutEnumValues) {
            $allowedValueTexts = [];
            foreach ($allowedValues as $allowedValue) {
                $allowedValueTexts[] = is_bool($allowedValue) ? ($allowedValue ? 'true' : 'false') : (string)$allowedValue;
            }
            return $propText . ': ' . implode('|', $allowedValueTexts);
        }
        $itemShape = $namesItemShape ? static::readItemShapeOfProp($prop) : null;
        return $itemShape !== null ? $propText . ': ' . $itemShape : $propText;
    }

    /**
     * The item shape a list / object prop's description writes (`[{"key","label","align"?}]`, `{"label","lat","lng"}`),
     * without the quotes; null for a scalar prop or a description without one. The map point's optional keys are named
     * after the shape in the description — the shape stands for the whole contract, `…` marks the optional rest.
     *
     * @param array<string, mixed> $prop
     */
    protected static function readItemShapeOfProp(array $prop): ?string
    {
        $types = is_array($prop['types'] ?? null) ? $prop['types'] : [];
        if (array_intersect($types, [ComposedViewNodeProp::TYPE_ARRAY, ComposedViewNodeProp::TYPE_OBJECT]) === []) {
            return null;
        }
        // a list of quoted keys (`"key"` / `"key"?`), not nested in another object (`"source"?:{"name","url"}` is no item shape)
        if (preg_match('/(?<!:)\[?\{"[A-Za-z]+"\??(?:\s*,\s*"[A-Za-z]+"\??)*\}\]?/', (string)($prop['description'] ?? ''), $shapeMatch) !== 1) {
            return null;
        }
        // the always-present keys; `…` stands for the optional ones (`"align"?`, or named "optional" in the description)
        $itemShape = (string)preg_replace('/\s+/', '', str_replace('"', '', $shapeMatch[0]));
        $hasOptionalKeys = str_contains($itemShape, '?') || str_contains((string)($prop['description'] ?? ''), 'optional');
        $itemShape = (string)preg_replace('/,[A-Za-z]+\?/', '', $itemShape);
        return $hasOptionalKeys ? (string)preg_replace('/\}(\]?)$/', ',…}$1', $itemShape) : $itemShape;
    }

    /**
     * One slot: `header ≤3 compact: inline, input`.
     *
     * @param array<string, mixed> $slot
     */
    protected static function describeSlot(array $slot): string
    {
        $acceptsCategories = is_array($slot['acceptsCategories'] ?? null) ? $slot['acceptsCategories'] : [];
        $acceptedNames = [];
        if ($acceptsCategories !== []) {
            $acceptedNames[] = static::describeAcceptedCategories($acceptsCategories);
        }
        foreach (is_array($slot['acceptsNodeTypes'] ?? null) ? $slot['acceptsNodeTypes'] : [] as $acceptedNodeType) {
            $acceptedNames[] = (string)$acceptedNodeType;
        }
        $limits = [];
        if (($slot['minChildren'] ?? 0) > 0) {
            $limits[] = '≥' . $slot['minChildren'];
        }
        if (is_int($slot['maxChildren'] ?? null)) {
            $limits[] = '≤' . $slot['maxChildren'];
        }
        if (($slot['sizeClass'] ?? ComposedViewNodeSlot::SIZE_CLASS_REGULAR) !== ComposedViewNodeSlot::SIZE_CLASS_REGULAR) {
            $limits[] = (string)$slot['sizeClass'];
        }
        // the usual default slot — every node but the heavy ones, no limit: `[…]`; every node: `[…, heavy]`
        if ($slot['name'] === ComposedViewNodeSlot::DEFAULT_SLOT_NAME && $limits === [] && in_array($acceptedNames, [['any but heavy'], ['any']], true)) {
            return $acceptedNames === ['any'] ? '…, heavy' : '…';
        }
        return trim($slot['name'] . ' ' . implode(' ', $limits)) . ': ' . implode(', ', $acceptedNames);
    }

    /**
     * The categories a slot accepts, short: `any`, `any but heavy`, `no layout` (every non-heavy category but the named
     * ones, when at most two are missing), else the list.
     *
     * @param array<mixed> $acceptsCategories
     */
    protected static function describeAcceptedCategories(array $acceptsCategories): string
    {
        if (array_diff(ComposedViewNodeCategory::ALL, $acceptsCategories) === []) {
            return 'any';
        }
        $missingCategories = array_values(array_diff(ComposedViewNodeCategory::ALL_BUT_HEAVY, $acceptsCategories));
        if ($missingCategories === []) {
            return 'any but heavy';
        }
        if (count($missingCategories) <= 2 && !in_array(ComposedViewNodeCategory::HEAVY, $acceptsCategories, true)) {
            return 'no ' . implode('/', $missingCategories);
        }
        return implode(', ', $acceptsCategories);
    }

    /**
     * The render-report line: the codes grouped by their first word when several share it (`INVALID_*`).
     *
     * @param array<mixed> $findingCodes the schema's `findingCodes`
     */
    protected static function buildRenderReportLine(array $findingCodes): string
    {
        return '**Render reports:** `[SIGNAL:FRONTEND_WIDGET_RENDER_REPORT]` names each block that did not mount cleanly: '
            . '`empty`, `pending`, `failed <CODE>` or `mounted <CODE>` (' . implode(', ', static::groupCodesByFirstWord([...$findingCodes, ...self::FRONTEND_RENDER_REPORT_CODES]))
            . ') with the node path — say so (Always 8) and fix the cause in your next tree.';
    }

    /**
     * Codes that share their first word become one `<WORD>_*`; the others stay, in order.
     *
     * @param array<mixed> $codes
     * @return string[]
     */
    public static function groupCodesByFirstWord(array $codes): array
    {
        $codeCountsByFirstWord = [];
        foreach ($codes as $code) {
            $firstWord = explode('_', (string)$code)[0];
            $codeCountsByFirstWord[$firstWord] = ($codeCountsByFirstWord[$firstWord] ?? 0) + 1;
        }
        $groupedCodes = [];
        foreach ($codes as $code) {
            $firstWord = explode('_', (string)$code)[0];
            $groupedCode = $codeCountsByFirstWord[$firstWord] > 1 ? $firstWord . '_*' : (string)$code;
            $groupedCodes[$groupedCode] = $groupedCode;
        }
        return array_values($groupedCodes);
    }

    /** The second example: its intro line and the JSON fence through the one writer. */
    public static function buildInlineValuesExample(): string
    {
        return self::INLINE_VALUES_EXAMPLE_INTRO . "\n\n"
            . WidgetBlockSerializer::buildFenceBlock(ComposedViewConversationWidget::WIDGET_TYPE, null, self::INLINE_VALUES_EXAMPLE_FIELDS);
    }

    /** @param array<string, class-string<ConversationWidget>> $directlyWrittenWidgetClassesByType */
    protected static function buildDirectlyWrittenWidgetLines(array $directlyWrittenWidgetClassesByType): string
    {
        if ($directlyWrittenWidgetClassesByType === []) {
            return '';
        }
        $lines = [
            '### Widgets you write directly',
            'No skill and no tool brings their data: in this response, one short line, then the block (as below, plus the '
            . WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName() . ' where listed). `*` = always write it, `?` = optional; `findWidgets` with the name gives the full '
            . '"when to use".',
        ];
        foreach ($directlyWrittenWidgetClassesByType as $widgetType => $widgetClass) {
            $summary = static::readFirstSentence($widgetClass::SUMMARY, self::MAX_WIDGET_SUMMARY_LENGTH);
            $lines[] = '- `' . $widgetType . '` — ' . ($summary !== '' ? $summary . ' ' : '') . 'Fields: ' . static::describeWidgetFields($widgetClass) . '.';
            $exampleAnswer = $widgetClass::buildExampleAnswer();
            if ($exampleAnswer !== '') {
                $lines[] = $exampleAnswer;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * The block fields of a widget class: the self-hydration key when the widget reads it
     * ({@see ConversationWidget::READS_WIDGET_SELF_HYDRATION_KEY}),
     * then every public property it declares beyond the base class, with `*` for a non-nullable one and its `@var`
     * sentence.
     *
     * @param class-string<ConversationWidget> $widgetClass
     */
    protected static function describeWidgetFields(string $widgetClass): string
    {
        $fieldDescriptions = $widgetClass::READS_WIDGET_SELF_HYDRATION_KEY
            ? ['`' . WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName() . '`* (the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ')']
            : [];
        foreach ((new ReflectionClass($widgetClass))->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->isStatic() || $reflectionProperty->getDeclaringClass()->getName() === ConversationWidget::class
                || !$reflectionProperty->getDeclaringClass()->isSubclassOf(ConversationWidget::class)
            ) {
                continue;
            }
            $propertyType = $reflectionProperty->getType();
            $isOptional = $propertyType === null || $propertyType->allowsNull();
            $fieldDescription = static::readPropertyDescription($reflectionProperty);
            $fieldDescriptions[] = '`' . $reflectionProperty->getName() . '`' . ($isOptional ? '?' : '*')
                . ($fieldDescription !== '' ? ' (' . $fieldDescription . ')' : '');
        }
        return $fieldDescriptions === [] ? '—' : implode(', ', $fieldDescriptions);
    }

    /** The property's `@var` description: the text after the type, its first sentence when it fits, else none. */
    protected static function readPropertyDescription(ReflectionProperty $reflectionProperty): string
    {
        $docComment = (string)$reflectionProperty->getDocComment();
        if (preg_match('/@var\s+\S+\s+(.+?)\s*(?:\*\/|\n\s*\*\s*@)/s', $docComment, $matches) !== 1) {
            return '';
        }
        $description = (string)preg_replace('/\s*\n\s*\*\s*/', ' ', $matches[1]);
        // a code reference is for the reader of the class, not the model: `(… {@see X})` and `{@see X}; ` go
        $description = (string)preg_replace(['/\s*\(\{@see [^}]+\}\)/', '/\{@see [^}]+\}[;,]?\s*/', '/\s*\(\s*\)/'], '', $description);
        $description = trim((string)preg_replace('/\s+/', ' ', $description));
        return rtrim(static::readFirstSentence($description, self::MAX_FIELD_DESCRIPTION_LENGTH), ' .');
    }

    /** The text up to its first sentence end (`. `), or '' when that sentence is longer than `$maxLength` — never cut mid-sentence. */
    protected static function readFirstSentence(string $text, int $maxLength): string
    {
        $sentenceEnd = strpos($text, '. ');
        $firstSentence = $sentenceEnd !== false ? substr($text, 0, $sentenceEnd + 1) : $text;
        return mb_strlen($firstSentence) <= $maxLength ? $firstSentence : '';
    }

    /**
     * The widgets the model writes without a skill ({@see ConversationWidget::IS_WRITABLE_WITHOUT_A_SKILL}), the kit
     * itself left out, by wire type.
     *
     * @return array<string, class-string<ConversationWidget>>
     */
    public static function findDirectlyWrittenWidgetClasses(): array
    {
        $directlyWrittenWidgetClassesByType = [];
        foreach (ConversationWidgetClasses::getWidgetClassesByWidgetType() as $widgetType => $widgetClass) {
            if ($widgetClass::IS_WRITABLE_WITHOUT_A_SKILL && $widgetClass !== self::KIT_WIDGET_CLASS) {
                $directlyWrittenWidgetClassesByType[(string)$widgetType] = $widgetClass;
            }
        }
        ksort($directlyWrittenWidgetClassesByType);
        return $directlyWrittenWidgetClassesByType;
    }
}
