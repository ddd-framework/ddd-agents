# Definition size and descriptions

**Reader:** host developer

Contents
- The second cost: the schema on every model call
- What the package already does once
- Levers on the schema
- Writing the description
- Variants for the phone line
- Measuring

## The second cost: the schema on every model call

A loaded tool's schema is sent with every model call of the conversation, whether the tool is called or not.
`ToolDefinition::$residentSchemaTokens` estimates it as the characters of `toOpenAiToolSchema()` divided by four,
once per tool name and process; the eviction of unused tools compares these numbers (the context skill's). The wire
shape must also stay byte-stable from call to call, or the prompt-cache prefix breaks:

- the serializer's class discriminator `objectType` is stripped from every schema
  (`ToolDefinition::withoutClassDiscriminators()`);
- date and date-time properties carry fixed examples;
- an empty `properties` map is sent as an object, so a tool without arguments is valid in strict mode.

## What the package already does once

Do not repeat in any tool what is already sent once:

- the query-options grammar (`QueryOptionsSyntax`) — a query-option property carries only its allowed properties and
  a pointer;
- the shared request parameters (`SharedRequestParametersSyntax`) — `noCache` and the paging parameters carry no
  description per tool; `outputFormat` is not in the MCP schema at all;
- `expand` lists relation names only, without nested filter lists;
- the provenance and every injected service are left out.

## Levers on the schema

| Lever | When |
|---|---|
| `MCPToolSummary` | always: the `load_tool` catalog shows this line instead of the whole docblock while the tool is not loaded |
| a request DTO without `DtoQueryOptions` | the tool does not let the model filter, sort or expand: a `DtoQueryOptions` on a large entity reflects its whole expandable graph into the schema |
| `DtoQueryOptions(expose: …)` | a family is dead for every consumer of the DTO — and only where the repository does not apply it for the caller (a database repository applies select, expand and order; narrow only what is provably unused) |
| `MCPHiddenQueryOptions` | a family or a paging property is dead for the agent only (the DTO is shared with a REST endpoint, or the tool forces the option itself) |
| `MCPExposedFilterProperties` | the base entity has many columns and the owner asks about few |
| `MCPOutputOnlyProperty` | a value object travels both ways and its resolved half is server-filled |
| `MCPToolAlwaysLoad` / `MCPToolNoEviction` | residency: small and always needed / must never leave — keep the pinned set few and small |

## Writing the description

The method docblock is what the model selects and operates the tool on. Keep and cut:

| Cut | Keep |
|---|---|
| a list of the result's fields — the model reads them in the result | routing: "Use it to answer …" |
| an enumeration that mirrors the payload | delineation against sibling tools that share a word ("for one item use …", "this is the only tool that …") — never cut it |
| | operating gotchas the model cannot infer: the date format, what is not sortable, a parameter the backing load ignores, value enums of a filter |
| | a write's full input contract: whole-or-partial semantics, each field's shape, what omitting a field does — a wrong write costs more than tokens |
| | behaviour notes: read-only, slow, "an empty result is an answer — never invent one" |

- One to three `@example` lines per tool, each one valid JSON line for the input: one minimal call plus the most useful
  variant; for an upsert one create (no id) and one update (with id). The model copies their shape.
- Name the subject in the tool name and the description (`getDemoNotes`, "the owner's notes"), so words shared with
  other skills do not mis-route.
- Defend against a mis-route in the description and the skill text, measured with evaluation cases — never with a
  keyword guard inside the tool.
- English only.

## Variants for the phone line

A docblock may end in variant sections `## [surface=screen] …` and `## [surface=phone] …`. The screen sections render
by default, the phone sections only while the owner is on the phone line; the markers never reach the wire
(`ToolDefinition::renderDescription()`, `ToolDefinition::renderVariantSections()`). An external MCP client always
reads the screen variant (`MCPBundleDocumentation::createFromClassName()`).

## Measuring

- The exact wire schema of a loaded tool: `ToolDefinitions::forPackages([$packageClass])->getByName($toolName)->toOpenAiToolSchema()`.
- Per skill, package and tool, with a real tokenizer: `AISkillCatalogDocService` (`AISkillCatalogDocService::buildSkillCatalog()`,
  `AISkillCatalogDocService::buildToolDocumentation()`, `AISkillCatalogDocService::buildSharedBlocks()`) — the skills-and-purposes skill describes it.
- Every description edit is gated by the host's evaluation cases of the skill, run before and after, with the
  delineation cases included: a lean description that blurs a boundary is a regression.
