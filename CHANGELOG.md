# Changelog

## 0.2.2

Maintenance release of the 0.2 line (branch `release/0.2`; the same settings ship on the 0.3 line):

- The write gate after an untrusted read is opt-in. `AI.untrustedContent.writeAfterUntrustedRead` takes `off` (the new
  default: a WRITE or DELETE after an untrusted read of the turn runs as any write, also in a delegated sub-task and a
  scheduled run), `ownerConsent` (the owner-consent check, the default of 0.1.30–0.2.1) or `refuse`. A missing value is
  `off`; an unknown value is `off` too and is reported once per process in the issues log.
- The refusal of a model-built URL nobody showed (`MCPToolFetchesModelChosenUrl`) has its own key,
  `AI.untrustedContent.unseenUrlAfterUntrustedRead`: `refuse` (default, unchanged behaviour) or `off`.
- Unchanged: a READ or WORKSPACE tool is untrusted unless it declares trust; the tool-result screen and the reading rule.
- Host step: a host that wants the write gate sets `writeAfterUntrustedRead` to `ownerConsent` (with the decision site
  `ownerConsent` `on`) or `refuse` in its own `config/app/AI/untrustedContent.php`. A host that replaces the file keeps
  working: a missing key is `off` / `refuse`.
- npm: published with the dist-tag `v0.2` (not `latest`).

## 0.2.1

Messages API, found on the first live runs:

