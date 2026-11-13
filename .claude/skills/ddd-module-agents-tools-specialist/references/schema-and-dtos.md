# Schema and DTOs — from method and docblock to the tool's JSON-Schema

**Reader:** host developer

Contents
- The pipeline
- Parameter types: what the schema shows, what the executor injects
- How the arguments reach a request DTO
- Properties and their docblocks
- Dates and time zones
- Query options and shared request parameters
- The output side
- The MCP DTOs of the HTTP path

## The pipeline

`MCPPackageDocumentation::createFromClassName()` (or `MCPBundleDocumentation` for a mount) walks the public `MCPTool`
methods; `MCPTool::createFromReflectionMethod()` reads the attributes and the method docblock;
`MCPSchema::createFromReflectionMethod()` builds the input schema from the parameters;
`MCPObjectProperty::createFromReflectionProperty()` and `MCPObjectDefinition` build each property, nested objects
inline or in `$defs`. The schema is generated, never written: a wrong type or docblock yields a wrong schema without
a warning, or a `TypeDefinitionMissingOrWrong` at build time.

## Parameter types: what the schema shows, what the executor injects

| Parameter type | Schema | Execution (`MCPToolExecutorService::buildMethodArguments()`) |
|---|---|---|
| a `RequestDto` subclass | its public properties | built and filled from the call's arguments |
| a `ValueObject` subclass | its public properties | filled from the arguments ONLY when its class declares a static `fromArray(array)` (neither `ValueObject` nor `DefaultObject` declares one); otherwise taken from the container if registered there, else constructed with no data — it arrives EMPTY although the schema advertised its properties |
| a framework `Entity` (`DDD\Domain\Base\Entities\Entity`) | `TypeDefinitionMissingOrWrong`: the builder's entity test is `Doctrine\ORM\Mapping\Entity`, and the framework entity extends `DefaultObject`, not `ValueObject` | — |
| a `Service` subclass | left out | taken from the container |
| an `MCPServerProvidedInput` (the `AgentToolCallProvenance`) | left out | the provenance the loop passed; an empty instance on the HTTP path; never filled from the arguments |
| any other class | `TypeDefinitionMissingOrWrong` | must be a public container service, else `InternalErrorException` — the model's arguments never build an arbitrary object |
| a builtin (`int`, `string` …) | `TypeDefinitionMissingOrWrong` | — |
| a union type | `TypeDefinitionMissingOrWrong` | — |

So: a tool's inputs are request DTOs; services are method parameters; the call context is a parameter typed
`AgentToolCallProvenance`. Use a value object as a parameter only when it declares `fromArray()`; as a PROPERTY of a
request DTO it fills normally. The placeholder rule below runs for request DTOs only.

## How the arguments reach a request DTO

- Every request DTO of the method is filled from the SAME flat argument map. The executor builds a synthetic request
  that mirrors the arguments into query, body and route attributes and as JSON body, exposes scalar arguments as
  headers (without overriding real ones), and calls `RequestDto::setPropertiesFromRequest()`. A property declared
  `in: PATH` therefore fills over MCP too — reuse the controller's own request DTO instead of writing a second one.
- **Nothing else of the client's request reaches the tool.** Over HTTP, `MCPController::handleMcpRequest()` hands the
  executor a new request whose only header is `Authorization` (the bearer, or a token minted for the account); the
  client's other headers, its cookies and uploaded files are gone. In the agent loop there is no request at all. A
  header parameter other than `Authorization` fills only from an argument of the same name; a file upload never
  fills.
- **Placeholders are dropped first** (`MCPToolExecutorService::withoutPlaceholderArguments()`): a model that fills
  every parameter sends `""` or null for the ones it does not need. On an OPTIONAL property (nullable or with a
  default) such a value counts as not passed: the default stays, a `Choice` validation never sees `""`, a stored
  value is not overwritten. `false` and `0` are values. `MCPEmptyStringIsValue` makes `""` a value on one property.
  An eval-harness call keeps its arguments as they are.
- In the agent loop, before the gates and the hydration, the arguments the conversation's space fills are added
  (call-context-and-agent-space-defaults.md).
- A list tool that declares `MCPExposedFilterProperties` is refused when its hydrated `filters` or `orderBy` names
  another property.

## Properties and their docblocks

- Public, non-static properties without `HideProperty` become schema properties. The property docblock's text is its
  description; `@example` lines become its examples.
- **A typed list is `@var T[]`**, never `@var array<T>`: the docblock type reader takes letters, digits, `|`, `_`,
  braces, backslashes, square brackets and parentheses only, so the generic form is read as a plain array and the
  items lose their type.
