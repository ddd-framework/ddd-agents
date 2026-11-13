# The composed-view kit: nodes

**Reader:** host developer and package maintainer

Contents: what the kit is · the block · the reader is the validator · findings · budgets · a node class · categories, slots and size classes · sourcing rules · tones · embedding a domain widget · forms · the schema · what the model is taught · adding a node type

## What the kit is

`ComposedView` is one wire type whose payload is a component tree the model composes from node types — layout, text,
data, media, inputs — with local state, computed values over it, a closed list of actions, and data that is bound
rather than copied. It covers every data shape no domain widget covers: a comparison table, a chart over time, a few
headline numbers, places on a map, a form. The class is `ComposedViewConversationWidget`
(`IS_WRITABLE_WITHOUT_A_SKILL`, `EMAIL_RENDERABLE`, `EMBEDDABLE` false). Bindings, data sources and entity references
are the next reference.

## The block

| Field | Holds |
|---|---|
| `title` | the tree's title, in the owner's language |
| `state` | state name → initial scalar value; inputs write it, other nodes read it with `{"$state":…}` |
| `data` | dataset name → inline rows, or ONE `$from` or `$source` binding |
| `computed` | name → expression in the frontend's closed expression language (`ComposedViewKitSpec::FRONTEND_EXPRESSION_FUNCTION_NAMES`) |
| `view` | the root node |
| `actions` | name → action; the types are `ComposedViewTreeReading::ACTION_TYPES` (`copy`, `resetState`, `openEntity` with a `ref`, `openUrl` with an http(s) url) — nothing starts a chat turn |

A node is `{"type":"<NodeType>", …props}`; its default slot is `children`, named slots sit under `slots`. The keys
`type`, `id`, `children` and `slots` are reserved (`ComposedViewNode::RESERVED_WIRE_KEYS`); every other key is a prop.
An event prop takes `{"set":"<state name>"}` or `{"action":"<action name>"}`. No string anywhere may contain three
backticks: it would end the fence.

## The reader is the validator

`ComposedViewConversationWidget::fromWidgetBlock()` reads the tree through `ComposedViewTreeReading::fromWidgetFields()`
(over `ComposedViewBudgets::MAX_SOURCE_BYTES` of canonical JSON it reads no view at all). What does not fit is dropped
where it stands and reported; the rest renders. Nothing is refused: the reader keeps data valid, it is no gate.
`getWidgetFields()` writes back exactly what was kept, so read → write → read is stable.
`ComposedViewConversationWidget::getValidationFindings()` returns what was dropped or flagged.

## Findings

Codes on `ComposedViewValidationFinding`; the ones in `ComposedViewValidationFinding::NOTE_CODES` keep the node and only
flag it.

| Code | Means |
|---|---|
| `INVALID_NODE` | unknown type, missing required prop, a slot that does not accept it, a root that may not be one |
| `INVALID_PROP` | an undeclared prop, a literal of the wrong type, a value outside its enum |
| `UNKNOWN_BINDING` | a binding to an undeclared name, `$item` outside an item scope, an unknown data source or param |
| `INVALID_STATE`, `INVALID_ACTION`, `INVALID_EXPRESSION` | a bad state name or value, action, or expression (the backend flags a malformed or misnamed expression; the frontend evaluator reports one that does not compile or run) |
| `BUDGET_EXCEEDED` | the part over a budget |
| note `INLINE_VALUE_WITHOUT_SOURCE` | figures written inline on a data node with no source (below) |
| note `INVALID_INITIAL_CHOICE`, `UNREAD_STATE`, `UNKNOWN_ITEM_KEY`, `INVALID_SLOT_NODE_COUNT` | a choice state outside its options, a state nothing reads, an `$item` field no bound row has, a slot with fewer children than its minimum (a child over the maximum is dropped with `INVALID_NODE`) |
| note `WIDGET_LIFTED` | an embedded widget its slot refused, shown as its own block after the view |

When the model's final text carries a `ComposedView` whose reading dropped a node or a binding
(`WidgetBlocks::buildComposedViewFindingLinesThatDropANodeOrABinding()`), the loop holds the text once per owner turn
and asks for the corrected block with a `WIDGET_TREE_FINDINGS` signal. The frontend adds its own render report
(signal sources reference).

## Budgets