- A note of a call (`clear_at`) is sent with `clear_at` only where the API accepts it: after a user turn and before an
  assistant turn or at the end of the messages. A note after an assistant message was answered with a 400 ("clear_at …
  must follow a user turn and precede an assistant turn"); it now goes out as a plain `role: "system"` message
  (`AgentMessagesRequestComposer::withClearAtOnlyWhereTheApiAcceptsIt()`).
- A text tool result is one string whether or not it carries the cache marker. Before, the result the call marked went
  out as a list of text blocks and as a string on the later calls: the provider read both the same (cache and thinking
  held), but `app:ai-agent:cache-diff` named every such call a rewrite of that message.
- `app:ai-agent:run-report` reads every named run with the rights lifted, like `inspect` and `cache-diff`: the runs of
  an evaluation belong to the account they ran for, and the report showed `no rows` for them.
- After the provider dropped thinking blocks, only the steps of those blocks go on without thinking; the other steps
  keep theirs and the thinking floor stays. 0.2.0 removed the thinking of every earlier step on the next call, which
  rewrote the cache of the whole history (seen live: 29k of 70k tokens read, 41k written). The request without the
  dropped blocks is the one the provider read and cached (probed: the next call reads the whole cache and reports no
  drop; seen live after a picture left at a turn end: 66k of 68k tokens read, nothing dropped). The steps are recorded per call (`RequestSnapshot::$messagesWireDroppedThinkingStepIndexes`, from the paths
  of `input_transformations` and `AgentMessagesConversation::$stepMarkerMessageIndexesByMessagePosition`); a call
  recorded without them lifts the floor as before. `AgentMessagesRequestComposer::composeBody()` takes the converted
  conversation as an optional second argument; `AgentMessagesWireReplay::fromRecordedFloors()` and
  `AgentPreviousReasoningCut::decideFor()` take the dropped steps as an optional last argument.
- Docs: a picture that leaves the wire changes its tool result also in the frozen part of a Messages history, under
  the picture cap and, for a picture that is not durable, at the end of its worker invocation (a turn end): the
  provider drops the thinking after it and the cache is read up to that result (probed and seen live).
- Web: version in step with the package, no change.

## 0.2.0

The Anthropic Messages API as a third wire API of the agent, next to Chat Completions and Responses. Claude models get
their own thinking back, load skills append-only and keep a stable prompt prefix.

- **Wire API `messages`.** `RunSettings::AGENT_WIRE_API_MESSAGES`: an Anthropic model over the direct OpenRouter
  egress (`https://openrouter.ai/api/v1/messages`). The value comes from the conversation's row (`--agent-wire-api`,
  a purpose's run settings, an eval arm) or from the model catalog of mgamadeus/ddd-ai 3.5.0, which names it for Claude
  Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.1 and Fable 5.2. A row naming it for another vendor or another egress is
  refused (`REJECTION_MESSAGES_NEEDS_ANTHROPIC_MODEL`, `REJECTION_MESSAGES_NEEDS_DIRECT_OPENROUTER`); a catalog value
  the egress cannot serve is Chat Completions.
- **The catalog value is checked here.** ddd-ai 3.5.0 returns `settings.agentWireApi` as written.
  `AgentWireConfiguration::resolveWireApiOfCatalogValue()` matches it against the forms this agent speaks
  (`CATALOG_WIRE_APIS`), whatever its case; a value it does not speak is Chat Completions with one warning per model
  and process.
- **Append-only skill loading on Messages.** `conversationAppend` is the default there as on Responses: a skill's
  instructions go out as a `role: "system"` message at the place of the load, its tools as `tool_addition` blocks
  (declared top-level with `defer_loading`). The notes of a call (signals, the plan, the time) go out as one
  `role: "system"` message with `clear_at: next_user_message` and stay in the history of later calls, hidden from
  the model.
- **Thinking carried over.** The answer's content blocks are stored per step and sent back verbatim. A thinking block
  is bound to everything before it, so the newer part of the history is frozen (`AgentMessagesWireReplay`): stored
  answers, full tool results and unshortened arguments from a recorded floor on; below it the history renders as on
  every other wire API. `previousReasoningCarryOver` works as before: `capped` (the default on Messages, cap 4,000
  thinking tokens: the oldest steps' thinking leaves in one block), `all`, `currentTurn` (the frozen part starts at
  the newest owner turn), `off` (no frozen part, no thinking).
- **Dropped blocks are reported, not fatal.** Every request asks the provider to drop a thinking block whose prefix
  or model changed instead of refusing the request. The drops are recorded on the snapshot
  (`RequestSnapshot::$inputTransformations`), traced (`turn.modelCall.inputTransformations`), shown by
  `app:ai-agent:cache-diff` (column `thinking`) and `app:ai-agent:inspect --snapshots`, and later calls send thinking
  from that step on. A refusal that names a thinking block repeats the call once without carried thinking.
- **Answer reading.** `AgentMessagesResponse` reads text, calls, usage (input = uncached + cache read + cache write;
  thinking tokens) and the dropped blocks; an answer without text and call fails the call (`messagesAnswerUnusable`).
  The egress overrides the three answer hooks of ddd-ai's language-model trait for this shape.
- **Prompt cache.** Four `cache_control` markers: two in the system content, the newest stable message and the one the
  previous call marked. The writes are booked at the 5-minute write rate; no service tier is noted for a Messages
  body, so no tier multiplier enters its booking.
- **Provider pin.** A Messages call goes to Anthropic only, without fallback. A console provider order
  (`forceProviderOrder()`) replaces the pin for that process; the egress then sends no carried reasoning on a call it
  would have pinned (Messages, and reasoning items of an OpenAI model).
- **Console.** `app:ai-agent:run-report --usage` (per run: input, cached, cache-write, output and thinking tokens, the
  cached share, tokens and model seconds per call, dropped thinking blocks); `app:ai-agent:probe-reasoning-replay
  --wire=messages` with the scenarios `capped-cut` and `current-turn-strip`; `--agent-wire-api=messages` on the chat
  and the evaluation commands.
- **Changed:** `REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES` names both append-capable APIs in its text; the
  constant keeps its name.
- **Host step:** update together with `mgamadeus/ddd-ai` ^3.5.0 (required here; ddd-ai 3.5.0 conflicts with
  ddd-agents below 0.2.0). A host that overrides a Claude model in its own `config/app/AI/models.php` sets
  `settings.agentWireApi` to `messages` there to move that model; without it the model stays on Chat Completions. A
  running conversation of such a model that was stamped `systemPromptSwap` keeps the swap; a new one loads
  append-only. No schema change: the new facts live in the request snapshot's JSON.
- Web: version in step with the package, no change.

## 0.1.44

- Web: the "Working on it…" line no longer stays under a finished answer. Rows that owe no work — the frontend's
  widget render report and the other no-work SIGNAL rows, ERROR and CHECKPOINT rows — are stored with role USER and
  may land after the answer; the activity now skips them (the same walk as `lastWireMessageIsUnansweredUserMessage`).
  A saved owner message right after a send still shows the line while the run status is not yet `running`.
- Web: a send's answer replaces exactly its optimistic owner row (matched by text, else the oldest one) instead of
  dropping the last row: a live read that merged in between no longer leaves a second owner bubble, and a second
  message sent while the first was in flight keeps its bubble.

## 0.1.43

- `showDriveFiles` shows folders too: a folder reference goes onto the DriveFiles card (a folder row with its
  entries and the link to the drive screen) instead of being dropped; the not-found text names files or folders.

## 0.1.42

- Web: the loading skeleton of a widget surface wraps only a plain text title in the title paragraph; an element
  title (a widget's own headline, e.g. DriveFiles) is rendered as it is — before, a paragraph sat inside a paragraph
  (invalid HTML, a React hydration error while a DriveFiles card loaded).

## 0.1.41

- Forced tool choice for models that reject it: when the model's catalog sets `supportsForcedToolChoice` false
  (mgamadeus/ddd-ai 3.4.8: Claude Opus 5.5, Fable 5.1 answer `tool_choice: "required"` or a named function with a 400),
  every forced choice of the loop (the stall re-drive, a supervisor steer's pin, the `call_tool` delivery pin) goes out
  as `auto`, and the wire tail appends a `[SIGNAL:tool-expected]` line naming the expected tool — earlier messages stay
  untouched. A model that still answers without the tool goes on through the existing stall ladder.
  `ArgusAIAgentConversation::sendsForcedToolChoiceAsNote()`, `RequestSnapshot::$forcedToolChoiceSentAsNote` (the
  composition view shows the line), `AgentModelInputTailEntry::KIND_TOOL_EXPECTED`. `none` and `auto` are unchanged.
- New console command `app:ai-agent:probe-reasoning-replay`: a live probe on OpenRouter with the agent's composers,
  catalog provider filters and key, nothing persisted, nothing booked — scenarios `clean`, `edit-history`,
  `edit-tools`, `edit-system`, `model-switch`, `forced-tool-choice` and (`--wire=responses`) `append-only`, options
  `--drop-block`, `--switch-model`, `--effort`, `--format=json`. Per call: acceptance, provider, reasoning items back
  (signed or encrypted), items replayed, cache share, reported cost. On `--wire=responses` a non-OpenAI model bypasses
  the runtime refusal (`REJECTION_RESPONSES_NEEDS_OPENAI_MODEL`) for the probe only; the runtime refusal is unchanged.
- Requires mgamadeus/ddd-ai ^3.4.8.

## 0.1.40

- Reasoning carry-over per model: the conversation's row, else the model's catalog value
  (`AIModel::$previousReasoningCarryOver`, mgamadeus/ddd-ai 3.4.7), else the per-API default. The catalog value applies
  on the Responses API and on Chat Completions over the direct OpenRouter egress. Kimi K3.1 Pro carries its current
  turn's `reasoning_details`, so it keeps what it decided about pictures that later calls no longer attach.
- A model gets only its own reasoning items back (`RequestSnapshot::holdsWireReasoningItemsFor()` compares the stored
  model name): after a model switch mid-conversation the earlier model's items stay behind. Items stored without a
  model name go back only on the Responses API. The `capped` cut counts only the items a call would send.
- A tool result whose pictures ride on the call ends with a note to write down, in the visible text, what the pictures
  show and what was decided (`ToolCall::buildImagesRideOnlyNowNote()`); the dropped marker replaces it once the
  pictures leave.
- Requires `mgamadeus/ddd-ai` ^3.4.7.

## 0.1.39

- Agent loop: a model call is sent again after a transport failure (cURL timeout, refused or reset connection), a 429
  or 5xx of the router or an empty body — at most twice, after 2 s and 6 s (a 429's `Retry-After` up to 20 s). No tool
  ran, so a retry is safe; a 4xx answer and a model's own refusal are never retried. The claim is heartbeated around
  each wait and a lost claim stops the retries. Retry n rotates the OpenRouter `order` by n positions (the next host is
  asked first; the allowed hosts never widen). Trace event `turn.modelCall.retry`; the request snapshot keeps the
  failed attempts (`modelCallRetriesJson`). When the retries are spent the turn ends as before.
- Each attempt of the agent's model call gets the model's `transportTimeoutSeconds` (ddd-ai 3.4.6; Kimi K3.1 Pro 120 s).
- `app:ai-agent:eval-tools` runs a case once more after an infrastructure error (in process and with `--parallel`), as
  `eval-suite` does; `cURL error 7` and `cURL error 52` count as infrastructure errors in both.
- Requires `mgamadeus/ddd-ai` ^3.4.6.

## 0.1.38

- Web: the package renders the `DriveFiles` widget (the cards `showDriveFiles` writes): one row per file or folder with
  thumbnail or type tile, type · size · date (a folder: its entries), the store's state, open (browser-viewable files;
  an office file opens its PDF twin of the same name, else download only), download, and a link to the drive screen
  (the card's `openLink` when it is an app path or same-origin URL, else the source's `buildDriveScreenHref`). Expired
  card URLs are read again; loading, failed (retry), empty and not-connected states. Built on the agents kit (no
  admin-ui dependency).
- Web: the seam `DriveFileCardsSource` (`readFileCards`, `readFileContent`, optional `buildDriveScreenHref`) with
  `registerDriveFileCardsSource()` (root entry), the types `DriveFileCard`, `DriveFileContent`, `DriveFilesRequest` and
  the pure helpers (`./widgets`); widget doc and preview fixtures. A host that registered its own `DriveFiles` widget
  removes it (the wire name is now the package's).

## 0.1.37

- Evaluation: a judged run applies the case's deterministic `expect` gates after the judge
  (`AgentEvalJudgedRunService::applyDeterministicGatesToRunResult()`): skills, forbidden skills, `tool` with its
  status, argument and result checks, `requiredTools`, `forbiddenTools` (`AgentEvalExpectation::evaluateConversationForJudgedRun()`,
  the single shot's checks; not the last turn's widgets, phrases, task plan, signal sources or call counts), plus the
  application's own gates (`buildHostGateFailures()`, default none). A judge's PASS with a missed gate becomes FAIL, the
  missed gates lead the reasons and are listed under the verdict's `deterministicGateFailures`
  (`AgentEvalJudgeVerdict::applyDeterministicGateFailures()` / `getDeterministicGateFailures()`,
  `AgentEvalSimRunResult::getDeterministicGateFailures()`); an ERROR stays an ERROR. A host's bridge that did this
  itself can go; keep only its own checks in `buildHostGateFailures()`.
- Evaluation: a case names its agent space by reference, `"agentSpaceId": "{{ref:agentSpace:<name>}}"` (also inside any
  other string of a case), resolved without a scope by the host's `AgentEvalRunHooksService::resolveAgentSpaceReference()`
  (default null). An unresolved reference fails the case with its message and never falls back to the run's space;
  numbers keep working. New `AgentEvalCase::getAgentSpaceReferenceName()`, `AgentEvalRunHooksService::resolveAgentSpaceIdOfCase()`.

## 0.1.36

- Agent wire: `parallel_tool_calls` goes out with tools only when the model's catalog entry does not say
  `supportsParallelToolCalls: false` (`AgentWireRequestParameters::$sendsParallelToolCalls`, all three composers: Chat
  Completions, Responses, Responses server state). Fixes Kimi K3.1 Pro over OpenRouter: with its `requireParameters` no
  endpoint accepted the parameter (404 "No endpoints found that can handle the requested parameters").
- Requires mgamadeus/ddd-ai ^3.4.5.

## 0.1.35

- Prompt cache: Claude models (catalog mode `anthropicCacheControl`, ddd-ai 3.4.4) get Anthropic `cache_control:
  {type: ephemeral}` markers on the agent's Chat Completions call over the direct OpenRouter egress — after the base
  prompt, before the loaded skills, at the end of the system content and on the newest stable message (at most four).
  Writes are booked at 1.25x the input rate. Before, nothing marked them and a Claude model cached nothing.
- Model switch: an append-only conversation whose owner picks a Chat Completions model (Opus, Kimi) after its first
  model call moves to `systemPromptSwap` at the next run start instead of failing every call with "Agent wire
  configuration rejected (chatCompletions + conversationAppend)". One-way; a strategy named at the creation of a Chat
  Completions conversation is still refused.
- Console chat: a new conversation is stamped from the model of `--model` / `--tier`, not from the agentic default, so
  a Chat Completions `--model` no longer needs `--skill-loading-strategy=systemPromptSwap`.
- Requires `mgamadeus/ddd-ai` ^3.4.4.

## 0.1.34

- Sandbox: `AgentSandboxCommand::$status` (`RUNNING`, `EXITED`, `KILLED`, `TIMED_OUT`; null = unknown). The host fills
  it from the runner: an exit code → EXITED, a killed job → KILLED, the runner's timeout → TIMED_OUT, else RUNNING. A
  screen never reads null as running.
- Sandbox audit: `reason` and `outcomeDetail` of `AgentSandboxActionLogEntries` (VARCHAR(1000)) get no index any more
  (the generator indexes every scalar column by default). A host that already created the table drops the two indexes
  through the schema diff.

## 0.1.33

- Drive file cards: `AgentDriveFile::$openLink` (nullable) — where the host's admin screen shows the file or folder.
  A drive store sets it through `setOpenLinkIfSafe()` (a host path `/…` or an absolute http(s) URL; `javascript:`,
  `data:` and protocol-relative links are dropped). It reaches the card component through the `DriveFiles` hydration
  read (`POST …/driveFiles/cards` → `files.elements[].openLink`); the model's file lines do not carry it. A renderer
  uses it as the card's "open" link and falls back to its own behaviour when it is null.

## 0.1.32

- Added: sandbox administration for an admin screen of the agent workspaces. Host contract
  `AgentSandboxAdministration` (tag `ai.agent_sandbox_administration`): workspaces with session state, disk and
  memory, mount mode, runner instance and owner; read-only file tree and file bytes; unsaved drive changes; processes;
  recent commands; session log; start, offload, kill a job, end, reset. The runner and the workspaces stay the host's.
- Added: `AgentSandboxesAdminService` (conversation count and live-turn flag per workspace from the conversation
  table, the space's root conversations, a 409 guard while a turn runs unless forced with a reason, the reset
  confirmation with the workspace's name, file delivery under the untrusted-content rules: raster images and PDF
  inline, UTF-8 text as text/plain with a 256 KB preview cut, anything else an opaque attachment, 25 MB at most) and
  `AgentSandboxesControllerTrait` (`GET /list`, `/{workspaceKey}`, `/files`, `/files/content`, `/driveStatus`,
  `/processes`, `/commands`, `/events`, `/conversations`, `/actions`; `POST /start`, `/offload`, `/jobs/{jobId}/kill`,
  `/end`, `/reset`; access hook `throwForbiddenUnlessSandboxesAccessAllowed()`).
- Added: the audit `AgentSandboxActionLogEntry` (new table `AgentSandboxActionLogEntries`; every action, refusals and
  failures included; readable by a reader of every agent row). Without the table the actions run and the audit goes
  to the error log.
- Migration: optional. A host that wants the screen implements `AgentSandboxAdministration`, mounts the trait on an
  operator-only controller and creates the table through the schema diff.

## 0.1.31

- Security: per-call content trust. A tool's response DTO may implement `MCPToolResultDeclaresContentTrust`;
  `isTrustedContent()` decides for that call and wins over the tool's static rule in both directions (a tool that serves
  staff-only and partner texts needs no split). A throw counts as untrusted. The verdict is stored on the call
  (`ToolCall::$returnedUntrustedContent`, hidden; also in fork outcomes and run recordings) and read by the write gate
  after an untrusted read, the reading rule of the platform note and the tool-result screen. Calls stored before it
  keep the static rule; no schema change (the call lives in the message's JSON).

## 0.1.30

- Security: tools are untrusted by default. A READ or WORKSPACE tool returns untrusted content unless it (or its
  package) declares `MCPToolReturnsTrustedContent` or is listed in the new `config/app/AI/untrustedContent.php`
  (`trustedPackages`, `trustedTools`); `MCPToolReturnsUntrustedContent` still forces untrusted, WRITE / DELETE tools
  are trusted (`MCPToolContentTrust`). An untrusted result without marks carries
  `ToolResultUntrustedSegments::UNMARKED_READING_RULE` on its platform note and is read by the tool-result screen.
  The package's memories, schedules, run reports and mandates packages declare trust.
- Security: the executor holds a WRITE or DELETE (or a tool without a declared effect) once an untrusted tool completed
  in the same owner turn: it runs only after the owner-consent check finds the owner's yes to this step
  (`writeAfterUntrustedRead: ownerConsent`, the default; refusal kind `OWNER_CONFIRMATION_REQUIRED`), or is refused for
  the turn (`refuse`, kind `WRITE_AFTER_UNTRUSTED_READ`). No value switches it off. Tools with their own consent step
  (`MCPToolRequiresOwnerConfirmation`), READ and WORKSPACE calls, the owner's MCP client and the eval harness are not
  held. Without the site `ownerConsent` on, in a scheduled run and in a delegated sub-task the held write is refused.
- Security: `MCPToolFetchesModelChosenUrl` (on `getWebPageAsMarkdown`, `extractInformationFromWebPagesWithFastLLM`,
  `getWebPageAsScreenshot`): after an untrusted read of the turn a URL whose query string or fragment appears in no
  owner message and no tool result is refused (`UNSEEN_URL_AFTER_UNTRUSTED_READ`).
- Security: `PageScreenshot` decodes only PNG through Core's `RasterImageDecoder` (coder pinned, limits).
- Security: the HTML transcript renders no script (no CDN marked.js, escaped Markdown, a CSP without scripts).
- Security: `WidgetBlock::getFields()` deep-copies with `allowed_classes: [stdClass]`.
- Web: images in model Markdown load only from the page's origin, inline raster data and
  `AgentsEnvironment.markdownImageHosts`; any other source renders as a link.
- Added: the sink scan (`tests/Untrusted/UntrustedSinkScanTest.php`, `config/untrusted-sinks-allowlist.php`).
- Requires `mgamadeus/ddd` ^3.40.0.
- Migration: declare `MCPToolReturnsTrustedContent` on the host packages that read only the platform's own records
  (or list them in `untrustedContent.php`); keep tools that read third-party text (customer messages, mail, files,
  pages) untrusted; switch the decision site `ownerConsent` on if the agent writes after such reads, or choose
  `refuse`. Hosts with a markdown image CDN set `markdownImageHosts`.

## 0.1.29

- Added: `AgentEvalWorkspaceParticipant` (tag `ai.agent_eval_workspace_participant`): a module that keeps state per
  eval workspace prepares and removes it beside the application's workspace hooks; the runner calls every participant
  through `AgentEvalWorkspaceParticipantsService` (a throw is one `[ERROR]` line, the others still run).
- Added: `app:ai-agent:eval-tools --keepWorkspace` (forwarded to child processes, `AgentEvalRunOptions::$keepsWorkspace`):
  no workspace clean-up after a case, the report names the kept key.

## 0.1.28

- Fixed: the console chat and the eval runner run the host's settled-turn listeners after their inline turn
  (`AIConversationTurnEndFollowUpsService::runSettledTurnListenersAfterInlineTurn()`, root conversations only), as a
  worker's release does; before, a turn-end hook (e.g. a workspace commit) never ran there.
- Fixed: a settled-turn listener that throws is logged as a warning with its class (before: swallowed silently); a
  failed memory curation and a failed purpose close are traced.
- Fixed: `AgentToolCallProvenance::$agentSpaceId` falls back to the conversation's stored `agentSpaceId` when the
  space object did not load (before: null although the conversation has a space).

## 0.1.27

- Added: `AgentDriveFileCardsTrait` (`POST …/driveFiles/cards {store, references, widgetSelfHydrationKey}`), the
  hydration read of the `DriveFiles` widget: the facts of the block's files from their drive store under the viewer's
  rights. A host mounts it on its chat controller; content URLs come from the store's own REST side.

## 0.1.26

- Added: the drive mount contract. A file store module implements `AgentDriveStore` (tag `ai.agent_drive_store`,
  autoconfigured): `findMountsOfCall()`, `listDriveFiles()`, `findDriveFiles()`, `saveDriveFiles()`,
  `findFileCards()`. `AgentDriveStoresService` collects the stores and resolves a file argument against the mounts of
  a call: `drivespaces/<name>/…` is that mount, `drive/<id>` a reference, any other path (also `/workspace/…`) the
  agent space's own drive; `..` and unknown mounts are refused with the mounts named. `AgentDriveMount` carries the
  mode (`READ_ONLY`, `READ_WRITE`, `WRITE_WITH_CONSENT`; an outward-visible space is never written without consent)
  and its state-note line.
- Added: the tool package `agent.drive` (`AgentDriveMCPPackage`, available while a store is installed):
  `listDriveFiles` (READ, parallel-safe; `drivespaces` lists the mounts), `saveDriveFile` (WRITE, server-side copy;
  a read-only mount refuses, a consent mount asks the owner through `OwnerConsentsService`) and `showDriveFiles`
  (writes the file card). The store module's skill binds the package.
- Added: the file card widget `DriveFiles` (`DriveFilesConversationWidget`): the block holds the store and the file
  references only; a renderer hydrates them through `AgentDriveStoresService::findFileCards()` under the viewer's
  rights. Hosts with a chat renderer add the component for `DriveFiles`.

## 0.1.25

- Fixed: the eval workspace key of a repeated case names its real repetition: `app:ai-agent:eval-tools --repeat`
  passes `--repetition=<k>` to each child process; a run that counts no repetitions has no `-r<k>` segment
  (`eval-<case>-<runToken>`, `AgentEvalRunnerService::buildWorkspaceKeyOfCase(string, ?int, string)`). Before,
  every repetition showed `r1`.

## 0.1.24

- Fixed: the harness calls of an evaluation (restore, `stateAfterRun` read and cleanup, the judge's end-state reads)
  run in the workspace of the case run: `AgentToolCallProvenance::forEvalHarness()` takes the conversation's
  `runSettings->workspaceKey` (or an explicit `$workspaceKey`), and `AgentEvalRunnerService::buildHarnessToolCallProvenance()`
  passes the run's key. A case with a `restore` block prepares its workspace before the restore bracket opens. Before,
  these calls had no key and the tool fell back to the space's own workspace.

## 0.1.23

- Added: `MCPToolEffect::WORKSPACE` — "changes only the agent's private working area of this agent space — nothing and
  no one else". A tool has it only when all four conditions of the constant's docblock hold (only the space's
  workspace; no domain data; nothing visible to anyone; never another workspace), enforced by the sandbox, not by the
  tool. Order of harm READ < WORKSPACE < WRITE < DELETE (`getHarmRank()`); `changesOnlyTheWorkspace()`,
  `changesNoSharedData()` (READ or WORKSPACE) on the effect, the catalog and the stored tool call.
- How the package treats a WORKSPACE call: no owner confirmation, no mandate, never outward; not in the write ledger,
  not under the identical-write refusal, not a supervised write, not a change of the record a task plan reads; its own
  bucket in the paused-turn record and its own count `workspaceChanges` in the run counts; it ends the per-turn reuse
  of every earlier read and the scan for an earlier final refusal; it never forks (one conversation, one workspace and
  shell) and does not hold forked reads back; the eval read-only gate and the environment-wide write lock let it
  through, and a case whose expected tools are READ / WORKSPACE only is a read case; MCP hints readOnly, destructive
  and idempotent false; the judge trace marks it `(WORKSPACE — …)`. `EveryToolEffectIsHandledTest` fails for a new
  effect value until each reader is decided.
- Added: `AgentToolCallProvenance::$workspaceKey` from `RunSettings::$workspaceKey` (inherited by delegation
  children; null = the tool derives its own key). An evaluation gives each case run its own workspace
  `eval-<case>-r<repetition>-<runToken>`, prepares it with the case's `scratchFiles` (`{path, content}` or
  `{path, contentBase64}`) through `AgentEvalRunHooksService::prepareWorkspaceOfCase()` and removes it after the
  case through `cleanUpWorkspaceOfCase()` (both no-ops by default; the package owns no workspace store).

## 0.1.22

- Fixed: `app:ai-agent:eval-tools` and `app:ai-agent:eval-suite` without `--model` / `--models` run a case with
  `requiresVision` when the agent's default model can see images: the model the egress would take (its `modelScope`,
  else its `defaultAIModelName`, the application's `ArgusAIAgentConversation` when it has one;
  `AgentEvalConsoleCommand::findDefaultAgentModelName()`). Before, every such case was skipped as if the default model
  were blind. The tools command resolves it only when a case of the run needs vision.

## 0.1.21

- Fixed: a tool parameter typed on a package service (`AIWebSearchService`, `WebSearchHostService`, any other)
  receives the application's same-path subclass, as `DDDService::getService()` does
  (`MCPToolExecutorService::resolveFromContainer()` resolves the application's class name first); before, it got the
  package's instance unless the application aliased the package id to its class. Such aliases can stay.
- Added: `WebPageMarkdown::buildSentenceAfterSearchResultsPageRefusal()`, the application's sentence after the refusal
  of a search engine's results page (empty by default).

## 0.1.20

- Changed: the marker of a tool result whose pictures no longer ride with the model call says that the model saw them
  in an earlier step and that what it wrote about them stays its record (`ToolCall::buildImagesDroppedFromWireMarker()`);
  only details it never wrote down are barred. Before, "Do not describe or rely on what they showed" made models take
  back their own correct descriptions of earlier screenshot parts in the final summary.
- Changed: the skill `Web.PageView` asks for one part per step, never two parts in the same message.

## 0.1.19

Every application with web research should adopt this release.

- Fixed: a search, a page read and a page reading return the application's same-path subclass of `WebSearchHitList`,
  `WebPageMarkdown` and `WebPageExtract` (before, only a refused page did; a crawled page was always the package's
  class), and a conversation summary the application's `AIConversationSummary`: the package maps every Argus
  repository with `toEntity(entityInstance: X::newInstance())` (`ArgusRepositoriesAreNeverMappedOntoThePackageClassTest`).
- Added: `WebSearchHostService::findMaxSearchCallsPerTurn(AgentToolCallProvenance): ?int`, the search limit of a
  call's turn set by the application (0 = no limit, null = `AI.webSearch.hits.maxSearchCallsPerTurn`).
- Added: `WebPageMarkdown::buildPlatformLinesBelowHeader()`, the application's own lines between a read page's header
  and its marked text (empty by default), and `WebPageMarkdown::buildContinueWithoutThePageSentence()`, the sentence
  after a failed read, for a same-path subclass.

## 0.1.18

- Fixed: a description of a screenshot part that starts with the `{preCallMessage}` marker counts as a description
  when it carries at least 120 characters beyond the marker
  (`WebPageScreenshotPartsOfTheCurrentTurn::MIN_DESCRIPTION_CHARS_OF_A_STATUS_LINE`); before, the next part was
  refused as if only a status line had been written.
- Changed: the screenshot line of a page's last part names the earlier parts shown in the turn and says that the
  model's descriptions of them are its record for the summary (`PageScreenshotPart::buildEarlierDescriptionsAreTheRecordText()`).

## 0.1.17

- Changed: while the picture budget holds less than two parts, the screenshot line of every part but the last says
  that the next message first writes what this part shows and may then open the next one, and that the descriptions
  stay the model's record; before, the model learned the rule only from a refused call (one extra call per read).
- Changed: the skill `Web.PageView` tells the model never to take back its own descriptions of earlier parts because
  their pictures left its view.
- Fixed: a screenshot call that throws before any answer (the client's timeout after `timeoutSeconds.screenshot`, a
  connection error) is a failed render with the reason `TIMEOUT` ("retry once") or `FETCH_FAILED`; before, it reached
  the model as an internal error with an issue id.

## 0.1.16

- Changed: while the picture budget (`maxToolCallImagesOnWire`) holds less than two screenshot parts, the default,
  `getWebPageAsScreenshot` refuses the next part, before any render, as long as no assistant text followed the
  previous screenshot of the turn ("describe part N of … first"); a status line does not count, a failed render does
  not block. Before, models opened part 1, 2 and 3 back to back and described only the last one, whose pictures were
  the only ones left in view. A budget of four pictures or more lets every call through.
- Added: the judge of a judged evaluation run reads, under each `delegate` call, the tool calls of every sub-agent it
  started, results shortened to 1,500 characters, nested up to three levels (`AgentEvalJudgeTraceMessageLines`);
  before, it saw only the children's verdicts and counted the sources and dates they found as invented.

## 0.1.15

- Changed: a page read refused because the address cannot be resolved, is blocked or is no public address no longer
  points the model to `extractInformationFromWebPagesWithFastLLM` with the same URL; the text says that no tool can
  reach it and to go on with a search (`WebPageMarkdown::REASONS_NO_TOOL_CAN_REACH`). A timeout or a fetch failure
  keeps the hint.

## 0.1.14

- Changed: a screenshot of a host without a DNS record is refused as `UNRESOLVABLE` ("cannot be resolved (check the
  spelling)"), a private or reserved address as `NOT_PUBLIC`; both decided by `PublicUrlGuard::classifyHost()` like a
  page read (before, a typo host was told it was not a public web address).
- Added: `WebFetchFailureReasons`, ONE sentence with the next move per reason a page read and a screenshot share:
  `UNRESOLVABLE`, `UNSUPPORTED_TYPE` (a screenshot points to `getWebPageAsMarkdown`), `TOO_LARGE` (retrying is
  pointless), `NO_CONTENT` and `TOO_MANY_REDIRECTS` (no retry); before, the last three got the generic sentence.
- Fixed: the screenshot line's "Already shown in this turn" counts every earlier call of the turn that did not fail or
  get refused and leaves out only the calling row by its tool call id (before, a call whose completion had not reached
  the read set was missing); the device is compared in any letter case.
- Added: the screenshot line names the conversation's picture budget (`maxToolCallImagesOnWire`, default 2 = one
  part): the pictures of a part are gone once the next part is open, so the model describes each part first. A host
  that needs the previous part beside the current one raises `maxToolCallImagesOnWire` to 4.
- Added: time phrases in English, German, French, Spanish and Italian (`today`, `this week`, `this month`, "past N
  days") and the name of the current month (in the conversation's zone; `may`, `mars`, `mai` only with the year)
  become the search's `recency`, with the note; another month with its year gets the `recency` note.
- Added: a page read that fails (`FETCH_FAILED`) for a URL no search of the conversation listed names the listed pages
  of the same site (`WebPageUrlCorrection::buildUnlistedFailedUrlNote()`); two or more pages read get a note to name
  every page's URL in the answer. The skill says never to build a URL.
- Evaluation template: visual cases judge the calls and the descriptions, never the pictures (the judge does not see
  them); new cases `websearch.read-url.typo-host`, `websearch.read-url.two-pages-named`,
  `websearch.urls.no-built-paths`; `websearch.recency.news` counts month names and phrases in any language as FAIL.

## 0.1.13

- Changed: a failed screenshot names its reason. The reason of the answer (`reason` at the top, under `data` or under
  `error`) or of a rejected call (`TIMEOUT`, else `FETCH_FAILED`) lands on `PageScreenshot::$failureReason`, and the
  model reads one sentence with the next move per reason (`BOT_CHALLENGE` → read the text with `getWebPageAsMarkdown`,
  `TIMEOUT` → one retry, `BLOCKED` / `UNRESOLVABLE` / `NOT_PUBLIC` → no retry, `CAPABILITY_OFF`); an unknown code is
  named in the generic sentence (`PageScreenshotPart::buildFailureText()`). A failed render is never cached. The page
  read has its own sentence for `UNSUPPORTED_TYPE` and names an unknown reason.
- Changed: screenshots are cached in memory only (before: memory and database, megabytes of base64 per row). New key
  `AI.webSearch.cacheInDatabase.<capability>` (true / false) adds or drops the database cache per capability; the
  package file sets `screenshot: false`. Rows already in the database are no longer read and expire.
- Added: the screenshot line names the parts of the same page and device already shown in the turn, and a reopened
  part gets a note (`WebPageScreenshotPartsOfTheCurrentTurn`, no refusal); the tool and `Web.PageView` ask to describe
  each part before the next and never to reopen one.
- Changed: the operator note of `searchTheWeb` is a correction that names the argument (`Next time pass "domains":
  ["example.com"] instead of site:`); two new examples show `domains` and `recency`.
- Added: a query naming an unambiguous time window (`today`, `this week`, `this month` and the "past/last N days"
  forms) without `recency` is searched with that window and the note says so; news words without a window get a note
  suggesting `recency` and dated items (a year alone does not).
- Changed: every fact of a reading ends with its `[source: site, Reading n]`, and the readings close with the rule to
  keep that source next to the figure; the answer instruction asks for a Source column filled in every table row and
  "not stated" only for a value no page lists. `Web.Search` says the same.
- Evaluation template: `websearch.tables.source-per-row`, `webpageview.screenshot.failure-reason`,
  `webpageview.screenshot.parts-no-reopen`; `websearch.recency.news` fails an undated item and a time phrase in the
  query; `websearch.queries.no-operators` fails a `site:` repeated after the correction.

## 0.1.12

- Changed: `searchTheWeb` searches plain words. Search operators in a query (`site:`, `-site:`, `OR`, `AND`, `|`,
  quotes, `intitle:`-style prefixes) are removed; the `site:` domains become the call's `domains` when the call has
  none and every query named a site, otherwise they are dropped. The platform note says what changed, and notes a
  query of more than 10 words (`WebSearchQueryNormalization`, `WebSearchCallQueries`).
- Added: at most `AI.webSearch.hits.maxSearchCallsPerTurn` `searchTheWeb` calls per turn (default 3, 0 = no limit); a
  further call is refused with the next move. Failed and duplicate calls do not count, outside the agent loop there is
  no limit (`ToolCallsOfTheCurrentTurn`).
- Changed: `getWebPageAsMarkdown` and `extractInformationFromWebPagesWithFastLLM` read a URL copied inexactly from a
  search result of the conversation (percent escapes decoded, transliterated, another case) as the listed URL when
  exactly one listed hit matches, and say so in the platform note (`WebPageUrlCorrection`).
- Changed: the wording of `Web.Search`, `searchTheWeb` and the answer instruction: plain-word queries, sites in
  `domains`, a time window in `recency` instead of the query text, a source next to every figure (one per table row),
  no silently dropped entries, derived figures only from values of one source, URLs copied character for character.
- The evaluation template has four new cases (operators, the search limit, exact URLs, derived figures) and a sharper
  recency case. Rerun the evaluation of `Web.Search` after the update.

## 0.1.11

- Changed: the search fee follows the endpoint's answer. A search answer with `data.cached: true` (the endpoint's own
  cache answered) books nothing; a `data.billing.feeUsd` is booked for that request instead of
  `hits.feeUsdPerRequestByMode`. Both fields are optional; an answer without them is booked as before
  (`WebSearchHostService::bookWebSearchRequests()` receives only the real requests and their summed fee).

## 0.1.10

- Added: the package's web research, off by default (`config/app/AI/webSearch.php`, `'enabled' => false`, no
  endpoints): while it is off nothing is offered and no request is made. Switched on, with an endpoint per capability
  (`endpoints.search`, `endpoints.urlToMarkdown`, `endpoints.screenshot`; relative = through the Argus gateway, absolute
  = direct; a capability can be switched off with `capabilities.*`):
  - `WebSearchMCPPackage` (id `agent.webSearch`, skill `Web.Search`): `searchTheWeb` (up to 5 queries in parallel,
    one merged page list round robin over the queries, the engine's excerpts, a render budget),
    `extractInformationFromWebPagesWithFastLLM` (a fast model reads up to 10 pages for one task; every quote checked
    against the page and the excerpts, an unverified quote marked, never dropped; an uncrawlable page read from its
    search excerpt), `getWebPageAsMarkdown` (up to 5 pages in parallel; refused before any crawl: a search engine's
    results page, a host without DNS record, a private address);
  - `WebPageScreenshotMCPPackage` (id `agent.webPageScreenshot`, sub-skill `Web.PageView`, needs vision):
    `getWebPageAsScreenshot` (parts of two 2000-px slices, desktop and mobile, the re-render of a page that blocks
    scrolling; rendered only for a public host; without Imagick the first part is the whole capture);
  - the tools read untrusted content, the research results are never stubbed by the budget, every tool has 45 s;
  - `AIWebSearchService` (search, readings, page reads, screenshot parts) and the host hooks `WebSearchHostService`
    (same-path subclass): default country, payer (`WebSearchPayer`; default the signed-in account), spend check,
    booking of the search fee (`hits.feeUsdPerRequestByMode` per real request, never a cache hit or a failure), a line
    after a page read. The readings are billed by ddd-ai's usage recorder to the payer (usage scope
    `AI.WEB_PAGE.EXTRACT`, prompt `AI.WebSearch.PageExtract`, model `reading.modelName`);
  - every limit, cache lifetime and timeout in the configuration; both skills bound in the package's default
    `skillPackages.php` (offered only while their packages are available).
- Host duty when switching it on: ship `webSearch.php` with `enabled` and the endpoints, list
  `AISkill::WEB_SEARCH => ['agent.webSearch']` and `AISkill::WEB_PAGE_VIEW => ['agent.webPageScreenshot']` when the
  host's `skillPackages.php` replaces the package's, override `WebSearchHostService` for country, payer and booking.
  Reference: `ddd-module-agents-tools-specialist`, `references/web-research.md`.

## 0.1.9

- Added: the package's memory store, the default implementation behind `AgentMemoriesService`. Off by default
  (`config/app/AI/agentMemory.php`, `'enabled' => false`): while it is off nothing changes, every memory question is
  answered "no memory" and the new table is never read. Switched on (after the table `AgentMemories` exists):
  - `AgentMemory` / `AgentMemories` (`AgentMemoryStoreService`): durable directives, facts and solutions of the owner,
    per account and optionally per space, bitemporal (a newer value closes the old row), one current row per scope and
    content (unique generated `virtualCurrentDedupKey`), embedded for the similarity search (a text match without an
    embedding provider), read rights through `AgentAccountRightsService`;
  - the standing directives of the scope in every model input, `findSimilarMemories()` for the search service, the
    table in the embedding repair;
  - the curation after an owner turn (`AgentMemoryCurateMessage` on the compaction transport, `AgentMemoryCurationService`):
    extraction and reconciliation model calls (prompts `AI.Memory.Extract`, `AI.Memory.Reconcile`,
    `AI.Memory.ReconcileResolution`, `AI.Memory.ConflictExcerpt`; usage scopes `AI.MEMORY.EXTRACT.CANDIDATES`,
    `AI.MEMORY.RECONCILE.DECIDE`), a `MEMORY_CONFLICT` signal for a contradiction the owner has to settle;
    `AI.agentMemory.structuredHomes` names the host's data that is never copied into a memory;
  - the tools `AgentMemoriesMCPPackage` (id `agent.memories`, skill `Memory.Management`, bound in the package's default
    `skillPackages.php`): `listMemories`, `searchMemory`, `remember`, `updateMemory`, `forget`.
- Added: `MCPPackage::isAvailable()` (default true). The skill binding leaves an unavailable package out, and a skill
  bound to unavailable packages only disappears (`AISkill::withoutUnavailablePackages()`). The memory tools are
  unavailable while the store is off.
- Host duty when switching the store on: create the table (schema diff), route `AgentMemoryCurateMessage` like the
  other compaction messages (the routing list of `AgentMessengerTransports` names it), and list
  `AISkill::MEMORY_MANAGEMENT => ['agent.memories']` when the host's `skillPackages.php` replaces the package's.

## 0.1.8

- Added: the schedule tools `AgentTaskSchedulesMCPPackage` (id `agent.taskSchedules`) with the skill `Assistant.Tasks`
  (`config/app/AI/Skills/Assistant/Tasks.md`): `startBackgroundTask`, `scheduleOneTimeTask`,
  `createRecurringTaskSchedule`, `getTaskSchedules`, `getTaskRuns`, `getTaskRunReport`, `pauseTaskSchedule`,
  `resumeTaskSchedule`, `deleteTaskSchedule`, `updateTaskSchedule` over the package's schedules. Scope: the space the
  call names (filled from the conversation), else the account's schedules without a space. They apply every limit of
  `AIConversationScheduleCreationLimits` and refuse schedule administration inside a scheduled run; the names match the
  tools `config/app/AI/Purposes/Schedule/Run.md` refers to. A host offers them by listing
  `AISkill::ASSISTANT_TASKS => ['agent.taskSchedules']` in its `skillPackages.php` (its file replaces the package's);
  the budget note, the cost note and the widget of the task list (`TASK_LIST_WIDGET_TYPE`) are override points of a
  same-path subclass. Nothing changes for a host that does not bind it.

## 0.1.7

- Changed: requires `mgamadeus/ddd` ^3.37.2. From that Core version on, a value object stored in a column or read from
  JSON is built as the application's class of it, so an application's same-path subclass of `RunSettings` (and of every
  other package value object) keeps its own fields on load, as the `RunSettings` docblock states.
- Fixed (npm): the composer's action row fills only the package's own icons (the send button) with the text colour; a
  host's control in `actionsSlot`, `actionsSlotStart` or `sendReplacementWhenEmpty` keeps its icon as drawn (an outline
  icon no longer turns into a solid blob).
- Added (npm): `onMessageSent(messageText)` on `AgentChat` / `AgentChatController`, once for every accepted send (typed,
  a widget's send, the opening request's message), e.g. for a send animation. A refused send reports nothing.
- Added (npm): `AssistantAvatar` on `AgentChat` / `AgentChatController` (`AgentChatAssistantAvatarProps`: the message):
  shown left of each assistant message, at its top; `renderAssistantAvatar` on `ConversationView`,
  `ConversationViewBody` and `ConversationMessageList`.
- Added (npm): `AgentChatActivityProps.chatStore` (read more of the chat's state with `useAgentsChatSelector`) and
  `AgentChatActivityProps.unansweredWidgetMessageId` (the newest widget-carrying assistant message the owner has not
  answered, else null); `findUnansweredWidgetMessageId` is exported from `./chat`. Whether that widget waits for an
  answer (`AWAITS_OWNER_INPUT`) is not on the wire.
- Documented (npm): `ThinkingIndicator` is always mounted, also between turns — the resting avatar under the last
  message — one instance across a turn's start and end.

## 0.1.6

- Added: `AIConversationMessage::getMessageTextWithoutWidgetBlocks()`, the words of a message without its widget
  blocks ('' for an unset text and for a message of nothing but blocks). The owner texts, the owner words and the owner
  turn lines of `AIConversationOwnerTurns`, the quoted-fact extraction (`extractQuotedUserFacts()`) and the summary input
  (`AgentModelInput::buildSummaryContent()`, USER rows only) read the owner's prose through it: an input widget in the
  owner's message (uploaded images, a marked website element) carries ids and JSON keys, not words. A newest owner row
  of nothing but blocks yields no owner words, is no owner text and no turn line. The chat wire and the compaction
  transcript keep the raw text.
- Added (npm): a registry entry may declare `sentInCustomerMessage` (the customer's own message may carry its fence
  without a `composerComponent`) and `customerMessagePlacement` (`insideBubble` by default, or `aboveBubble`: the echo
  stands on its own above the bubble; a message of nothing but such widgets shows no bubble). The user bubble extracts
  every fence whose entry declares `composerComponent` or `sentInCustomerMessage`.
- Added (npm): `hostComposerWidgets` on `ConversationMessageInput`, `ConversationView`, `ConversationViewBody` and
  `AgentChatController`: composer widgets the host holds outside the widget context (an app's attached-files widget),
  sent after the context's own in the message text and in `options.composerWidgets`. `outgoingAttachmentImages` /
  `attachments` are unchanged. `AgentChatController` reports a send that carried composer widgets through
  `onComposerWidgetsSent(composerWidgets)`.
- Added (npm): `attachLibraryFilesToComposer` on the widget callbacks and `AgentChatController` (a canvas puts
  media-library files into the composer, `ComposerLibraryFile`); optional `focusInput` on
  `ConversationMessageInputHandle` and `focusComposer` on `AgentChatComposerHandle`.
- Added (npm): `CanvasBehaviour.titleBesideCloseButton`: the canvas pane offers its close row as the body's title slot
  (`CanvasTitleSlotContext` / `useCanvasTitleSlot`).
- Added (npm): `inputContainerClassName` and the opt-in `compactStackedSlots` on `ConversationMessageInput` (the compact
  form puts the top slot on a row of its own and the start cluster left of the text; without it the compact layout is
  unchanged); `inputActionsSlotStart`, `fileDropHintSlot` and `chatAreaProps` on `AgentChatController`;
  `fileDropHintSlot` on `ConversationView` / `ConversationViewBody`.
- Fixed (npm): a send the host refuses (`onSendMessage` returns `false`: a closed conversation, a start still in
  flight) no longer clears the input's text and composer widgets.
- Changed (npm): the attachment preview card's remove control is a bin inside the card's corner, always in view, and
  only when the host passes `onRemove` (a read-only card otherwise); the preview strip's gap and padding are tighter; `ConversationMessageInputAttachmentPreviewWidget`
  is exported from `./chat`.
- Added (npm): `./renderer` exports `ConversationComposerWidget`, `ComposerLibraryFile`, `CanvasTitleSlotContext`,
  `useCanvasTitleSlot`, `buildComposerWidgetFenceBlock`, `buildMessageTextWithComposerWidgets` and
  `splitComposerWidgetFencesFromMessageText`.

## 0.1.5

- Fixed: `expect.argsContain` fixtures written before 0.1.3 match again. A text under a named key that starts with that
  key as JSON (`"dateFrom":"2026-01` under `dateFrom`) is searched in the whole arguments, as it was written; any other
  text reads only the named argument (`AgentEvalExpectation::isNeedleWrittenForTheWholeArguments()`). Migration
  (optional): write the value only (`"dateFrom": ["2026-01"]`). Since 0.1.3 such a fixture failed without a hint.
- Fixed (npm): the "Sent" and "Declined" labels of an answered Form are in `i18n-keys.json` (the manifest scan reads
  the string child of `DonePill`), so a host can translate them.

## 0.1.4

- **Changed default:** a NEW append-only conversation gets the tool delivery `callTool` and the cold-skill drop at the
  compaction rebase (`ContextRetentionSettings::$toolDelivery` `native` | `callTool` | `callToolJsonSchema`,
  `$dropColdSkillsAtCompactionRebase`; config `AI.contextRetention.toolDelivery` / `dropColdSkillsAtCompactionRebase`).
  Under `callTool` the top-level tools are the meta tools plus one fixed `call_tool(name, arguments)`; a loaded skill's
  tools are listed as text in its load and a `call_tool` call is unwrapped into the named tool right after the answer,
  so the tool list never changes inside a conversation (measured: mid-turn skill-load calls 42 % → 77 % cached,
  uncached input −20 %). Both settings are stamped at creation: a conversation stored before this release keeps the
  native delivery and unloads nothing. A host keeps the old behaviour for new conversations with `'toolDelivery' =>
  'native'` and `'dropColdSkillsAtCompactionRebase' => false` in its `config/app/AI/contextRetention.php` (a host that
  replaces that file without the keys gets the built-in defaults `callTool` / `true`). Console and eval arms
  `--tool-delivery`, `--drop-cold-skills-at-rebase`; `RequestSnapshot::$toolDelivery` records each call; the working
  state records `coldCapabilityDrops` (`AgentColdCapabilityDrops` of `AgentColdCapabilityDrop`). Details: the context skill, `tool-delivery-and-cold-skill-drop.md`.
- Changed: the turn-initiator SQL condition is shared (`AIConversationMessagesService::buildTurnInitiatorPredicateSql()`
  and `buildTurnInitiatorPredicateParameters()`).
- Fixed (web): an `Image` or `ImageGroup` inside a `Pressable` opens no lightbox and the group shows no carousel
  controls; the whole tile is the one control (before, a click on the picture opened it full size and React logged a
  button inside a button).
- Fixed (web): a `Pressable` whose content is not one `Surface` pads its content by the card padding, so text no longer
  touches the selection ring or gets cut by the rounded corner.
- Added (web): the colour token `--agents-fg-on-cta`, the text on the `--agents-fg-cta` fill (primary and shimmer
  buttons). White by default in both themes, so nothing changes until a host sets it; a host whose CTA colour is light
  in dark mode sets it dark there.
- Fixed (web): a Form in a reply that can no longer send keeps its options inert, not only its submit; after a reload a
  sent or declined Form shows "Sent" / "Declined" again, read from its signal row in the transcript
  (`findStoredFormAnswer()`; `ConversationWidgetTranscriptMessage` gains the optional `messageType` and `message`).

## 0.1.3

- Fixed: the judge's write ledger (`AIConversationAuditRenderService::isReadOnlyToolName()`) reads the tool's declared
  `MCPToolEffect` instead of its name prefix, so a READ named `preview…` is no longer shown as a write. A name the effect
  catalog does not know still falls back to the read prefixes.
- **Schema:** one new nullable JSON column, `AIConversationMessages.clientContext` (the client context below). Apply it
  with the schema diff before the release goes live: the context load selects it and owner rows write it. A host with a
  table prefix or `tableNames` regenerates its models as always.
- Added: the client-context contract. A start, a posted message and an injected signal take the body field
  `clientContext` (`AgentClientContext`: `routePath` ≤ 512 characters, `routeParameters` as a list of `{name, value}` —
  ≤ 512 characters as `name=value&…`, at most 20 —, `recordKind` ≤ 64, `recordId` ≤ 128, `tab` ≤ 64). A value over a
  cap or with a control character is refused with HTTP 400, never cut; an empty context is stored as none. It is stored
  on that owner row (`AIConversationMessage::$clientContext`, in `CONTEXT_LOAD_COLUMNS`; on a signal-only start on the
  start signal) and rendered from the owner's newest input as ONE volatile tail entry `[SIGNAL:client-context]` right
  before the clock (`AgentModelInputTailEntry::KIND_CLIENT_CONTEXT`): never part of the cached prefix, never a
  transcript row, never in a delegation child, nothing when the newest owner input carries none. The host hook is the
  new service `AgentClientContextService` (`buildClientContextTailText()`, `describeRecordOfClientContext()` to turn an
  id into a name, `getClientContextKeyLabel()`); the default renders the keys plainly in about 40 tokens; a failing
  hook is logged and leaves the entry out. Service entries: `driveConversation(…, clientContext:)`,
  `startOrReopenConversationAtEntryPoint(…, $clientContext, $runSettings)`, `AIConversationTurnSignalsService::injectSignal(…, clientContext:)`,
  `AIConversationMessagesService::appendInboundSignal(…, clientContext:)` — trailing optional parameters: a host
  subclass that overrides one of these methods adds the parameter to its signature.
- Added: `AIConversationMessage::getContextLoadColumns()` (the context load reads it on the class the host uses,
  `AIConversationMessagesService::getContextLoadColumns()`): a host message class with a column of its own redeclares
  `CONTEXT_LOAD_COLUMNS` as `[...parent::CONTEXT_LOAD_COLUMNS, 'itsColumn']` and the column rides every context load.
- Added: `AgentConversationControllerHooksTrait::getRequestDtoClassOfRoutedMethod()` and
  `resolveRequestDtoOfRoutedMethod()`: every routed trait method reads its request into the class the hook names —
  default the host's request DTO at the same path, else the module's — so one more request parameter needs no
  redeclared route (the generated API document keeps naming the module's DTO). A host that has a same-path request DTO
  already now gets it on the trait's routes too.
- Added: `AIConversationAgentService::beforeTurnOfDriveIsDispatched($conversation, $driveRequest, $isNewConversation)`,
  called by `driveConversation()` after the conversation is created and the owner's rows are appended, before the turn
  is dispatched (default nothing); `AIConversationDriveRequest::$clientContext` carries the drive's client context.
- Docs: a host subclass of `AIConversation` repeats its class-level `#[DatabaseIndex]` lines and `#[LazyLoadRepo]`
  verbatim (PHP does not inherit attributes; the framework's model generator reads the generated class only). The entry
  skill lists them; `ConversationClassAttributesDocumentedForHostSubclassesTest` keeps the list equal to the class.
- Fixed: `ToolResultUntrustedSegments::READING_RULE` reached no model. The executor now puts it into the platform note
  of every tool result whose text holds a mark (`buildPlatformNoteWithReadingRule()`, once, after the tool's own note),
  and an injected signal holding a mark gets it appended (`appendReadingRuleWhenMarked()`). Added the one-line form
  `ToolResultUntrustedSegments::wrapSingleLine()`; `findSegmentsInText()` finds both forms.
- Added: `AgentConversationControllerHooksTrait::findConversationOfInjectedSignal()`, the conversation of an injected
  signal (default `findVisibleConversation()`, as before).
- Added: `AgentSpacesService::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpaceOfConversation($conversation,
  $agentSpace)` (what `AIConversation::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()` asks now; default
  the space-independent method, so a host override of that one keeps working) and
  `getToolArgumentNamesLeftOutOfTheReadDedupLine()` (the strip list of the read dedup line of a paused turn, apart
  from the prefixes; default their names).
- Added: reads keyed by space AND account for a space several accounts share — `AgentSpaceSqlCondition::forAgentSpaceOfAccount()`,
  `AIConversationsService::findNewestOpenCustomerChatIdOfAgentSpaceAndAccount()`,
  `AIConversationSchedulesService::countAgentRecurringSchedulesCreatedForAgentSpaceOfAccountSince()`,
  `countUnseenFinishedRunsOfAgentSpaceOfAccount()` and `buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpaceOfAccount()`.
  The space-only reads are unchanged.
- Added: "do not narrow" for a tool argument the space fills — the model passes `"none"`
  (`AgentSpacesService::TOOL_ARGUMENT_VALUE_NOT_NARROWED_TO_AGENT_SPACE`, `isToolArgumentValueNotNarrowedToAgentSpace()`)
  and the executor leaves the argument out instead of filling it (`applyAgentSpaceDefaultsToToolArgumentsOfCall()`).
  The default description prefix is unchanged; `DESCRIPTION_PREFIX_OF_TOOL_ARGUMENT_FILLED_FROM_AGENT_SPACE_OR_NOT_NARROWED`
  is the prefix a host returns for arguments that may read across spaces. Before, such a value failed the hydration.
- Added: request snapshots for a conversation started over HTTP — the start's query parameter `storeRequestSnapshot`,
  honoured in an admin session (`AgentConversationControllerHooksTrait::isRequestSnapshotStoredForStartRequest()`, the
  host's hook), stamps `RunSettings::$storeRequestSnapshot` on the new conversation.
- Added: evaluation runs at account level. Every evaluation command on `AgentEvalConsoleCommand` takes `--accountId`
  (`OPTION_EVAL_ACCOUNT_ID`, forwarded to child processes): a case without a space runs at account level for it, a
  space without an owner runs for it (a space with an owner keeps its owner) —
  `AgentEvalRunHooksService::resolveEvalScopeWithFallbackAccount()` (account read by `findAccountOfEvalScope()`),
  `AgentEvalRunnerService::resolveEvalScopeWithFallbackAccount()`,
  `resolveScopeWithFallbackAccountAndFindNotApplicableReasonForCase()`. The planning gives such a case the scope
  `AgentEvalSuiteTask::$accountId` (scope id = the negated account id), `AgentEvalSuitePlanOptions::$fallbackAccountId`.
  Without the option nothing changes: `resolveEvalScope()` (and a host's override of it) decides, and a case without a
  space is skipped as before.
- Added: `MCPToolExecutorService::recordAdminAudienceToolCallWithCallContext()` with the call's provenance
  (`findConversationIdOfCallContext()`); its default calls `recordAdminAudienceToolCall()`, so a host override of that
  one keeps working.
- Changed: `noCache` is no longer in the agent's tool schemas (`MCPObjectProperty::MCP_HIDDEN_SHARED_PARAMETERS` =
  `outputFormat`, `noCache`): no tool call reads it (the framework reads the HTTP request's own query parameter), so it
  cost tokens on every tool and let a model flip it to repeat a read; a value a client still sends is ignored as
  before. The tool schemas change once (a one-time prompt-cache miss after the deploy).
- Changed: a validation failure inside a tool (a `BadRequestException` with `validationErrors`: an entity's `update()`,
  a request that did not validate) answers the model with its violations after the message — `Violations:` and one
  line `- <property path>: <message>` each, at most 10 (`MAX_VALIDATION_VIOLATIONS_IN_FAILURE_TEXT`), a message cut at
  200 characters, `- … and <n> more`; the received value is never shown.
- Changed: `findWidgets` is offered only while a skill the conversation can reach (or holds loaded) brings the data of a
  catalog widget (`AIConversation::hasWidgetsBroughtByReachableSkills()`,
  `ConversationWidgetCatalog::hasEntryProvidedByOneOfSkills()`); in a conversation without such a skill it answered
  every search with "no match". A catalog that cannot be built keeps it offered.
- Added: what the model input leaves out to save tokens, per host (`config/app/AI/agentModelInputOptions.php`,
  `AgentModelInputOptions`) and per purpose (frontmatter `modelInputOptions:`, `AIConversationPurpose::$modelInputOptions`,
  `AgentModelInputOptionsOverride`; each key it names wins): `offersDelegationTools`, `offersTaskPlanTools`,
  `offersAskOwner`, `offersFindWidgets` (inputs on `AgentOfferedToolsInputs`); `teachesWidgets` (false leaves out the base
  prompt's `Widgets` section with its composed-view kit, and `findWidgets`); `droppedBasePromptSections` (headings left
  out whole, `AgentBasePromptSections::dropSections()`, `AgentModelInput::$droppedBasePromptSections`);
  `dropsMemorySectionWithoutMemoryTool` (the memory rules while no reachable tool writes agent memory,
  `AIConversation::canReachToolThatWritesAgentMemory()`); `offersReachableToolsFromTheFirstCall` (false: a skill's tools
  arrive with its load also below `appendOnlyReachableToolSetCeilingTokens`,
  `AgentAppendOnlyToolSet::CEILING_TOKENS_OF_LOADING_BY_SKILL`). No prompt text is reworded: every switch defaults to
  the package's behaviour and the default input is byte-identical (`AgentModelInputOptionsTest`).
- Added: record references in evaluation fixtures — `{{ref:<kind>:<name>}}` in any string of a case, resolved once per
  kind and name in the run's scope by the new hook `AgentEvalRunHooksService::resolveRecordReference()` (default null)
  through `AgentEvalRunnerService::resolveRecordReferencesOfCase()` (`runCase()`, eval-tools in both flavors,
  eval-selection); a whole-string token keeps the resolved type, an unresolved one makes the case not applicable
  (`AgentEvalRecordReference`, `AgentEvalRecordReferences`).
- Added: a selection case can expect no skill (`expectedSkill: "none"`, `AgentEvalCase::EXPECTED_SKILL_NONE`) and forbid
  skills (`forbiddenSkills`, `AgentEvalCase::getForbiddenSkillNames()`; `AgentEvalCaseRow::buildForSelectionRun()` takes
  them as a trailing optional parameter); `expect` takes `forbiddenSkills` and `noSkill: true`.
- Added: the judge's and the driver's cost of a judged run — `judgeCostUsd` and `driverCostUsd` on the judged row (after
  `costUsd`, the agent's own), `metrics.harnessCosts` in the summary when not zero, the report line `Judge and driver
  cost:` (`AgentEvalHarnessCosts`, `AgentEvalSimRunResult::getHarnessCosts()`, counted in `AgentEvalJudgedRunService::callModel()`
  from the provider-reported cost, `readCostOfHarnessCall()`).
- Added: `AI.evalSuite.judgeModelName` and `driverModelName` — the judge and driver of a run that names none (null or
  empty: the package's defaults as before; `AgentEvalJudgedRunService::CONFIG_KEY_JUDGE_MODEL_NAME`, `…_DRIVER_…`). A host
  `evalSuite.php` without the keys behaves as before.
- Added: `--repeat` prints why each failed run failed under the case's `passed/runs` line (its error, else its first
  failure; at most 5 per case, 200 characters; `AgentEvalCaseRepetition::$failureDetails`, not stored).
- Fixed: `expect.stateAfterRun` of a case with a `restore` block read AFTER `restore.writeTool`, so it saw the restored
  value. It now runs inside the bracket — the agent's run, the read and the field checks, the cleanup, then the revert
  write (`AgentEvalRunOptions::$stateAfterRunCheckInsideRestore`, `AgentEvalRunnerService::evaluateStateAfterRunInsideRestore()`,
  also in `AgentEvalJudgedRunService::runSimWithRestore()`). Its calls get the harness's packages (every skill's,
  `AgentEvalRunnerService::getHarnessPackageClasses()`) instead of the expected skills' only.
- Fixed: `expect.argsContain` ignored its key and searched the whole arguments; a string key now reads that argument (a
  string as it is, any other value as unescaped JSON, a dotted key a nested value). An integer key (a list) keeps the
  whole-arguments search. A fixture whose text sat in another argument than the one it named now fails.
- Fixed: `expect.resultContains` / `resultNotContains` compared against the JSON-escaped result while the argument
  checks unescape; both now compare unescaped text (the decoded result text plus its `structuredContent` as unescaped
  JSON).
- Fixed: the evaluation teardown skipped the host's residue hook for an account-level conversation;
  `AgentEvalRunHooksService::tearDownHostResidueOfConversation()` now runs for every stored conversation (schedules and
  mandates are still closed only for a conversation of an agent space).
- Fixed: a host's same-path subclass of an Argus repository was never reached: the agent's egress
  `ArgusAIAgentConversation` (model calls, base prompt cache, reasoning-effort decision, evaluation harness) and the
  decision, summary, compaction, rerank, judgment, activity-line and mandate-approval repositories were built with
  `new`. Every one is now built with `X::newInstance()`; the contract test `ArgusRepositoriesAreNeverBuiltWithNewTest`
  fails on a `new Argus…()` in `src`.
- Fixed: the live path did not send the effort of the model it called. Without `--model` the egress read the agentic
  settings of the attribute's default model, not of the model the `AGENTIC` scope pins, so browser and console turns
  sent no `effort` (the provider's default applied). `ArgusAIAgentConversation::getArgusLanguageModelInstance()` now
  applies `agenticUseCase.reasoningEffort` and, when the catalog sets one, `agenticUseCase.temperature` of the model
  of the call (`findModelOfThisCall()`: the override, else the model set, else the scope's, else the default); the
  turn's decided effort still wins. Each egress applies them to its own copy of the `#[ArgusLanguageModel]` instance,
  so one call's settings never reach another call.
- Changed: the default cap of the reasoning carry-over `capped` is 4000 (`AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS`,
  `contextRetention.php` `previousReasoningCapTokens`; was 1000, while the class documentation of the cut said 4000).
  At 1000 the two kept steps alone exceed the cap at 300–900 reasoning tokens per step, so the cut moved on 8 of 11
  call pairs of a planning turn, each moving the cached prefix back. A host that ships its own `contextRetention.php`
  keeps its value; a running conversation's cut moves less often from the next call on.
- Fixed: the arguments of an `update_task_plan` call were stubbed (`{"superseded":true}`) as soon as a newer plan call
  followed, inside the running turn too: each plan update rewrote an item near the start of the turn and the next
  call fell back to the system-and-tools prefix. Only plan calls of earlier turns are stubbed now, once at the turn
  boundary (`ArgusAIAgentConversation::buildOpenAiChatMessagesArray()`); within a turn the input of call N is the
  unchanged start of call N+1 (`TaskPlanCallsOfTheRunningTurnKeepTheirArgumentsTest`). A turn with many plan updates
  carries their full arguments until it ends.
- Changed: an explicit run setting of a decision site wins over the config file in both directions (explicit run
  setting > `AI.decisionModel` site mode > default). Before, a site the file had `off` (or left unlisted) returned
  `off` before the conversation's row was read, so an evaluation arm could not switch it on and A/B runs needed the
  file in `shadow`. The skill-preload, stall-recovery and reply-language rows `on` / `off` and the turn-closing rows
  `off` / `decisionModel` / `counterThenDecisionModel` now decide over the file (`AgentDecisionModelSettings::siteModeForConversation()`,
  `findExplicitRowSiteMode()`); a turn-closing row naming a decision-model mode also switches a `shadow` site on. The
  global `mode: off` and the kill switch `decisionModelOff` stay absolute. The admin status toggles of these sites are
  disabled only under the global `off`.
- Docs: the skill preload's catalog entry is documented as the code sends it — the skill's `description` (the same
  text the chat model reads), plus for a root the line `Loading it unlocks the sub-skills: <name> (<criteria>)`; a
  skill's `decisionCriteria` is read only as a sub-skill's criteria in its root's entry (or for an empty
  `description`). `criteriaVersion` hashes the criteria lines only (decisions and skills-and-purposes skills).
- Docs: the context skill (the client context, the tail order, leaving parts of the model input out, replacing
  `Character.md`), the tools skill (the reading rule, the violations, `noCache`, "do not narrow", the admin audit, the
  meta-tool switches), the context skill's cache chapter (why the carry-over cap is 4000, the plan-call stub at the
  turn boundary), the loop skill's task-plan wire rule, the widgets skill (the `findWidgets` offer and switches), the skills-and-purposes skill (the
  purpose key `modelInputOptions`), the autonomy and entry skills (shared spaces, the new hooks, the conversation's
  class attributes, sixteen config files), the loop skill (the drive hook), the observability skill (`--accountId`, the
  start's snapshot switch, record references, `forbiddenSkills` / `noSkill` / `expectedSkill: none`, the `argsContain`
  and result comparisons, the state-check order, the judge and driver configuration and cost, the `--repeat` detail),
  the README.

### Frontend (`@mgamadeus/ddd-agents`)

- Fixed: the activity ticker dropped lines it had already shown within one turn. `activityTickerReducer` cleared the
  queue whenever an activity read missed a step already on screen, so a live stack of five lines fell back to one. It
  now merges per step (the later state wins, a read that arrives out of order never moves a step back) and starts
  clean only for another turn: the endpoint names another `turnMessageId`, or a new turn starts before it is named.
- Added: `AgentChatActivityStack`, a stacked activity feed for `AgentChat`'s `ThinkingIndicator`. It shows one sentence
  per tool call: the newest at the bottom at full size, older ones drifting up, smaller and fainter under a top mask,
  about three readable ranks (`ACTIVITY_STACK_VISIBLE_LINES`). Before the first sentence it shows the calm dots and
  "Working on it…", afterwards only generated sentences, and it fades out over the success beat. The moving layer is
  `aria-hidden` with a polite status region for the newest sentence. Motion follows `activityTiming`, stays still
  under `prefers-reduced-motion`, and colours come from `--agents-*`.
- Added: `AgentChatActivityDots` with `variant` `bounce` (the default) or `pulse` (calm, without movement in space), and
  `AgentChatActivityIndicator`'s `dotsVariant` (default `bounce`, unchanged). `AgentChatActivityProps` carries the
  resolved `activityTiming` (optional field).
- Added: the timing of the activity reads and of the working indicator as host options —
  `AgentChat activityTiming` and `AgentsEnvironment.chatActivityTiming` (`AgentChatActivityTiming`:
  `activityRequestWindowMs`, `runningFloorMs`, `linePaceMs`, `backlogLinePaceMs`, `successBeatMs`; each field from the
  chat, then the environment, then the package: 3000, 4000, 2200, 1400, 1400 —
  `resolveAgentChatActivityTiming()`, `DEFAULT_AGENT_CHAT_ACTIVITY_TIMING`). A host whose realtime push writes
  `activityUpdatedAt` when the narrator finishes lowers the read window and shows a narrated line at once.
  `useConversationActivity`, `useActivityTicker` and `msUntilNextActivityLine` take the values as trailing optional
  parameters.
- Docs: when a turn opens without the opening activity line ("I'm looking at …"): it needs the decision site
  `turnStartLine` `on` (the package's `decisionModel.php` ships the global `mode: off`), an owner in the chat, an
  owner message with words, a usable decision answer at or above 0.5 and a narrated sentence (widgets and decisions
  skills).

- Added: `createAgentsHttpConversationClient({ clientContext })` (`/client`): read on every start, posted message and
  injected signal and sent as the body field `clientContext`; `AgentsClientContext` and `AGENTS_CLIENT_CONTEXT_LIMITS`
  (`/contract`), `mapClientContextToWire`, `WireClientContext`, `AgentsClientContextOperation`. A field over its cap or
  with a control character is left out, never cut, so a send never fails on it.
- Fixed: the two icon-only buttons at the composer carry accessible names through the package's texts — the send
  button (`chat-input-send-button`): `Send message`; the round scroll-to-latest button beside it
  (`chat-scroll-to-latest-button`, new test id): `Scroll to the latest message`. The text-key manifest lists both.
- Docs: the README's adapter section — a binding over RTK Query with forced reads and no subscription must not set
  `keepUnusedDataFor: 0` (the store aborts a read in flight when a second read of the same key arrives).

## 0.1.2

- Added: `DBAIConversationMessage::buildToolCallExecutionCostInUSDSql()`, the SQL of what a row's tool call booked
  while it ran (0 without one), for a reader that shows tool executions apart from the model steps.
  `buildCostInUSDIncludingToolCallExecutionSql()` builds on it; its SQL is unchanged.
- Fixed: `DBAIConversation::findTopConversationStats()` leaves truncated rows out (`truncatedAt IS NULL`), as the
  conversation's own totals do.
- Fixed: `ArgusAgentDecisions` declares its elements as `A[]|B[]|…` instead of `(A|B|…)[]`, the form the framework
  reads when a cached set is loaded.
- Fixed (npm): the widget image capture's `html, body { margin: 0; padding: 0 }` applies only to a page that holds a
  capture root; loaded app-wide it removed the host page's body spacing.
- Added (npm): the widget text variant `title` (`AgentsWidgetTextVariant`, 1.125rem / 500 in the default
  components), between `headline` and `value`.
- Changed (npm): a KPI text value is shown with the `title` variant and sits on the bottom of the hero's line box, so
  text and number values end on the same line across a row of tiles.
- Requires `mgamadeus/ddd` ^3.36.2 (was ^3.34.3): the framework's Redis cache backends read a configuration without
  `password` without the "Undefined array key" warning, which `app:mcp:clear-cache` aborted on.
- Fixed: the module called a global function `___()` that neither the framework nor the module defines (the run report,
  the stop reason of a cut turn, the plan-less stall reply, schedule notices and the refusals of a schedule resume). A
  host without its own definition got "Call to undefined function" on a turn's failure path. Every such text now goes
  through the new service `AgentOwnerFacingTextTranslationService::translateOwnerFacingText()`: the framework's
  `Translatable::translateKey()` by default (a plural line is looked up by its plural English wording), the host's
  `___()` when it defines one, English when a key has no translation; it never throws. A host with its own translation
  layer overrides `translateKeyWithApplicationTranslations()`. The contract test
  `SourceCallsOnlyDefinedGlobalFunctionsTest` fails when the source calls a global function neither the package nor its
  dependencies define, and when it names `___` at all.
- Fixed: the package defaults refused the first turn. `AI.contextRetention.skillLoadingStrategy` is
  `conversationAppend`, and a catalog model without `settings.agentWireApi` speaks Chat Completions, where append-only
  loading is refused ("Agent wire configuration rejected (chatCompletions + conversationAppend)"). The documented
  fallback to `systemPromptSwap` now holds on every path: a run over a conversation without a stamped strategy resolves
  the default for its wire API (`ContextRetentionSettings::getSkillLoadingStrategyForAgentWireApi()`, used by
  `AgentWireConfiguration::resolveFor()`); a creation that does not know the wire API leaves the strategy unstamped
  under the append-only default (`ContextRetentionSettings::resolveDefaultSkillLoadingStrategyStampForAgentWireApi()`)
  and the run stamps it before its first call, an ephemeral conversation in memory
  (`AIConversationsService::stampCreationSettingsOfUnstampedConversation()` no longer skips it); the console chat stamps
  a new conversation with the wire API of the agentic default model. The model catalog is unchanged (it belongs to
  `mgamadeus/ddd-ai`). A strategy a start, a purpose or a CLI option names is refused on Chat Completions as before.
- Fixed: a worker turn logged `Undefined array key "password"` (and host, port, database) when the Redis cache group
  has no password. The native Redis connection of the base system prompt cache reads every key optionally
  (`AgentBaseSystemPromptCacheService::buildRedisConnectionDsn()`; a Sentinel connection without sentinels opens none).
- Added: `AgentAccountRightsService::isAccountReaderOfAllAgentRows()`, the module's own "reads every row" predicate
  (default: `Account::isAdminWithAccessToAllResources()`, true for the admin role of any account), with one predicate
  per table that delegates to it: `isAccountReaderOfAllAgentSpaces()`, `isAccountReaderOfAllAgentMandates()`,
  `isAccountReaderOfAllMessageEmbeddings()`, `isAccountReaderOfAllEvaluationRows()`,
  `isAccountReaderOfAllTurnEventSnapshots()`, and `isAuthenticatedAccountReaderOfAllAgentRows()`. Every "admin reads
  all" rule asks it: the read rights of the conversation tables, spaces, mandates, message embeddings, evaluation runs
  and cases, turn-event snapshots, `AgentSpacesService::requireAgentSpaceUsableByAccount()`, the message context of a
  customer-hidden conversation, the raw tool errors of `MCPController` and the admin audience of
  `MCPToolExecutorService`. A host whose scoped users hold the admin role narrows it in a same-path subclass. Behaviour
  is unchanged by default.
- Changed: the repositories of agent spaces, mandates, message embeddings and evaluation runs and cases ask the rights
  service instead of the account predicate (the evaluation repositories keep `isAuthenticatedAccountReaderOfEveryRow()`
  as their own override point). Same conditions and parameter names as before.
- Added: `MCPToolExecutorService::isEnvironmentWideWriteLockActive()` (default false): under it `dispatchTool()` refuses
  every tool that is no declared read (`#[MCPToolEffect(READ)]`; a tool without a declared effect counts as a write)
  before every other gate, for every caller, with a final refusal (`MCPToolResponse::REFUSAL_ENVIRONMENT_WRITE_LOCKED`)
  and the text of `buildEnvironmentWideWriteLockRefusalText()`.
- Changed: the test trait `MessengerTransportsHaveWorkerBlocksContract` accepts the framework's worker list
  ("config/system/workers.yaml", found beside "config/packages/messenger.yaml"; `workerListConfigPath()`) as well as a
  supervisor file. `supervisorConfigPath()` is no longer abstract (default none): a host on the worker list overrides
  only `messengerConfigPath()`; a host with a supervisor file keeps its override.
- Docs: the entry skill (installation checklist, override points, read rights, transport contract), the autonomy skill
  (owner-report translation), the context skill (the strategy fallback) and the tools skill (the write lock) describe
  the changes; the README names the three host hooks.

### Frontend (`@mgamadeus/ddd-agents`)

npm `@mgamadeus/ddd-agents` only; every addition is opt-in, the fixes are named as such.

- Added: `createAgentsHttpConversationClient` (`/client`), the conversation client over the module's own routes
  (`AgentConversationReadsTrait`, `AgentConversationWritesTrait`, the application's start route, optionally
  `AgentSelectableModelsTrait`): `baseUrl`, `fetchOptions` (an object or a function read per request), `fetch`,
  `buildStartPath` (default `/start`, `/start/{agentSpaceKey}` for a key above 0), `hasSelectableModelsRoute`,
  `polling`, `extraRequestParameters`. Failures become `AgentsClientError` (`errorCode` first, then the status). The wire
  types (`Wire…`), the mappers (`mapWireConversation`, `mapWireConversationMessage`, `mapWireConversationRunState`,
  `mapWireConversationActivity`, `mapWireSearchGroup`, `mapWireSearchHit`, `mapWireSelectableModel`,
  `mapHttpFailureToAgentsClientError`, `readWireSetElements`) and `composeAttachmentMessageText` are exported.
- Added: the port's optional `getConversationRunState` (`GET …/{id}/runState`, contract type `ConversationRunStateRead`).
  `withPollingChangeSubscription` polls it instead of `getConversationMetadata` when a binding has it; the HTTP client
  comes with that subscription, which also reads at once after an accepted post, signal or retry.
- Added: `i18n-keys.json` (subpath `@mgamadeus/ddd-agents/i18n-keys.json`), every text key of the package with its
  English default, placeholders, plural contexts and source files; `pnpm i18n-keys` regenerates it, the build and a spec
  fail when it is stale. Texts the generator could not see are marked with `textKey()`. The composer's placeholder reads
  the texts of an `AgentsProvider` subtree, not only the app-wide ones.
- Added: `AgentsEnvironment.onNavigate(href, event)` for every package link, anchors and `window.open` alike (return
  true when the host navigated); `AgentsLink` and `useAgentsLinkNavigation()`. `AgentsEnvironment.onOpenEntity(target)`
  opens an `openEntity` target on a host route instead of the canvas. Exported: `DEFAULT_AGENTS_COMPONENTS` (root,
  `/provider`) and `MarkdownLink` (`/renderer`).
- Added: `AgentChat` / `AgentChatController` props `disableSplitView`, `hostHasCanvasSurface` (default: the split's
  presence), `backgroundPaintedByHost` (default true, as before), `composerRef` (`AgentChatComposerHandle.setComposerDraft`);
  `AgentChat` prop `showsTopBlurBand` (default false; when shown, the band follows the chat pane).
- Added: `followHostColorScheme()` and the attribute `data-agents-theme` (pins the package's scheme over
  `prefers-color-scheme` and `data-theme`); `--agents-fg-on-accent`; plain colour variables for every `-rgb` triplet
  (`--agents-bg-dark`, `--agents-shadow`, `--agents-neutral-500`, `--agents-neutral-900`), each defaulting to its
  triplet.
- Added: `useAgentsConversationClient` / `useOptionalAgentsConversationClient` also from the root, `/provider` and
  `/chat`; `CanvasViabilityContext`, `ElementWidthCanvasViability`, `readCanvasViable` (`/widgets`).
- Changed: package rules mix the plain colour variables (`color-mix()`) instead of the `-rgb` triplets; a triplet set on
  the root element still applies through the plain colour's default.
- Changed: Markdown sets list markers, heading and bold weight and the link underline explicitly.
- Fixed: the canvas viability is measured on the chat's own container (`ResizeObserver`), not the viewport — a 480 px
  chat column in a wide window no longer squeezes a split into itself; the default `SplitView` honours
  `coversBelowWidth` (measured on its own box) and covers the main pane below it.
- Fixed: the `html, body` reset of the image render and the `:root` block of the composer's `--ai-*` variables were
  unlayered global rules that beat a host's layered reset; both are in `agents-defaults` now.
- Fixed: text on accent fills (the default user bubble, the started-call dot, the suggestion card, the assistant header
  icons) used the fixed white `--agents-fg-on-dark` and lost contrast on the bright dark-mode fills; it uses
  `--agents-fg-on-accent`. The chat gradient ends in `--agents-bg` instead of a raw `white`.
- Fixed: `AgentChat` puts its client into context, so a chat given only the `client` prop works without
  `AgentsConversationClientProvider` (the activity read threw before) and its children reach the client.
- Fixed: the thread of the default chat starts at the top of the pane; it kept 168 px of clearance for a top bar it does
  not have (`--agents-chat-top-padding` is 24 px on `AgentChat`'s chat area).
- Fixed: the composer shows its placeholder while empty, also when focused — it disappeared once a conversation existed,
  because the chat focuses the composer after each turn.


## 0.1.1

- Fixed: the Composer requirement on `mgamadeus/ddd-ai` is `^3.4.0` (was `^3.2.1`). `app:agents:eval-tools` uses
  `ModelTierOptionTrait`, which ddd-ai has from 3.4.0; with 3.3.x the first `cache:clear` failed with "Trait not found".
- Fixed: the npm peer ranges fit together. `react-apexcharts` is `>=1.4.0 <1.6.0` (1.6.0 and later need apexcharts 4)
  and `apexcharts` `^3.41.0` (the floor react-apexcharts 1.4/1.5 asks for). A package manager that installs peers no
  longer reports an unmet peer.
- Docs: the npm README names the peer set in one install line and says where the agent skills come from.

## 0.1.0

- First stable release, the state of 0.1.0-alpha.70 unchanged. Composer `mgamadeus/ddd-agents` 0.1.0 and npm
  `@mgamadeus/ddd-agents` 0.1.0 (dist-tag `latest`; prereleases keep `next`). Requires `mgamadeus/ddd` ^3.34.3 and
  `mgamadeus/ddd-argus` ^1.6.0 as before. While the package is 0.x, a minor release may break: pin `~0.1.0` / exact.

## 0.1.0-alpha.70

- Added: `askOwner` takes the optional argument `planItemKey` (`AskOwnerQuestion::$planItemKey`), the key of the plan
  item whose open question it is, as the plan render shows it. The plan render treats that item (and its subtree) as
  waiting for the owner for the rest of the turn: for the "after the items before it" mark of a later answer-text item it
  counts like an unanswered owner widget (`TaskPlanItem::isAwaitingOwnerOffer()`). The turn state reaches the render as
  `TaskPlanRenderTurnState` (`TaskPlanRenderTurnState::ofTurn()` over the current turn's rows at context build,
  `AgentModelInput::$taskPlanRenderTurnState`, `TaskPlan::toTailHintText()` and
  `TaskPlanItems::buildCompactTailHintLines()` take it, optional). The askOwner result ends with
  `item <key> waits for this answer` when the key names an item of the plan; an unknown key fails nothing and adds
  nothing. Without the key nothing changes.
- Changed: the askOwner description, its `planItemKey` argument and the descriptions of both plan tools (`set_task_status`, `update_task_plan`) carry the
  sentence "Name the plan item your question belongs to." (`AskOwnerTool::NAME_THE_PLAN_ITEM_SENTENCE`); no other
  wording changed.

## 0.1.0-alpha.69

- Changed: the plan line of an answer-text item carries "after the items before it" only while an earlier open leaf is
  owed by the agent; when every earlier open leaf only waits for the owner's answer (`TaskPlanItem::isOwedWhenOpen()`
  false — a rendered owner widget unanswered; a blocked leaf is not open), it carries the plain answer-text mark, so the
  draft goes into the answer that asks the question instead of being held back and dropped. Mark wording unchanged.

## 0.1.0-alpha.68

- Changed: in a turn whose answer ends on the model's question to the owner (`askOwner`), the model's done tick of an
  item whose result is text in the answer (`inAnswer`), sent with `set_task_status` or as the item's status in
  `update_task_plan`, is accepted and closes the item; its result says "closed: its text is in this answer"
  (`TaskPlanItemTickGate::checkDoneEvidence()`, `TaskPlanItemPlatformCloser::turnEndsOnAnOwnerQuestion()` counts an
  `askOwner` call of the same response that has not run yet). No content check. Without that tick such an answer still
  closes nothing; every other item kind keeps its rules. The tick rules (`TaskPlan::buildTickRules()`) state the
  exception, and the checked-stop bounce says that an answer without a question to the owner closes these items itself (no tick), and one
  ending with `askOwner` only with a done tick of each in the same turn.

## 0.1.0-alpha.67

- Fixed: `app:ai-agent:eval-suite` runs a case once more that ended on cURL error 35 (the TLS handshake with the model
  provider broke off, "unexpected eof while reading"), as it does for cURL 28 and 56
  (`AgentEvalSuiteCommand::RETRIED_INFRASTRUCTURE_ERRORS`).
- Fixed: the judge of a judged run sees the clock of each call. The clock rides on the wire tail, which the trace does
  not carry; a judge counted "tomorrow" from the UTC date and failed a correct answer. The trace now gives every call
  with a clock a line `[N] CLOCK (the "now" the agent was given for this call, in the space's zone <zone> — …): <local
  date> <weekday> <time>` (`AgentEvalJudgeTraceMessageLines::CLOCK_LINE`), in the zone the call's request snapshot
  recorded; a call without a zone (no clock signal) gets none.
- Fixed: an identical read answered from the earlier result of the turn gets that result's data. Before, only the
  words of the result came back; for a tool whose data sits in `structuredContent` (words "Successful response for
  '<tool>'") the model got no data. `ToolCall::buildResultTextServedAgainOnARepeat()` adds the `structuredContent` as
  JSON (without `backgroundOperations`) for a result in the executor's structured shape; it is never longer than the
  first serve. A markdown-rendered result is served as before.
- Changed: the eval tool block answers a blocked tool (without a simulated error) and a write under the read-only
  switch with `Tool '<name>': this step is not executed in a test run; go on with the task's other steps as the skill
  says` (`RunSettings::EVAL_TOOL_REFUSAL_SENTENCE`). The former texts ("complete the task without it", "answer from what
  you can read and say what you would change") pushed the model away from the skill's rules. A recording made before
  this version differs in these result texts.
- Changed: the warning of an `update_task_plan` call that drops items quotes the owner's newest message beside them
  (`TaskPlanUpdateOutcome::$ownerWordsForDropWarning`, at most `DROP_WARNING_OWNER_WORDS_MAX_LENGTH` characters), so the
  model sees whether the owner cancelled them. Added: `AIConversationMessages::getNewestOwnerTextMessageText()`.

## 0.1.0-alpha.66

- Fixed: the admission of queued sub-agents reads where the spawn wrote. `admitQueuedDelegationChildren()` counted the
  occupied slots and read the queued rows with autocommit SELECTs; behind a pooler that routes a lone SELECT to a
  replica (ProxySQL in front of Galera with `wsrep_sync_wait = 0`) they could reach a node that had not applied the
  child's `IDLE → QUEUED` of a moment before, so the child stayed QUEUED with every slot free. The admission now runs in
  one READ COMMITTED transaction (it stays on the writer) with the tree's lock taken in it
  (`SqlDialect::acquireTransactionLock()`) and released after the commit (on MariaDB in a short transaction of its own,
  so `RELEASE_LOCK` reaches the connection that holds the lock). Inside a caller's open transaction it nests.
- Fixed: a capacity-lock attempt that answers at once without the lock (`GET_LOCK` NULL, or 0 without waiting) is
  repeated within the 5 s wait (`DELEGATION_CAPACITY_LOCK_RETRY_PAUSE_MICROSECONDS`); before, it admitted nothing and the
  child was answered as queued. The outcome is named: `AgentDelegationAdmission::LOCK_OBTAINED`,
  `LOCK_OBTAINED_AFTER_RETRY`, `LOCK_TIMED_OUT` (another admission held it the whole wait), `LOCK_REFUSED`.
- Fixed: a `delegate` call answered "queued" states the real count and the reason. Before, it printed the cap
  (`queued (5 sub-agents already run in this conversation)`) whatever ran. Now: `queued (no slot is free: 5 of 5 sub-agent
  slots of this conversation are taken; …)`, `queued (the admission could not run now, its lock was not obtained; 0 of 5
  …)` or `queued (2 of 5 … are taken; it starts at the next admission)`. Changed:
  `DelegationSubAgentRecord::buildSpawnAnswerText()` takes the `AgentDelegationAdmission` that left the child queued
  (null = started) instead of an int. A recording made before this version differs in this answer text.
- Fixed: a spawned child left queued although it could have run (the lock not obtained, or a slot still free) is
  admitted once more before its `delegate` call is answered
  (`AIConversationDelegationSpawnService::admitSpawnedChildrenAndRecordAdmission()`); before, it waited for a sibling's
  end or the recovery pass, which a recorded run never reaches.
- Fixed: the re-check of the open done criteria after a completed tool runs, on MariaDB outside an open transaction, in
  a READ COMMITTED transaction of its own
  (`AIConversationTaskPlanRecordService::preResolveSeededLeavesReadingLatestCommits()`), so the criteria's reads reach the
  writer that holds the tool's write. The plan slice is persisted after it, outside it.
- Added: two always-on turn events. `delegationAdmission` (subject `children:<ids>`): the lock outcome and attempts,
  the occupied count, the queued ids read, the admitted ids, whether the admission was retried, the spawned ids.
  `doneCriterionRecheck` (subject `after:<tool>`): per re-read criterion leaf its key, criterion, status before and
  after, and the number of leaves resolved. Both are also in `AIConversationTurnEvent::KINDS`.
- Added: `AIConversationRunClaimsService::admitQueuedDelegationChildrenAndDescribeAdmission()` and
  `AIConversationDelegationSpawnService::promoteQueuedDelegationsAndDescribeAdmission()` (the admission with its
  `AgentDelegationAdmission`); `admitQueuedDelegationChildren()` and `promoteQueuedDelegations()` keep their int[]
  return. The cap (`DELEGATION_CONCURRENCY_CAP`) is unchanged.

## 0.1.0-alpha.65

- Fixed: the message handlers raise the memory limit instead of setting it
  (`DDDService::raiseMemoryLimitToAtLeast()`, `mgamadeus/ddd` 3.34.3). Before, `ini_set('memory_limit', '512M')` as the
  first line of a job lowered the limit of a console command that ran the handler in-process with a higher one, and
  with the usage already above 512M PHP warned "Failed to set memory limit", which the dev kernel turned into an
  exception and the job failed. Changed: embedding, summarize, finalize, report email, scorecard and compaction (512M),
  resume (1024M), and `AISkillCatalogDocService` (1024M, also runs inside a job). The console commands keep `ini_set`
  (their own process), the fork-join child its `-d memory_limit`.
- Fixed: the recorded-run comparison reads a row's `agentSpaceId` as an id of its own family
  (`AgentRecordedRunIdNormalization::FAMILY_AGENT_SPACE`, table from the agent space repository in
  `AgentRecordedRunsService::buildTableByFamily()`). Before, it went through the free-number path, which reads only
  numbers of at least six digits, so a five-digit space the run created (69490 recorded, 69494 replayed) stayed raw and
  the runs differed. A space the harness names reads as `<agentSpace>`, one the run created without naming it as
  `<agentSpace#1>`, one that existed before the run stays raw.
- Changed: requires `mgamadeus/ddd` ^3.34.3.

## 0.1.0-alpha.64

- Fixed: a judge or driver model name the model catalog does not know is refused before any call. Before, the call ran
  on the agent's default model (`ArgusAIAgentConversation::getAIModel()` falls back when the name does not resolve), so
  the verdict was given by another model than the one named. `AgentEvalJudgedRunService::refuseUnknownModelName()`
  throws `AgentEvalUnknownModelNameException` (a retired name passes: it resolves to its successor);
  `callModel()` checks every call, `runSim()` and `runCompactionTreeEval()` check the run's judge and driver before the
  first turn. The evaluation commands (`eval-tools`, `eval-suite`, `eval-compaction-tree`, through
  `AgentEvalConsoleCommand::execute()`) print `--judge-model: The model catalog knows no model "<name>" …` and exit `2`
  before anything runs.
- Fixed: a harness model call the provider refused is no longer read as an empty answer.
  `loadHarnessCompletion()` reads the load's failure reason and throws `AgentEvalHarnessModelCallFailedException`
  (`judge call failed: <model>: <reason>`, `driver call failed: …`). A failed judge call is asked once more, then is the
  verdict `ERROR` with the provider's message (`AgentEvalJudgeVerdict::forFailedJudgeCall()`, `isJudgeCallFailed()`,
  key `judgeCallFailed`); the case row gets `error: judge evaluation error: judge call failed: …` and counts as an
  evaluation error. Before, it was `FAIL` "Judge output not parseable" with an empty `raw` and counted against the agent.
- Changed: a failed driver call ends its case as an error row with the provider's message (`runSim()` throws; the
  remaining cases of the run continue) instead of sending the "please go on" owner turn.
- Readers of `verdict.verdict` see `ERROR` where they saw `FAIL` for a refused judge call. An application's own
  command that is no evaluation command calls `refuseUnknownModelName()` after reading its model options.

## 0.1.0-alpha.63

- Requires `mgamadeus/ddd` ^3.34.1 (the recorded-run classes of the framework: write-statement audit, message
  dispatch capture, row scope columns; on MariaDB the transaction limits of the evaluation purge are restored to the
  session's previous values as whole numbers in one statement) and `mgamadeus/ddd-argus` ^1.6.0.
- **Host requirement** for record and replay and for the tool-call comparison:
  `DDD\Symfony\Messenger\Middleware\MessageDispatchCaptureMiddleware` in the middleware list of the default message
  bus (`messenger.bus.default`). Without it a recorded or compared call SENDS the messages it dispatches instead of
  keeping them. The middleware is a pass-through while no capture is active.
- Added: record and replay of a run in the package. `AgentRecordedRunsService` builds the per-run
  `AgentRecordedRunSession` (the switches it sets and restores, the queue of the messages a run dispatches, the
  snapshot of the run's rows, the comparison of a replay with its recording); the recording file
  `AgentRecordedRunRecording` with `AgentRecordedRunFields`, `AgentRecordedRunSnapshot`, `AgentRecordedRunCreatedIdRead`;
  the scope of a run `AgentRecordedRunScope` and `AgentRecordedRunScopeColumns`. `app:ai-agent:chat` gains
  `--record-run-for-comparison=<file>` (drives `--turns-file` and records the run) and `--replay-run-and-compare=<file>`
  (drives the recorded turns, answers every Argus call and tool result from the recording and compares; exit 1 on a
  difference), with the override points `buildRecordedRunSettingsPassedByCaller()`, `buildRecordedRunScope()`,
  `buildRecordedRunConcludingScopeIds()` and `buildWireRecordingKeys()`.
- Added: `app:mcp:compare-tool-calls` (`MCPCompareToolCallsCommand`): runs the comparison script of one MCP package
  in-process; `--record` writes the recording, `--replay-and-compare` lists every difference against it (exit 1).
  Built on `ToolCallComparisonsService` and `ToolCallComparisonSession` with the script, tool calls, conversation
  fixtures, case outcomes, stored recording, definitions dump and id placeholders in
  `Domain/AI/Entities/MCPPackages/ToolCallComparisons/`; an application's own recorders plug in through
  `ToolCallComparisonHostRecordingInterface`. Known limit: the id placeholders work on the number of an id, so on an
  empty database (every table counting from 1) rows of different tables with the same id read as one placeholder.
- **Breaking:** `AgentRecordedRunSessionInterface::buildRecording()` takes `AgentRecordedRunFields` and returns
  `AgentRecordedRunRecording` (was `array` / `array`).
- **Breaking:** `AgentRecordedRunSessionInterface::describeRun()` takes `AgentRecordedRunSnapshot` (was `array`).
- **Breaking:** `AgentRecordedRunPlayedAnswerIdTranslation::translateResponseBody()` takes an
  `AgentRecordedRunCreatedIdsReaderInterface` instead of a `callable`.
- **Breaking:** the protected `AgentRecordedRunPlayedAnswerIdTranslation::translateToolCallArgumentsIn()` and
  `translateIdValues()` lose the `callable` parameter and their by-reference `bool` out-parameter; whether something
  was translated is read from `$translations`.
- **Breaking:** `RecordedRunClockAndIdSources::runWithPinnedSources()` is removed; use `installPinnedSources()`, which
  returns `RecordedRunInstalledSources`, and `restoreInstalledSources()` in a `finally`.
- **Breaking** for an application's same-name subclass of `AgentChatCommand` that declared them itself: the command
  now declares the options `--record-run-for-comparison` / `--replay-run-and-compare`, the constant
  `RECORDED_RUN_HARNESS` and the methods `printSharedScopeTables()`, `printComparison()` and
  `buildWireRecordingKeys()`; remove the subclass's copies.
- Docs: the observability skill gains a reference of every package console command (`console-commands.md`) and of
  the evaluation fixture format (`evaluation-fixtures.md`); record and replay and the tool-call comparison are
  documented as the package's.

## 0.1.0-alpha.62

- Added: `app:ai-agent:eval-conversations-purge` on `AIConversationEvalPurgeService`: deletes the conversation trees
  of the configured evaluation accounts whose last activity is older than an age limit, with every dependent row, in
  bounded chunks (each write chunk in its own transaction with a 2 s lock wait and a 20 s statement limit, each read
  with the 20 s statement limit), with a time budget, a dry run with counts (the default; `--apply` writes), kept agent
  spaces, and steps an application extends through `buildHostDependantPurgeSteps()`. An account that is no configured
  evaluation account is refused; on MariaDB and PostgreSQL.
- Added: `AIConversationEvalPurgeService::beginStatementLimitedRead()` / `endStatementLimitedRead()` — a pair an
  application puts around its own reads of a purge run; every read of the purge itself runs between them.
- Deprecated: `AIConversationEvalPurgeService::preparePoliteWriteSession()` does nothing (the limits sit on each write
  chunk and each read) and is no longer called by the purge; it will be removed in a later release.
- Added: `AgentEvalSeedAccountLeasesService` (one evaluation process per seed account, a lock file per account on the
  host) with `AgentEvalSeedAccountLease` and `AgentEvalSeedPool`, and
  `AgentEvalSeedAccountResetService::resetAccountForCleanStart(Account, ?AgentSpace)` with
  `AgentEvalSeedAccountResetReport`.
- Added: `app:ai-agent:decision-fixtures` (synthetic decision cases from a JSON fixture, scored against their
  expectations), `app:ai-agent:decision-stats` (read-only decision statistics over the turn events) and the abstract
  `AgentDecisionReplayCommand` (an application's replay of stored tool calls against one decision site). Each
  decision call of `decision-fixtures` and of a replay is booked under an evaluation envelope of its own.
- Added: `app:ai-agent:eval-compaction-tree` (a judged long run with frequent compaction; the case comes from a case
  file, `--case-file`; the space from the case file first, the scope options such as `--agentSpaceId` are the
  fallback).

## 0.1.0-alpha.61

- Added: the evaluation commands `app:ai-agent:eval-tools`, `eval-suite`, `eval-selection`, `eval-recall`,
  `eval-tiers`, `eval-analyze`, `eval-eviction`, `eval-guard`, `eval-schedule-runs` on the shared base
  `AgentEvalConsoleCommand` (scope options `--agentSpaceId` and the application's scope option, `--ignore-budget`,
  result files in the configured results folder, child processes on the suite schedule with resource lanes, stop on
  the first signal or a `STOP` file). An application subclasses a command (repeating its `#[AsCommand]` name) where it
  names its own scope, fixtures or texts.
- Added: `AgentEvalSuitePlanService::findFixtureAt()`, `findFixtureFilesAt()`, `findFixturePath()`,
  `getSuiteDefinitions()`, `createTask()`, `createPlanOptions()`; `AgentEvalResultFilesService::writeResultDocument()`
  and `writeLooseRunFile()`; `AgentEvalConsoleCommand::requestStop()`; `AgentEvalRunHooksService::findBillingEntityOfScope()`
  and `attachNewScheduleToScope()`; the configuration keys `AI.evalSuite.fixturePaths` and `AI.evalSuite.suites`
  (both empty in the package, which ships no fixtures). A host that replaces `evalSuite.php` adds the two keys.
- **Breaking** for a host that calls them: `AgentEvalSuiteRunsService::recordSuiteRun()`,
  `AgentEvalSuiteRun::forFinishedRun()` and `applyFinishedRun()` take `AgentEvalStoredRunOptions` instead of an array,
  and `AgentEvalSuiteRun::getRunOptions()` returns it (`toArray()` gives the former array).
- **Breaking:** `AgentEvalRunOutcome::recordEvictionTurns(array)` is replaced by
  `recordEvictionTurnSet(AgentEvalEvictionTurns)`, without a forwarding method; the reader `getEvictionTurns()` stays
  as a deprecated array reader beside `getEvictionTurnSet()`.
- Changed: `app:ai-agent:run-report` prints two notes below the run table when they apply
  (`<run>: decision cost not read: <exception class>`, `<n> log file(s) could not be read and was/were skipped`);
  `AIConversationRunReportModelCost::forReadFailure()` keeps the class of a failed usage-log read.
- Changed: a suite-format result file written with message traces requested keeps the rows' `messages`
  (`AgentEvalRunSummary::toArray(true)`); a summary file never carries them.

## 0.1.0-alpha.60

- A node class names former node types that stored trees still carry in `ComposedViewNode::LEGACY_NODE_TYPES`
  (default none): `ComposedViewNodeClasses::getNodeClassForNodeType()` resolves them to the class, so the tree
  reader no longer drops a stored node of a renamed type (`unknown node type`). They are no registered node type:
  the kit spec, the node schema export and `getNodeTypes()` name `NODE_TYPE` only. One former type named by two
  classes is a build error; a former type that is a current `NODE_TYPE` stays the current class's. An application
  that renamed `VideoCard` to `VideoWidget` declares `LEGACY_NODE_TYPES = ['VideoCard']` on its node class.

## 0.1.0-alpha.59

- The video and map tiles carry widget names: the node type `VideoCard` is `VideoWidget` (folder
  `kit/components/media/video-widget/`, `video-widget-values.ts`, preview fixtures `youtube-video-widget*` in
  `video-widget.preview-fixtures.json`), `MapPointCard` is `MapPointWidget`. Stored trees with the node type
  `VideoCard` still render (as `VideoWidget`); the old fixtures export path stays for one release.
- Test ids: `video-card…` → `video-widget…`, `map-point-card…` → `map-point-widget…`.

## 0.1.0-alpha.58

- The evaluation suite's parts ship in the module: the suite objects (`AgentEvalSuiteTask(s)`,
  `AgentEvalSuiteSchedule`, `AgentEvalCaseRow(s)`, `AgentEvalRunSummary`, `AgentEvalBaselineChoice` /
  `AgentEvalBaselineComparison`, `AgentEvalSuiteRecord`, …) with the planning service `AgentEvalSuitePlanService`,
  the result files `AgentEvalResultFilesService` (results folder `AI.evalSuite.resultsDirectory`, default
  `var/agent-evals`; baseline read; retention sweep), the run report `AIConversationRunReportService` and the command
  `app:ai-agent:run-report`. New config file `config/app/AI/evalSuite.php`.
- Two new tables: `AgentEvalSuiteRuns` and `AgentEvalSuiteCases` (entities `AgentEvalSuiteRun`, `AgentEvalSuiteCase`;
  with a table prefix e.g. `EntityAgentEvalSuiteRuns`). An admin with access to all resources reads every row, every
  other account none. A host that already has these tables keeps them: the columns are the same.
- Two new tables need their DDL in a host (schema diff): `AgentEvalSuiteRuns`, `AgentEvalSuiteCases`.

## 0.1.0-alpha.57

- The task of a sub-agent (`DelegationSubAgentRecord::$task`, `DelegateToolArguments::$task`,
  `AgentSubAgentRequest::$task`) is typed as its three concrete classes
  (`AgentDelegationAgentTask|AgentDelegationToolRunTask|AgentDelegationAwaitedRunTask`) instead of the abstract
  `AgentDelegationTask`: the API documents describe each shape (`oneOf`), so generated client models have their
  fields. `objectType` picks the class, as before; the task factories return the same union.

## 0.1.0-alpha.56

- Append-only loading: the decision model's preload on a conversation's first row is no part of the first epoch's
  base set (`LoadedCapabilities::withoutSkillsLoadedBySource()`): the turn's first model call appends it behind the
  `load_skills` call and its result of 0.1.0-alpha.55, with its `additional_tools` item and its instructions — the
  shape of the model's own load. The base prefix (prompt and base tools) is the same for every new conversation,
  whatever the preload picked. A structural or plan-item load on row 0 stays in the base set. A conversation already
  running appends such a skill once on its next call (one cache miss).

## 0.1.0-alpha.55

- Append-only loading: skills the platform loaded before the turn's first model call (a preload: decision model, plan
  item, structural) are appended behind a `load_skills` call and its result — the shape of the model's own load —
  in front of the item that carries their tools and instructions. Seen only as instructions, a preloaded skill was
  loaded again as the model's first call. Recorded in `RequestSnapshot::$appendedPreloadLoadCallSkillNames`,
  re-rendered byte for byte by the later calls of the epoch (call id `call_preload_<row index>`); the result text is
  `AIConversationMessage::LOAD_SKILLS_RESULT_LOADED`. The system-prompt swap is unchanged.
- A tool's `@example {}` of its argument set goes out as `"examples":[{}]`, no longer `[[]]`.

## 0.1.0-alpha.54

- A `load_skills` call the step held back (append-only loading: refused with the held-back text, never run) is no
  skill load of the turn: the prose answer after it is a reply, no longer nudged as a stall
  (`AIConversationTurnMessages::hasSkillOrToolLoadCall()`).

## 0.1.0-alpha.53

- "Card" names of conversation elements become "widget" names; the old exports stay as deprecated aliases for one
  release: `CanvasWidgetCard` → `CanvasLauncherWidget` (`CanvasWidgetCardProps` / `CanvasWidgetCardCanvas` →
  `CanvasLauncherWidgetProps` / `CanvasLauncherWidgetCanvas`), `ConversationErrorCard` → `ConversationErrorWidget`
  (`…Props`), `CardFooter` → `WidgetFooter`; internal: `ConversationSuggestionCard`, `ConversationIntroCard`,
  `ConversationMessageInputAttachmentPreviewCard` → `…Widget`, `EntityMentionCard` → `EntityMentionWidgetBody`.
- The canvas origin `cardClick` is `widgetClick`; `cardClick` (`LEGACY_CARD_CLICK_ORIGIN`) still opens like it.
- Test id `writing-draft-widget-card` → `writing-draft-widget`. `VideoCard`, `MapPointCard` (tiles inside a widget)
  and their test ids stay.
- The npm package of 0.1.0-alpha.52 was not published (its tag carried the renamed files without the import
  updates); this release carries 0.1.0-alpha.52's change too.

## 0.1.0-alpha.52

- The driver's and the judge's model calls of a judged run (`AgentEvalJudgedRunService::callModel()`) send the
  harness system prompt and the prompt as the one message — none of the platform's sections of an agent call (owner
  presence, skill catalog, memories, product, clock); `buildHarnessModelInput()` builds it.

## 0.1.0-alpha.51

- After a model step that only repeated `load_skills` (every call loaded nothing new), `load_skills` is held back for
  the ONE next model call; the tool choice stays the step's. Under the system-prompt swap that call sends the tools
  without `load_skills`; under append-only loading the tools stay on the wire byte for byte and a `load_skills` call is
  refused with the held-back text. The call after it offers `load_skills` again
  (`AIConversationTurnMessages::hasNewestModelStepOnlyRepeatedSkillLoads()`, trace `turn.step.skillLoadsHeldBack`).
- The widget-image renderer reads the capture read path through `fetch` like through axios: a GET without body, with
  the capture token, without the page's credentials, `debug` and `noCache`; anything else on that path fails the
  capture (`WIDGET_HYDRATION_FAILED`). A widget whose client uses `fetch` renders live.

## 0.1.0-alpha.50

- A model step that only repeated `load_skills` leaves the wire only when a newer such step exists: the NEWEST one
  stays, so the next step reads its answer ("Already loaded … call them"); with it removed the model saw nearly the
  same input and made the same call again.
- A `load_skills` name that is no skill: when it equals ONE catalog name once its dots and its case are ignored, that
  skill loads and the answer starts `Loaded <name> (you wrote "<given>").`; a call that still named a name that is no
  skill (`ToolCall::$loadSkillsCallNamedNoSkill`) is no repeated load and stays on the wire, and the `[SIGNAL:skills]`
  tail line names only skills that are loaded.
- The stall text after a prose answer that followed a skill load: when the loaded skill itself says the request takes
  no tool, the prose answer was right; otherwise call the tool. The text after an empty step is unchanged.
- The checked-stop bounce names the items whose result is text in the answer in their own group ("Write that text in
  the answer you give now — the platform closes them from it; never tick them.") and no longer under "Items you tick"
  (`TaskPlanItems::getOwedOpenAnswerTextItemTitleLines()`, `getOwedOpenItemTitleLines(..., $includesAnswerTextItems)`); its closing
  sentence then says the answer is accepted and such an item stays open when its text is missing.
- The platform's ONE_INSTRUCTION consent check of a refused call names the call: `requestedToolName` and
  `requestedToolSummary` in the payload, and the approval prompt judges consent to THAT call. An application with its
  own copy of `AI/Mandates/ApprovalCheck.md` adds the line.

## 0.1.0-alpha.49

- The judged run of an eval case moves into the package: `AgentEvalJudgedRunService` (extends
  `AgentEvalRunnerService`) — `runSim()` / `runSimWithRestore()` (a driver model plays the owner after the opening
  prompt and the case's scripted follow-ups, a judge model returns the verdict; the restore bracket around the run),
  `runCompactionTreeEval()` (a long conversation whose compaction tree is judged; a stored run reads the stored tree),
  `callModel()` (a plain completion in `ENVELOPE.AI.AGENT.EVALUATION`, booked as `AI.AGENT.SIMULATE.OWNER` or
  `AI.AGENT.JUDGE.EVALUATION`), `judgeVerdict()` (an unparseable answer is asked once more; a cut-off verdict is judged
  once more, a second cut-off is `ERROR`), the owner-visible transcript and the judge's trace
  (`AgentEvalJudgeTraceMessageLines`, the audit blocks, `AgentEvalRunCounts`). Override points with neutral defaults:
  `applyCaseFixtures()`, `buildHostEndStateTextForJudge()`, `buildTextOfHostWidgetShownToOwner()`,
  `applyHostSectionsToVerdictPrompt()`, `isHostOwnerReplyRow()` and the texts `getEmptyDriverReplyText()`,
  `getEmptyCompactionTreeDriverReplyText()`, `getDefaultCompactionTreeRubricText()`,
  `getTruncatedTwiceVerdictReasons()`; default models `getDefaultJudgeModelName()` / `getDefaultDriverModelName()`.
- Objects of the judged run: `AgentEvalSimCase`, `AgentEvalJudgeVerdict`, `AgentEvalRunCounts` (stored count key
  `toolWidgets`), `AgentEvalSimRunResult`, `AgentEvalCompactionTreeRunResult`, `AgentEvalJudgedRunOptions`,
  `AgentEvalRestoreBracket`.
- Four prompt files under `config/app/AI/Prompts/AI/Agent/Evaluation/` (`Driver.md`, `Verdict.md`,
  `CompactionTreeDriver.md`, `CompactionTreeVerdict.md`) named by `AgentAIPromptNames::APP_AI_AGENT_EVALUATION_*`; an
  application replaces a file whole.
- `AgentEvalRunnerService`: the restore bracket is split into `openRestoreBracket()` / `closeRestoreBracket()`
  (`runWithRestore()` unchanged), `assembleConversation()` is taken out of `buildConversation()`, and the follow-up
  owner message is written by `appendOwnerMessage()`, which on a stored conversation also stamps the inbound activity
  (`recordInboundActivityIfPersisted()`).
- The observability skill names the judged run (reference `building-evaluation-in-the-host.md`, § The judged run).
- The two run results (`AgentEvalSimRunResult`, `AgentEvalCompactionTreeRunResult`) are read through their getters; they
  have no array form.

## 0.1.0-alpha.48

- Tick rules: the sentence that a question to the owner is never an answer-text item stands on its own after the
  `inAnswer` passage; inserted before "mark it", it read as an order to mark the question item.

## 0.1.0-alpha.47

- The model's takeover load of a preloaded skill names the skills and their tools ("Skills loaded: <names>. Their
  tools are in your tool list — <tools>. Call the one you need next."); after it nothing new arrives on the wire, and a
  bare "loaded" made the model load again. The `[SIGNAL:skills]` line after a repeated load names the tools too.
- An open `inAnswer` leaf behind a leaf that is still open says so on its plan line
  (`TaskPlanItem::ANSWER_TEXT_AFTER_OPEN_ITEMS_MARK`: written by the answer of the turn that finished the items before
  it); the answer step after an owner question names only the items whose line says "write it in the answer of this
  turn"; the tick rules say a question to the owner is never such an item and that its answer comes after the items
  before it. A draft of part 3 was written while part 2 still waited for the owner.

## 0.1.0-alpha.46

- One evaluated run of one eval case moves into the package: `AgentEvalRunnerService` (single and follow-up turns,
  purpose, persistence, reload, restore, state check, teardown, applicability, eviction trajectory, recall seed, trace)
  with `AgentEvalRunHooksService` (13 hooks, neutral defaults), the case, scope, options, outcome, expectation,
  figures, restore plan, harness tool calls (`AgentEvalHarnessToolCalls`), extractions
  (`AgentEvalToolResultExtractions`), applicability (`AgentEvalNotApplicableReasons`) and
  `AgentEvalPreconditionNotEstablishedException`; `TaskPlanSize::fromMessagesOfConversation()`. The run applies the
  case's tool block itself for every caller; the state check lifts it for its read and cleanup; a case file's message
  trace no longer carries the conversation back-reference; the tool-message failure text says "widget".
- Turn-event subjects as constants: `AIConversationTurnEvent::SUBJECT_PLAN_RETIREMENT`, `SUBJECT_SKILL_PRELOAD_RERANK`.
- `load_skills` for a skill only the platform preloaded, asked by the model for the first time, answers "Skills
  loaded; their tools are now available." and the model takes the skill over (`source` model); "Already loaded" to
  that first own load made the model ask again and again. Repeats after that are answered as before.
- An `inAnswer` leaf that a turn's answer did not close (the turn ended on the owner's question, or an earlier leaf
  was open) records `answerTextHeldOpenAtMessageIndex`: its plan line says an earlier answer may already have given the
  text, and the model's done tick is accepted instead of a second copy. The tick rules say so.

## 0.1.0-alpha.45

- A model step that only repeated `load_skills` for skills already loaded is not replayed to the model: its call and
  result rows leave the wire (`ArgusAIAgentConversation::withoutStepsThatOnlyRepeatedSkillLoads()`; the stored rows
  stay; a prose head stays; a step with any other call stays; kept under the server-state wire), and the next step's
  tail says `[SIGNAL:skills] <skill> is loaded — its tools are in your tool list now: call the tool.` The model
  repeated its own freshest pattern instead of calling the loaded tool (21 of 1,237 runs loaded ≥ 3 times). The
  tools and the system content are unchanged.

## 0.1.0-alpha.44

- An `inAnswer` plan item is not closed by the answer of a turn that ends on the model's question to the owner (a
  completed `askOwner` with no later call but plan bookkeeping): it stays open and a later answer closes it. The
  answer that asked "go on with the drafts?" closed drafts never written. The tick rules say so.
- A plan the platform's turn-end close finishes retires like one the model's ticks finished: the owner's next message
  reopens it (keep the finished items by their keys) instead of being told to drop its items.
- The plan directive asks again for variants and sub-steps with their own result as subtasks of a part (and never
  an item or a subtask for loading a skill or another preparatory call).
- `[SIGNAL:clock]` also names this month and last month with their first and last day; the recorded-run comparison
  normalizes their month names.

## 0.1.0-alpha.43

- `inAnswer` plan items: the open line says the text belongs in this turn's answer (`TaskPlanItem::ANSWER_TEXT_MARK`);
  the answer step after a recorded owner question asks for the text of every such item; and the turn-end closer
  closes one only when no leaf before it in plan order is still open — an answer that asked the owner about an
  earlier part closed a draft it never carried.
- Owner-consent decision (`owner_explicitly_agreed_afterwards`): a wish in the same line about something the step does
  not change ("yes, go ahead — and the opening hours stay as they are") leaves the yes standing; what refuses is a yes
  that depends on something, moves to a later time, covers only part of this step, or keeps part of what the step
  changes. A yes with such a wish was read as a condition and the owner was asked again.

## 0.1.0-alpha.42

- The module ships eleven more console commands as bases an application subclasses under the same name: the chat
  (`app:ai-agent:chat`) and the full-screen chat (`app:ai-agent:chat-tui`, with the suggested `php-tui/php-tui`), the
  conversation inspector (`app:ai-agent:inspect`), the reaper (`app:ai-agent:reap`), the owed-turn recovery
  (`app:ai-conversation:deliver-owed-reports-and-resume-owed-turns`), the schedule state sweep
  (`app:ai-schedule:close-for-inactive-agent-spaces`; an application keeps an old name as an alias with
  `name: '<package name>|<old name>'`), the decision probe (`app:ai-agent:jev-probe`), the scorecards command
  (`app:ai-conversation:scorecards`), the MCP documentation and cache commands (`app:mcp:documentation`,
  `app:mcp:clear-cache`) and the node-schema export (`app:ai:composed-view:export-node-schema`).
  `MCPPackage::buildDocumentation()` takes `$noCache` like the bundle, and `clearDocumentationCache()` clears one
  package's documentation; the scorecards service selects conversations (`findConversationIdsForSelection()`).
- The skill templates of the sweep, owed-work and reaper commands are gone (the package ships the commands); the cron
  sample names the package commands.
- Removed: `ComposedViewNodeSchema::SCHEMA_FILE_RELATIVE_PATH` (an application's frontend path; nothing read it). The
  export command uses `ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH`.

## 0.1.0-alpha.41

- `update_task_plan` items take `inAnswer` (boolean): an item whose result is text in the answer (a draft, a summary)
  gets the delivery gate — the model's tick is not applied before the answer, and the platform closes the leaf from the
  turn's owner-visible answer (`TaskPlanItem::markResultIsTextInTheAnswer()`; model-made leaves only, never seeded
  items or containers). The tick rules and the plan directive say so.
- A container whose subtasks are all closed and none of them done takes its blocker's state: it renders `[✗ blocked: …]`
  and counts blocked, never ✓ (`TaskPlanItem::findBlockerOfClosedContainer()`); a part whose only subtask was blocked
  showed as done while nothing was produced.
- The plan directive (`AgentPlanDynamicsDecision`, and the base prompt's plan rule): one item per part the owner named;
  a subtask only for a separate result inside that part, never for loading a skill or any other preparatory call.
- `[SIGNAL:answer-step]` after an owner question recorded in this turn also names what the reads of the turn ask the
  answer to carry (the points they list, a ready widget block).

## 0.1.0-alpha.40

- `[SIGNAL:answer-step]` after an owner question recorded in this turn: first what the question's own tool result asks
  to stand in the text (a draft the question approves: every line of it), then the results of the closed plan items.
  The model wrote only the results, and the owner approved a draft that was never shown.
- Reply language of a FIRST owner message: inconclusive (the configured language applies) unless it holds two words
  written in lower case — names, brands and places with a preposition ("<Brand>, in Berlin.") were read as English
  with full confidence. A text without cased letters counts its tokens as before.

## 0.1.0-alpha.39

- A write the owner-consent check held (`OwnerConsentsService::reserveOwnerConsentForStep()` NOT_RESERVED) is marked on
  the call (`AgentToolCallProvenance::$isWriteHeldForOwnerConsent` → `ToolCall::$wasHeldForOwnerConsent`). The answer
  contract no longer lists it under "Completed so far in this turn" (its "nothing was changed" result came back
  COMPLETED); it names it under its own obligation: "Held for the owner's confirmation in this turn (nothing was
  changed): <tools>. Your final answer MUST ask the owner for that confirmation, as a question of its own".

## 0.1.0-alpha.38

- The search across conversations leaves out every sub-agent's conversation (`delegatedFromMessageId` set): a child
  of an eval run carries no eval mark of its own and answered as the owner's chat; what a sub-agent found reached its
  parent as a notification.
- The conversation list (the summary reads behind the session list) leaves out what the search leaves out: eval runs
  (unless they hold seeded history), schedule runs and conversations a schedule started.
- A tool with `MCPToolReceivesCurrentConversationId` always gets the calling conversation's id — a value the model wrote
  (`0`) no longer wins — and the agent loop's schema no longer offers the argument. A conversation without an id keeps
  the call's value.
- Checked-stop bounce: the discarded answer's content is to be written again in full, not a line saying it exists
  (`AgentCheckedStopPolicy::buildCheckedStopBounceText()`).
- Task plan update: after `Dropped: …` the result says that a dropped item's work is not delivered and that an item the
  owner did not cancel is added back and worked.
- `ContextRetentionSettingsOptionsTrait` can be reflected (a debug-mode container build failed with "Cannot access
  trait constant self::OPTION_UNLOAD_GRANULARITY directly").

## 0.1.0-alpha.37

- A sub-agent's result reaches the parent whole up to 8,000 characters (`DelegationRunEndNotification::VERDICT_MAX_LENGTH`,
  was 4,000): measured results of research sub-agents were 3,200–7,000 characters, and a cut one cost the parent a
  `check_delegations` call that read the whole result again on a grown context. Longer results are still cut with the
  pointer to `check_delegations`.
- Sub-agent directive (`SubAgentDirective.md`): a sub-agent is told that the main conversation is handed the first
  8,000 characters (about 1,000 words) of its result at once and reads the rest only with a further call — keep the
  result within that length, the findings first. Both numbers come from the constant (`{%VERDICT_MAX_LENGTH%}`,
  `{%VERDICT_MAX_WORDS%}`).

## 0.1.0-alpha.36

- `delegate` takes `parallelTasks`: ONE call starts one sub-agent per item (each item `label` and `task` +
  `skill_names` or `toolName` + `toolArguments`). The call's row carries the first item; every further item gets a
  `delegate` row of its own right after it, with its own record, number, deadline and run-end notification. Each item
  passes the model's guards on its own (a refused item fails its own row only); async, the children are admitted
  together once every row exists. A one-item list is the single form. Models emitted several `delegate` calls one
  response at a time despite the texts; an array argument is filled in one call.
- The `delegate` description, the `parallelTasks` description, the call row's answer line naming the other items and
  the per-item rejection carry the agreed wording; `Agentic.md` rule 3 says "ONE `delegate` call whose `parallelTasks`
  holds one item per sub-agent".

## 0.1.0-alpha.35

- `[SIGNAL:answer-step]` after a platform-written widget that ends the purpose: the text the last tool result asks for —
  every part it lists, in its order (the closing line alone dropped the owed sentences the result listed first).
- `[SIGNAL:answer-step]` after an owner question recorded in this turn (`askOwner`): a fourth wording — the results of
  the plan items closed in the turn, never the question again (the question was repeated as the whole answer).
- Mandate approval check: the payload names `currentWeekday` and `requestedValidUntilWeekday` on the space's clock
  (`AgentClock::formatWeekday()`); the prompt `ApprovalCheck.md` uses them for periods named by a weekday or a week and
  approves a window that matches one of two honest readings.

## 0.1.0-alpha.34

- `send_owner_report`: a `REPORT` declared before the report is refused while the task plan holds open items the model
  ticks (a plan this turn touched or a work-signal turn; once per turn), naming them. The step after an accepted
  `REPORT` has no tools, so the checked stop discarded the report written there and the report reached the chat twice.

## 0.1.0-alpha.33

- Tool-result budget: a result of a tool declaring `MCPToolResultIsNeverStubbedByTheBudget` no longer counts against the
  budget (`AIConversation::resolveToolResultRetention()`). Two page reads and a web search filled a 16k budget and the
  2k summary of the same round was stubbed; the stub asked to re-call the tool, and the model did.

## 0.1.0-alpha.32

- A structured tool result (a response DTO without markdown rendering, a bare domain object) now hands the model its
  platform note (`MCPToolResponsePlatformNoteProvider`) and its dictated widget type
  (`MCPToolResponseDictatedWidgetTypeProvider`); only a markdown-rendered result did. A search DTO, which must stay
  structured for `groupsFromToolCallId`, can name its call id in the note.

## 0.1.0-alpha.31

- `ConversationSearchResults` widget description (model-facing `.md`): the agent names the search's tool call in
  `groupsFromToolCallId` instead of copying the groups; the payload example shows what the agent writes and what the
  widget receives after the platform filled it.

## 0.1.0-alpha.30

- `ConversationSearchResults`: a block may name the search's tool call in `groupsFromToolCallId` instead of carrying
  `groups`; when the row is stored the platform writes the `groups` (and an empty `query`) of that call's stored
  structured result into the block (`ComposedViewBindingResolutionService::fillConversationSearchResultsGroupsOfRow()`,
  the hook and lookup of `$from`). The model no longer copies kilobytes of JSON (a copied block lost a bracket and was
  invalid). The search tool must return a structured result with a top-level `groups` list.

## 0.1.0-alpha.29

- `[SIGNAL:clock]` also names yesterday, tomorrow, this week and last week as dates of the space's zone (weeks from
  `AgentModelInput::FIRST_WEEKDAY_OF_THE_WEEK`, Monday; an application redeclares it): models computed "tomorrow" as the
  day after and read "last week" as the rolling seven days. The recorded-run normalization covers the new dates.
- `[SIGNAL:answer-content]`: a still-open part of the request — "a question is answered by the read that answers it, an
  order by the call that carries it out — a question is never an order to write" (it said "make its call now", read as
  an order to call the remaining write tool).
- Web: the polling spec of `AgentChat` no longer races its 150 ms answer delay under load (800 ms).
- `load_skills` and `load_tool` descriptions: "callable again at any step" is replaced by "A skill already loaded is
  never loaded again — its tools are in your tool list: call them." / "A tool already in your tool list is never loaded
  again — call it." (static text, the cached prefix stays stable across turns).
- A later repeated `load_skills` of a turn (the one followed by the step without tools) is answered with the loaded
  skills and their tools (`LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools()`) instead of the generic
  repeat text about the work being done and the next plan item.

## 0.1.0-alpha.28

- The search across an account's conversations answers from the owner's dialogue only, and decides it INSIDE its
  three queries (message vectors, compaction nodes, keyword) instead of after them: an eval run without seeded history,
  a conversation whose own or root purpose is customer-hidden or `Schedule.Run`, and a conversation whose own or root
  initiator type is `SCHEDULE` are neither read for a distance nor ranked nor given an over-fetch place
  (`DBAIConversations::restrictToConversationsTheSearchAcrossConversationsAnswersFrom()`). New host lists:
  `AIConversationPurpose::PURPOSE_NAMES_LEFT_OUT_OF_THE_SEARCH_ACROSS_CONVERSATIONS` and
  `AIConversation::INITIATOR_TYPES_LEFT_OUT_OF_THE_SEARCH_ACROSS_CONVERSATIONS`. Before, scheduled runs were searched
  and the excluded rows took the over-fetch places of the owner's messages. Requires `mgamadeus/ddd` ^3.33.0
  (`JSON_FIELD_TEXT` in DQL on MariaDB).

## 0.1.0-alpha.27

- npm: `AgentsStateAction` carries the index signature of redux 5's `UnknownAction`, so a redux 5 / react-redux 9 host
  store that registers `WidgetHydrationReducer` stays assignable to `Store<any, UnknownAction>` (before: "Index
  signature for type 'string' is missing in type 'AgentsStateAction'" at every `<Provider store>`).

## 0.1.0-alpha.26

- `[SIGNAL:answer-step]`: a third wording for the text-only step after a platform-written widget that ends the purpose
  — the closing text the last tool result asks for, no recap of the widget and no list of completed steps; the "never
  a report" sentence is absent there (`AgentModelInput::buildAnswerStepTailHint()`, under the same condition as the
  absent answer contract, `hasPlatformWrittenWidgetThatEndsThePurposeAfterPosition()` from the owner's newest input,
  `findNewestOwnerInputPosition()`). The budget closing step keeps its own wording when both apply; every other forced
  step keeps the default wording byte for byte.
- The host's builders read fresh: the docblocks of `AgentBaseSystemPromptCacheService::resolveAgentSpaceContext()` and
  `AgentSpacesService::buildAgentSpaceContextPromptSegment()` and the skills (stable prefix, host duties 7a) state that
  a builder the inputs version makes run again reads the host's records at that moment, never an object loaded before
  the write — else the old text is cached under the new version for the TTL.
- npm: `CUSTOM_VISUALIZATION_SANDBOX_CONFIG` and `resolveCustomVisualizationFrameUrl` are exported from
  `@mgamadeus/ddd-agents/widgets`, so a host can serve the sandbox frame from another site.

## 0.1.0-alpha.25

- Task plan: a leaf that asks for a tool call AND a rendered widget closes in the completed-call pass when the tool
  made the platform write that widget while the call ran
  (`TaskPlanItemPlatformCloser::closeWhenCompletedCallAndWrittenWidgetProveDone()`, read by
  `TaskPlanItems::closeLeavesProvenByCompletedCall()`), under the turn-end conditions; the plan note on the tool result
  names it. A leaf whose widget the model writes itself, a widget the owner answers and a leaf that waits for an answer
  text to the owner stay open until the turn end as before. The completed-call closings still never read the locked
  gate tools (`TaskPlanEvidence::withoutEntitlementLocks()`): the call that just completed meets the tool gate.
- Answer contract: `AgentModelInput::completedWritesAnswerContractHint()` is null once a row the platform wrote during a
  tool call after the owner's newest input holds a widget of a type that ends the conversation's purpose
  (`hasPlatformWrittenWidgetThatEndsThePurposeAfterPosition()`, `AgentModelInput::$widgetTypesThatEndThePurpose`, set by
  `AIConversation::putDirectivesIntoContext()` from `AIConversationPurpose::getWidgetTypesThatEndThisPurpose()`; empty
  for a delegation child). A turn without that widget, a widget of another type and a widget block in the agent's own
  text keep the contract.

## 0.1.0-alpha.24

- Final refusals: `MCPToolResponse::finalRefusal()` marks a refusal the same arguments meet again (missing feature
  flags or account rights, an ineligible scope, a space the account may not use, a mandate a sub-agent or a scheduled
  run cannot obtain); it travels as `ToolResult::$refusalIsFinal` (recorded runs keep it) and
  `ToolCall::$wasRefusedAsFinal` (`isRefusedAsFinal()`; true or null, so a stored call carries the key only when true).
  The recorded-run comparison leaves `wasRefusedAsFinal` out of a replayed call while the recording does not hold it
  (`AgentRecordedRunComparison::TOOL_CALL_KEYS_ADDED_AFTER_RECORDINGS`).
- Repeat after a final refusal: every identical write after a final refusal in the same turn — the second, third and
  every later call, with a gate kind or without one — does not run; the dispatch decides it before the repeat guard
  (`AgentRepeatedToolCallCheck::findFinalRefusalOfTheSameWriteInTurn()`), answers it with the refusal the first call met
  (`buildRepeatAfterFinalRefusalCorrective()`), stores it FAILED with `wasRefusedAsDuplicate` and `wasRefusedAsFinal`,
  and the next step runs without tools. The quoted refusal is cut at a sentence end at or below
  `MAX_QUOTED_FINAL_REFUSAL_CHARS` (1000, above the longest refusal the package writes; `cutAtSentenceEnd()`). A signal
  row of any source or a completed write between the refusal and the repeat lets the repeat run. A forked call keeps
  the flag its child stored; a DELETE rewritten to "already completed" keeps no final refusal, inline and forked.
- `#[MCPToolRequiresOwnerWordsAsEvidence]`: a tool judged on the owner's own words is never offered to a delegated run
  (`AIConversation::findToolNamesLeftOutOfADelegatedRun()`, part of `findBlockedToolNamesOfConversationTree()`, which the
  offer and the append-only tool set read; the `load_tool` sub-skill tools are filtered by the same names,
  `LoadableSubSkillTools::withoutToolNames()`). A call of it by name in a delegated run is refused finally before it
  executes (`AgentToolCallDispatchService::buildRefusalOfToolLeftOutOfADelegatedRun()`; the refusal tells the sub-agent
  to finish the rest of its task and name the open step), and the fork admission does not fork it. A `delegate` tool
  run of it is refused (`DelegationSubAgentRefusal::forToolRequiringOwnerWords()`, with its own text when the
  delegating conversation is itself a delegated run). `AgentMandatesMCPPackage::grantMandate` declares it; a host that
  redeclares the grant tool repeats the attribute.
- `load_tool` and the tools a conversation is never offered: requested names that
  `findBlockedToolNamesOfConversationTree()` returns are taken out before anything is loaded or promoted — no sub-skill
  is loaded for them. A tool left out of a delegated run is answered with the dispatch's refusal text, a tool the
  purpose blocks with "Not available in this conversation: `<name>`. It cannot be loaded or called here; continue
  without it."; the other requested tools load as before.
- Task plan: the tick rules (plan header, `set_task_status` description, maintenance rules) put a stop the owner
  ordered first — the turn ends there and the open items stay open. The checked-stop bounce text names that stop: no
  item is worked, the final answer repeats what was said and names the items that stay open; otherwise the final
  answer reports every item.
- Skill catalog: `LoadSkillsTool::renderCatalogBlock()` takes the skills the same prompt build renders in its
  loaded-skills block and marks their lines `- <name> — LOADED, its tools are in your tool list — <description>`
  (`renderCatalogLineOfLoadedSkill()`), under a header that names the mark, the loaded-skills block and a skill
  announced as loaded later in the conversation (`CATALOG_HEADER`; under append-only loading a skill loaded inside the
  epoch is unmarked and announced by the appended item). Without such names the block is byte for byte as in
  alpha.23 (`CATALOG_HEADER_WITHOUT_LOADED_MARKS`). `AgentModelInput` passes the names exactly when it renders the
  loaded-skills block. Under the system-prompt swap a skill load therefore also changes the catalog lines of the
  skills it loads, inside the cached part before the loaded skills. The `load_skills` schema is unchanged.
- Message writes: `AIConversationMessagesService::openClaimFencedWrite()` locks the conversation row in every fenced
  write and compares the run token only when one is held; the parent's notification of a sub-agent run end
  (`AIConversationDelegationNotificationsService::notifyParentOfSubAgentRunEnd()`) is such a fenced write on the
  parent. A fenced write without a run claim (an inline console drive) and that notification do not fail with
  MariaDB error 1020 under `innodb_snapshot_isolation` when another session changes the conversation row after the
  write's snapshot. The inbound writers `persistInboundRowIndexSafe()` and `persistSignalWaitingForRunningTurn()` take
  the message index lock without that row lock. A continuation run with a deadline row
  (`AIConversationDelegationSpawnService::openContinuationRunOfRecord()`) locks the parent's conversation row before
  the `delegate` row (`AIConversationMessagesService::lockConversationRowOfStoredDelegateCall()`), the order of that
  notification: the deadline row's foreign key points at the parent's row, and with the `delegate` row locked first
  the continuation and the notification of the same sub-agent would end as a deadlock on both database engines.
- Repeat guard: `AIConversationToolCallHistory::countCallsWithSignature()` does not count a repeat the loop answered
  with an earlier final refusal — that call ran nothing, and counted it refused the identical call a later signal row
  lets run again.
- `update_task_plan`: an element that removes an item names it by key at any level of the call — no move rejection
  for it; a removal inside a subtree the call drops anyway is accepted and named once with that subtree; the seeded-item,
  repeated-key and "a container stays a container" rejections apply through it as before.
- npm package (`web/`): `./chat` exports `PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES` and `SIGNAL_SOURCES_THAT_OWE_NO_WORK`
  (the SIGNAL sources the message list shows as a call marker and the ones that never make a failed run retryable);
  `./renderer` exports `getWidgetClickSignalSources` and `isWidgetClickSignalSource` (the package's widget click
  sources plus `AgentsEnvironment.furtherWidgetClickSignalSources`). `public-api.json` declares the new names.
- `./client/testing` adds `simulateGoogleMapsAuthFailure` and `resetGoogleMapsAuthFailureForTests`: a spec or story
  makes the `Map` node act as if Google refused the Maps key, and resets the flag afterwards. Both act on the same
  module the map component reads; the entry imports no chunk it did not import before.
- The chart palette's documentation names the tokens it reads (`--agents-chart-01` … `--agents-chart-08`). No
  behaviour change.
- `./renderer` exports `composedViewTreeToWire`: the kept tree `readComposedViewTree` returns, in the wire form the
  backend writes for the block, so an app compares its own widgets' reading with its backend's.
- `./renderer` exports `readOpenEntityActionTarget(action, currentRow?)` and its result type `OpenEntityActionTarget`
  (entity type, entity id, agent space key): the entity an `openEntity` action opens, from the backend-resolved
  `entity` of the action or, for a row-bound action, of the row it fires in. A host's own `onOpenEntity` of
  `ComposedViewRenderer` reads the action with it.
- The root entry exports `getEntityDetailBody(entityType)`: the registered entity detail body of one entity type (the
  package's or an app's), or `undefined` when none is registered.
- `./preview` exports `EntityMentionAskFlowStory`: the "Ask about this" flow of `EntityMention` (the real ask button
  beside the real composer strip of the chat input) as a ready-made story body; `maxWidth` narrows it. `public-api.json` declares the new names of this release.
- `./client/testing`: `restore()` of `installWidgetHydrationStandIns` aborts and forgets the reads started through the
  stand-ins that have not settled. Before, a read left pending (`{ __pending: true }`) kept its hydration key in
  flight past the unmount and the restore, and the next mount of the same key, also with a fresh state store, stayed
  loading.
- `./contract` exports `buildChangedMessagesRequest(messages, pageSize)`: the request a live update reads (the highest
  loaded id, the lowest index that can still change, the page size), or `null` when nothing is loaded. A binding tests
  its own endpoint filter against the requests the chat sends.
- `./chat` exports `ConversationDeepLinkedWidget` and its props: the mount of one deep-linkable widget a page opens
  (`?agentWidget=`), with `openedByPage`, the entry's feature gate and the widget error boundary.
- `./client/testing` exports `storeOpenCanvas`, `readStoredCanvas`, `clearStoredCanvas` and the type
  `StoredCanvasPointer`: the pointer a conversation keeps in `sessionStorage` for its open canvas, written, read and
  cleared as the chat does.
- The package suite covers `readMapView` (aliases, dropped and capped points, the route line) and the message reads
  of `useConversationMessagePages` (a changed-tail read held while the open's newest page loads, dropped after a reset,
  retried as the newest page after a failure). No behaviour change.

## 0.1.0-alpha.23

- Chart legend markers: `radius` and `shape` are both set, so the peer range of ApexCharts (`^3.35`) type-checks and renders round markers before and after 3.45.
- npm package (`web/`): an application builds its screens on the published entries alone, without the package's
  source files. New entry `@mgamadeus/ddd-agents/provider` (the provider without the registrations and the
  stylesheet); the root entry and `./provider` also export the prop types of every replaceable component
  (`AgentsComponents`, `AgentsTextProps`, …). The existing entries export the components and helpers an application
  composes from: `./chat` (`CanvasPane`, `ConversationMessageList`, `ConversationMessageInput`, the message rows, the
  activity ticker), `./kit` (`ModelText`, `EmbeddedConversationWidget`, `ApexChart`, `useChartPalette`,
  `useNumberLocale`, the entity-detail body contract), `./renderer` (`ConversationWidgetRenderContext`,
  `ConversationWidgetActions`, the agent-space frame, header and hooks, the canvas anchor hooks and reducers, the render
  report signal, the deep-link allow-list, `unregisterWidgetHydrator`), `./widgets` (`WidgetLabel`, `CardFooter`,
  `DonePill`, the skeleton shapes, payload validation, the concept explainer parts), `./preview`
  (`mapFixtureToDeclaredAgentSpaceKeyFieldName`) and the root entry (the definition types and folder readers of the
  entity-detail and concept-explainer registrations, `ComposedViewDataSourceFetcher`). Internals stay unexported.
- `./client/testing` adds the stand-ins a spec uses instead of mocking package modules:
  `installWidgetHydrationStandIns` (hydration answered by data, a failure or a pending read) and
  `installTestConversationWidgets` (a registry of test widgets); both return an object whose `restore()` undoes them.
- Non-code files an application reads are published under `dist/files/` and exported one subpath per file, at their
  path below `src`: the widget documentation (`*.md`), every `*.preview-fixtures.json`, the widget contracts, the node
  schema (`renderer/widget-tree/composed-node-schema.json`), the sandbox frame page, the conversation tokens
  (`chat/_conversation-tokens.scss`) and three SCSS modules; the SCSS partials they import are published beside them.
  `exports` is the one list the build copies from.
- `scripts/check-package.mjs` fails the build when an export target is missing or outside `files`, when a built entry
  is no declared subpath, or when a built entry lacks a name `public-api.json` declares for it (the new file lists the
  values and types of every code subpath; `--write-public-api` rewrites it after a deliberate change).

## 0.1.0-alpha.22

- `#[MCPExposedFilterProperties]`: the executor reads the model's raw `filters` and `orderBy` strings before the
  arguments are hydrated, right after the eligibility gate and before the mandate gate
  (`MCPToolExecutorService::refuseRawQueryOptionArgumentsOutsideExposedFilterProperties()`). A property outside the
  tool's list is answered with the tool's allowed properties on the first call, also when the entity does not know
  the property or an `orderBy` entry is empty (named `(empty entry)` in the refusal). A `filters` string that does not
  parse or validate leaves the call to hydration, which reports the error as before; a filter inside `expand` is answered
  by hydration as before. The check on the hydrated request objects stays; both build their refusal in
  `refusePropertyNamesOutsideExposedFilterProperties()`. Tools without the attribute are unchanged.

## 0.1.0-alpha.21

- Release workflow: the npm publish step logs verbosely. No code change.

## 0.1.0-alpha.20

- `delegate`: a `toolName` written with the namespace of the model's function list (`functions.<tool>`) names the
  tool after it instead of being refused as not offered (`AgentDelegationToolRunTask::readToolNameFromArgumentItem()`,
  also used for `namesBothTaskShapes` and tool-result task requests). `functions.` alone names no tool. Every name
  without the prefix is read as before.

## 0.1.0-alpha.19

- Stall classification: text of a step the loop sent with tool choice `none` (the answer step after a refused repeat,
  an owner-gate refusal, a result that ends the purpose, the step before the budget closes) is no longer judged as
  "text after a skill load" and is delivered instead of nudged and discarded; the checks of a final text still run on
  it. `AgentStepToolChoice::letsTheModelCallTools()` / `offersWorkToolsToCall()`; trace
  `turn.stall.textOfStepWithoutToolsNotJudged`. Steps sent with tools are unchanged.

## 0.1.0-alpha.18

- The module ships its plain console commands (`DDD\Symfony\Commands\Agents\`, 20 with the mark command) and the
  chat line editor: schedule fire, compact-finalized, the two event purges, maintenance (snapshot strip, capability
  rewrite, embedding repair and backfill, environment backfill), diagnosis (diagnose, validate-conversation,
  cache-diff, activity-lines, tool-response-stats, skill-token-map, context-eviction-sim, compact, search-inspect) and
  the hidden child-process command `app:ai-agent:execute-tool-call`. A command runs on a schedule only from the cron
  line the host registers (`templates/crons.sh`).
- **Behaviour change:** `agentToolCallChildProcess.commandName` defaults to the module's
  `app:ai-agent:execute-tool-call`; `commandName: null` no longer disables forking — an application that declares a
  `consoleScript` now forks with the module's command; unset `consoleScript` to stay inline.
- The schedules service answers which skip an occurrence was (`isSkipReasonOf…()`), and every skip word is produced
  through `static::`.
- Cron rows: a host that registered the former template rows (`agent-schedules-fire`,
  `agent-conversations-finalize-idle`, `agent-events-purge`) keeps them until it deactivates them; `crons.sh` keeps
  `agent-schedules-fire` (an upsert switches its command) and names the split finalize and purge rows anew.
- Developer skills: the command list, the host duties, the cron list and the child-process contract follow the
  shipped commands; the templates for firing schedules, reducing finalized conversations and purging events are
  removed (the package commands replace them).
- Test kernel: the cache key is a fingerprint of path, time and size of every watched file.

## 0.1.0-alpha.17

- Package prompt defaults: the owner's formal or informal form of address is one rule in `Agentic.md` and
  `Character.md` (recorded with a tool only when the conversation offers one); the recall passage names no
  application skill.
- `AIConversations::getSentenceOnReadingASessionInFull()`: the default says which tool reads a session and what to pass.

## 0.1.0-alpha.16

- Task plan tick rules: an item whose read or call returned nothing usable is not done, it is blocked.
- Checked-stop bounce: when the owner asked to stop after a part and check back, the answer reports that part and ends
  with the one question; the later items stay open.
- Package prompt defaults: an answer without a widget withholds nothing; no promise to remember a personal statement;
  a limit on the text is counted before it is claimed (`Agentic.md`); the example question in "Drive" is described,
  not quoted (`Character.md`).

## 0.1.0-alpha.15

- A spec of the frontend writes its deeply nested JSON text directly: `JSON.stringify` exhausted the call stack on
  the CI runner and stopped the npm release of 0.1.0-alpha.14.

## 0.1.0-alpha.14

Composer only: the npm release of this version stopped at the specs (see 0.1.0-alpha.15).

- First pre-release of the frontend `@mgamadeus/ddd-agents` (npm, dist-tag `next`) from `web/`, released in lockstep
  with the Composer package: the agent chat, the widget renderer, the composed-view kit, the conversation client and
  the image render. Built with Vite, declarations from `tsc`; the package's own specs run in the package folder.
- Release workflow: a tag `vX.Y.Z` publishes `web/` on npm by trusted publishing with provenance; CI builds and tests
  `web/` on every push.

## 0.1.0-alpha.13

- **Host work required.** The code no longer names tools of an application. Five method attributes replace the name
  lists: `MCPToolReceivesCurrentConversationId`, `MCPToolWritesAgentMemoryOnly`,
  `MCPToolResultIsNeverStubbedByTheBudget`, `MCPToolShowsNoActivityLine`, `MCPToolSendsMessageToOwner`. A host
  declares them on its tools: without the declaration the conversation id is not filled in, a memory write neither
  runs inline beside forked calls nor refreshes the standing directives within a run, a research read is stubbed by
  the budget, the memory tool shows an activity line, and a send tool stays callable inside a scheduled run.
- Removed: `AIConversation::BUDGET_EXEMPT_TOOL_RESULT_NAMES`,
  `AgentToolCallForkJoinService::INLINE_WRITES_OF_CONVERSATION_STATE_ONLY`, the `blockedToolNames` line of
  `Purposes/Schedule/Run.md`.
- `AIConversationPurpose::getToolNamesBlockedByDeclaration()`: a purpose class blocks tools by what they declare. A
  purpose that inherits `Schedule.Run` keeps the block of send tools only when its class extends `ScheduleRunPurpose`.
- The prompt files `Agentic.md` and `Character.md`, the closing line of the earlier-sessions text
  (`AIConversations::getSentenceOnReadingASessionInFull()`, overridable) and the two catalog texts of the
  `ConversationSearchResults` widget name no tool of an optional skill. A host that binds such tools states them in its
  set class and in a subclass of the widget.
- The header of the skill catalogue says that the list names loaded skills too and that a loaded skill is not loaded
  again.

## 0.1.0-alpha.12

- A `load_skills` call that names no skill is answered with the loaded skills and their callable tools by name when
  both exist.
- The first model step of a turn whose repeated `load_skills` call the repeat guard answers, while tools of loaded
  skills are callable, is answered with those tools and no longer forces the tool-less step; the next repeated
  `load_skills` of the turn gets the generic corrective and the tool-less step as before.
- The note before the forced `send_owner_report` step asks for the outcome and names NOTHING_TO_REPORT first.

## 0.1.0-alpha.11

- Console commands: the package ships its first command, `app:ai-conversation:mark-finalized`, and the groundwork
  every later command builds on: the abstract `AgentConsoleCommand` (default account, whole-run rights lift, budget
  bypass by `--ignore-budget`, run-level usage envelope, space by `--agentSpaceId` or the application's own options;
  the tear-down runs each step once, also after a failure), `AgentConsoleProgressReporter`, `AgentEvalArmOptionsTrait`
  (every arm option needs a value) and `ContextRetentionSettingsOptionsTrait`. An application replaces a command with
  a subclass that repeats `#[AsCommand]` with the same name.
- Value objects `ContextRetentionSettingsOverride` and `CompactionSettingsOverride` (`applyTo()` returns a merged copy;
  a value that is no whole number, below the knob's minimum or too large is refused with
  `SettingsOverrideValueRefusedException`), `AgentEvalArms`, the interface `AgentRecordedRunSessionInterface`.
- `AgentSpacesService::getConsoleScopeOptions()` (an `AgentSpaceConsoleScopeOptions` set, empty by default) and
  `findAgentSpaceForConsoleScopeOptionValues()` (null by default): override points for naming a space on the console.
- `AgentAIUsageScope`: `ENVELOPE_AI_CONVERSATION_SEARCH`, `ENVELOPE_AI_AGENT_EVALUATION`, `AI_AGENT_SIMULATE_OWNER`,
  `AI_AGENT_JUDGE_EVALUATION`.
- Design-decision references of the context, autonomy and decisions-and-guards skills: six reasons now argue from the
  mechanism, and where a measurement decided they name what a host measures itself.

## 0.1.0-alpha.10

- The tool-less step after a refused identical write is forced only when the loop already refused that call once in
  the turn. Before, a repeat of a write that ran in the same turn paused the tools on its first refusal.

## 0.1.0-alpha.9

- A `done` / `not_applicable` tick on a criterion leaf the record parks (`blockedByOwnerStatement()`) is no longer
  applied: a leaf the platform already parked keeps its park, any other leaf takes the record's park now
  (`TaskPlanItem::adoptRecordParkInsteadOfTick()`, `isParkedByThePlatform()`). Behaviour change of the gate-table row
  G8 "a blocked verdict": it now reaches RECORD instead of G28.

## 0.1.0-alpha.8

Documentation only; no behaviour change.

- Context skill: a compaction node's `coveredTokens` is the model-counted footprint of its raw rows (a roll-up sums its
  children's); the per-model default threshold names `AIModelsService::defaultEffectiveContextTokensForWindow()`.
- Loop skill: the three answers to a tick that repeats an item's status, when such a call fails, what the all-no-op
  breaker counts, and that a later same-status tick with a note replaces the item's run-time note.
- Docblocks of `AIConversationCompactionNode::$coveredTokens` and `AgentTaskPlanTickOutcome::isAllAlreadyInStatus()`
  corrected; a comment no longer cites source line numbers.

## 0.1.0-alpha.7

Documentation only; no source class changes.

- The developer skills carry the remaining generic facts about the agent core (43 skill files extended); descriptions
  and the source-ownership manifest are unchanged.

## 0.1.0-alpha.6

Documentation only; no source class changes.

- Developer skills: the entry skill `ddd-module-agents-specialist` (install, wiring, host duties, configuration,
  folder → skill table, templates for the host's console commands, middlewares, `messenger.yaml` and crons) and eight
  topic skills `ddd-module-agents-{loop,context,skills-and-purposes,tools,widgets,autonomy,decisions-and-guards,observability}-specialist`.
  `references/source-ownership.tsv` of the entry skill names the owning skill of every file under `src/` and `config/`.
- Contract tests `tests/Contract/DeveloperSkill*Test.php`: skill format, source ownership, every named identifier
  exists, every PHP template passes the syntax check.
- `README.md` and `AGENTS.md` describe the current state (config defaults, the package's own suite, the `ddd` floor
  `^3.27.0`).

## 0.1.0-alpha.5

- A `delegate` call that names `toolName` is a tool run, also when it carries a `task` text beside it (the text is
  not read). Before, such a call was refused and the model ran the tool itself in the main conversation. Only a call
  with neither shape is refused.

## 0.1.0-alpha.4

- The answer contract names what the final answer contains, not that it is due: a part of the owner's request that
  is still open is worked first.
- The plan directives that ask for a plan are no longer sent once the turn changed the plan; the plan-creating
  directive names sub-steps and variants as subtasks; the direct band asks for every call the request needs.
- The result text of an unchanged plan resend orders no work: the next open item, or the answer when the owner asked
  to be asked first (`TaskPlan::buildUnchangedResendResultText()`).
- A load call the repeat guard answered counts as no load for the stall check.
- A turn's first model call names the skills loaded right now (`[SIGNAL:skills]`, tail entry kind `LOADED_SKILLS`).

## 0.1.0-alpha.3

- The grant result of a standing mandate (time window or unlimited) asks the model to tell the customer in one
  sentence what it may now do on its own, until when, and that a word from them stops it.
- System prompt: a plan item is `done` only when its result exists; a load, create or publish item whose call could
  not deliver is `blocked`. A blocker is reported together with the alternative that is available.

## 0.1.0-alpha.2

- The answer contract owes the outcomes of the writes since the owner's newest input: their text or, newer than it,
  their own signal (a widget submit, an action confirmation). Before, a turn started by a widget submit listed the
  writes of the previous turn again, and the model repeated that turn's whole answer.
- The doc comment of `CustomVisualizationConversationWidget::$script` names the package's sandbox global
  `window.AGENTS_VISUALIZATION`.

## 0.1.0-alpha.1

First pre-release. Requires `mgamadeus/ddd` ^3.27.0, `mgamadeus/ddd-ai` ^3.2.1, `mgamadeus/ddd-argus` ^1.3.2,
`mgamadeus/ddd-common-money` ^1.1.1, `erusev/parsedown` ^1.7.

First version of the module: the agentic loop on top of ddd-ai.

- Conversations, messages, agent runs and turns, the task plan, purposes, delegation to sub-agents, schedules,
  mandates, compaction, the decision calls and the supervisor, with their repositories for MariaDB and PostgreSQL.
- The agent space (`AgentSpace`): the work context a conversation, a schedule and a mandate belong to. An
  application derives it and references its own entities from it.
- Conversation widgets and the composed view: block syntax, the widget registry, the kit of composed nodes and the
  names an application declares for its own blocks (`Widgets/Syntax/WidgetBlockFieldNames`).
- The MCP / JSON-RPC wire DTOs and the tool executor under `DDD\Presentation\Mcp\`.
- What an application declares instead of the module: its names outside a conversation
  (`config/app/AI/agentApplicationNames.php`), its wording towards the model
  (`config/app/AI/agentApplicationWording.php`), its purposes, skills, tools and widgets.
- `AgentsModule` with the namespaces the module owns (`AgentsModule::OWNED_NAMESPACES`).
