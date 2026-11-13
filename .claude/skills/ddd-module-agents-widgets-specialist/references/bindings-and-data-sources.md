# The composed-view kit: bindings and data sources

**Reader:** host developer

Contents: four data paths · binding kinds · `$from` — a tool result of the conversation · making a result bindable · what the model reads under a result · `$source` — a registered data source · entity references · traps

## Four data paths

| Path | The block carries | Use for |
|---|---|---|
| a self-hydrating domain widget, alone or as a `Widget` node | ids | a domain view that already exists |
| `$source` | a source name and params | live rows the frontend reads through a client endpoint, under that endpoint's own entity rights |
| `$from` | a tool call id and a path | the numbers a tool of this conversation returned — bound, never retyped |
| inline values | the values and their `source` | researched values with no tool result behind them |

## Binding kinds

`ComposedViewBinding::fromWireValue()` reads a binding: an object with exactly one key that starts with a dollar sign,
never a string template. The kinds are `ComposedViewBinding::KINDS`:

| Wire | Reads |
|---|---|
| `{"$state":"period"}` | a declared state name |
| `{"$computed":"rows"}` | a computed expression |
| `{"$data":"d7"}` | a named dataset of the block |
| `{"$item":"author.name"}` | a field of the current row inside a node with `PROVIDES_ITEM_SCOPE`; a dotted name walks nested objects (`ComposedViewBinding::readRowField()`) |
| `{"$from":{"toolCall":"call_7","path":"$.rows[*]"}}` | a stored tool result of this conversation |
| `{"$source":{"name":"…","params":{…}}}` | a registered data source |

A dollar object with an unknown kind or a bad shape is still read as a binding with `isWellFormed` false and dropped
with `UNKNOWN_BINDING`.

## `$from` — a tool result of the conversation

Resolved by the backend when the row is **stored**, never at render time:
`ComposedViewBindingResolutionService::resolveBindingsOfRowBeforePersist()` runs in both persist paths (the model's
own rows and the one widget writer), right before the write and its realtime tick. Each `$from` object gets its state
written into itself (`ComposedViewBindingResolution`):

| State | When | Written into the binding |
|---|---|---|
| `STATE_PENDING` | the call has no result yet (a call of the same step, a call still running) | `toolCall`, `path`, `state` |
| `STATE_RESOLVED` | the call completed and the path selects a value | `value` (a list capped at `ComposedViewBindingResolution::$maxRows` rows), `resolvedAt`, `rowCount`, `cappedToRows`; rows of a listing result carry `entityRef` and `entity` |
| `STATE_FAILED` | no usable result, the path selects nothing, the result binds nothing, the id names no call or two calls | `value` empty, `reason` |

When a later row stores the call's result (or a later step starts), `findRowsResolvedByStoredRow()` re-resolves the
turn's pending bindings and the caller writes those rows again in the same claim-fenced write. A block written by the
model is always resolved fresh: a `state`, `value` or `entity` the model typed is dropped first, so it cannot forge a
resolved snapshot. A stored block keeps its snapshots; a replay reads them and resolves nothing again. The model reads
the block folded back to what it wrote (`ComposedViewBindingResolution::foldSnapshotsForTheModel()`).

The path is a small JSONPath subset (`ComposedViewToolResultPath`): it starts with `$`; `.name`, `['name']` or
`["name"]` select a key, `[3]` a list element (zero-based), `[*]` or `.*` every element — the path then selects a list.
No filters, no recursion, no slices; at most `ComposedViewToolResultPath::MAX_PATH_LENGTH` characters and
`MAX_SEGMENTS` segments. After a wildcard a missing key skips that element; before any wildcard it selects nothing.

Calls are looked up by conversation and tool call id: first in the loaded rows, then in the stored rows (a call
compacted out of the window still resolves).

## Making a result bindable

The executor stores a result's structured shape next to the markdown the model reads
(`ComposedViewBindableResultRecord`) when:

- the response DTO implements `DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewBindableResult` (a marker interface, no method), or
- it implements `ListsEntityReferenceRows` (which extends it), or
- the tool returns its structured shape and no markdown.

A `$from` path then addresses the DTO, not its text. A structured shape over
`ComposedViewBindableResultRecord::MAX_DATA_BYTES` is not stored for binding; only its entity rows are kept. A property serialized as a TOON string offers no path: a binding
cannot read rows out of it.

