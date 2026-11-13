# Results and their size

**Reader:** host developer

Contents
- What the executor makes of a return value
- Failures and refusals
- What rides beside the result
- Pictures
- Third-party text
- Why size matters, and the levers in order
- A list names its window
- Measuring

## What the executor makes of a return value

`MCPToolExecutorService::renderToolResultForModel()` decides, in this order:

| The tool returned | The model reads |
|---|---|
| an `MCPToolResponse` it built itself | that response, verbatim — the way to attach image parts (`addImageContent()`) or resources |
| an `LlmMarkdownRenderable` (and the caller did not ask `outputFormat=structured`) | `toLlmMarkdown()`; the loop-control fields of the DTO ride in a control-only `structuredContent` (loop-control-fields-and-tool-written-messages.md); `applied: false` rides there too |
| a `RestResponseDto` | "Successful response for '<tool>'" plus the serialized DTO as `structuredContent` |
| any other framework object | the same, serialized through its own recursion-safe serializer |
| an object with `getContent()` | that text |
| a string | the string |
| anything else | `json_encode` of it |

The markdown is the default, and the model cannot switch it off: `outputFormat` is not in its schema. An HTTP client
can pass `outputFormat=structured`.

**Return a `RestResponseDto`, never a bare entity.** A DTO serializes through `toObject()`: recursion-guarded, and
`HideProperty`, the static hide lists and TOON apply. Make it `LlmMarkdownRenderable` when the structured shape is
large or repetitive; leave a small flat result (a settings object, a confirmation) structured — there the JSON costs
nothing extra.

The rendering is a pure function of the DTO's own data: no repository call, no persist. Lead with the answer (the
current value, the change), group repeats (a daily series as constant spans), give a one-line legend for any
encoding, and let every object render itself — a container composes its children's renderings and never formats a
child's fields. An empty or not-connected state renders as a normal answer ("No notes yet."), never as an error. Put
an example output into the method's docblock. Three details:

- Guard a typed property that may stay uninitialized (loaded on demand, or never set) with `isset()` before reading
  it: reading an uninitialized typed property is a fatal error, and it surfaces as a failed tool call.
- An object that appears both alone and inside a list offers a compact or one-line variant beside its full
  rendering; the container calls the short one.
- Entries that carry no detail worth acting on collapse into one line of names with their count, instead of one block
  each.

## Failures and refusals

| Situation | What the model reads | `isError` |
|---|---|---|
| a framework exception with a 400–499 code (bad request, forbidden, not found) or `AgentToolExecutionTimeoutException` | "Execution failed for Tool '<tool>': <message>" | yes |
| any other throwable | "… an internal error occurred (issue id <id>)…" — the message is written to the issues log (`IssuesLogService`), never shown | yes |
| a refusal of an executor gate | the gate's text; `MCPToolResponse::$refusalKind` set (`REFUSAL_MANDATE_REQUIRED`, `REFUSAL_OWNER_CONFIRMATION_REQUIRED`, `REFUSAL_SCOPE_NOT_ELIGIBLE`, `REFUSAL_ADMIN_ACCESS_REQUIRED`) | yes |
| `OwnerGateRefusal::because($reason)` thrown by the tool | "… REFUSED — <reason>"; the loop forces the next step to be text (present, ask, end the turn) | yes |
| a result whose `structuredContent` says `outcome: incomplete` or `applied: false` | the result; the loop counts the call as "ran, saved nothing" — it proves no plan item and a retry is no identical repeat | no |

Write a refusal for the caller: what did not happen, why, the one next move. Throw a 4xx framework exception for a
refusal the model must read; anything else is hidden behind an issue id. The failure text is an override point
(`MCPToolExecutorService::buildToolFailureText()`, `MCPToolExecutorService::isToolFailureMessageWrittenForTheCaller()`), owned by the entry skill.

## What rides beside the result

| Field | Set by | Stored | On the wire |
|---|---|---|---|
| platform note | `MCPToolResponsePlatformNoteProvider::buildPlatformNoteForModel()` | on `ToolCall::$platformNoteForModel`, outside the result | after the result |
| dictated widget type | `MCPToolResponseDictatedWidgetTypeProvider::getDictatedWidgetType()` | on the call | never; the stall check reads it |
| preserved facts | `AgentToolResultFactsService::extractPreservedFacts()` (default: none) for a markdown-rendered `RestResponseDto` or framework object | in the stored result | stripped |
| bindable record | a result implementing `ComposedViewBindableResult` | on `ToolCall::$bindableResult` | the `$from` paths and entity references, where the model is taught the kit |

