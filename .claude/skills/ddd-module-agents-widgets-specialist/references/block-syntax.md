# Block syntax

**Reader:** host developer and package maintainer

Contents: the one written form · field order and the origin field · the forms the readers accept · the parsed block · what the model reads back · the prose rule · the frontend side of the wire · adding a syntax

## The one written form

Every block the package writes, dictates or teaches is a fence with the info string `widget` and ONE flat JSON object:

```widget
{"type":"QuickReplies","agentSpaceId":42,"options":["Yes, keep it","No"]}
```

`WidgetBlockSerializer::buildFenceBlock()` is the one writer; `ConversationWidget::buildWidgetBlock()` and
`AIConversationMessage::buildWidgetFenceBlock()` call it. The payload fields sit **next to** `type`, never inside a
nested `payload` object: the frontend's fence rewrite strips `type` and hands every other key to the component as its
payload, so a nested wrapper would arrive as `payload.payload`. The JSON is encoded with unescaped slashes and
unicode.

## Field order and the origin field

1. `type` — the wire name.
2. The agent space's key, when the widget has one (`ConversationWidget::$widgetSelfHydrationKey`), under the field name
   of `WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName()`. A host whose blocks and clients carry another
   name (its own entity id) declares a class at the same path that extends `WidgetBlockFieldNames` and redeclares
   `WIDGET_SELF_HYDRATION_KEY`; every writer and reader asks the getter. The same class may declare
   `OWNER_TEXT_SUFFIX_PATTERN_OF_ANOTHER_SPACE`, the suffix its client appends to a pill click on a widget of another
   agent space (the default appends none).
3. The fields of `getWidgetFields()`, in that order. They lose to `type` and the key on a name collision.
4. `emittedBy` — **last**, and only on a block a tool or the platform stored
   (`ConversationWidget::buildWidgetBlockEmittedBy()`): `tool:<toolName>` or `platform:<reason>`. The bytes before it are
   exactly `buildWidgetBlock()`'s. A block without the field was written by the model — every model block, every
   dictated block the model copied, every block stored before the field existed. `WidgetBlock::getOrigin()` reads it as
   a `ConversationWidgetOrigin`; `ShownConversationWidget::getOrigin()` honours it only on a tool-written row.

## The forms the readers accept

`WidgetBlocks::fromMarkdown()` asks one parser per written form, in the order of `WidgetBlocks::getSyntaxParsers()`,
at every line; the first that reads the line wins:

| Parser | Form | Syntax constant |
|---|---|---|
| `WidgetFenceSyntaxParser` | a backtick or tilde fence of length three or more, info string `widget`; an ordinary code fence is consumed as text, so nothing inside it is read as a widget | `SYNTAX_FENCE` |
| `WidgetOneLineSyntaxParser` | the fence written on one line as a code span | `SYNTAX_BARE` |
| `WidgetBareSyntaxParser` | the bare `widget {…}` paragraph a model writes when it drops the backticks | `SYNTAX_BARE` |
| `WidgetContainerSyntaxParser` | a `:::Name … :::` container: recognised, never parsed (`OUTCOME_UNSUPPORTED_SYNTAX`) | `SYNTAX_CONTAINER` |
| `WidgetLeafSyntaxParser` | the stored leaf form `::Name{payload='…' key=…}` older rows carry | `SYNTAX_LEAF` |
| `WidgetGluedFenceOpenerSyntaxParser` | a fence opener glued to the end of a prose line, asked last | `SYNTAX_FENCE` |

Repairs both sides make, so a reader and the renderer agree on what the owner saw:

- A three-backtick widget fence ends right after its JSON object and counts as closed when no closer line comes, or
  when a backtick line WITH an info string comes before the closer — provided a JSON object begins the body and
  balances within the 5,000 characters `WidgetBlockSourceText::findBalancedJsonObjectEnd()` walks.
- A fence that never closes runs to the end of the text; `WidgetBlock::$isClosed` is false (the model was cut off).
- JSON that decodes only after dropping up to `WidgetBlocks::MAX_SURPLUS_CLOSERS` surplus trailing closers is read
  without them (`WidgetBlocks::buildWidgetJsonWithoutSurplusClosers()`).
- JSON is decoded with depth 32; deeper is malformed.
- A stored leaf block is read only with the attributes `payload` and the space key field; any other attribute makes it
  MALFORMED (`WidgetLeafSyntaxParser::decodeLeafAttributes()`).

