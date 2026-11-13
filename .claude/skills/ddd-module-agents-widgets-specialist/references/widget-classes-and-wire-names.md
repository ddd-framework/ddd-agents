# Widget classes and wire names

**Reader:** host developer and package maintainer

Contents: what a widget is · the wire name · the class · the constants · the registry · where a host puts its class · overriding a package widget · the package's own widgets · how the model finds a widget · the frontend half

## What a widget is

A widget is a block in a message text that the chat renders as a component instead of prose: a list, a set of answer
pills, a form, a composed dashboard. The block is JSON inside a fence (see the block syntax reference); its `type` is
the **wire name**, every other field is the widget's payload. The backend never renders a widget. It describes each
type by one class, writes blocks, and reads blocks and the owner's reactions back.

Two data patterns exist and the class does not care which one a type uses:

- **data-complete**: the block carries everything the component shows (the package's `WebSearchResults` and
  `ConversationSearchResults`). Use it for data with no stable way to read it again.
- **self-hydrating**: the block carries ids only, the component reads the data itself through a client endpoint. The
  id it reads by is usually the agent space's key (`$widgetSelfHydrationKey`, below). Preferred whenever such an
  endpoint exists: nothing is copied, nothing goes stale.

## The wire name

- `ConversationWidget::WIDGET_TYPE` is the wire name and the frontend registry name of the same component. It is
  **append-only**: stored rows are never rewritten, so a renamed or removed name breaks every conversation that
  carries it.
- A wire name never ends in `Directive` (`ConversationWidgetClasses::LEGACY_WIDGET_TYPE_SUFFIX`). That suffix marks the
  long form older stored rows carry; `ConversationWidgetClasses::getWidgetClassForWidgetType()` strips it once and
  resolves the short name. A doubled suffix does not resolve.
- An unknown name resolves to nothing. The frontend then renders nothing for the block; the backend reads it as a
  block without a class (`WidgetBlock::buildWidget()` returns null).

## The class

One concrete subclass of `DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget` per wire name, named `<WIDGET_TYPE>ConversationWidget`, in the domain
of the data it shows. It is a value object that reads nothing: whoever builds it resolves translations and facts and
passes them in.

| Part | Rule |
|---|---|
| Constructor | none with parameters; the registry and `ConversationWidget::fromWidgetBlock()` build it with `new static()` |
| Payload fields | typed public properties; a field the frontend reads is a property of the class |
| `getWidgetFields()` | protected; returns the fields in block order, without `type` and without the space key; `ConversationWidget::withoutNullWidgetFields()` drops optional fields left null |
| `$widgetSelfHydrationKey` | declared on the base; written into the block under the field name the host chose (`WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName()`, default `agentSpaceId`) |
| Restore | `ConversationWidget::fromWidgetBlock()` sets every public property the block carries; a value of the wrong type is skipped, never thrown; a numeric string fills an `int` |

`fromWidgetBlock()` is overridable where the fields need more (the package's `ComposedViewConversationWidget` reads
and checks its tree there; `EntityMentionConversationWidget` validates its id).

## The constants

| Constant | Default | Meaning |
|---|---|---|
| `AWAITS_OWNER_INPUT` | false | the widget waits for the owner (a form, a picker, a confirm, pills); a turn that shows it hands the turn to the owner and is exempt from the prose stall shapes, never from the empty one (block syntax reference) |
| `AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT` | false | the instance decides through `awaitsOwnerInput()` (a `ComposedView` awaits only when its tree holds a `Form`) |
| `OWNER_CONTROL` | empty | what the owner can do on it, in a few words ("answer pills", "none (read-only)"); read by `ConversationWidgetClasses::getOwnerControlOfWidgetType()` |
| `SUMMARY`, `WHEN_TO_USE` | empty | one line each; a class with a `SUMMARY` is an entry of the widget catalog the model searches |
| `IS_WRITABLE_WITHOUT_A_SKILL` | false | the model writes it from what it has, no skill first; the generated kit section teaches its fields, and a closed block of it alone is a delivered answer |
| `EXAMPLE_INTRO_LINE`, `EXAMPLE_FIELDS` | empty | the example answer `ConversationWidget::buildExampleAnswer()` renders for such a widget |
| `DIRECT_WIDGET_WHEN`, `DIRECT_WIDGET_SIGNAL` | empty | the line by which the turn-start decision may pick this widget as the turn's direct answer, and the signal sentence of that choice |
| `READS_WIDGET_SELF_HYDRATION_KEY` | false | the field line the model is taught names the space key as a field |
| `PROPOSES_IDENTITY_OR_CLASS` | false | the owner's next words may reject what the widget proposes |
| `EMBEDDABLE`, `EMBEDDED_SIZE_CLASS` | false, `regular` | the widget may sit in a `ComposedView` slot as a `Widget` node, in a slot of at least that size class |
| `EMAIL_RENDERABLE` | false | the host's report mail captures it as an image (`ConversationWidgetClasses::getEmailRenderableWidgetTypes()` is the one list) |