## What the model reads under a result

Where the kit is taught (`AgentModelInput::teachesComposedViewKit()`), a bindable result carries two generated lines
on the model's wire: its `$from` paths (`ToolCall::buildBindablePathsLineForModel()`, rendered by
`ComposedViewBindablePathListing` — row lists with their count and the keys of the first row) and its entity
references (`ToolCall::buildEntityReferenceLineForModel()`). Without them the model has to copy values inline.

## `$source` — a registered data source

One subclass of `DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources\ComposedViewDataSource` per source, named `<Name>ComposedViewDataSource`, in the package's
`Widgets/Composed/DataSources/` or a host's `Domain/<YourDomain>/Entities/AI/Conversations/DataSources/` (any depth),
found by `ComposedViewDataSourceClasses`:

```php
namespace App\Domain\Notes\Entities\AI\Conversations\DataSources;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources\ComposedViewDataSource;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources\ComposedViewDataSourceColumn;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources\ComposedViewDataSourceParam;

#[ComposedViewDataSourceParam(name: 'period', type: 'string', isRequired: true, description: '7d or 30d')]
#[ComposedViewDataSourceColumn(name: 'day', type: 'string', description: 'the day, Y-m-d')]
#[ComposedViewDataSourceColumn(name: 'notesAdded', type: 'number', description: 'notes added that day')]
class NotesPerDayComposedViewDataSource extends ComposedViewDataSource
{
    public const string NAME = 'notesPerDay';

    public const string SUMMARY = 'notes the owner added per day';

    public const string CLIENT_ENDPOINT = 'GET /api/notes/{agentSpaceId}/perDay?period={period}';

    public const string ROWS_PATH = '$.rows';

    public const int FRESHNESS_SECONDS = 300;
}
```

- `NAME` matches lower camel (a lowercase letter, then letters and digits), is unique, and the class is named
  `ucfirst(NAME)` plus `ComposedViewDataSource`; `CLIENT_ENDPOINT` must not be empty. A violation is a build error
  (logged and skipped in production, thrown under tests).
- `CLIENT_ENDPOINT` is an existing client read; its entity rights are the source's rights — the class grants nothing.
- A param may be a literal or bind a declared state name only (`{"$state":"period"}`); the reader refuses anything else,
  an unknown param and a missing required one (`UNKNOWN_BINDING`).
- The frontend needs a fetcher registered under the same `NAME` that reads the endpoint; the sources appear in the
  schema after the host regenerates it.

## Entity references

A tool result that lists entities implements `DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\ListsEntityReferenceRows` and its `ListsEntityReferenceRows::getEntityReferenceRows()`: one
`EntityReferenceRow::create()` per listed row, in the order the model sees them. The platform mints a reference per row
(`EntityReferenceMinting::mintForToolCall()`): `<toolCallId>:<entityType>:<index>`, the index counted per entity type.
The model writes only those references — in an `openEntity` action's `ref`, or row-bound through `$item`. When the row
is stored, the reference must name a call row of THIS conversation, unambiguously, and a row that call returned;
the resolution then writes the entity (`entityType`, `entityId`, the space key) into the action, replacing whatever the
model wrote. An unreadable reference loses its entity; the node renders its text without the click.

The domain side is one `DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceResolver` per type, named `ucfirst(ENTITY_TYPE)` plus `EntityReferenceResolver`,
in `Domain/<YourDomain>/Entities/AI/Conversations/EntityReferences/`, found by `EntityReferenceResolverClasses`:
`ENTITY_TYPE` (lower camel, unique; the class name must match it, else a build error), `SUMMARY`, and `isReadableForWidgetSelfHydrationKey()` — read through the owning domain's
service under the reader's rights, never a bypass. The frontend opens the entity in a detail body registered for the
same type; `EntityMentionConversationWidget` carries the same pair in the owner's own message.

## Traps

- A `$from` to a call of the same step is pending when the prose row is stored; it resolves when the call row is stored.
  A test that reads the first stored row sees `pending`.
- `$maxRows` caps a resolved list; `cappedToRows` tells the frontend. A `Repeat` over it renders at most
  `ComposedViewBudgets::MAX_REPEAT_ROWS`.
- Data sources are not loaded by a skill: the comment on `ComposedViewDataSource` names a skill field for them that the
  skill class does not have.