`ComposedViewBudgets` holds every limit, exported into the schema so both sides use the same numbers: source bytes,
`MAX_NODES`, `MAX_DEPTH`, state keys and string length, computed expressions and their length, inline items and cells,
text length, actions, repeat rows, rendered nodes, input updates and data-source reads per minute. A part over a budget
is dropped and reported, never the whole block.

## A node class

One subclass of `DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode` per node type, named `<NODE_TYPE>ComposedViewNode`. The package's own sit below
`Widgets/Composed/Nodes/<Group>/`; a host's below `Domain/<YourDomain>/Entities/AI/Conversations/Nodes/` of its source
root, any depth. `ComposedViewNodeClasses` finds them by folder, keyed by `NODE_TYPE`, with the same fail-soft build
errors as the widget registry and the same override rule (a subclass of a registered class replaces it).

```php
namespace App\Domain\Notes\Entities\AI\Conversations\Nodes;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the note text')]
#[ComposedViewNodeProp(name: 'pinned', types: [ComposedViewNodeProp::TYPE_BOOLEAN], description: 'shown first when true')]
class NoteChipComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'NoteChip';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'one note of the owner as a small chip';
}
```

| Declaration | Meaning |
|---|---|
| `NODE_TYPE`, `CATEGORIES`, `SUMMARY` | wire type, the categories slots test, one line for the model |
| `ComposedViewNodeProp` (repeatable attribute) | `name`, `types` (`TYPE_STRING`, `TYPE_NUMBER`, `TYPE_BOOLEAN`, `TYPE_ARRAY`, `TYPE_OBJECT`, `TYPE_EVENT`), `isRequired`, `isBindable` (default true), `allowedValues`, `description` |
| `ComposedViewNodeSlot` (repeatable attribute) | `name` (default `children`), `acceptsCategories`, `acceptsNodeTypes`, `minChildren`, `maxChildren`, `sizeClass`, `sizeClassFromProp` with `sizeClassesByPropValue`, `acceptsHeavyWhenCompact` |
| `PROVIDES_ITEM_SCOPE`, `ITEMS_PROP_NAME` | the node repeats its children over rows; `{"$item":…}` reads their fields (`Repeat`) |
| `TEXT_CONTENT_PROP_NAME`, `OPTIONS_PROP_NAME`, `SUBMITS_THE_STATE_OF_ITS_INPUTS` | the main text prop, the choices prop of an input, a node that submits its inputs (`Form`) |
| `isAcceptedBySlot()`, `isAcceptedAsRoot()` | override where acceptance depends on the props (the `Widget` node does) |

## Categories, slots and size classes

`ComposedViewNodeCategory`: `INLINE`, `BLOCK`, `DATA`, `MEDIA`, `INPUT`, `LAYOUT`, `HEAVY`. A slot that names no
categories accepts `ALL_BUT_HEAVY`. A heavy node (a map, a diagram, an embedded widget) never sits in a compact slot
unless that slot sets `acceptsHeavyWhenCompact`. The size classes (`ComposedViewNodeSlot::SIZE_CLASSES`: compact,
regular, full) tell a child how much room it has; a Grid's cells narrow with its `columns` through `sizeClassFromProp`.

## Sourcing rules

A node that shows figures sets `NEEDS_A_SOURCE_FOR_INLINE_FIGURES` and names its inline props in
`INLINE_ROWS_PROP_NAMES` (lists of rows) and `INLINE_FIGURE_PROP_NAMES` (one figure). When its subtree holds an inline
figure and neither its `source` prop nor a data node around it names a source, the reader flags
`INLINE_VALUE_WITHOUT_SOURCE` — unless a node with `NAMES_THE_SOURCES` (`SourceRef`) sits ANYWHERE in the tree, which
sources the whole tree. Inline means: written rows, a number, a text that starts with a digit or a sign
(`ComposedViewTreeReading::FIGURE_TEXT_PATTERN`), a `$data` binding to a written dataset, and an `$item` inside a
repeating node over written rows. `$from`, `$source` and `$computed` need no source. A second trigger: every inline
object with a `value` key needs a `source` or `derivedFrom` key. A new data node declares its part, or every tree that
uses it is flagged.

## Tones

Every `tone` prop accepts the one list `ComposedViewTone::ACCEPTED_WORDS`: the six `TONES` plus three older words read
as aliases. A new node never narrows the list; a new tone is added here and in the frontend at once.

## Embedding a domain widget