`WidgetBlocks::getMessageTextMarkers()` collects each parser's markers (`widget`, `::`); the database preselection of the
widget view loads an assistant row only when its text carries one, so a new syntax extends it by itself.

## The parsed block

`WidgetBlock` is the envelope every parser yields: `ordinal` (position among the message's blocks),
`ordinalAmongBlocksOfType` (among the VALID blocks of its type; a name and its legacy long form count as one type), `start` and
`length` (byte span), `syntax`, `isClosed`, `widgetJson` and `outcome` (`OUTCOME_VALID`, `OUTCOME_MALFORMED`,
`OUTCOME_UNSUPPORTED_SYNTAX`). Ask the envelope about the text and the widget about the content:

| Question | Ask |
|---|---|
| the typed widget | `WidgetBlock::buildWidget()` (null without a class) |
| the fields as an array | `WidgetBlock::getFieldsAsArray()` — never a `json_decode` of your own |
| the canonical type | `WidgetBlock::getCanonicalWidgetType()`; `isOfType()` matches plain and legacy form |
| does a promised field carry a value | `WidgetBlock::carriesFilledFields()` — a whole-value placeholder in angle brackets, or the placeholder with its brackets stripped, is not filled |
| the types of a message | `AIConversationMessage::getWidgetTypes()`, `getWidgetFieldsOfType()`, `carriesOnlyWidgetBlocks()` |
| closed valid block of a type | `WidgetBlocks::hasClosedValidBlockOfType()` |

No reader finds a BLOCK by matching text: it asks a parsed `WidgetBlock` (a few readers check for the substring
`ComposedView` before they parse, to skip texts without one). A REACTION is matched to a type by the substring rule
`ConversationWidgetReaction::namesWidgetType()` — that is why reaction sentences name the wire name. The loop's empty
check (`ModelResponse::getVisibleProse()`) removes every span between three backticks and nothing else: a tilde fence or
a bare `widget {…}` line counts as visible prose there.

## What the model reads back

The model never sees `emittedBy`: tool-written rows are not on the model's wire, and the two model-facing readers of
stored rows render through `WidgetBlocks::buildMarkdownWithoutEmittedBy()`. A `ComposedView` block is turned back into
what the model wrote — resolved `$from` snapshots removed — by `WidgetBlocks::buildModelFacingText()`.

Older stored `ComposedView` fences may carry a field `WidgetBlock::LEGACY_WRITTEN_SYNTAX_FIELD` (`writtenSyntax`) that
nothing writes any more: the source budget (`ComposedViewTreeReading::measureSourceBytes()`) and the model-facing text
drop it, every other reader ignores it as an unknown field. Never give a widget a field of that name.

## The prose rule

Everything that teaches a widget (a tool description, a skill body, a dictated note) asks for **one short prose line,
then the block on its own line**, and never says "do not repeat it in prose": the prose carries the key statement, so
the answer stands when the widget fails to render, and a backtick block alone is an empty turn. What a display block
changes there: a widget of the turn that awaits the owner (`AWAITS_OWNER_INPUT`) or ends the purpose hands the turn to
the owner and is no prose stall, but a turn of that block alone is still an empty one; only a closed, valid block of an
`IS_WRITABLE_WITHOUT_A_SKILL` widget is an answer on its own. A block a tool result dictated, or the turn-start decision
chose, that is missing from the answer is a stall of its own. Which stall shape each flag exempts, and every other
exemption, is `ddd-module-agents-loop-specialist` (stall and final text).

## The frontend side of the wire

The web half is not released yet; this is the contract the backend writes for. The renderer rewrites a widget fence and a bare `widget {…}` line into the same node a stored `::Name{payload='…'}`
leaf produces: `type` becomes the node name, all other fields one `payload` JSON string; the space key is lifted into
its own attribute too. The name resolves with the same legacy rule (exact name; else strip `Directive` once). An unknown
name or malformed JSON renders nothing — the block is dropped, never shown as raw JSON. The component receives the
payload plus three injected flags: interactive (controls usable: a live message, or the newest reply after a reload),
justDelivered (delivered live right now — the only gate for one-shot side effects) and the host message id.

## Adding a syntax

One class implementing `WidgetBlockSyntaxParser` (`readBlockAtLine()`, `getMessageTextMarkers()`) and its place in
`WidgetBlocks::getSyntaxParsers()`; the renderer needs the twin. Nothing writes a new form: the one written and stored
form stays the fence.