- A plain `ObjectSet` or `EntitySet` property documents as an array of its element type, read from the set's own
  "@var ElementType[] $elements".
- A `Choice` constraint becomes the property's `enum`.
- A union of builtins is a type list; a union of classes is `oneOf`; a mix of both throws `TypeDefinitionMissingOrWrong`.
- A property of a NESTED object with `SerializeInToonFormat` gets a second string property "<name>InToonFormat"
  (not a top-level property of the request DTO).
- `MCPOutputOnlyProperty` drops a server-filled property from the input schema at every depth.
- **The generated schema carries no `required` list** (`MCPSchema` and `MCPObjectProperty` never fill one). The
  model learns what is required from the description: say "(required)" in the property docblock, and refuse a
  missing value in the tool with a message written for the caller.
- Objects nest down to the build's `maxDepth` (6 by default). Only in `$defs` (`addObjectsToDef`, the HTTP
  `tools/list`) is a class already on the call path cut; inline — the agent's schema — only `maxDepth` ends a cycle,
  so a self-referencing DTO costs six levels of schema. Keep cyclic references out of request DTOs.
- English only, in every docblock and example: the agent answers in the owner's language at run time.

## Dates and time zones

Type a moment with the framework's classes, never PHP's own: `DDD\Infrastructure\Base\DateTime\DateTime` and
`DDD\Infrastructure\Base\DateTime\Date`. A `DateTime` property documents the format `Y-m-d H:i:s` (a wall-clock
time on the conversation's clock), a `Date` `Y-m-d`, each with a FIXED example — a moving example would change the tool schema every day and break the
prompt-cache prefix. In the loop the executor hydrates the arguments with the conversation's zone as input zone (a
local wall-clock `DateTime` becomes a UTC instant; an explicit offset wins; `Date` is untouched), runs the tool body
in UTC, and renders the result for the model in the conversation's zone (`SerializerRegistry::withInputTimezone()`,
`SerializerRegistry::withModelFacingTimezone()`, the `MODEL_FACING_DATETIME` flag). A tool never converts zones
itself; on the HTTP path no zone is set.

## Query options and shared request parameters

- A query-option parameter (`filters`, `orderBy`, `expand`, `select`; a DTO with `DtoQueryOptionsTrait` and the
  `DtoQueryOptions` attribute) documents as a string with its endpoint's allowed properties; the grammar itself is
  emitted ONCE — in the agent's system context when any loaded tool uses query options
  (`ToolDefinitions::$usesQueryOptions`), in the HTTP `initialize` instructions (`QueryOptionsSyntax`). Never write
  the grammar into a property description.
- `expand` lists relation names only on the MCP side.
- Properties marked `SharedRequestParameter` (`noCache` on every request DTO, the paging parameters) STAY on every
  tool's schema with their name and type; only their explanation is shared — the per-tool property carries no
  description, and `SharedRequestParametersSyntax` explains them once (agent system context, HTTP `initialize`).
  `outputFormat` is the exception: it is left out of the MCP schema entirely, so the model cannot switch a tool to the
  structured shape.
- Narrow per tool with `MCPHiddenQueryOptions`, for every consumer of a DTO with `DtoQueryOptions(expose: …)`.

## The output side

The output schema is built only on request (`includeOutputSchema`; the HTTP `tools/list` and the agent offer do not
ask for it). It accepts a `RestResponseDto` or `ValueObject` return type; a framework `Entity` return type fails that
build with `TypeDefinitionMissingOrWrong` (the same entity test as for parameters). Return a `RestResponseDto`. What
the model actually reads is the rendering (results-and-their-size.md).

## The MCP DTOs of the HTTP path

| Class | Role |
|---|---|
| `DDD\Presentation\Mcp\Dtos\MCPRequestDto` | the JSON-RPC body: `id`, `method`, `params` |
| `MCPToolCallParams` | `tools/call` params: `name`, `arguments` (an array, so arbitrary JSON objects hydrate) |
| `MCPToolResponse` | `content` parts (text, image, resource), `structuredContent`, `isError`, `refusalKind`, `preservedFacts`; the platform note, the dictated widget type and the bindable record are protected and never leave the process |
| `JsonRpcResponseDto`, `JsonRpcErrorDto`, `JsonRpcError` | the envelope and the error (codes `JSON_RPC_CODE_PARSE_ERROR` … `JSON_RPC_CODE_SERVER_ERROR`) |
| `McpCapabilitiesResponseDto` | the `initialize` answer |
| `MCPResponseDto` | the HTTP response: one SSE frame or static JSON |

`DDD\Domain\AI\Entities\MCPPackages\MCPRequestDto` (a class with the serializer and validator traits) is read by
nothing in the package; do not confuse it with the presentation class of the same short name.