The `Widget` node (`WidgetComposedViewNode`) puts a domain widget into the tree: `widget` is its wire name, `fields` its
payload. Only a class with `EMBEDDABLE` qualifies, in a slot that fits its `EMBEDDED_SIZE_CLASS`. A refused widget is
**lifted**: it leaves the tree with a `WIDGET_LIFTED` note and is shown as its own block after the view
(`WidgetBlocks::buildMarkdownWithLiftedWidgetBlocks()`); inside a repeating node it is dropped instead. The `findWidgets`
answer shows the `embeds` marker for such widgets.

## Forms

`FormComposedViewNode` wraps inputs; its `name` identifies it in the reaction. The submit sends one
`FRONTEND_WIDGET_CLICK` signal — `ComposedView form "<name>" submitted:` with one `- <field>: <value>` line per field —
or `ComposedView form "<name>" declined.` when the owner presses its decline button. A tree with a named form awaits the
owner (`AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT`). The answer is a `ComposedViewFormSubmission` (`formName`, field
values). Inputs change local state only; nothing is sent before the submit.

## The schema

`ComposedViewNodeSchema::build()` is the one schema: node types with props, slots, sourcing and state rules, budgets,
categories, size classes, action types, binding kinds, finding codes, embeddable widgets, all widget types, data
sources and entity types. `ComposedViewNodeSchema::buildJson()` renders the committed file the frontend types, the
frontend reader and the parity tests read. **The package ships no command that writes it**: a host writes a console
command of the host that writes `buildJson()` into its frontend tree and fails in a check mode when the committed file
differs. `ComposedViewNodeSchema::SCHEMA_FILE_RELATIVE_PATH` names one application's path; do not rely on it.

## What the model is taught

`ComposedViewKitSpec::build()` generates the kit section that fills `{%COMPOSED_VIEW_KIT%}` in the base prompt `Agentic.md`
from the schema — the node lines, `ComposedViewKitSpec::JSON_FENCE_SPEC`, and one field line per widget with
`IS_WRITABLE_WITHOUT_A_SKILL` (a public field's docblock sentence IS its model-facing contract). Its e-mail variant
keeps the node lines, inputs included, replaces the form rule by a line that forbids inputs, and lists only the
`EMAIL_RENDERABLE` widgets. What a host's class contributes to that text:

- A node line lists the required props, the text prop, the `source` prop, the event props, an input's `value` and the
  optional props named in `ComposedViewKitSpec::KEY_OPTIONAL_PROPS_BY_NODE_TYPE`; every other optional prop stays
  unlisted. The item shape of a list or object prop (`[{"field","label"}]`, `{"label","lat","lng"}`) is read from its
  `description`, so write it there in that form.
- The render-report codes are listed with the codes that share their first word grouped (`INVALID_*`).
- A widget written without a skill gets one line: its `SUMMARY` — only its first sentence, and only when that sentence
  fits `ComposedViewKitSpec::MAX_WIDGET_SUMMARY_LENGTH`, else no summary — and its public fields (`*` when not
  nullable, the field's docblock sentence up to `ComposedViewKitSpec::MAX_FIELD_DESCRIPTION_LENGTH`). A class with an
  `EXAMPLE_INTRO_LINE` also shows the example answer `ConversationWidget::buildExampleAnswer()` renders from
  `EXAMPLE_FIELDS`, so the model answers in one response with a line and the block.
- A second example after `JSON_FENCE_SPEC`, `ComposedViewKitSpec::INLINE_VALUES_EXAMPLE_FIELDS` with its intro line,
  shows researched values written inline with a `source` (a KPI tile grid and a line chart); it reads without a finding.

On which calls it is taught — every root conversation in the chat or an in-app call and every
scheduled run, never a sub-agent or the phone line (`AgentModelInput::teachesComposedViewKit()`) — and how it stays in
the cached prefix is `ddd-module-agents-context-specialist`.

## Adding a node type

The types the package registers are listed in the node-types reference.

1. The node class (above) in the host's node folder.
2. Its frontend component under the same `NODE_TYPE`, registered in the frontend kit.
3. Regenerate the schema file with the host's command; the frontend reader drops what the schema does not list.
4. A test that reads a tree with the node through `ComposedViewTreeReading::fromWidgetFields()` and asserts the kept
   view and `findings` codes; `ComposedViewNodeClasses::replaceNodeClasses()` swaps in stub node types and `null`
   restores the folder scan.