The platform note and the dictated widget type are read ONLY from a response that implements `LlmMarkdownRenderable`
and is rendered as markdown, i.e. the call did not ask `outputFormat=structured`: a DTO that carries a note implements
both interfaces, and a structured call loses the note without an error. Put instructions to the model into the
platform note, not into the result: the injection screening reads the result only, and the platform's own
instructions in a result read like an injection.

## Pictures

- Attach with `MCPToolResponse::addImageContent()`. The executor splits image parts out of the text, so their base64
  never lands in the text the model is billed for (`ToolResult::$images`).
- Each image is checked before it is kept (`ToolResultImage::decodeMcpImageContentPart()`): valid base64, at most
  `MAX_DECODED_BYTES` (5 MB), a decodable PNG, JPEG or WebP by its magic bytes (`ALLOWED_MIME_TYPES`), at most
  `MAX_PIXELS` (20 MP). Per result: `DEFAULT_MAX_IMAGES_PER_TOOL_CALL` (4) unless `MCPToolImageBudget`, never more than
  `MAX_IMAGES_PER_TOOL_CALL_CEILING` (12), together at most `MAX_IMAGE_BYTES_PER_TOOL_CALL` (6 MB). A rejected or
  excess image becomes a text note, never a failed call.
- An inline image rides once. A durable picture names its store and id on the part (`sourceStore`, `sourceImageId`),
  and the package that serves it implements `ToolCallImageStore` (`ToolCallImageStore::getToolCallImageStoreName()`,
  `ToolCallImageStore::resolveToolCallImage()`, deterministic and cheap per id); which pictures ride on later model calls is the context
  skill's (`ddd-module-agents-context-specialist`).

## Third-party text

Wrap every passage a third party wrote with `ToolResultUntrustedSegments::wrap($source, $text)` and put
`ToolResultUntrustedSegments::READING_RULE` into the platform note (so the DTO renders as markdown). The marks show the model where foreign text
starts and ends; the screening of a tool marked `MCPToolReturnsUntrustedContent` then reads only the marked passages.

## Why size matters, and the levers in order

A result is sent again with every later model call until the wire clears it (the clearing stub replaces results of
`MIN_RESULT_CHARS_TO_STUB` (256) characters or more; `MCPToolNewestResultIsNeverStubbed` exempts the newest).
Clearing and compaction are the context skill's. Levers, in this order, each re-measured:

1. **Markdown rendering** (`LlmMarkdownRenderable`) — the biggest lever for large or repetitive data; lossless:
   everything the owner would see on the screen this data backs, in compact form.
2. **Hide repeated nested context** — the static methods of the framework's `SerializerTrait`
   (`DDD\Infrastructure\Traits\Serializer\SerializerTrait`), called on the child class per call:
   `SerializerTrait::addStaticPropertiesToHide()` with `true` and the property names.
3. **TOON for a flat homogeneous list** (dozens of rows with repeated keys) — `SerializeInToonFormat` on the list
   property plus `SerializerTrait::$toonEnabledSerialization` set to true in the tool, or
   `SerializerTrait::addStaticPropertiesToSerializeAsToon()` on the list class; `SerializerTrait::setStaticToonColumnsSpec()`
   curates columns and is lossy.
4. **Drop noise at the query level** — a default filter the caller can override.
5. **Scope the request** — a cap on how many rows one call returns, said in the description; a huge result with a huge
   argument is a scoping problem, not a serializer problem.

## A list names its window

A list tool that returns one page of a longer list leads its reading with the window it holds: "Rows 26–50 of 140,
ordered by created, newest first (skip 25, top 25). More rows follow: skip 50 reads the next page." Without the line
every page looks like the first; the model calls again to check, and a reader cannot tell pages apart. No package
class carries this: it is a convention of the response DTO, set from the applied query options and rendered first
(the template's `toLlmMarkdown()` shows the shape).

## Measuring

Measure the characters of the result as the model reads it and divide by four — the same estimate the loop uses for
the schema (`ToolDefinition::$residentSchemaTokens`). Call the tool through the executor in a host test or a console
command of the host (testing-a-tool-in-a-host.md) with realistic arguments, once with the markdown, once with
`outputFormat=structured`; a routine result above a few thousand tokens is a problem to fix.