Two behaviours are static methods a class overrides only when it needs to:
`ConversationWidget::readOwnerAnswerOnTheNewestWidgetOfThisType()` (the answer a plan gate reads) and
`ConversationWidget::applyOwnerEditAtTurnOpen()` (apply the owner's submitted edit before the first model call of a
turn; see the reference on writing and reading). Which widget ends a purpose is the purpose's answer
(`AIConversationPurpose::getWidgetTypesThatEndThisPurpose()`), never a widget constant
(`ddd-module-agents-skills-and-purposes-specialist`).

## The registry

`ConversationWidgetClasses` maps wire name to class, built once per process by globbing every
`*ConversationWidget.php` below the package's own widget folder and below `Domain/<Domain>/Entities/AI/Conversations/Widgets/`
of every source root (the host's `src/` and every installed module), any depth. Abstract classes and the interfaces in
those folders are skipped. Questions the loop asks it:

| Method | Answer |
|---|---|
| `getWidgetClassForWidgetType()` | the class, after the legacy rule; null for an unknown type |
| `getWidgetTypes()` | every registered type, sorted |
| `isWidgetTypeAwaitingOwnerInput()` | the class's `AWAITS_OWNER_INPUT` |
| `getAnswerableWidgetTypes()` | the types whose class implements `AnswerableConversationWidget` |
| `findFormWidgetTypeSubmittedByRow()` | the form type a row submits (`FormSubmitConversationWidget`) |
| `getDirectWidgetWhenLinesByWidgetType()` | the answer set of the direct-widget question |

**Build errors fail soft in production**: two unrelated classes with one wire name, a short name that is not
`<WIDGET_TYPE>ConversationWidget`, a wire name ending in `Directive`, a class that does not load. The class is logged
as an error and skipped. A class in those files that is no widget (an interface, a helper) is skipped silently. Under `APP_ENV=test`, or with `ConversationWidgetClasses::$throwErrors`, each one throws a
`LogicException` — run a test that builds the registry so a broken class fails the build.

## Where a host puts its class

`src/Domain/<YourDomain>/Entities/AI/Conversations/Widgets/<Name>ConversationWidget.php` below the application root,
namespace `App\Domain\<YourDomain>\Entities\AI\Conversations\Widgets`. A class anywhere else — another folder, or
another root namespace — is not found: blocks of its type parse, `WidgetBlock::buildWidget()` returns null and no reader
of its answer ever runs. A host under another root namespace: `ddd-module-agents-specialist` (override points).

## Overriding a package widget

The registry, not the container, resolves widget classes, so a same-path subclass under the host's
`Domain/AI/Entities/Conversations/Widgets/` is **not** found. Put the subclass into a domain widget folder (for example
the folder Domain/AI/Entities/AI/Conversations/Widgets of the host) with the same short name, extending the package class: of two classes
with one wire name, the one that extends the other wins (`ComposedViewFolderClassFinder::resolveOverridingClassName()`).

## The package's own widgets

| Wire name | Class | Kind |
|---|---|---|
| `QuickReplies` | `QuickRepliesConversationWidget` | single-choice answer pills of `askOwner`; answerable |
| `MultiChoice` | `MultiChoiceConversationWidget` | multi-choice widget of `askOwner`; answerable |
| `ComposedView` | `ComposedViewConversationWidget` | the composable kit; answerable through its `Form` nodes |
| `CustomVisualization` | `CustomVisualizationConversationWidget` | the model's own sandboxed HTML, CSS and script; last resort |
| `WebSearchResults` | `WebSearchResultsConversationWidget` | the pages a web answer used; data-complete |
| `ConversationSearchResults` | `ConversationSearchResultsConversationWidget` | grouped cross-conversation recall hits; data-complete |
| `EntityMention` | `EntityMentionConversationWidget` | written by the owner's composer, never by the agent |
| `ToolTrace` | `ToolTraceConversationWidget` | one tool step the chat shows |
| `SuggestedActions` | `SuggestedActionsConversationWidget` | suggested next owner messages; read as a leaf attribute, a fence of it renders nothing |

`QuickReplies` and `MultiChoice` share the abstract `OwnerQuestionConversationWidget` and carry no wire name of their
own there.

## How the model finds a widget

A domain widget is written only when the model learned it: a loaded skill or a tool description shows its block, a
tool result dictates it, or the meta tool `findWidgets` finds it. `FindWidgetsTool` searches `ConversationWidgetCatalog`:
every class with a `SUMMARY`, with the skills that bring its data — the skill frontmatter `providesWidgetTypes`
(`AISkill::$providesWidgetTypes`) and every skill whose MCP packages name the widget in their code. A match of at least
`ConversationWidgetCatalog::MIN_SCORE_TO_LOAD_A_SKILL` loads that skill in the same call; a weaker one is listed.
`findWidgets` is offered on screen surfaces only (a root conversation in the chat or an in-app call), never on the phone
line or in a sub-agent. Widgets with `IS_WRITABLE_WITHOUT_A_SKILL` and the kit are taught by the generated kit section
instead (see the composed-view nodes reference). How a skill file declares `providesWidgetTypes` is
`ddd-module-agents-skills-and-purposes-specialist`.

## The frontend half

The package's web half is not released yet; its Composer release ships no frontend code. The frontend that renders
blocks must register a component under exactly the class's `WIDGET_TYPE`, parse the payload the class's
`getWidgetFields()` writes, and send back reactions in the shapes of the interaction-contracts reference. The web half
gets its own skill when it is released.
