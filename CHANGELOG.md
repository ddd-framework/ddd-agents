# Changelog

## Unreleased

- **Conversation search for every host (`agent.conversations`).** The package now carries what applications built
  themselves:
  - New tool `searchThisConversation(query, limit?)`: searches the conversation the owner talks in (the root of a
    delegation tree) by meaning and words, its messages and the regions of its compaction nodes
    (`SemanticSearchService::searchWithinConversation()`); long excerpts, the message number of each match; a failed
    index answers with a note (issue logged), never an error. Service: `AgentConversationRecallService::findThisConversation()`,
    `searchThisConversation()`, `isOwnConversation()`.
  - `readConversation` takes `aroundIndex` (a match's message number): the dialogue around it (a third of the page
    before it, default 10 messages), the match marked. The conversation the call runs in and its tree root are read
    under the read rights alone, without the recall switch and the audience rule.
  - `searchConversations` without a query lists up to 25 conversations (a period with `fromDate`/`toDate`); the
    current conversation and its tree root are left out of every search.
  - `searchConversations` hands its matches to the `ConversationSearchResults` widget: the result carries the top-level
    `groups` (`AIConversationSearchGroup[]`, the chat search's shape) and `query`, is a `ComposedViewBindableResult`,
    and names the ready block with `groupsFromToolCallId` = the call (`conversationSearchResultsWidget`, also in the
    markdown); the platform fills the groups when the answer is stored. Outside the loop no block is named.
  - `ConversationSearchResultsConversationWidget`: `WHEN_TO_USE` names `searchConversations` and `searchThisConversation`;
    a host's same-type subclass is needed only for a search tool of its own. The widget doc
    (`web/src/widgets/ConversationSearchResultsConversationWidget/…md`) carries the class's `SUMMARY` and `WHEN_TO_USE`
    again (its "When to use" named `recallConversation`, a host tool; a host test that compares class and doc failed
    once the host's subclass was gone).
  - Skill `Agent.Conversations`: this conversation, reading around a match, listing a period, showing the matches,
    telling a match as what it is (which conversation, who said it); `providesWidgetTypes: ['ConversationSearchResults']`;
    `decisionCriteria` within 200 characters (0.11.7 had 226; a host's catalog test may cap it).
  - The default closing line of the earlier-sessions text (`AIConversations::getSentenceOnReadingASessionInFull()`)
    names `readConversation` (skill `Agent.Conversations`).
  - Extension points stay the same-path subclass of `AgentConversationRecallService` (`isConversationReadByOthers`,
    `describeAgentSpace`, `findRecallCandidates`, `findReadableConversation`, `findOwnConversation`, `buildRow`,
    `buildRecallMessage`) and of `AgentConversationsMCPPackage`.
  - Backward compatible: the tool names and arguments stay; `aroundIndex` and the `forResult()` tool call id are optional.
- Web: `package.json` exports the widget docs of `ContinueInPersonalSpace`, `ExtendConnectionPermission` and
  `MailDraftPreview` (0.11.x shipped the widgets without them; the build has to copy the three `.md` files to
  `dist/files/widgets/` like the others).
- Fix: the search of a space's conversation list by summary (`DBAIConversations::findByAgentSpaceAndSearchTerm()`)
  read `LOWER()` over the JSON column `summary`, which PostgreSQL refuses; it now reads the summary's title and text as
  text (`JSON_FIELD_TEXT`) on both engines, and a `%` or `_` in the term is a literal character.
- `AIConversationSignalSource::CONNECTION_APPROVAL`: the core source the connection approval writes
  (`AIConversationMessage::SIGNAL_SOURCE_CONNECTION_APPROVAL`) is a case of the core enum (a platform statement; it
  starts no turn). A test keeps every core `SIGNAL_SOURCE_*` constant a case of the enum.

## 0.11.7

- Voice: with a fixed call language, speech in another language is side talk in the room; the agent does not answer,
  translate or hand it on (`Prompts/AI/Voice/LanguageFixed.md`). A delegated call task reads an e-mail address, phone
  number or web address heard only in the transcript back once, spelled out, before anything is sent to it (that is
  the one confirmation); side talk aimed at others is no request (`AgentCallTasksService`).

## 0.11.6

- **Security: no image in a mail the agent sends.** `AgentConnectionMimeMessageBuilder::buildHtmlBody()` renders the body
  through `AgentConnectionMailMarkdown` (a markdown image becomes its alt text) and removes any `<img>` before
  `Datafilter::sanitizeHtml()`: a remote image is a tracking pixel an injected instruction could plant in a mail the
  agent writes. Links stay. The web `MailDraftPreview` card shows an image of the body as its alt text too.

## 0.11.5

- **Connections: an explicit send order is the yes, at most one confirmation.**
  - `SEND_BY_RULE` also covers, in an attended run outside a chat thread and in a conversation only its owner reads,
    recipients the owner wrote in their own messages of the conversation (`findAddressesNamedByOwner`); internal
    recipients as before; removals still ask. In a conversation others read an address in a message names no recipient
    for the rule (`AgentConnectionsService::isConversationSharedWithOthers()`; the host's default answer is "read by
    others").
  - A mail send that waits for the owner's yes shows the exact mail once as the tool-written `MailDraftPreview` widget
    (DRAFT, one send button) and tells the model to write one short line — no copy of the mail, no `askOwner`. After a
    shown preview a refused consent tells the model never to ask again. A sent mail is shown as a SENT preview.
  - The tool-written DRAFT preview of exactly this content (`contentHash` = the step's hash, on a TOOL_MESSAGE row with
    `emittedBy: tool:…`) is the consent step's shown-content evidence (`findShownMailPreviewIndex`); the consent
    dialogue reads it as a WIDGET line (`composeShownMailPreviewText`). A block the model typed into its text never counts.
  - The owner-consent criteria name short send orders with typos ("senden", "snde", "schick", "raus"), the preview's
    button, and a bare "ok"/"ja"/"passt" right after the question as a yes.
  - Mails go out as `multipart/alternative`: the body as written plus its markdown rendered to HTML (Parsedown, safe
    mode, then `Datafilter::sanitizeHtml()`).
  - Skills `Agent.Connections.Mail` and `Agent.Connections.Overview`: call `sendMail` at once, one confirmation at most.
  - Decision fixtures: `"shownAs": "mailPreview"` cases; the connection-send fixture covers the preview and typos.
  - Web: the package renderer `MailDraftPreview` (DRAFT with one send button, SENT chip). A host that registered its own
    renderer with `replacesPackageWidget: true` can drop it.
- Fix: two by-reference notices in `OwnerConsentsService::addShownContentLine()`.

## 0.11.4

- **New: the skill `Agent.Conversations` (package `agent.conversations`, READ, parallel safe).** `searchConversations`
  searches the owner's earlier conversations in every space they may read (titles and summaries by word, messages
  through the chat's own search: fulltext, vector, rerank); without a query it lists the most recent. `readConversation`
  reads one page by page. Visibility is the chat's list and search (read rights, customer-visible, the owner's
  dialogue); every text is an untrusted passage. Audience rule: a conversation others read receives text only from
  conversations of its own space (`AgentConversationRecallService::isConversationReadByOthers()`, host hook, default
  true = fail closed; a thread of a shared chat channel always counts as read by others). Honours
  `isCrossConversationRecallEnabled()`. Bound in the package's `skillPackages.php`; a host that replaces the file adds
  `AISkill::AGENT_CONVERSATIONS => [AgentConversationsMCPPackage::class]` and answers the hook for its spaces.
  `Agent.Memory` points to it for what was said.
- Eval recall: a seed block may name its own space (`agentSpaceId`: id or `{{ref:agentSpace:…}}`) and owner
  (`ownerOperatorKind`, hook `AgentEvalRunHooksService::resolveSeedOwnerAccountId()`); the case key
  `expectAbsentMentions` fails a case whose answer leaks the text. The result row has a new column `leakedMentions`.

## 0.11.3

- Fix (web): the default glyph of `lockOpen` (a key, ⚷) differs from `lock` (⚿).

## 0.11.2

- **Fix (web): the `ContinueInPersonalSpace` and `ExtendConnectionPermission` widgets lay out as one row.** The widget
  surface's column direction won over the widget's own row, so the notice's flex basis became a 240px height and the
  button dropped below it; the notice and the button now sit in a row of their own inside the surface (wrapping on a
  narrow pane).
- **Fix (web): every icon name has a glyph in the package default.** 35 names of `AGENTS_ICON_NAMES` (among them
  `lockOpen` and `settings`, used by the new widgets) drew the fallback dot; the default glyph map now covers the whole
  list, and a test keeps it so.
- `ExtendConnectionPermission` documents that the block's `reason` is not shown (the widget shows its own interface text).

## 0.11.1

- **Decision fixtures: the owner-consent site.** `app:agent:decision-fixtures` runs `"site": "ownerConsent"` fixtures:
  each case's rows go through the live dialogue builder (`buildOwnerConsentDialogue`, `addShownContentLine`) and the
  live `decideDialogue`, scored on consent against the expectation. The fixture
  `tests/Fixtures/Decisions/OwnerConsent.ConnectionSend.json` covers a connection send with the shown-content evidence:
  an order to send what was shown is a yes, an unrelated, deferring or holding-back message stays no.

## 0.11.0

- **Web: the `ContinueInPersonalSpace` widget.** A notice and a button "Continue in your personal space" for a request
  that only a connection of the owner alone may answer, asked in a conversation others read. The click calls the new
  environment hook `startConversationInPersonalSpace(ownerText)`, which starts a new conversation in the owner's
  personal space; nothing goes back into the shared conversation. Without the hook, or without a `request` in the
  block, the widget renders nothing. The backend that writes it arrives with the agent connections.
- **Web: the `ExtendConnectionPermission` widget.** A notice and a button "Extend the permission" for a step that needs a
  level or provider permission an agent connection does not have yet. The click calls the new environment hook
  `openConnectionPermissions({connectionId, capability, level})` (type `AgentsConnectionPermissionTarget`), which opens
  the app's connection settings with that permission preselected. Without the hook, or without a connection, capability
  and level in the block, the widget renders nothing.
- **New: AgentConnections.** The agent works through an account's or a space's external accounts (Google mail,
  calendar, contacts, drive and native documents; IMAP/SMTP mail; Slack) under per-capability levels (READ … SEND_BY_RULE)
  with a gate in front of every provider call: the level, the stage guard (outside production only allow-listed
  recipients), send limits, the audit (`AgentConnectionActions`, external addresses hashed) and a person's yes for
  every outward step that needs one (in the conversation through the owner-consent step, unattended as an
  `AgentConnectionApprovalRequest` answered on the admin screen or in a chat thread; one answer wins).
  - Tables (all new; nothing changes on an existing table): `AgentConnections`, `AgentConnectionCredentialSets`
    (encrypted secrets), `AgentConnectionPermissions`, `AgentConnectionReleases`, `AgentConnectionActions`,
    `AgentConnectionActionTargets`, `AgentConnectionApprovalRequests`, `AgentConnectionAccountLinks`,
    `AgentConnectionAddresses`, `AgentConnectionThreads`, `AgentConnectionTriggers`, `AgentConnectionInboundEvents`,
    `AgentConnectionDriveRoots`. Every read path is behind `AI.agentConnections.enabled` (off by default).
  - Sharing: a connection is the owner's ("only me") or released to an agent space with a ceiling per capability
    (`AgentConnectionCapabilityCeilings`) and boundaries (`AgentConnectionScopeBoundaries`: folders, labels,
    calendars, contact labels and sources), both typed sets stored as JSON. In a conversation only a release to the
    conversation's own space counts (`AgentConnectionReach`); the management screens see every member space.
  - Google consent is incremental: the connect asks for the read scopes of the chosen capabilities plus identity; a
    higher level asks for its additional scopes first (`startOAuthReconsent`, `include_granted_scopes`) and takes
    effect only after the provider granted them (`AgentConnectionConsentRequiredException`, HTTP 409
    `consent_required`). A tool that needs a missing scope refuses and shows the owner the new widget
    `ExtendConnectionPermission`.
  - A connection the audience of a shared conversation may not see is refused there and offers the widget
    `ContinueInPersonalSpace` (`{request, reason}`); the reason comes from the host hook
    `AgentConnectionsService::getOwnerFacingText()`.
  - Outward steps carry typed arguments (`AgentConnectionStepArguments`); the approved content hash is taken over
    their stored form. The per-recipient limit counts exact targets (`AgentConnectionActionTargets`).
  - A merge of contacts deletes its donor: it needs the delete level and always a yes, also under a rule.
  - Mail triggers start a run only for a new mail of the poll window, inside the trigger account's label boundary,
    from a sender the mailbox's own server authenticated (trusted authserv-id, DMARC with an aligned header.from).
  - Skills `Agent.Connections.Overview`, `.Mail`, `.Calendar`, `.Contacts`, `.Files`, `.Documents`, `.Chat`; admin
    endpoints `AdminAgentConnectionsTrait`; OAuth callback per provider; Slack Events API and Socket Mode.
  - Config `AI.agentConnections` (`config/app/AI/agentConnections.php`): adapters, stages, limits, internal domains,
    `unresolvedSecretReferencePrefixes`.
- **Owner consent: evidence of shown content.** `OwnerConsentRequest::$shownContentMessageIndex` names the agent's own
  text row (never a widget or tool row) that showed a step's whole content (for a mail send: every recipient, the
  subject and the body, unchanged) before the owner's message; the AgentConnections gate fills it.
  `OwnerConsentsService` then reads that row into the dialogue (widget blocks the agent wrote into its text as their
  text fields), takes the evidence as the answer to "was the step explained", and leaves "did the owner agree" to the
  decision model at its normal threshold: a clear "send it" after the shown mail needs no second question, an unrelated
  message still refuses. Without the evidence nothing changes. The consequence line of a send is "It reaches
  <recipients>." `listConnections` carries `stageRecipientNote` outside production.

## 0.10.7

- **The newest row a tool wrote.** `AIConversationMessages::getNewestIndexOfRowWrittenByTool(string $toolName)` (pure,
  over the loaded set) and `AIConversationMessagesService::findNewestIndexOfRowWrittenByTool(AIConversation, string)`
  (the persisted, not truncated rows, whatever window is loaded): the highest `indexInConversation` of a TOOL_MESSAGE
  authored by TOOL whose row label (`signalSource`) is the tool's name, as `writeWidgetRow()` writes it; null when there
  is none. A block the model wrote into its own text never counts. For a purpose that closes a plan item on the card a
  tool delivered.
- **Fix (web): the owner's own message and the name of an agent space reach the app's text components as plain text.**
  `ConversationMessageUser` hands its prose, and `ConversationWidgetAgentSpaceHeader` the tooltip's explanation, through
  the environment's `escapePlainTextForText`, so an app whose `Text` or `Tooltip` replacement reads a string as markup
  shows them as written (before: markup in a customer message or in a business name was read as markup there). The
  prose line carries the test id `conversation-message-user-text`. With the default components nothing changes.

## 0.10.6

- **A seeded plan can grow.** New purpose frontmatter key `seededTaskPlanAcceptsNewItems` (default false). With `true`,
  a conversation of the purpose is offered `update_task_plan` on its seeded plan and may add top-level items and
  subtasks (one per ticket of a support run) and tick them; the seeded items keep their text, place and order and are
  never dropped (the existing update check). The planned turn's budget counts the added leaves
  (`6 + stepsPerPlanItem × leaves`). Without the key nothing changes: a seeded plan is still refused a rewrite.

## 0.10.5

- **A turn with a task plan has no step ceiling.** It gets 6 + `stepsPerPlanItem` (default 3) × leaf items, at least 12,
  with no upper bound; the turn's cost cap is the brake (the purpose's `maxTurnCostUsd`, else 2.00 for a raised turn;
  `runSettings` still win). Before, 60 capped it. A plan-less turn keeps 12. `plannedTurnMaxStepsCeiling` is now an
  optional ceiling a purpose sets; a step floor a tool or a loaded skill declares stays capped at 60 (or that ceiling).
  Check that every purpose with long plans has the cost cap it wants.

## 0.10.4

- **A purpose sets its turn budget.** New purpose frontmatter keys, read live by `AIConversationTurnBudget` (via the
  conversation's purpose, `AIConversationAgentService::buildTurnBudget()`):
  - `stepsPerPlanItem` (1–20, default 3): the steps a planned turn gets per open plan leaf (6 + n × leaves);
  - `plannedTurnMaxStepsCeiling` (at most 120, default 60): the ceiling of a planned turn and of a tool's or skill's
    step floor.
- **`maxTurnCostUsd` of a purpose now applies.** It was read from the frontmatter but used nowhere; it is now the cost
  cap of every turn of the purpose's conversations when the run settings name none (`runSettings.maxTurnCostUsd`
  still wins). A purpose that declared it gets that cap from now on: check the value.
- `AIConversationTurnBudget::buildForConversation()` takes the purpose as a fourth, optional argument. Nothing changes
  for a purpose without these keys.
- `ConversationMessageUser`: the `isGenericChat` prop was accepted but never read; it is now marked `@deprecated` and
  no longer destructured. A customer message renders the same in every chat; existing callers keep compiling.

## 0.10.3

- **Security — the owner-consent step fails closed.** Before, every call whose provenance named no stored
  conversation ran ungated: no consent, no standing-mandate lookup, no `decideMandateCoverage()` — also an agent's call
  in a transient conversation (an eval replay of a schedule firing executed a gated write that the host's hook refuses
  in production). Now `OwnerConsentsService` ungates ONLY an eval harness step (`$isEvalHarnessCall`) and a direct call
  without an agent: the executor marks the provenance it injects for a caller that passed none
  (`AgentToolCallProvenance::$isDirectCallWithoutAgent` — the HTTP-MCP server of the authenticated owner,
  executor-direct host code; `forDirectCallWithoutAgent()` for an operator's console call, used by
  the tool call of `app:mcp:documentation`). No provenance, or one without a conversation that is not marked, is refused
  (`OwnerConsentVerdict::FAILURE_NO_CONVERSATION`, "NOT DONE").
- New hook `OwnerConsentsService::isDirectCallerTheConsentGiver()` (default yes): a host whose HTTP-MCP clients are
  other agents answers no, and their gated calls are refused.
- A conversation without an id is decided like a stored one: the standing mandate and the host's hook run first, a
  replayed schedule run finds no owner (`noLiveOwner`), a transient chat case is asked of the decision model. It
  writes no turn event and no use record (a consent there is not single-use). Eval cases with gated tools that passed
  ungated before now meet the gate as in production.
- Real runs are unaffected: a schedule run, a delegation child and a background operation always carry a stored
  conversation (a child is refused as before: only the root can ask the owner).

## 0.10.2

- **A schedule may start its runs with a purpose of its own** (a pinned model, preloaded skills, a seeded task plan)
  instead of `Schedule.Run`. `AIConversationSchedule::getRunPurposeName()` / `setRunPurposeName()`; stored in the
  schedule's `runSettings` JSON (`RunSettings::$runPurposeName`) — no new column, nothing to apply in the schema,
  never copied onto a run. Allowed: a registered purpose whose class is `ScheduleRunPurpose` or extends it
  (`AIConversationPurposesService::findScheduleRunPurposeNames()`, once per process), whose `requiredFeatureFlags` the
  space has, and which the new hook `AIConversationSchedulesService::isPurposeAllowedForSchedule()` allows (default
  yes). `applyRunPurposeToSchedule()` / `applyRunPurposeToStoredSchedule()` set it and refuse with the allowed names.
  The fire path starts the run with that purpose and its `preferredModelName` as the run's model; a purpose that no
  longer qualifies runs as `Schedule.Run` with a warning (`resolveRunPurposeNameOfSchedule()`).
- The tools `startBackgroundTask`, `scheduleOneTimeTask`, `createRecurringTaskSchedule` and `updateTaskSchedule` take
  the optional `purposeName`; `getTaskSchedules` shows `runs as <purpose>` for a schedule that does not run as
  `Schedule.Run`. A host's admin endpoint calls the two service methods above.
- The runs of such a purpose count as schedule runs in the day context and are left out of the search across
  conversations like `Schedule.Run`.
- Purpose file of a schedule's run: `purposeClass: …\ScheduleRunPurpose`, `inheritsFrom: Schedule.Run`, then
  `preferredModelName`, `seedTaskPlanItems` and so on; an eval case runs it with `scheduledRun` and `purposeName`.

## 0.10.1

- **eval-tools stops like eval-suite.** SIGINT / SIGTERM, a `STOP` file in the run folder and a passed
  `--max-duration` stop the run gracefully: the queue is dropped, cases that change no live state are ended, writes
  finish and restore; a second signal ends every running case. A run in this process starts no further case. Before,
  eval-tools read neither signals nor the file, and a run whose shell was gone kept its parent and children running.
- **A host-wide `STOP` file**: `STOP` in the results folder (`AgentEvalResultFilesService::getHostWideStopFile()`) stops
  every eval-suite and eval-tools run of the host; a run started while it exists says so at its start.
- **`--max-duration=<seconds>`** for eval-tools and eval-suite (default: no limit): past it the run stops as on a first
  signal and prints `Stopped at max duration — N case(s) not run`. Shared in `AgentEvalConsoleCommand`
  (`configureMaxDurationOption()`, `requestStopForStopFilesOrDeadline()`, `describeStopRequest()`).
- **`--case` of eval-tools takes a comma list** like eval-suite's; a list runs in child processes like a whole fixture.
- **Driver prompt: an open yes question is answered.** The simulated owner answers a shown step that asks for a yes
  from its facts and never says `[DONE]` or `[GIVE-UP]` while it is open; `[DONE]` only when every part of the goal is
  confirmed (a reply counts once it is reported sent). Before, a partial result read as the goal and the case failed as
  "reply never sent".

## 0.10.0

A standing mandate can replace the per-step owner consent of a tool that says so — the agent can take a class of
steps on its own (in unattended runs too) and leave single cases to a person.

- **`MCPToolRequiresOwnerConfirmation(coveredByMandateActionClass: '…')`.** An active TIME_WINDOW or UNLIMITED mandate
  of that class for the conversation's account and space covers the tool's consent step: `reserveOwnerConsentForStep()`
  answers `OwnerConsentReservation::OUTCOME_COVERED_BY_MANDATE` without a consent question; the write runs. The mandate
  is read live on every call: without one, after a revoke or an expiry the step asks the owner as before. A
  ONE_INSTRUCTION mandate never covers (it is granted from the same dialogue the consent step reads). The attribute
  without the argument behaves as before.
- **The host decides per call:** `OwnerConsentsService::decideMandateCoverage(AgentMandate, AgentToolCallProvenance,
  OwnerConsentRequest): OwnerConsentMandateCoverage` (same-path subclass; default covered). `notApplicable()` leaves the
  consent step (another context); `refused('<what to do instead>')` runs nothing, the model reads
  `NOT DONE — nothing was changed. <reason> Do not retry this step in this run.`, and the call is not marked as held for
  a confirmation.
- **Unattended runs** (a scheduled run, a sub-agent) are covered like an attended turn by the mandate of the
  conversation's account; a revoke stops the next covered step of a running schedule.
- **After an untrusted read** with `AI.untrustedContent.writeAfterUntrustedRead` not `off`, the executor does not hand
  the class on: the owner confirms the step themselves.
- **Record:** the `ownerConsent` turn event of a covered step has `decidedBy` `mandate` (refused by the host:
  `mandate:refused`), `coveringMandateId` and `coveringMandateActionClass`; `OwnerConsentVerdict::coveredByMandate()` /
  `refusedUnderMandate()`. `app:agent:decision-stats --site=ownerConsent` adds "Steps decided by a standing mandate".

## 0.9.0

Decision models read cut texts as cut, and the owner-consent decision gets more context where a cut kept a clear yes
from passing. The thresholds are unchanged; the normal path reads as much as before.

- **Every cut is marked.** A text a decision model reads is cut with a marker that says where and how much
  (`[… 804 characters cut at the start]`, `AgentDecisionTextCuts::keepEnd()` / `keepStart()`): owner consent, every
  turn-start site, stall recovery. An assistant text keeps its END (its question and offer), an owner message its START.
- **Fix: four turn-start sites read the start of the assistant text.** Reasoning effort, plan dynamics, direct widget
  and turn-start line cut the assistant text before the owner message to its FIRST 300 characters, so the question at
  the end of a long reply was missing and a short "ja" referred to nothing. They now read its end, like reply language
  and skill preload. Stall recovery reads the end of the stalled step's text.
- **Limits per decision in config.** `config/app/AI/decisionContext.php` (`AI.decisionContext`,
  `AgentDecisionContextSettings`): owner consent `lineCharacters` 700, `assistantLineMaxCharacters` 4000,
  `expandedLineCharacters` 12000; turn start and stall recovery 600 (owner message) and 300 (assistant text). An
  application replaces the file whole; a missing or invalid key keeps its default. The constants
  `MAX_LINE_CHARS`, `MAX_ASSISTANT_LINE_CHARS`, `MAX_OWNER_MESSAGE_CHARS`, `MAX_ASSISTANT_TEXT_CHARS` and
  `MAX_TEXT_CHARS` stay as the package defaults.
- **Owner consent: a context question and at most one second pass.** When a dialogue line was cut, the request asks a
  third question, `excerpt_shows_what_the_answer_refers_to`. When the first pass did not consent and the model says the
  excerpt does not show what the answer refers to (below `contextSufficientMinimum`, 0.5), or the owner's yes reached
  its threshold while the step-explained probability lies from `nearMissFloor` (0.5) up to its threshold, ONE second
  pass reads every line up to `expandedLineCharacters` and decides. A second pass without an answer leaves the first
  verdict (no consent). `secondPass: false` switches it off. A dialogue without a cut is asked exactly as before.
- **Visibility.** `OwnerConsentDecisionResult` and the `ownerConsent` turn event carry `dialogueWasCut`,
  `contextSufficientProbability`, `secondPassReason` (`contextInsufficient`, `nearMiss`) and the first pass's
  probabilities. `app:agent:decision-stats --site=ownerConsent` counts the passes and lists the newest refusals whose
  answer was clear while the step was not recognised.

## 0.8.7

- **Fix: a clear yes to a step with a long target was refused.** `ArgusAgentOwnerConsentDecision::buildState()` cut
  every assistant line to its last 700 characters, so an explanation that shows a long step (a reply with its exact
  text) lost its start and the step-explained probability stayed below the threshold. An assistant line now keeps its
  end up to `MAX_LINE_CHARS` plus the length of the step and the consequence, at most `MAX_ASSISTANT_LINE_CHARS`
  (4000; `getAssistantLineLimit()`). Owner and widget lines stay at 700; thresholds and questions are unchanged.

## 0.8.6

- **`rightsHeldByNobody` no longer asks a host to map the right of a switched-off feature that the rights catalogue
  lists.** A right that every declaring MCP package leaves unavailable is left out even when a rights constant class
  lists it too (`AgentAccountRights::RIGHTS`): `useAgentGoals` while the goal register is off, `useAgentMemory` while
  the memory store is off. It is reported once the feature is switched on. A right only a constant class lists is
  reported as before.

## 0.8.5

- **Fix: replacing a package widget no longer changes the module's `ComposedView` schema.** With
  `REPLACES_PACKAGE_WIDGET` the module's half was built from the application's class, so in an application the
  committed `composed-node-schema.json` (and the parity fixture's stub schema) read as stale: `widgetTypes` (and
  `embeddableWidgets` for an embeddable package widget) lost the replaced type. The module's half is now built from the
  replaced package class (`ConversationWidgetClasses::getReplacedPackageWidgetClassesByWidgetType()`,
  `ComposedViewNodeSchema::buildEmbeddableWidgetEntries()`); the application's part names the replaced types in
  `replacedPackageWidgets` (only when there are any) and carries the app's own embeddable entry; the web merge
  (`addComposedNodeSchemaApplicationPart`) drops the package's embeddable entry of each replaced type.

## 0.8.4

- **An app's widget replaces the package's widget of its wire name without extending it.**
  `ConversationWidget::REPLACES_PACKAGE_WIDGET` (PHP twin of the web field `replacesPackageWidget` of 0.8.3): of two
  classes of one wire name, the application's class with the flag stays and the package's is dropped, in either order
  of discovery; two application classes stay a build error. For an app whose widget of that name must not take the
  package class's behaviour (the package's `ImageGallery` is answerable, an app's own gallery may not be).
  `ConversationWidgetClasses::resolvePackageWidgetReplacingClassName()`, `isPackageWidgetClass()`.

## 0.8.3

- **An app keeps its own widget of a wire name the package now ships.** `ConversationWidgetEntry.replacesPackageWidget`
  (web): an app's entry with the flag takes the place of the package's entry of its name in
  `registerConversationWidgets()`; without it a name the package registers still throws, an app's own name registered
  twice always throws. The PHP side already lets a subclass of the package widget win; an app that used `ImageGallery`
  or `AttachedFiles` before 0.8.1 extends the package class there and sets the flag here.
- **Fix: `addGoal` answered a new goal as "already in the register" over a host store that does not set the id on the
  goal it gets.** `AgentGoalsService::persistGoal()` now puts the written row's id on the passed goal
  (`writeGoalCarryingItsId()`); the port docblock of `AgentGoalStoreService::writeGoal()` says so.

## 0.8.2

- **A goal of a standard node takes the node's done criterion as its success signal.** `addGoal` with a `blueprintKey`
  and no `successSignal` sets the signal to the node's `doneCriterionDescription` before the refusal hook sees the
  goal (`AgentGoalsMCPPackage::applySuccessSignalOfStandardNode()`); nothing changes without a bound provider, for an
  unknown node or a node without a criterion. New `AgentGoalBlueprintProviderService::findStandardNodeByKey()`
  (default: the node of `buildAssessedBlueprintForAgentSpace()`; an application may answer from its static tree
  without the assessment). An application that did this in `findReasonToRefuseGoal()` drops its own step.

## 0.8.1

- **New widget `ImageGallery`: pictures of any file source to look at or to pick from.** The block carries file
  references only (`media/901`, `drive/812`), never a URL: `title`, `name` (what the gallery is for, named in every
  reaction), `selection` `none` | `one` | `many`, `display` `inline` | `canvas`, `files[]` of
  `{reference, label?, caption?}` (at most 12, letters A–L by position). Tiles with the letter, a lightbox (PREVIEW,
  previous / next, Escape), the canvas body `imageGallery`. The pick posts a FRONTEND_WIDGET_CLICK signal, a stored
  contract pinned by `image-gallery-widget-contract.json`:
  `ImageGallery[ <name>]: the operator picked A (media/901)`, several
  `… picked 2 pictures: A (media/901), C (media/903)`, none `… the operator declined all pictures`.
  `ImageGalleryConversationWidget::readAnswer()` answers an `ImageGalleryPick` (letters, file references) and counts a
  letter only when the gallery shows that reference under it; `isDeclined()`, `isReactionToThisWidget()` (a named
  gallery ignores the picks of another), `addFile()`, `buildPickSentence()`, `buildDeclineSentence()`.
- **New widget `AttachedFiles`: the files an owner attached to a message, by reference.**
  `{files: [{reference, name, isImage}]}` (at most 12), sent with the owner's message (`sentInCustomerMessage`,
  shown above the bubble). `AttachedFilesConversationWidget::getFileReferences()`. Web: `AttachedFilesStrip` (chips with
  thumbnail, remove, running and failed uploads) and `buildAttachedFilesComposerWidget()` for `hostComposerWidgets`;
  the host uploads the files itself (its own store) and clears the widget in `onComposerWidgetsSent`.
- **New port `FilePreviewSource`: where file widgets read their pictures.** `registerFilePreviewSource({
  readPictureUrl(reference, variant: 'THUMBNAIL' | 'PREVIEW', signal) })` once before the first chat; typically the
  host's file route (`FileSourcesControllerTrait` `…/files/{source}/{id}/content?variant=`) fetched with the session
  and answered as an object URL. Without a source a tile says "Pictures are not connected here". `useFilePictureUrl()`
  for host widgets.
- Composed-view schema and parity fixture list the two new wire names; `public-api.json` and `i18n-keys.json`
  regenerated (12 new keys).
- No config or schema change.
- Docs: the widget table of the widgets skill lists `ImageGallery` and `AttachedFiles`.

## 0.8.0

**The goal register** (contributed against 0.7.8, released on 0.7.9). Off by default (`AI.agentGoals.enabled`); new
table `AgentGoals` only when a host switches it on and uses the package's store.

- New: the goal register. `AgentGoal` / `AgentGoals` hold what the owner wants to reach (holder `OWNER`) and what
  the agent pursues for them (holder `AGENT`), scoped like memory: an account, optionally one agent space. Horizon
  (`VISION`, `LONG_TERM`, `SHORT_TERM`), status (`PROPOSED`, `ACTIVE`, `ACHIEVED`, `DROPPED`, `PAUSED`, `BLOCKED`)
  and provenance (`USER_STATED`, `AGENT_DERIVED`, `CONFIRMED`, `PRODUCT_DEFAULT`, `LEARNED`) are typed constants
  validated with `Choice`. The goal carries its own rules: initial status from the provenance, reachable statuses
  (a `VISION` is never achieved, only an agent goal is blocked), parent rules (an owner goal never hangs under an
  agent goal, at most 3 levels), bitemporal versions (`validTo`, `invalidatedAt`, `supersededByGoalId`) and its line
  for the model, which names the space through `AgentSpacesService::buildAgentSpaceLabelForTheModel()`.
- New: `AgentGoalsService::persistGoal()` is the one write path of a goal. An exact restatement writes nothing unless
  it corrects the horizon, the target day or a dropped goal (then the old row is closed first, the new one written and
  the old one pointed at it); a near-duplicate (`areStatementsNearDuplicates()`, threshold
  `NEAR_DUPLICATE_SIMILARITY_PERCENT`) is written first and then supersedes the old row. Also
  `transitionGoalStatus()`, `findCurrentGoalsForScope()`, `findGoal()`, `invalidateCurrentGoalsOfScope()` and
  `closeGoalValidityWindow()`.
- New: `AgentGoalStoreService` is the host port of the register, like the memory store. Its default stores the goals
  in the package's table `AgentGoals` (read rights like memory); a host that keeps goals in rows of its own registers a
  subclass at the same path. A generated unique key keeps one current row per scope and statement.
- New: the switch `config/app/AI/agentGoals.php` (`AI.agentGoals.enabled`, default `false`). Code before schema: with
  the switch on and the table missing, every goal tool answers "not available" and nothing throws (a missing table
  is looked up again at most once a minute); `app:agent:check-installation` reports the missing table as a WARNING, and only
  while the switch is on and the package's own store answers.
- New: the tools of `agent.goals` (`AgentGoalsMCPPackage`, right `useAgentGoals`, an everyday right): `getGoals`
  (read, parallel-safe), `addGoal` (write; a goal the agent derives is stored as `PROPOSED` with a plain note asking the
  owner to confirm it; the marker `NOT SAVED AS THE OWNER'S GOAL` opens only a goal the application refused and the
  answer "not available") and `updateGoalStatus` (write). Each takes
  an optional `agentSpaceId` like the memory tools. The override point `findReasonToRefuseGoal()` lets an application
  refuse a goal before the write (default: accept).
- New: the standard goal tree. `AgentGoalBlueprint`, `AgentGoalBlueprintNode` (criterion, weight, assessment ✅ / ⬜ /
  ❔) and the set `AgentGoalBlueprintNodes`, keyed by the node key. The port `AgentGoalBlueprintProviderService` has one
  method, `buildAssessedBlueprintForAgentSpace()`; the package binds no tree. The tool `getGoalBlueprint` (package
  `agent.goals.blueprint`, read, parallel-safe) is offered only while an application registers a provider.
- New: the agent skill `Agent.Goals` (`config/app/AI/Skills/Agent/Goals.md`), bound in `skillPackages.php` to both
  packages; it is offered only while the switch is on. Developer reference: the context specialist's
  `references/goals.md`, the README section "Goals: the register is a host port too" and the config map.
- Adopting it in an application: (1) create the table `AgentGoals` from the package's model
  (`app:entity:show-sql` for `AgentGoal`) or register a store of your own at the path of `AgentGoalStoreService`;
  (2) set `AI.agentGoals.enabled` to `true` in the application's `config/app/AI/agentGoals.php`; (3) grant the right
  `useAgentGoals` (in the package's default `accountRights.php` every account has it; an application with its own
  `AI.accountRights` adds it); optionally register a provider at the path of `AgentGoalBlueprintProviderService` and
  override `findReasonToRefuseGoal()` or `areStatementsNearDuplicates()`.

- Changed: the skill binding lint accepts a further package of the same skill that adds one feature word to the
  skill's words (`agent.goals.blueprint` beside `agent.goals`, `agent.ui.window` beside `agent.ui`); before, such a
  package was a warning of `app:agent:check-installation`.
- npm 0.8.0 in lockstep (no web change).

## 0.7.9

- **`saveDriveFile` never asks the owner for a copy that is refused anyway.** New optional port
  `AgentDriveSavePrecheck` (tag `ai.agent_drive_save_precheck`, one per store name) with
  `findSaveRefusals(): AgentDriveSaveRefusals` (`AgentDriveSaveRefusal`: source argument and reason). On a
  `WRITE_WITH_CONSENT` mount the tool asks the target store's precheck first: the sources it refuses (not readable,
  kept out by the flow rule) become refused lines and are not put to the owner, the consent request names only the
  rest, and when nothing remains the answer is "Nothing was copied." without a question. A store without a precheck
  behaves as before. `AgentDriveStoresService::findSaveRefusals()`. ddd-clouddrive 0.5.4 ships the drive's precheck.
- Tools skill and its file-sources reference corrected: the picture goes to the model in the format of the tool picture
  store's wire profile (e.g. WebP); the mode constant is `MODE_READ_WRITE`; the tiers keep the sent bytes by their
  sha256, the conversation keeps the picture id; a decision's `requiresOwnerConsent` is met only by a consent mount
  (the agent cannot pass a consent itself).
- No config or schema change; npm 0.7.9 in lockstep.

## 0.7.8

- **Memory curation: the model of a call can be chosen.** `ArgusAgentMemoryCurationCall::useAIModel(AIModel $aiModel)`
  sets the model a curation call runs on; it wins over the model scope, the tier and the default name, and the call's
  attempt record (`AgentMemoryCurationModelCall::$aiModelName`) names it. The curation itself never calls it; an
  application that measures its curation on several models calls it in an override of
  `AgentMemoryCurationModelCallsService::loadCall()`.
- **Memory curation: the conflict provenance is built for the application.**
  `AgentMemoryCurationService::buildConflictProvenance()` builds with `AgentMemoryConflictProvenance::newInstance()`, so
  an application's subclass at the same path (its own wording of `describeStatedAgo()`) is the object the
  `MEMORY_CONFLICT` signal carries. An override that converted the package's object can drop the conversion.
- Tools skill, reference `file-sources-in-the-drive-tools.md`: why find tools live with the source and viewing in
  `agent.drive`, and a worked flow (find → `viewImages` → `saveDriveFile` → `showDriveFiles`) with requests, server
  call chains, answers, refusal and consent variants, and where each check sits.
- No config or schema change; npm 0.7.8 in lockstep.

## 0.7.7

- **New: the owner-consent thresholds are configurable** — `config/app/AI/ownerConsent.php` (`AI.ownerConsent`,
  `OwnerConsentSettings`): `minimumProbability.stepExplained` and `minimumProbability.ownerAgreed`, 0.90 each by
  default (unchanged behaviour). `OwnerConsentRequest::forAction()` copies them onto the request
  (`minimumStepExplainedProbability`, `minimumOwnerAgreedProbability`); `OwnerConsentVerdict::decided()` compares each
  probability with its own threshold. `OwnerConsentRequest::$minimumProbability` holds the higher of the two for
  display and is deprecated (no longer read). The consent turn event also records both thresholds. Host steps: none;
  an application that wants other values adds its own `config/app/AI/ownerConsent.php`.
- **Changed: the decision sites read the record of a bound conversation.**
  `AIConversation::buildStoredFactLinesForDecisions()` leads with one line `record of this conversation: <what the
  record is>`, from the description the working state stores since the conversation was bound
  (`AIConversationSubjectDescription::renderDecisionFactLine()`, constants `DECISION_FACT_PREFIX` and
  `MAX_DECISION_FACT_CHARS` = 200; without the record's name and link, which are third-party text). The skill-preload
  and reasoning-effort sites read it, so a sentence that names no record ("what is this about?") no longer leaves the
  preload decision without anything to choose by. It is the first line (the decision states are cut) and the same text
  in every call. A conversation without a record, or bound before a describer existed, reads what it read before. An
  application that overrides `buildStoredFactLinesForDecisions()` or counts the lines of
  `AgentSpacesService::buildStoredFactLines()` in a test sees one more line for bound conversations. No table change.

## 0.7.6

- **A decision answer that is unusable keeps its reason.** An answer without an `answers` object and with a
  non-transport status (400, 401, 402, 404, 422, or 200 without answers) stores
  `ArgusAgentDecisionTrait::$unusableAnswerReason` = `http <status>: <message>`. The message is the provider's error
  text: control characters and line breaks become spaces, it is cut at 160 characters, and it is never given to a
  model. `AgentDecisionCallsService::classifyDecisionBatchOutcome()` copies it into
  `AgentDecisionCallOutcome::$failureReason` (so into the sites' decision records) and logs the warning
  `[AgentDecisionModel] <site> decision answer unusable`. The skill preload, the owner-message screening and
  `app:agent:jev-probe` show it as well. Behaviour is unchanged: the site still falls back as unparsable and the breaker
  is not tripped. No config or schema change; npm 0.7.6 in lockstep.

## 0.7.5

- Tools skill: new reference `file-sources-in-the-drive-tools.md` (find per source, view/copy/show generic;
  `AI.fileSources.findTools`; how an argument becomes a source file; `viewImages`, `AgentFilePicturesService`, picture
  ids with fingerprint and the picture tiers). Documentation only; npm 0.7.5 in lockstep.

## 0.7.4

- Fix: `AgentEvalArmOptionsTrait` reflects on its own. The keys of `PREVIOUS_REASONING_CUT_COUNT_ARMS_MINIMUM_VALUES`
  read the trait's constants through `self::`, which PHP refuses when the trait itself is reflected ("Cannot access
  trait constant self::ARM_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT directly"); Symfony's debug resource tracking does that
  when the package lies outside the application's vendor directory (a path repository, a linked checkout, a worktree
  whose package directories are links), and the container build died with debug on. The keys are literals now
  (unchanged values); `TraitsReflectOnTheirOwnTest` reflects every trait of the package. npm 0.7.4 in lockstep, no
  web change.

## 0.7.3

**Memory curation: the model calls behind its results.** No table change, no web change; npm `@mgamadeus/ddd-agents` 0.7.3 in lockstep.

- **New: the model calls of a memory curation are on its results.** Every attempt of a curation model call is one
  `AgentMemoryCurationModelCall` (`DDD\Domain\AI\Entities\Conversations\Memories\Curation`): the stage
  (`STAGE_EXTRACTION`, `STAGE_RECONCILIATION`, `STAGE_CONFLICT_RESOLUTION`), the model that ran, the request body as
  sent, the model's raw answer text, the provider's whole answer decoded, the input and output tokens and the attempt
  (2 = the repetition after an answer of the wrong shape). Only the request body is kept: the request headers carry
  the egress authentication and are never copied. The set `AgentMemoryCurationModelCalls` is keyed by object (two
  attempts with identical content are both kept) and gives `getModelCallsOfStage()`, `sumInputTokens()` and
  `sumOutputTokens()`.
- **New: `AgentMemoryCurationResult::$modelCalls`** carries every attempt of `loadWithParseRetry()`, also the one whose
  answer is thrown away; `mergeResult()` appends the other result's calls, so the reconciliation's calls come before
  the conflict resolution's. **`AgentMemoryReconcileResult::$modelCalls`** (of `curateAndApply()` and
  `curateConversation()`) carries every call of the pass in call order, also when the pass ends after an extraction
  without a candidate.
- **New: `ArgusAgentMemoryCurationCall::buildModelCallOfAttempt(int $attempt)` and `getCurationStage()`** (answered by
  `ArgusAgentMemoryExtraction` and `ArgusAgentMemoryReconcile`). The record is read from what the language-model trait
  of `mgamadeus/ddd-ai` keeps of the last call (the `body` of `lastRequestPayload`, `lastRawResponse`,
  `getLastInputTokens()`, `getLastOutputTokens()`, `getAIModel()`); no second capture.
- **Unchanged:** the curation's behaviour, its operations, its prompts and its config. The calls are always recorded
  and hold the request bodies of one pass in memory until the result is dropped. Not recorded: the conflict excerpt
  call made while the conflict signal is written, and the calls of a pass that ends with
  `AgentMemoryCurationUnavailableException`.

## 0.7.2

**Free conversations about one record, the list of a record's conversations, and what the model reads about the
record of a bound conversation.** npm `@mgamadeus/ddd-agents` 0.7.2 in lockstep (`subject` on a start, the list and
the subject read on the client port).

- **A conversation without a purpose can be bound to a record.** A start names it:
  `AIConversationStartWithMessagePostRequestDto::$subject` (`subjectEntityClass`, `subjectEntityId`), in PHP
  `AIConversationAgentService::driveConversation(…, aIConversationSubject: $subject)` and
  `startOrReopenConversationAtEntryPoint(…, aIConversationSubject: $subject)`. Any number of free conversations may
  exist per record; a free conversation is never the singleton of a purposed start. The binding of a free conversation
  has `purposeName` null, one at account level `agentSpaceId` null.
  `AIConversationSubjectBindingsService::bindSubjectToConversation()` no longer refuses a conversation without a
  purpose or a space.
- **Which classes a client may name:** new hook
  `AgentConversationControllerHooksTrait::isSubjectEntityClassAllowedForConversationStart(string $subjectEntityClass)`,
  default no class. A start that names a subject answers 404 for a class that is not listed and for a record the
  caller cannot read, 400 for a malformed subject; a start without a subject is not affected. The classes of purposed
  starts stay with `isSubjectEntityClassAllowedForPurposeStart()`. New protected
  `findReadableSubjectEntityOfConversationRequest()`.
- **The conversations of a record, with their own trait `AgentConversationSubjectsTrait`** (a controller adds it on
  purpose; an update never mounts the routes): `GET …/listConversationsOfSubject?subjectEntityClass=…&subjectEntityId=…`
  (query options of the conversations list) answers the caller's visible conversations of the record, free and
  purposed, of every space (`AIConversationsService::findAllVisibleToCustomerOfSubject()`,
  `DBAIConversations::findAllVisibleToCustomerOfSubject()`,
  `AIConversationSubjectBindingsService::restrictConversationQueryToSubject()`), under the same class and read
  checks as the start, and the record itself as the describer names it now (`subject` of
  `AIConversationsOfSubjectGetResponseDto`: class, id, name, link — what a client shows before a conversation about
  the record exists; `AIConversationBoundSubject::ofSubject()`); `GET …/{aiConversationId}/subject` answers the
  record one conversation is bound to (`AIConversationBoundSubject`: conversation id, class, id, purpose, name,
  link) or null;
  `GET …/listSubjectsOfConversations` (the query options of `listAllConversations`) answers the records of a page of
  the caller's conversations as the set `AIConversationBoundSubjects` (`subjects.elements`, one element per bound
  conversation, keyed by the conversation: `findSubjectOfConversation()`), so a history can name each conversation's
  record with one request. New `AIConversationSubjectBindingsService::findBindingOfConversation()` and
  `findBoundSubjectsOfConversations()`.
- **The model reads what the record of a bound conversation is, in every call.** New port
  `AIConversationSubjectDescriber` (tag `ai.conversation_subject_describer`): `getSubjectEntityClasses()` and
  `describeSubject(AIConversationSubject, Entity): ?AIConversationSubjectDescription` (one line in the application's
  words, the record's display name, its link). The answer of the first describer that names the record's class or a
  parent class is stored once on the conversation's working state when it is bound
  (`AgentWorkingState::$subjectDescription`, `AIConversationsService::persistSubjectDescriptionSlice()`, written only
  while the key is unset) and rendered as the new system segment `CONVERSATION_SUBJECT` right after the purpose
  directive (`AgentModelInput::$conversationSubject`, `AIConversation::findConversationSubjectBlock()`). The text is
  byte-stable for the conversation's life, so a reopened conversation knows its record and the prompt cache holds; the
  record's name stands between the marks of untrusted content. Before, a purposed conversation of a record knew it only
  from what its starter put into the kickoff. Without a describer, or when it fails (a warning in the log), the
  conversation is bound without the segment. A conversation bound before a describer existed gets none.
- **Changed: a new conversation is bound inside the drive,** after the purpose stamp and before its first turn is
  dispatched. `resumeOrStartPurposedConversation()` hands its subject to `driveConversation()` instead of binding after
  the drive returned (the kickoff turn could start before the row existed).
- **Changed signatures:** `AIConversationAgentService::driveConversation()` and
  `startOrReopenConversationAtEntryPoint()` take a last optional parameter `?AIConversationSubject $aIConversationSubject`.
  A class that overrides one of them adds the parameter.
- **Schema, and what a host does on adoption:** `AIConversationSubjectBindings.purposeName` and `.agentSpaceId`
  become nullable (DROP NOT NULL), and the table gets the index `AIConversationSubjectBindings_idx_sub_sub` on
  `subjectEntityClass, subjectEntityId`; the schema diff shows both, apply it with the update. No new column: the
  description lives on the conversation's working state. On a table of 0.6.9–0.7.1 a free start with a subject fails
  on the NOT NULL column until the diff is applied, so apply it before a class is allowed; purposed starts are not
  affected. Nothing else is needed: without the trait and an allowed class a host behaves as before. To use it, a
  host adds `AgentConversationSubjectsTrait` to its conversations controller, lists its classes in
  `isSubjectEntityClassAllowedForConversationStart()`, and registers a describer (`AIConversationSubjectDescriber`).
- **Web:** `startConversation({ …, subject })`; optional `listConversationsOfSubject(subject, options)` (answers
  `ConversationsOfSubject`: `subject` and `conversations`), `listSubjectsOfConversations(agentSpaceKey, options)` and
  `getConversationSubject(conversationId)` on
  `AgentsConversationClient` (the HTTP binding and the polling wrapper have them). New contract types
  `ConversationSubject` (`PurposeSubject` is now its alias), `BoundConversationSubject` and `ConversationsOfSubject`;
  `public-api.json` declares them and the wire names of `./client`.
- Tests: the list across spaces and purposes for its account only, another record, another class and another account
  (MariaDB and PostgreSQL); a free conversation is not the purposed singleton; the binding without a purpose and
  without a space; the description stored once and rendered for the drive's object and every later load; a failing
  and a missing describer; a host without the table; the describer of a parent class; the cleaned description and
  its round trip through the working state; the segment's text, the name's marks and its place after the purpose
  directive; the start with a subject (default 404, unreadable 404, malformed 400, the subject handed to the drive
  and to the entry-point start); the list route's checks; the routes only with their trait; the drive binds between
  the purpose stamp and the dispatch; the HTTP binding's body, query and answers.

**Agent.UI: a screen filter names the values it takes.** Follow-up to the screen actions of 0.6.14, from the first host
that declares every list and ran them in a real client. Part of 0.7.2.

- **Fix: `useAgentUIClient` hands the screen actions to the runner.** Since 0.6.14 the hook's stable adapter object left
  out `actions` and the navigator's `currentTarget` / `applyFilters`, so every `actOnScreen` of a client built with the
  hook answered `ACTIONS_UNAVAILABLE` (the runner's own tests passed adapters directly). The object is now built by
  `createLatestScreenAdapters`, covered by `_useAgentUIClient.spec.ts`: an optional adapter or method is present
  exactly when the application's newest set has it.
- **`ScreenTargetFilter` gains `values`, `multiple`, `hint` and `tabs`** (all optional; a filter with a key alone
  behaves as before). `values`: the stored values of an enumeration or a sort; `multiple`: a comma list of them;
  `hint`: what a free value is (`date YYYY-MM-DD`, `id`); `tabs`: the tabs of a record screen whose list the filter
  belongs to (a filter declared for a tab replaces the one of every page with the same key).
- **`listScreens`** keeps one line per screen with the filter keys of every page and `tabs with filters …`. When the
  search is narrowed to at most `MAX_TARGETS_WITH_FILTER_VALUES` (4) screens, each line is followed by
  `filter values: status one of OPEN|SOLVED · from date YYYY-MM-DD · sort one of id|created, each with :asc or :desc`
  and one `filters of tab <tab>: …` line per tab; otherwise the header says how to get them. The screen whose key
  is the search text always names its values and comes first, however many screens match (`account` also matches
  `accounts` and every screen with a tab of that name).
- **`listScreens` leaves no screen out of a long catalogue.** A catalogue whose full lines exceed
  `MAX_TARGET_LIST_CHARS` (4500) used to end with `… N more; narrow with searchText.`: a host with 67 screens
  lost 18 of them from the whole list. Such an answer now lists every screen in a short line
  (`formatScreenTargetShortLine`: key, label, record parameter, `6 tabs`, `filters`, `views`) and says so in its
  header; tabs, filter keys and values come with the screen's key as search text. Short lines are capped as before.
- **`openScreen`** takes a filter of a tab with that tab only (`UNKNOWN_FILTER` otherwise) and opens the screen
  without a value its filter does not take: the answer's detail `filtersNotSet` names the filter and its values. The
  tool result carries it as `Filter not set: …` inside the untrusted mark (the client wrote it) and closes with the
  server's own `AgentUIMCPPackage::FILTERS_NOT_SET_NOTE`; the result of an opened screen is built by
  `AgentUIMCPPackage::buildOpenedResponse()`. The screen never opens with a list that is silently empty.
- **`actOnScreen` `setFilters`** refuses such a value with the new outcome `UNKNOWN_FILTER_VALUE` and the filter's
  values (`AgentUIMCPPackage::REFUSALS`).
- **`actOnScreen`: an undeclared row, tab or column points at `openScreen`.** The refusal `NOT_DECLARED` read "mark the
  element and let the user press it" for every action, so on a list or a tab bar an application built without the
  declaring components the model marked the row instead of opening it (measured: 0 of 3 and 1 of 3 runs opened the
  screen). `openRow`, `switchTab` and `sort` now get their own next move
  (`AgentUIActionsMCPPackage::NOT_DECLARED_NAVIGATION_REFUSALS`: `openScreen` with the record id or the tab, the
  filter `sort`); a button keeps the old sentence. `describeRefusal()` and `buildRefusedResponse()` take the action.
- **Client helpers (`./ui`)**: `screenTargetFiltersOfTab`, `describeScreenTargetFilter`,
  `describeScreenFilterValues`, `formatScreenTargetFilterValues`, `findUnknownScreenFilterValue`,
  `describeUnknownScreenFilterValue`, `isDescribedScreenTargetFilter`, `formatScreenTargetShortLine`,
  `MAX_DESCRIBED_FILTER_VALUES`.
- **Evaluations**: the fixture `catalog` takes `filterValues` (target key => what its filters take);
  `AgentScriptedUIClientService` names them in a narrowed `listScreens` answer, and for the screen whose key is the
  search text, as the real client does (`MAX_TARGETS_WITH_FILTER_VALUES`).
- **Skill `Agent.UI`**: a filter value comes from the `Filters` line or from `listScreens` narrowed to the screen.


## 0.7.1

**Voice: samples for a voice picker.** npm `@mgamadeus/ddd-agents` 0.7.1 in lockstep. No table change.

- **Added: `app:agent:voice-samples:generate` and `AgentVoiceSamplesService`.** One short sentence per voice and
  language (`AI.agentVoice.samples`), spoken by the live model of the calls in a GPT-Live server session
  (`LiveVoiceSampleSynthesizer`, below the session worker's gated folder `Services\Voice\SessionWorker\Samples\`:
  without ratchet/pawl and react/event-loop nothing loads it, `canGenerateSamplesHere()` is false and the command
  answers that the library is missing), stored as WAV under a fingerprinted key (model, voice, language, sentence,
  format) in the object store `agentVoiceSamples`. A current sample is kept, an outdated one replaced and removed. The
  seconds of each session are booked under `AI.VOICE.SAMPLE` through ddd-ai's session usage (override point
  `recordSessionSeconds()`).
- **Added: `AdminAgentVoiceSamplesTrait`** (`GET …/samples/{voiceId}/{languageCode}`, `audio/wav` as untrusted content
  through `UntrustedContentResponseDto` (the provider spoke the bytes), ETag, a private day of caching, `404
  sample_not_found`, `403 feature_not_available` while switched off; access hook
  `throwForbiddenUnlessVoiceSamplesAccessAllowed()`).
- **Added (web, `./voice/admin`): `AgentVoiceSampleButton`, `useAgentVoiceSamplePlayer`** and the framework-free player
  (`toggleAgentVoiceSample`, …): one audio element per page, the fetch injected by the application, usable inside a
  select item. 5 new text keys.
- Tests: service, session wire (start event, URL, events, quiet frames, no `Origin`, loop per sample, booking),
  generation without the library, route trait (untrusted headers), player and button specs; the command is the 48th
  visible one.

## 0.7.0

**Files of any source in the drive tools; the model looks at pictures through the package.** Requires `mgamadeus/ddd`
^3.48.0 (the `FileSource` port). No table change, no web change; npm `@mgamadeus/ddd-agents` 0.7.0 in lockstep.

- **A file argument of the `agent.drive` tools may be a reference `<source>/<id>`** of any registered `FileSource`
  (`media/901`): `showDriveFiles`, `readDriveDocument`, `viewDriveDocumentPages` and the source side of
  `saveDriveFile`. `AgentDriveStoresService::resolveDrivePath()` answers such an argument with the read-only mount of
  the new adapter store `files` (`FileSourcesAgentDriveStoreService`, an `AgentDriveStore` and
  `AgentDriveFileContentReader` over `FileSourcesService`); only the exact form of a registered source other than
  `drive` counts (`findFileSourceReference()`), so a folder of the own drive named like a source stays a path. A source
  has no folders: `listDriveFiles` on it is refused and names the source's find tool (`AI.fileSources.findTools`).
- **`saveDriveFile` copies across stores**: with a reference as source the target's drive store does the copy
  (ddd-clouddrive ≥ 0.5.0: `DriveImportsService`, under the application's flow rule); nothing is saved INTO a source.
- **New tool `viewImages`** (`agent.drive`; `files[]` of references or drive paths, `size` SMALL 512 / MEDIUM 1024 /
  LARGE 2048): shows up to four pictures to the model, one line per picture. `AgentFilePicturesService` asks the
  flow rule (`FileFlowTarget::forModel()`) before a byte is read, renders through
  `FileSourcesService::renderImagePreview()` and names the picture `<source>/<id>:<edge>:<fingerprint>` in the store
  `files`; `AgentDriveMCPPackage` is the `ToolCallImageStore` that resolves it again under the rights of the running
  account (a changed file has another fingerprint and is dropped, not shown as the old picture).
- **The tool picture store keeps source pictures by tier**: `AgentToolCallImageStoreService::writeSourcePicture()` /
  `readSourcePicture()` (process cache, shared cache with its TTL, object store under `sources/<store>/<sha256>`); a
  picture whose flow decision forbids a durable copy stops at the shared cache. The conversation row holds the
  reference only, as before.
- **`agent.drive` is available with a file source alone** (`AgentDriveStoresService::hasFileSources()`), also without
  a drive store.
- **New config `config/app/AI/fileSources.php`** (`AI.fileSources`, `AgentFileSourceSettings`): `findTools`,
  `viewImages` (`maxPerCall`, `maxSourceBytes`, `maxSourcePixels`).
- An application that showed pictures of its own store through an own `ToolCallImageStore` can drop tool and store and
  bind `agent.drive` to the skill instead.
- Tests: reference resolution (exact form, registered source, paths that only look like one), the adapter store, the
  picture tiers and the durable-copy switch, `viewImages` and the save refusals; the surface lists six drive tools.

## 0.6.15

**Memory: the model sees the application's name for a space, the memory tools load with their skill, the directives
header is an override point.** No web change; npm `@mgamadeus/ddd-agents` 0.6.15 in lockstep.

- **New: how a text for the model names a space** (`AgentSpacesService::buildAgentSpaceLabelForTheModel(int
  $agentSpaceId): string`, default `space:<id>`). The memory line (`AgentMemory::toLlmMarkdown()`, through the new
  protected `buildAgentSpaceLabel()`) asks it for a memory of a space, once per line; a memory of the account still
  reads `account` and asks nothing. The lines reach the model in the results of all five memory tools
  (`AgentMemoriesMcpResponseDto::toLlmMarkdown()`). An application that knows the space under its own term answers
  its term and the id its tools take (`shop:4711`), so the memory line shows the id the model passes to the tools. The
  base prompt's `{%AGENT_SPACE_ID%}` (`applyAgentSpacePromptParameters()`), the mandate line
  (`AgentMandate::buildCoverageText()`), the curation's scope (`AgentMemoryCurationService::buildScopeLabel()`) and the
  operator run lists keep their own override points and do not ask the new method.
- **New: the header of the standing-directives block is an override point** (protected
  `AgentMemoriesService::getDirectivesBlockHeader(): string`, default `AgentMemories::DIRECTIVES_BLOCK_HEADER`).
  The service builds the block with the new `AgentMemories::toDirectivesBlockUnderHeader(string $header)`;
  `toDirectivesBlock()` keeps its signature and the constant header. Only the header line changes, the leading
  separator and the `- <directive>` lines stay. The chat's model input and the voice
  briefing read the same block. The line is part of the cached prefix: answer the same line for every conversation.
- **Changed, cost: the memory tools load with their skill** (`#[MCPToolAlwaysLoad]` on `AgentMemoriesMCPPackage`).
  Under lazy tool loading (`loadToolsOnSkillLoad: false`, which acts only under `skillLoadingStrategy:
  systemPromptSwap`) loading `Agent.Memory` now puts all five tool schemas into the model input at once instead of
  one `load_tool` call per tool: 1,155 tokens (4,626 characters / 4, the package's estimate
  `ToolDefinition::$residentSchemaTokens`; 1,125 by the GPT-3 tokenizer of `app:agent:skill-token-map`) in every call
  while the skill is loaded and the tools are not evicted, 1,238 tokens in a conversation with a space (the schemas then
  carry the note on the space argument). Per tool (chars / 4, without / with a space): `searchMemory` 269 / 297,
  `listMemories` 212 / 239, `remember` 355 / 383, `updateMemory` 195 / 195, `forget` 124 / 124. The tools stay
  evictable. No change under eager loading (the default) or under append-only loading (`conversationAppend`, the
  package's default strategy, which always loads eagerly whatever the tool delivery), where a loaded skill brings all
  its tools anyway; none while the memory store is off (`AI.agentMemory.enabled` false): the package is then not available
  and the skill `Agent.Memory` is not offered.
- Tests: the default label `space:<id>`; an application's label in the memory line, in the set's lines and in the
  results of `listMemories` and `searchMemory`, and a memory of the account that asks nothing; every memory tool reports
  always-load, a tool of another package does not, a lazy skill load provisions the memory tools and leaves other tools
  to `load_tool`, and the memory package is the only bound package with the attribute on its class; the default
  directives block pinned byte for byte, an application's header that changes only the header line in the chat and in
  the voice briefing, and `toDirectivesBlock()` / `toDirectivesBlockUnderHeader()`.

## 0.6.14

**Agent.UI: typed actions on the user's open screen (`actOnScreen`), off by default.** npm `@mgamadeus/ddd-agents` 0.6.14 in
lockstep.

- **New tool `actOnScreen`** in a package of its own, `AgentUIActionsMCPPackage` (`agent.ui.actions`, bound to the skill
  `Agent.UI`; offered only while `AI.agentUI.screenActionRequests` is true, like the window tool). A closed list of
  actions: `setFilters` (the URL filters the open screen's target declares; the rest of the URL stays), `sort`
  (`col.<key>`, `asc` / `desc` / `none`), `openRow` (`row.<key>`), `switchTab` (`t.<key>`), `press` (`act.<key>`). The
  model names a declared filter key or a reference of the newest screen read, never a selector, and never types into
  the page. Effect READ, never reused within a turn, the client's text and the route after the action untrusted. At
  most `AI.agentUI.maxScreenActionsPerUserTurn` (default 3) actions per message of the user, counted on success.
- **Nothing of it writes.** The client acts only on an element that declares the action (`data-agent-action` =
  `sort`, `open`, `tab`, `press`; a tab list's `role="tab"` counts). A button is pressed only when it is declared with
  `data-agent-action-effect="view"`; any other effect answers `WRITE_ACTION`, and a button without a declaration (the
  reader's `b.…`) is refused on the server before anything is sent (`NOT_DECLARED`). The refusals tell the model to use
  its data tool or to mark the button and let the user press it.
- **New request type `ACT_ON_SCREEN`** (`AgentClientToolRequest::TYPE_ACT_ON_SCREEN`,
  `AgentUIClientToolRequestTypeProviderService::isScreenActionRequestsEnabled()`).
- **Client (`./ui`)**: `DomScreenActor` (`ScreenActor`; marks the element through the highlighter, waits
  `ACTION_MARK_BEAT_MS`, then one pointer activation, `activateElement`), `ScreenAdapters.actions`, the optional
  `ScreenNavigator.currentTarget()` / `applyFilters()`, the outcomes `ACTIONS_UNAVAILABLE`, `UNKNOWN_ACTION`,
  `NO_FILTERS`, `NOT_DECLARED`, `WRITE_ACTION`, `DISABLED`, `NO_EFFECT`. Unsaved changes block what leaves the
  screen, an open dialog everything outside it. `ScreenReferenceRegistry.remember()` / `findDeclared()` (`col.`,
  `t.`, `act.` by action key, `row.` by row key).
- **Reader**: only where the screen declares something, a read gains `Filters (actOnScreen, action setFilters): …`
  (option `describeFilters`), key-based tab references with `Tabs (actOnScreen, action switchTab): …`, per table
  `Sort (…): col.<key> "Label" (asc)` and `Rows open with actOnScreen …`, and `Actions (actOnScreen, action press): …`.
  A screen without declarations reads as before.
- **`AgentUIMCPPackage`**: the per-turn counter is public and shared (`countToolCallsOfUserTurn()`,
  `readCounterOfUserTurn()`, `incrementCounterOfUserTurn()`); the existing methods delegate, behaviour unchanged.
- **Evaluations**: `AgentScriptedUIClientService` answers `ACT_ON_SCREEN` from the fixture keys `filters`, `actions`
  and `screensByAction` and logs each action or refusal.
- **Host steps** (all optional; without them nothing changes): `screenActionRequests: true` in the host's
  `agentUI.php`; the binding line in a replaced `skillPackages.php`; a same-path subclass of
  `AgentUIActionsMCPPackage` where the host gates the UI tools; `actions: new DomScreenActor(…)` in the client's
  adapters (with `@mgamadeus/ddd-admin-ui`: its `actorOptions`); `'ACT_ON_SCREEN'` wherever the host lists the request
  types its agent window steps aside for.
- `public-api.json` declares the purposed-start names of 0.6.9 (`PurposeStartRequest`, `PurposeSubject`,
  `StartedPurposeConversation`, `WirePurposeStartResponse`).

## 0.6.13

**The usage record names the conversation or voice call a cost was made for.** No web change; npm
`@mgamadeus/ddd-agents` 0.6.13 in lockstep. Requires `mgamadeus/ddd-ai` ^3.9.0 (the AI cost ledger).

- **New: the subject of a model call for the usage record** (`AIModelCall::$subjectEntity` of ddd-ai 3.9.0). The
  conversation's own calls, its compactions and its summaries name the conversation
  (`getSubjectEntityForUsageRecording()` of `ArgusAIConversation`, `ArgusAIConversationCompaction`,
  `ArgusAIConversationSummary`; none before the conversation is stored); the session seconds of a voice call name the
  call (`AgentVoiceCallAdmissionService::buildSessionModelCallOfRunningCall()`). Only a cost ledger reads it
  (`AI.usageLog.enabled` of ddd-ai, default off); the gate and the budget never do. An application with a ledger of
  its own reads it in its recorder.
- Tests: the session model call of a running voice call names the call, a start check names none.

## 0.6.12

**Voice: GPT-Live 1.1 is the default voice model.** npm `@mgamadeus/ddd-agents` 0.6.12 in lockstep. Requires
`mgamadeus/ddd-ai` ^3.8.3 (the GPT-Live catalog entries).

- **Changed: the default model of the OpenAI live provider is `gpt-live-1.1`** (`provider.openAiLive.model` of
  `agentVoice.php`, `OpenAILiveVoiceProviderAdapter::LIVE_MODEL`). Same events, voices and price as `gpt-live-1`; no
  wire change. There is no fallback to `gpt-live-1`: an application that set the model in its own `agentVoice.php`
  changes that line to `gpt-live-1.1`.
- **Fixed: a session is priced when its model setting is the provider's id.** `AgentVoiceCallAdmissionService::findSessionModel()`
  takes the catalog entry of the setting's name, else the `AUDIO` entry whose `externalId` is that name
  (`AIModelsService::getAIModelByExternalId()`), so `gpt-live-1.1` finds ddd-ai's `OPENAI.GPT_LIVE_1_1`. Before, a wire
  id never matched a catalog name and the session seconds went unpriced unless the application carried its own entry
  under the wire id (it can drop that entry).
- Tests: the session model found by the provider's id, the shipped default in the configuration, payload and
  endpoint tests.

## 0.6.11

**The purposed start route is opt-in; its subject classes are listed by the application; the optional subject table
is a warning.** Corrections to 0.6.9. No web change; npm `@mgamadeus/ddd-agents` 0.6.11 in lockstep.

- **Changed: `POST …/startPurpose` comes with its own trait, `AgentConversationPurposeStartTrait`.** 0.6.9 put it into
  `AgentConversationWritesTrait`, so an update alone mounted a new client route in every application that uses the
  write routes, without the quota or envelope attributes an application puts on its redeclared routes. A controller now
  adds the trait on purpose (`use AgentConversationPurposeStartTrait;`); an application that redeclares its routes
  redeclares this one too (body `buildPurposeStartedResponse()`). An application that adopted 0.6.9 and calls the
  route adds the trait.
- **Changed: `isSubjectEntityClassAllowedForPurposeStart()` answers no class by default** (0.6.9: every entity class).
  A start that names a subject answers 404 until the application lists the classes its purposes work on; a start
  without a subject is not affected. Every entity class by default made the route a probe for which ids exist wherever
  an entity class carries no read rights.
- **Changed: `app:agent:check-installation` reports a missing `AIConversationSubjectBindings` as a WARNING,** not
  BLOCKING: the code works without it (a subject is ignored until the table exists). New protected
  `AgentInstallationCheckService::getEntityClassesWhoseMissingTableTheCodeTolerates(): string[]` lists such tables; an
  application adds its own. An application that listed the table in `acceptedMissingTables` may keep or drop the line.
- Tests: the write routes no longer carry `startPurpose`, the new trait does; a subject without a listed class answers
  404; the missing subject table is a warning while a table the code reads stays blocking.

## 0.6.10

**`app:mcp:clear-cache` rebuilds the container in a child process; the Agent.UI picture keeps the columns of a wide
table.** npm `@mgamadeus/ddd-agents` 0.6.10 in lockstep.

- **`app:mcp:clear-cache`: the container is rebuilt in a child process.** Layer 2 (`cache:clear`) no longer runs in
  the command's own process, which already holds the reflection of every bundle and skill package of layer 1: in an
  application with 14 bundles and 65 skill packages whose console script sets 512 MB, the in-process run died with
  "Allowed memory size … exhausted", exit 255, and left the half-built warm-up directory of `cache:clear` behind. The
  child is started through the process component with an argument array (no shell, no timeout): the PHP binary and
  the console script of this process, `getContainerCacheClearCommandName()`, `--env=` of the running kernel and
  `--no-debug` when the running kernel is not in debug mode, with the environment variables this process started
  with and those of `$_ENV`. Its standard and error output are passed through as they arrive. New protected
  methods, one job each: `getPhpBinaryPath()`, `getConsoleScriptPath()`, `getRunningKernelEnvironment()`,
  `isRunningKernelInDebugMode()`, `getRunningKernelBuildDirectory()`,
  `buildContainerCacheClearChildArguments(): array`,
  `startContainerCacheClearChildProcess(array $childArguments, OutputInterface $output): int`.
- **`app:mcp:clear-cache`: a failed container rebuild fails the command.** A child that ends non-zero ends the
  command with exit `1`; the last lines name layer 2, the child's exit code and that layer 1 completed (run only
  `cache:clear` again). The warm-up directory the failed child left beside the build directory is removed
  (`getContainerCacheWarmupDirectoryPath()`, named like Symfony's `CacheClearCommand` names it, and
  `removeContainerCacheWarmupDirectory()`); a directory that cannot be removed is named by its path. The real cache
  directory is never touched, and nothing is removed after a successful child. Before, the exit code of
  `cache:clear` was ignored and the command answered `0`.
- **`app:mcp:clear-cache --onlyPackageCaches`.** Clears layer 1 alone and starts no child (exit `0`); the output
  says the container was not rebuilt and that the application's own cache reset, then the worker restart, rebuild it
  (`getContainerRebuildSkippedNotice()`). For an application that serves web requests from the same cache directory
  and must not run `cache:clear` under a serving web process.
- **`rebuildCompiledContainer(OutputInterface $output): void` keeps its signature.** It now starts the child and
  records the exit code in `$containerCacheClearExitCode`; the command asks the new
  `rebuildCompiledContainerAndFindExitCode(OutputInterface $output): int`, which calls it. An application's override
  that rebuilds the container another way keeps working: it records its exit code there, or leaves it null and the
  run counts as rebuilt (as before). The test command `ContainerCacheClearRecordingCommand` is removed.
- **Fixed: the picture lost the columns at the right edge of a wide table.** The copy leaves out the columns right of
  the window but kept the table's page width (2,637 px for 13 columns on a host list, 3 of them out of view); the table
  layout spread it over the 10 copied columns, each grew by about 17 %, and a column partly in view left the picture.
  Table parts (`table`, row groups, rows) now get neither their page height nor their page width; the cells keep
  theirs, so the copied columns have the page's widths.
- Tests: the child's arguments (environment, `--no-debug`), a failed child fails the command and names layer 2, the
  warm-up directory removed or named, `--onlyPackageCaches` starts no child, an override that records nothing counts as
  rebuilt; no width or inline size on a table, a row group or a row, the cell keeps its width.

## 0.6.9

**Purposes: one conversation per record, and a purposed start route for the client.** npm `@mgamadeus/ddd-agents` 0.6.9 in
lockstep (`startPurpose` on the client port).

- **Subject of a purposed conversation.** `AIConversationSubject` (`subjectEntityClass` + `subjectEntityId`) names the
  record a purposed conversation works on. `AIConversationAgentService::startOrResumePurposedConversationOfSubject()`
  keeps the singleton per space, purpose AND subject (the same record reopens its open conversation, another record
  starts a new one) and binds the new conversation to its subject. The existing `startOrResumePurposedConversation()`,
  `getOrStartPurposedConversation()`, `resumeSingletonPurposedConversation()` and
  `findNewestPurposedConversationOfAgentSpace()` keep their signatures; the last now leaves out conversations that
  work on a subject, so a start without a subject behaves as before. New: `resumeSingletonPurposedConversationOfSubject()`,
  `findNewestPurposedConversationOfAgentSpaceForSubject()`, `AIConversationSubjectBindingsService`.
- **Schema:** one new table, `AIConversationSubjectBindings` (one row per conversation: `aiConversationId` unique with a
  cascading foreign key, `agentSpaceId`, `purposeName`, `subjectEntityClass`, `subjectEntityId`, index on the four).
  Not a column of `AIConversations`: every conversation read selects all its columns. Apply it with the schema diff;
  until then a subject is ignored (no reuse by subject) and nothing fails
  (`AIConversationSubjectBindingsService::isBindingTableAvailable()`). The eval purge leaves the rows to the foreign key.
- **Route `POST …/startPurpose`** in `AgentConversationWritesTrait` (no new controller): `purposeId`, `agentSpaceId?`,
  `subject?`, `prefill?` → `aiConversationId`, `resumedExistingConversation`, `prefillDelivered`. Scope CUSTOMER: the
  purpose must be `startableViaApi` and its `requiredFeatureFlags` available to the space, as with the PHP starter. A
  subject the caller cannot read answers 404, a malformed one 400. New hook
  `findAgentSpaceOfPurposeStartRequest()` (default: the body's `agentSpaceId` under read rights; 400 without one), a
  host with a personal space per account overrides it. Hook `isSubjectEntityClassAllowedForPurposeStart()` (default: every entity
  class) narrows the classes a client may name as a subject; a refused class answers 404 like an unknown record. An
  application whose entities carry no read rights lists its subject classes there, so the route is no probe for
  which ids exist. A host that adds the trait gets the route; one that redeclares
  routes lists it in its own route order.
- **Reopen of a purposed entry point.** `AIConversationsService::findReopenablePurposedConversationForEntryPoint()`
  (account, space, entry point key, purpose, now): the entry point's reopen window, partition and checks, for a
  conversation of that purpose. `findReopenableConversationForEntryPoint()` keeps reopening only conversations without
  a purpose, so an application that drives a purposed conversation from an entry point asks the new read before it
  starts one (before, every click started a new conversation). A purposed start that names its record uses the subject
  lookup instead.
- **Web:** `AgentsConversationClient.startPurpose?(request: PurposeStartRequest): Promise<StartedPurposeConversation>`
  (optional on the port; the HTTP binding and the polling wrapper have it). New contract types `PurposeSubject`,
  `PurposeStartRequest`, `StartedPurposeConversation`.
- Tests: the reuse by subject, another subject, no subject, a closed conversation of a subject, the binding once per
  conversation and a host without the table (MariaDB and PostgreSQL); the route refuses a request without a space, an
  unreadable subject (404), a subject class the application does not allow (404) and a malformed subject class (400), hands a readable subject to the starter with scope
  CUSTOMER, reports a reopen and the prefill cap; the HTTP binding's body and answer; the purposed and the plain reopen of an entry point (MariaDB and PostgreSQL).

## 0.6.8

**Voice: four override points — the ring window of the open-calls read, ring target reachability with the agent space
and a deferred answer, the briefing's directives through the memory port, device counts per ring target.** No web
change; npm `@mgamadeus/ddd-agents` 0.6.8 in lockstep.

- Voice: the ring window of a call row without a ring deadline, in the read of the open calls, is a protected method of
  the call repository, `DBAgentVoiceCalls::getRingSecondsWithoutDeadline(): int` (the read's half of the call's
  `AgentVoiceCall::getRingSecondsWithoutDeadline()`). Default: `limits.ringSeconds`, so the query is unchanged. It is
  one number for the whole read (the package's call table names no medium); an application with several ring lengths
  answers the longest. An application that overrode `findOpenCalls()` only to widen that window may remove the
  override.
- Voice: the reachability of a ring target gets the agent space and a third answer.
  `AgentVoiceCallStartsService::findRingTargetReachability(string $ringTargetName, Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $ringConversation): string`
  answers `RING_TARGET_REACHABLE_NOW`, `RING_TARGET_NOT_REACHABLE_NOW` or `RING_TARGET_DECIDED_WHEN_RUNG`. Default: the
  answer of `isRingTargetReachableNow()` mapped to the first two, so nothing changes without an override. A target that
  answers "decided when rung" counts as a target to ring (no `no_registered_devices` because of it), is rung in the
  order of `getRingTargetNames()`, and its `ringCallOnTarget()` finds out; if every target deferred and none rang, the
  call ends `FAILED` as any ring that reached nobody (the agent's tool answers `APP_RING_DELIVERED_NOWHERE`). The agent
  space is not checked by the service and is asked with the rights lifted on the agent's path: an override narrows its
  reads to the owner account and that space. The package now asks the new list `findRingTargetNamesToRing()` and
  `isAppRingableInAgentSpace()`; an existing override of `findReachableRingTargetNames()` or `isAppRingable()` is still
  asked while it exists (without the agent space and the third answer). An application that overrode
  `findReachableRingTargetNames()` wholesale to defer a target may move that decision into
  `findRingTargetReachability()` and remove the override.
- Voice: the standing directives of the call's briefing are read through the chat's memory port,
  `AgentMemoriesService::findStandingDirectivesForAgentSpace()`, instead of the memory store directly. With the
  package's binding the block is the same as before. An application whose memories sit behind its own service binds
  that port once, for chat and call, and may remove its override of
  `AgentVoiceSessionContextsService::findMemoryDirectivesBlockOfConversation()`. The briefing now reads the
  conversation's agent space relation (as the chat does) to hand it to the port: a space that is gone, or that the
  caller of `open` cannot read, gives the account-wide directives.
- Voice: the device counts of a ring target in the trace of a started ring.
  `AgentVoiceCallStartsService::findSelectedDeviceCountOfRingTarget(string $ringTargetName, AgentVoiceCall $agentVoiceCall): ?int`
  and `findRungDeviceCountOfRingTarget(string $ringTargetName, AgentVoiceCall $agentVoiceCall): ?int` are asked after
  the target was rung. Default: null (unknown), and an unknown count writes nothing, so the stored events are
  byte-identical to before. A known count is added as `devicesSelected` / `devicesRung` to the trace event of that
  target (whoever built it); the `devicesRung` / `devicesSelected` of `call.ring_started` keep counting ring targets.
  An application that wrote a parallel trace event per device to say how many devices a target rang may drop it.
- Tests: the ring window of the open-calls read, the three reachability answers (deferred targets rung in order, all
  deferred and none rung ends FAILED, an older override still asked), the directives through a bound memory port
  (space gone → account-wide), the device counts merged into the target's trace event (unknown writes nothing).

## 0.6.7

**Voice: who pays for a call start and which account "call me" rings are override points.** No web change; npm
`@mgamadeus/ddd-agents` 0.6.7 in lockstep.

- **Voice: who pays for a call start is one override point.** `AgentVoiceCallAdmissionService::findBilledAccountOfCallStart(Account $account, ?AgentSpace $agentSpace, ?AIConversation $conversation): Account` (protected) names the account the model-call gate is asked for, and the usage envelope of the question is opened for, when `open` or a ring starts a call. Default, unchanged in its results: with a conversation the conversation's account (`findBilledAccountOfConversation()`), falling back to the call's account when that answers null; without a conversation the call's account. An application in which the owner of the agent space pays although someone else starts the call overrides this one method, also for a start without a conversation. An application that worked around the missing point by overriding `findRefusalOfCallStart()` to re-ask the gate for another account can remove that override (or keep only its own extra conditions in it). Who pays for a running call stays `buildSessionModelCallOfRunningCall()`.
- **Voice: the account a ring of "call me" is started for is one override point of the routes.** `AgentVoiceSessionsTrait::findRingAccountOfCallRequest(RequestDto $requestDto, ?AgentSpace $agentSpace): Account` (protected). Default: the signed-in account, so behaviour without an override is unchanged. The account it names is the one `ring` hands to `AgentVoiceCallStartsService::startRing()`, the one the one-call check (`call_already_running`) reads, and the one `open` of the rung call asks the admission for again. The routes that name a call (`answer`, `decline`, `hangUp`, `deviceStatus`, `GET /{callId}`, and `open` with a `callId`) now accept a call of the signed-in account OR of the account this hook names for the request and the space of the call's conversation (read with the entity rights lifted). Any other call stays `404 call_not_found`, the same status and message as before. An application in which an agency user or a sub-user presses "call me" for the owner of the agent space overrides this ONE method and gets both effects. An application that worked around it by redeclaring the `ring` route with its own copy of `buildVoiceCallRingingResponse()`, or by widening `findOwnCall()` for its agency or sub-user roles, can remove that copy or override.
- **Voice: the new protected helpers of the routes trait.** `findCallOfVoiceRequest(int $callId, RequestDto $requestDto): AgentVoiceCall` is what the routes that name a call now read their call through: `findOwnCall()` first (its signature and its override point are unchanged), then the hook's account. `isCallOfRingAccountOfVoiceRequest(AgentVoiceCall $agentVoiceCall, RequestDto $requestDto): bool` makes the decision. A call without an account, whose conversation cannot be read, or whose conversation names a space that is gone, is refused.
- **Voice: an override of the ring account checks the signed-in user itself.** At a route that names a call the hook gets the space of the call's conversation, read with the entity rights lifted: it says which space the call works for, not that the signed-in user may act for it. An override names another account only after it has checked that the signed-in user acts for that space, and the signed-in account in every other case (the hook's docblock and the voice skill's route reference say so).
- **Docs:** `AgentVoiceRingService::expireOverdueRingOfCall()` says when the ring message is removed (an overdue ring,
  whichever look wrote the end), no behaviour change.
- Tests: the billed account of a start (default with and without a conversation, an override naming the space owner),
  the ring account (default, an override ringing the owner, the routes that name a call accept the owner's call and
  refuse an outside account with `404 call_not_found`).

## 0.6.6

**Agent.UI: the agent reads the whole screen and sees its own window.** Measured on a host list of 200 tickets: the
read was cut at 6,000 characters inside 201 checkbox lines before the table, the main menu was never read, and a
picture copied 8,000 elements with all computed styles (about 150 MB of SVG) and drew a blank table body. npm
`@mgamadeus/ddd-agents` in lockstep.

- **Screen read (`DomScreenReader`).** A row's selection checkbox is no field line (the row says `(selected)`, also on
  cards); tables and cards come before the fields; at most 15 rows (`MAX_TABLE_ROWS`), cells cut at 60
  (`MAX_CELL_CHARS`), columns empty in all shown rows left out; a `Text:` line per section with the plain text no other
  line carries (`MAX_SECTION_TEXT_CHARS` 1,200); the main menu as `nav.<key>` references (`findNavigation`,
  `data-nav-key`, `data-nav-group`; a closed group shows only itself), written after the page and never cut away; a
  line with the agent window (`describeAgentWindow`) and the browser size after `Unsaved changes`.
- **Budget.** `MAX_SCREEN_CHARS` is half the answer cap (12,000) and equals the server's cut
  `AgentUIToolResponseDto::MAX_CONTENT_CHARS` (was 6,000 on the client, 8,000 on the server); a spec compares them.
- **Picture (`createDomScreenPictureCapturer`).** Only elements in the browser window (clipped by their scroll
  containers) are copied; a declaration is inlined only where it differs from the tag's browser default (an empty
  frame, cached per tag: `createFrameDefaultStyleSource`) or from the parent; table parts never get their page height
  (17,769 px for 200 rows stretched the copied rows: header and one row). `prepareScreenCloneForPicture(root, options)`
  takes `visibleBox` (`null` = the whole page with scroll containers expanded) and `defaultStyles`; `false` as before.
  `PICTURE_LIMITATIONS` (client and server): the last sentence now says only the browser window is shown.
- **UI state.** `AgentUIState` carries `agentWindow` (a token), `viewportWidth`, `viewportHeight` (1 to 20,000);
  `AgentUIClient` fills them from `AgentWindowAdapter.getMode()` (new, optional; `createAgentWindowAdapter` has it)
  and the window. The `[SIGNAL:ui]` note adds `agent window <mode> · browser window narrow|medium|wide`
  (`AgentUIState::describeWindows()`); the pixels stay out of the note.
- Tests: `_DomScreenReader.spec.ts` (list, text, menu, window line), `_screen-picture.spec.ts` (window part, scroll
  clip, table heights, size within elements × bytes, frame defaults), `_AgentUIClient.spec.ts` (window mode, size),
  the budget contract; `AgentUIStateTest` (normalize, width classes, stored JSON).
- **An application** that calls `prepareScreenCloneForPicture()` with a second argument passes the options object
  (`{ visibleBox, defaultStyles }`) or `false` as before; a host wrapper that cut rows, budgeted the read or skipped
  off-screen elements before a capture can go.

## 0.6.5

**Voice: the seconds since the last booking are booked when the worker ends a call; eval-tools survives a retry.** Bug
fixes. No web change; npm `@mgamadeus/ddd-agents` 0.6.5 in lockstep.

- **Fixed: a call the worker ended lost the seconds since its last booking.** Seconds are booked once
  `usageBookingMinimumSeconds` built up or on the provider's final report (`session.closed`); a goodbye hang-up, a
  refusal, the silence stop and the SIGTERM end hang up without waiting for that report, so up to 29 s per call (all of
  a short call) were never booked. The teardown now books what is left (`bookSecondsNotYetBookedAtTheTeardown()`): the
  last reported cumulative seconds plus the seconds since that report, minus what is booked, also under the minimum.
  After a final report nothing is left; before the first report the initialisation charge applies as before.
- **Fixed: `app:ai-agent:eval-tools` died on the retry after an infrastructure error** ("Only variables should be
  passed by reference": a call result handed to `ObjectSet::add()`). The same pattern is fixed in the surface comparer
  and the working state's cold capability drops.
- Tests: the rest booked at the teardown (reported seconds under the minimum plus the time since the report), nothing
  more after a final report, the reported seconds of a short call booked.

## 0.6.4

**An application's subclasses are reached: the spoken answer of a voice call and the gates of a subclassed tools
package.** No web change; npm `@mgamadeus/ddd-agents` 0.6.4 in lockstep.

- **Fixed: the voice session worker built the spoken answer of a finished task with the package's class,** so an
  application's same-path subclass of `AgentVoiceSpokenAnswer` (its `getShownOnlyInTheChatPromptName()` per channel
  type) was never used. The worker now builds it through `buildSpokenAnswer()` (protected, overridable), which resolves
  the application's class.
- **Changed: the class-level gates of a tools package are read through its parent packages**
  (`MCPPackage::findClassAttributesOfPackageHierarchy()`, used by `findRequiredAccountRightNamesOfClass()` and
  `MCPToolEntitlement`): `MCPRequiresAccountRights`, `MCPRequiresFeatureFlags`, `MCPRequiresAnyFeatureFlag` declared on a
  package also gate the tools of an application's subclass. Before, a subclass that did not repeat them lost every
  gate silently. An application that relied on dropping a gate by subclassing lifts it with a tool overlay
  (`AI.toolOverlays`, `accountRights: []`).
- Tests: the application's spoken-answer class is built; a subclass of the voice tools package keeps `useVoice`.

## 0.6.3

**Voice: a SIGTERM ends a running call within seconds instead of holding the worker up to the longest call.** Bug fix.
No web change; npm `@mgamadeus/ddd-agents` 0.6.3 in lockstep.

- **Fixed: the session worker held its process until the call ended on its own** (up to `limits.maximumCallSeconds`,
  3600 s), so every rollout or node drain during a call waited up to an hour per busy worker. Now the next watcher tick
  after a SIGTERM (at most 2 s) tells the voice model ONE short goodbye without a reason
  (`buildWorkerStoppingGoodbyeInstruction()`, new prompt `AI.Voice.WorkerStoppingGoodbye`), hangs up after the
  farewell's six seconds and runs the normal teardown (`finishCall()`: transcript flushed, one end marker, seconds
  booked, provider session hung up) with the end reason `COMPLETED`. The signal is then passed on as before. The owner
  can call again at once and reaches another worker.
- **New config `AI.agentVoice.endCallOnTerminationSignal`** (default `true`, `AgentVoiceConfiguration::$endCallOnTerminationSignal`;
  override point `isCallEndedOnTerminationSignal()`): `false` keeps the drain of 0.6.0 (the call runs to its end before
  the worker stops; the stop grace must then exceed `limits.maximumCallSeconds`).
- **Behaviour change for an application with voice on:** a deploy now ends running calls after the goodbye. A stop grace
  of about a minute is enough for the voice consumers; a grace sized for the longest call can be lowered.
- Tests: the goodbye once, the hang-up and the teardown after SIGTERM; no goodbye without a signal; the switch off
  keeps the drain; the config entry and its malformed form; the prompt file.

## 0.6.2

**Web only: hooks for an application around the voice components.** Additive; every default is unchanged. No PHP
change. npm `@mgamadeus/ddd-agents` 0.6.2 in lockstep.

- **`AgentVoiceCallBand` takes `actions?: ReactNode`**: the application's own controls (a button that opens the chat in a window), placed in the band's row of buttons before the band's own and rendered only while the band shows something (a call, a ring, a refused answer, an outcome). `AgentVoiceCallBandView` takes the same prop. Default: none, the band renders as before. An application that laid its own controls over or next to the band can put them into it.
- **`AgentVoiceChatStartButton` takes `onStart?: () => void`**, called in the click, synchronously, before the microphone prompt and `open`: the place for what needs the user's gesture, such as opening a window for the chat. Default: nothing. An application that wrapped the button or started the call itself to keep the gesture can use the button as it is.
- **A start that fails before `open` answers is the store's state**, as it already was: a refused microphone and a refusal of `open` (`no_ai_budget`, `daily_limit_reached`, …) set the call to `failed` with its `failureTextKey`, so every mounted `AgentVoiceCallBand` of that agent space shows it, inside a chat or not. Now pinned by specs; no behaviour change. An application that copied the failure into a band of its own can read the band instead.
- **Test ids are configurable**: `testId?: string` on `AgentVoiceCallBand` (and its view), `AgentVoiceCallCaptions`, `AgentVoiceIncomingCall` and `AgentVoiceInputGlow`, as the start button already had. The component takes it on its root and `${testId}-<part>` on its inner elements (the band's `-status`, `-mute`, `-end`, `-incoming-decline`, `-incoming-accept`, `-incoming-refusal`, `-ended-status`, `-failure`, `-start-again`, `-close`, `-actions`; the captions' `-caption-agent`, `-caption-owner`; the incoming call's `-detail`, `-decline`, `-accept` and `-refusal` for the card of a refused answer; the glow has no inner test id). Default: the fixed `agent-voice-…` ids, unchanged. An application that mapped or re-tagged those ids for its own test suite can set its ids directly.
- **`AgentVoiceIncomingCall` takes `zIndex?: number`** for its stacking order over the page, on the ring card and the card of a refused answer. Default: the custom property `--agents-voice-incoming-call-z-index` (itself defaulting to 2147483000), as before. An application that overrode the custom property only to fit its layers can pass the number.
- **`useFollowAgentVoiceCallConversation(handler)` is exported from `./voice`**: the hook the provider uses for `onConversationOfCallChanged`, for a component of the application's own that follows the call's conversation (called once per call and conversation with `conversationId` and `agentSpaceId`). An application that copied the hook can import it.
- **The start button's label shows through the environment's tooltip**: when the application replaces the package's `Tooltip` (`AgentsEnvironment.components.Tooltip`, app-wide or per subtree), the button renders inside it with the text key `Talk to %agentName%` and drops its `title`; otherwise the label stays the button's `title`. The accessible name is unchanged. No new dependency. An application that wrapped the button in its own tooltip can drop the wrapper.
- **`createAgentVoiceApi` takes `buildOpenPath?: (agentSpaceId: number | undefined) => string`** (type `AgentVoiceOpenPathBuilder`, exported from `./voice`): the whole path of `open` for an application whose route names the agent space in its path; the body still carries `agentSpaceId`, the other five routes stay below `apiPrefix`. `buildAgentVoiceRequests(apiPrefix, buildOpenPath?)` takes it as an optional second argument. Default: `${apiPrefix}/open`, as before. An application that wrote the six adapter functions itself only because of that path can use `createAgentVoiceApi`.

## 0.6.1

**The voice channel's lists become object sets, and an application's own voice channel provider wins.** Changes only
the voice signatures released with 0.6.0 one release ago (an application binding voice adapts its overrides); nothing
changes for an application with voice off. npm `@mgamadeus/ddd-agents` 0.6.1 in lockstep.

- **Changed: a list of objects on a public or protected signature or on a DTO of the voice channel is an object set,**
  no longer `X[]`, and each element class has a deliberate `uniqueKey()`. New sets: `AgentVoiceSeedInputMessages` (the
  seed rows of a session), `LiveTranscriptSentences` (the completed sentences of a call),
  `AgentVoiceCallTraceSpokenSentences` and `AgentVoiceCallTraceBacklogPushes` (the call header of the trace view):
  every element is held under its own object, so two equal rows are two rows, in the order they were added.
  `AdminAgentVoiceCallsDerivedValues` (the admin list) holds one entry per call under the call's id. The first trace
  event of each type is the value object `AgentVoiceCallTraceFirstEvents`, where a keyed array was handed on. An array
  stays only as state of a service that is never serialised and never returned (the buffers of the session worker, two
  memos), and its comment says so; scalar lists and wire, storage and config documents stay arrays. What the provider
  and a ringing client receive is unchanged.
- **Changed signatures** (`array` in 0.6.0):
  - the seed rows: `AgentVoiceSessionContext::buildLiveCreationSeedInputMessages()`, `buildLiveSeedInputMessages()` and
    the override point `buildApplicationSeedInputMessages()` return `AgentVoiceSeedInputMessages`;
    `RealtimeVoiceProviderAdapter::buildSessionCreationPayload()` (and `OpenAILiveVoiceProviderAdapter`) takes
    `?AgentVoiceSeedInputMessages $seedInputMessages = null`;
  - the sentences: `LiveTranscriptAssembler::appendFragment()`, `closeIdleSentences()`, `closeAllSentences()`,
    `closeOpenCallerSentence()` and `closeOpenSentenceForRole()` return `LiveTranscriptSentences`;
    `AgentLiveVoiceSessionsService::persistCompletedSentences()` takes it;
  - the calls: `AgentVoiceCalls::getOpenCallsAt()` and `getRunningCalls()` return a set of the same class;
    `AgentVoiceCallsService::closeStaleRunningCalls()` returns `AgentVoiceCalls`; `AgentVoiceCallEndsService`:
    `findOpenCalls()` returns it, `findOpenCallOfConversation()` and `collectCallIds()` take it;
    `AgentVoiceCalls::buildLlmMarkdownSummaryLine()` has no list parameter any more (`bool $onlyOpenCalls` alone);
  - the trace: `AgentVoiceCallTraceSummary::fromTraceEvents()` takes `AIConversationTraceEvents` and
    `?AIConversationTurnEvents` (null = none); its protected readers take `AgentVoiceCallTraceFirstEvents` and the
    sets; `AgentVoiceCallStartsService::recordRingTraceEvents()` takes `AIConversationTraceEvents`;
    `AgentVoiceTaskProgressSchedule::passLinesOn()` takes `AgentActivityLines`.
- **Changed: the answer of the admin list of calls.** `agentVoiceCallDerivedValues` is an object set
  (`AdminAgentVoiceCallsDerivedValues`): on the wire an object with `elements`, like `agentVoiceCalls`, where 0.6.0
  answered a plain list. The entries (`callId`, `callState`, `bookedCosts`) and the answer of one call are unchanged.
- **Changed: an application's own voice channel provider wins without a warning.** `AgentVoiceChannelService` asks an
  application's providers first, in container order, and the package's own provider
  (`AgentVoiceChannelProviderService`) last: it is the fallback. The package's provider is recognised by its class (an
  object of exactly that class; a subclass is an application's provider) and is not counted: the `voiceChannel`
  WARNING "several providers" of `app:agent:check-installation` and the log line per process come only with two or
  more providers of the application's own (`getRegisteredApplicationVoiceChannelProviderClassNames()`). In 0.6.0 one
  provider of the application's own beside the package's already warned, and the first available one in container
  order answered.
- **New: the voice transports in the copy sources,** as a commented block headed "only when voice is enabled": the
  transports `voice_live_session` and `voice_ring_deadline` with their routing in the Messenger template of the
  installation skill (`templates/messenger.yaml`), and their two consumers in `examples/workers.yaml`. As shipped, both
  files still hold the six agent transports only.
- **Changed (web): the ring source takes the poll.** `createAgentVoiceRingSource(accountRealtimePoll)` subscribes to
  the application's running poll and never stops it; the application creates and stops the poll. The form of 0.6.0
  that took a function starting a poll of its own is gone.
- **An application that overrides `buildApplicationSeedInputMessages()`** returns `AgentVoiceSeedInputMessages`
  (`AgentVoiceSeedInputMessages::fromMessages()`, an empty set for none); an override that still declares the return
  type `array` no longer loads.
- **An application with a provider adapter of its own** declares
  `?AgentVoiceSeedInputMessages $seedInputMessages = null` in `buildSessionCreationPayload()` and reads the rows from
  the set (`getElements()`).
- **An application that calls or overrides** `closeStaleRunningCalls()`, `getOpenCallsAt()`, `getRunningCalls()`, the
  assembler's methods, `persistCompletedSentences()`, `AgentVoiceCallTraceSummary::fromTraceEvents()`, the protected
  methods of `AgentVoiceCallEndsService` named above, `recordRingTraceEvents()` or `passLinesOn()` hands on and
  receives the sets; an override of `buildLlmMarkdownSummaryLine()` drops the list parameter.
- **An application whose client reads the admin list** reads `agentVoiceCallDerivedValues.elements`.
- **An application with a voice channel provider of its own** needs no change: its provider is asked first, and the
  warning it read in 0.6.0 is gone. An application that overrides the protected
  `getRegisteredVoiceChannelProviderClassNames()` of `AgentVoiceChannelInstallationCheckService` renames the override
  to `getRegisteredApplicationVoiceChannelProviderClassNames()`.

**Found after the 0.6.0 release (also applies to 0.6.0):**

- **New protected methods on `AgentReapStuckConversationsCommand`** (the voice sweep, among them
  `getAgentVoiceCallsService()`): an application command that extends it and already has a method of the same name with
  another return type no longer loads, and every console command dies at boot (`cache:clear` of `post-update-cmd`
  first). Rename the application's method before updating.
- **`app:entity:show-sql`** is ambiguous for the short name `AgentVoiceCall` while the application still has a call
  class of its own: pass the full class name of the application's class to check its table.
- **Web specs:** the specs type-check (`_AgentChatActivityStack.spec.tsx`, `_widget-preview-requests.spec.ts`,
  `_custom-visualization-sandbox-aliases.spec.tsx`, `_createAgentsHttpConversationClient.spec.ts`); no shipped code
  changes.

## 0.6.0

**The voice channel: the owner talks to the agent in the application's window, and the agent rings the owner's open
clients, a client that only polls included. Optional and switched off by default (`AI.agentVoice.enabled`): an
application that does not switch it on gets no tool, no skill, no required table and no new dependency.** Additive. npm
`@mgamadeus/ddd-agents` 0.6.0 in lockstep (new entries `./voice` and `./voice/admin`).

**New (PHP):**

- **A folder `Voice/` in every layer** (`DDD\Domain\AI\{Entities,Services,Repo\DB,MessageHandlers,Exceptions}\Voice\`,
  `DDD\Presentation\{Api\Agents,Api\Admin,Mcp\Client}\Voice\`): the call (`AgentVoiceCall`, state derived, read under
  the rights of its conversation) and its store port (`AgentVoiceCallStoreService`, contract `AgentVoiceCallStore`,
  shipped store `entity`); call life with the one end path (`AgentVoiceCallsService`); admission of a call start and of
  a running call (`AgentVoiceCallAdmissionService`: the right `useVoice`, the daily cap, the model-call gate, the
  longest a call may last); call start and ring (`AgentVoiceCallStartsService`, `AgentVoiceRingService`, the realtime
  message type `agent.voice.ring`, `AgentVoiceRingDeadlineMessage`); the provider seam `RealtimeVoiceProviderAdapter`
  with `OpenAILiveVoiceProviderAdapter`; the briefing (`AgentVoiceSessionContextsService`, the prompt files under
  `config/app/AI/Prompts/AI/Voice/`); the session worker (`AgentLiveVoiceSessionsService`,
  `VoiceLiveSessionAttachMessage`); the routes (`AgentVoiceSessionsTrait`) and the admin list of calls
  (`AdminAgentVoiceCallsTrait`) as controller traits; three tools (`AgentVoiceCallsMCPPackage`, package id
  `agent.voice`: `startVoiceCall`, `endVoiceCall`, `getVoiceCalls`).
- **The agent skill `Agent.Voice`** (`AISkill::AGENT_VOICE`) is bound to the tool package in the shipped
  `skillPackages.php` and offered only while voice can run; an application that replaced that file adds the line when it
  switches voice on. **The right `useVoice`** (`AgentVoiceAccountRights::USE_VOICE`) is not mapped by the shipped
  `accountRights.php`, so nobody holds it until the application says who.
- **Typed value objects where voice hands rows on,** no keyed arrays (`DDD\Domain\AI\Entities\Voice\Calls\`): the
  provider seam takes the seed rows of a session as `AgentVoiceSeedInputMessage[]`
  (`RealtimeVoiceProviderAdapter::buildSessionCreationPayload()`; an application adds its own in
  `AgentVoiceSessionContext::buildApplicationSeedInputMessages()`); the ring is an `AgentVoiceRingPayload`
  (`AgentVoiceRingService::buildRingPayload()`, rendered in one place, `toPayloadJson()`) with the caller context
  `AgentVoiceRingCallerContext` (empty in the package; an application's same-path subclass names its fields in
  `toPayloadArray()` and is filled in `buildCallerContext()`); a completed sentence of a call is a
  `LiveTranscriptSentence` (handed to the worker's hook `keepCompletedSentenceOutOfTheConversation()`), a sentence that
  waits to be written a `LiveTranscriptBacklogSentence`; the call task service answers `AgentCallTaskStart` and
  `AgentCallTaskOutcome`, the progress schedule `AgentVoiceTaskProgressUpdate`. A collection of them is a typed list.
- **The call's signal sources stay where they are:** the enum `PhoneConversationSignalSource`
  (`DDD\Domain\AI\Entities\Conversations\SignalSources\`) with its stored values. The voice services of the package
  write `PHONE_CALL_STARTED`, `PHONE_CALL_ENDED`, `PHONE_DELEGATION` and `PHONE_CALL_UNANSWERED_FOLLOW_UP`; the case no
  class of the package writes (`PHONE_VOICEMAIL`) stays for an application that stores it.
- **Config** `config/app/AI/agentVoice.php` (`AI.agentVoice`, read through `AgentVoiceConfiguration::current()`):
  `enabled` (false), `callStore`, the provider adapters and their settings, `language`, `limits`.
- **Two transports of their own,** `voice_live_session` and `voice_ring_deadline` (`AgentVoiceMessengerTransports`, with
  the routing of the two message classes). Only an application that switches voice on copies them;
  `AgentMessengerTransports` is unchanged.
- **One more table,** the call table of `AgentVoiceCall` (17 package tables). It is in use only while `enabled` is true
  and `callStore` is `entity` (`AgentVoiceConfiguration::isCallTableInUse()`): `missingTables` requires it only then
  (`AgentVoiceFeatureTableConditionProviderService`), and `app:agent:eval-conversations-purge` touches it only then
  (`AIConversationEvalPurgeService`: the calls of a purged conversation are deleted, the pointer of a call the agent
  started from it is emptied; override point `isVoiceCallTableInUse()`). Otherwise the two pointers are listed in
  `getColumnsNotPurged()` and no statement names the table.
- **`config/untrusted-sinks-allowlist.php`:** one entry, the HTTP client of `OpenAILiveVoiceProviderAdapter` (the two
  REST calls of a session go to the provider host of the committed config on fixed paths).
- **`AgentsModule`:** `OWNED_NAMESPACES` gains `DDD\Domain\AI\{Entities,Exceptions,Repo\DB,Services}\Voice\` and
  `DDD\Presentation\Api\Admin\Voice\`; `getPublicServiceNamespaces()` gains `DDD\Domain\AI\Services\Voice\`.
- **The session worker's WebSocket library is suggested, not required:** `ratchet/pawl` and `react/event-loop` are in
  `suggest` (and in `require-dev`). New `AgentsModule::getNamespacesRequiringClasses()` with
  `VOICE_SESSION_WORKER_NAMESPACES` (`…\Services\Voice\SessionWorker\`, `…\MessageHandlers\Voice\SessionWorker\`) and
  `VOICE_SESSION_WORKER_LIBRARY_CLASS`: without the library the session worker and the handler of its message are no
  services and are never loaded; the container compiles and everything else in voice loads (the call, the store, the
  ring, the admin list, the adapter's session creation).
- **One place answers whether voice can run in an installation:** `AgentVoiceCallAdmissionService::canVoiceRunHere()` =
  `enabled` AND the library installed (override point: the `protected` `isSessionWorkerLibraryInstalled()`). The
  admission of a call start, `AgentVoiceChannelProviderService`, the tool package (`isAvailable()`) with its skill, the
  routes and the installation check ask it.
- **`app:agent:check-installation`:** the check `voiceChannel` has a second finding, a WARNING when voice is switched on
  without the library (subject `AI.agentVoice.enabled`; the hint names the two packages). The channel then reads as
  switched off; nothing fails.
- **`app:agent:reap`** closes voice calls a dead worker left running: the default of `closeStaleChannelSessions()` runs
  the new `closeStaleVoiceCalls()` (one line per closed call through `buildClosedStaleVoiceCallLine()`, one count line,
  a lock of its own, `STALE_VOICE_CALLS_LOCK_NAME`). No command, cron line or option of its own. While voice is switched
  off it reads and prints nothing.

**New: a client that only polls is rung (PHP and web):**

- **The account-level realtime read states the reading client's presence.** `GET …/realtimeMessages` with the query
  parameter `clientId` (as on the run-state read; a value that is no client id is ignored, never a 400) records that a
  client of the account is there: `stampUIClientOfAccount()` → new
  `AgentClientPresenceService::recordClientStatementWithoutConversation()`. One entry per account in the channel's
  shared cache, no table, under a key of its own (`ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX`), so it never replaces what
  a client that shows a conversation stated; written when the account has none or its newest one is
  `STATEMENT_THROTTLE_SECONDS` (10 s) old, whichever client reads; fresh for `AgentClientPresence::FRESH_FOR_SECONDS`
  (25 s). `findNewestPresenceOfAccount()` answers the fresh statement of a client that shows a conversation first, else
  this one, so `isClientListening()` of the account audience is true while only a polling client is open and a call's
  ring is no longer refused with `no_registered_devices`. A read without `clientId` answers as before and states
  nothing. New hooks of the chat controller traits: `stampUIClientOfAccount()`, `getAgentClientPresenceService()`.
- **A bound transport still answers first** (`askTransportWhetherClientIsListening()`); only without its answer the
  statements decide. A client with a bound transport stops polling and its statement runs out, so an application that
  binds a transport answers that hook for the account audience too.
- **The account read always states a client id** (`GET …/realtimeMessages?clientId=…`), so the server knows a client
  that only polls, with or without `Agent.UI`: the `clientId` option of `createAccountRealtimePoll` when the host gives
  one, else the id of the bound `uiClient`, else an id the HTTP conversation client creates once
  (`createAgentUIClientId()`) and keeps while it lives. `getAccountRealtimeMessages` takes the optional argument
  `clientId`; the conversation reads still state a client id only with a `uiClient`.
- **The account realtime poll reports a cleared message and takes subscribers after its creation** (`./ui`): new
  `AccountRealtimePollSubscriber` (`onMessage`, `onMessageCleared`, both optional) and
  `AccountRealtimePoll.subscribe(subscriber)`, which answers the function that removes the subscriber. A message is
  cleared when a read that started after the message became known holds none of its type and key. A subscriber that
  comes late gets the messages open now before `subscribe` returns, then every arrival; it is told the clear only of a
  message it was handed. The creation-time `onMessage` is optional now. With a bound transport the poll does not read by
  itself: a clear comes with `readNow()` or `readAfterPing()`.
- **The ring source takes the poll or a starter:** `createAgentVoiceRingSource(accountRealtimePollOrStarter)` subscribes
  to the application's running poll (and never stops it) or starts and stops a poll of its own. A ring another client
  answered or declined, or that was withdrawn, stops in a polling client with the read that finds it gone instead of at
  its deadline (`acceptClearedRealtimeMessage()`; `onRingCleared(callId)` only for a ring the subscriber was handed).

**New (web):**

- **Entries `./voice` and `./voice/admin`** (`dist/voice.js`, `dist/voice-admin.js`; the names in `public-api.json`).
  `./voice`: the api of the routes (`createAgentVoiceApi`, `createAgentVoiceFetchBaseApi`), the call store with its
  hooks, `AgentVoiceCallProvider`, the components of a chat (`AgentVoiceChatStartButton`, `AgentVoiceCallBand`,
  `AgentVoiceCallCaptions`, `AgentVoiceInputGlow`), `AgentVoiceIncomingCall` and the ring source. `./voice/admin`: the
  api of the admin routes (`createAgentVoiceAdminApi`, `createStaticAgentVoiceAdminApi`) and the screens
  `AgentVoiceCallsScreen` and `AgentVoiceCallDetailScreen`. Neither entry is in `sideEffects`.
- **Texts and styles as in the rest of the package:** `AgentVoiceCallProvider` takes no `translate` prop, the texts are
  keys of `i18n-keys.json` and go through `AgentsEnvironment.texts` (`useAgentVoiceTranslateText()` for an application's
  own voice component). The voice styles are part of `styles.css`, no stylesheet of their own; every colour and size is
  a custom property `--agents-voice-*` read with its default; class names are module-scoped.
- **Three optional peers, loaded by `./voice/admin` alone:** `@mgamadeus/ddd-admin-ui`, `@mgamadeus/ddd-web` and
  `lucide-react`. `boundary.json` (now in `files`) names per code subpath the optional peers it may import
  (`optionalPeerImports`), and the new check 10 of `scripts/check-package.mjs` fails when another subpath loads one. An
  application without the admin screens installs none of them.
- **`scripts/i18n-keys.mjs`** also reads the texts of the admin screens (their `t` and `m` calls with their contexts)
  into `i18n-keys.json`.
- **Fixed, the default translate** (`provider/environment.ts`): a placeholder is filled only from a replacement the
  caller gave (`Object.hasOwn`), so `%toString%` is no longer filled from the prototype.

**Skills, docs and tests:**

- **Developer skill** `ddd-module-agents-voice-specialist` (the tenth skill, manifest id `voice`; it also owns
  `PhoneConversationSignalSource`); the entry skill routes to it. README section "Voice", `AGENTS.md` section "Voice
  channel".
- **Tests:** the voice suite in `tests/Voice/` (under the package kernel, voice switched off by default; base classes in
  `tests/Voice/Support/`); the kernel without the WebSocket library
  (`tests/Kernel/VoiceWithoutSessionWorkerLibraryTest.php`: the gate map, the imports, the compiled container, the
  answers of the one place, the handler list, the installation finding); the sweep of the reap command
  (`AgentReapStaleVoiceCallsSweepTest`, `AgentReapStaleVoiceCallsSweepRightsTest`); the evaluation purge and the call
  table (`AIConversationEvalPurgeVoiceCallTableTest`); the installation check (a right of a switched-off feature); the
  polling client's presence (`AgentClientPresenceWithoutConversationTest`, `AgentAccountRealtimeReadPresenceTest`, and
  for the ring `AgentVoiceCallStartsServiceStartRingOfAPollingClientTest`). Web specs: the voice specs below
  `src/voice/`, `_account-realtime.spec.ts`, `_agent-voice-ring-source.spec.ts`,
  `_createAgentsHttpConversationClient.spec.ts`.

**Behaviour changes for an existing application.** None of them needs voice switched on to apply.

- **An application command that overrides `AgentReapStuckConversationsCommand::closeStaleChannelSessions()`** calls the
  parent, or the package's sweep of stale voice calls never runs. The default did nothing before.
- **The account-level read writes a cache entry** for a client that states `clientId`, and the web client now always
  states one: one small entry per account, at most every 10 s, and one cache read more per read. The write is the
  realtime port's and does not ask whether voice is switched on. An application that wants none overrides
  `stampUIClientOfAccount()` with an empty body.
- **A conversation client binding of the application's own** is called with the poll's `clientId` option
  (`getAccountRealtimeMessages(clientId)`, the argument is optional) and states an id itself; without one its polling
  clients are not counted as listening.
- **The creation-time `onMessage` of `createAccountRealtimePoll`,** two edges: an answer that comes back after the
  answer of a later-started read is left out (`readNow()` resolves 0, no credential is handed over); the known messages
  are updated before the hooks run, so a hook that throws is not handed the same message again.
- **A text placeholder named like a member of `Object.prototype`** (`%toString%`) stays as written unless the caller's
  replacements hold it (the fix above).
- **`rightsHeldByNobody`** leaves out a right that only tool packages of a switched-off feature require (every class
  that declares it is an MCP package whose `isAvailable()` answers false); it is reported once the feature is switched
  on. `useVoice` is such a right.
- **An application with a voice channel provider of its own** now has two registered providers (the package's is
  registered in every installation and unavailable while voice cannot run): it reads the `voiceChannel` WARNING "several
  providers" and one log line per process. The application's provider still answers, as the first available one.
- **Lists an application's suite may pin:** the declared rights (`AgentDeclaredAccountRightsService`) contain
  `useVoice`; the package's database entities are 17; the realtime message types contain `agent.voice.ring`; the owned
  and the public service namespaces of the module are longer; the types of `./ui` contain
  `AccountRealtimePollSubscriber`. The package's surface snapshot (`agent-package-surface.json`) is unchanged:
  `Agent.Voice` is offered only while voice can run.

**The call table** is `AgentVoiceCalls`, shipped without a table prefix like every package model: an application
regenerates the models with its own `DATABASE_TABLE_PREFIX` (`post-update-cmd`), so its table is
`<prefix>AgentVoiceCalls`. No table is required while voice is off. Database tests of the three atomic writes of the call
store (the answer claim, the end claim of a call nobody answered, the relative booking of seconds) run on MariaDB and
PostgreSQL (`tests/Integration/Voice/`).

**Announced for 0.6.1:** the lists of objects on the voice signatures (`AgentVoiceSeedInputMessage[]`,
`LiveTranscriptSentence[]`, `AgentVoiceCall[]`, the trace rows, the admin's derived values) become sets; a provider of
the application's own wins over the package's without the "several providers" warning; `createAgentVoiceRingSource()`
keeps only the poll form. An application binding voice on 0.6.0 adapts its overrides with 0.6.1.

**An application that already has voice classes of its own** (a voice channel below its root namespace, at the paths of
these classes) keeps what it adds as same-path subclasses and deletes what the package now ships. Where a path or a name
differs:

- **The session worker** is `DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService`: a subclass in a
  folder `Services\Voice\Live\` moves to `Services\Voice\SessionWorker\`. **The attach message and its handler**
  (`VoiceLiveSessionAttachMessage`, `VoiceLiveSessionAttachHandler`) are in
  `DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\`, not directly in `MessageHandlers\Voice\`. Only classes of these
  two folders import the WebSocket library.
- **The attach message's class name is a routed and queued name.** The application's Messenger routing names the new
  class (take `AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS`), and the queue of `voice_live_session` is
  drained before the deploy: a message queued under another class name finds no handler.
- **No module class and no command of its own.** `AgentsModule` owns the voice namespaces, so a module class the
  application registered for them goes. Stale calls are closed inside `app:agent:reap`: a command of the application's
  own that closed them goes with its cron line (the sweep prints lines and has no JSON output), and an override of
  `closeStaleChannelSessions()` calls the parent.
- **The skill** is `AISkill::AGENT_VOICE`: an enum of the application's own for `Agent.Voice` goes, and a
  `skillPackages.php` of its own binds `AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class]`.
- **Typed answers and override points.** `AgentCallTasksService::startAgentTaskOnTranscript()` answers
  `AgentCallTaskStart` (`started`, where an array held `ok`), `findAgentTaskOutcome()` answers `AgentCallTaskOutcome`,
  `AgentVoiceTaskProgressSchedule::decideUpdateAt()` answers `AgentVoiceTaskProgressUpdate`. An override of
  `buildApplicationSeedInputMessages()` returns `AgentVoiceSeedInputMessage[]`, one of `buildCallerContext()` returns an
  `AgentVoiceRingCallerContext`, one of `keepCompletedSentenceOutOfTheConversation()` takes a `LiveTranscriptSentence`,
  and a provider adapter of the application's own takes the seed rows typed. What the provider and a ringing client
  receive is unchanged.
- **A worker of the application's own** that carries the calls without the package's session worker: answer
  `isSessionWorkerLibraryInstalled()` for it and override `openProviderSessionForCall()` of the routes trait, which
  attaches the package's worker.
- **Web:** the components and the admin screens come from the two entries; texts through `AgentsEnvironment.texts`,
  custom properties `--agents-voice-*`, the styles with `styles.css`.

## 0.5.17

**The web package no longer needs axios: the request stand-ins of the widget preview and the widget-image capture
replace `XMLHttpRequest` (the transport of axios's browser adapter) instead of `axios.defaults.adapter`; new opt-in
`interceptFetch` for the frozen capture.** Additive and non-breaking: with the default options a host on axios gets the
answers it got before; a host on `fetch` opts in. npm `@mgamadeus/ddd-agents` 0.5.17 in lockstep (no PHP change).
Contributed through a pull request.

- **axios** left `peerDependencies` (it was optional), `peerDependenciesMeta`, `devDependencies` and the lockfile; no
  module of the package imports it. A host may keep axios: its browser adapter sends through `XMLHttpRequest`, which
  the stand-ins answer.
- New internal module `src/request-interception/browser-request-interception.ts` (not an entry):
  `interceptXMLHttpRequests(handler)` and `interceptFetchRequests(handler)` replace `XMLHttpRequest` or `fetch` (on the
  window and the global object) until the returned function is called. The handler answers with a `Response` or
  throws; a thrown error fails an `XMLHttpRequest` like a network error (status 0, no header, no body) and rejects a
  `fetch`. The `XMLHttpRequest` stand-in supports `open`, `setRequestHeader`, `send`, `abort`, `timeout`,
  `withCredentials`, `responseType` `""`, `text`, `json`, `blob`, `arraybuffer`, `response`/`responseText`,
  `status`/`statusText`/`readyState`/`responseURL`, `getAllResponseHeaders`/`getResponseHeader`, `upload` with progress
  events, the `on…` handlers and listeners for `loadstart`, `progress`, `load`, `error`, `abort`, `timeout`, `loadend`
  and `readystatechange`. A `fetch` input may be a string, a `URL` or a `Request` of any realm (recognised by its brand,
  `Object.prototype.toString`, never `instanceof`).
- `installFrozenWidgetRequests(responses, fail, options?)`: `XMLHttpRequest` answers as the former axios adapter (a
  supplied GET by its key `METHOD /path?sortedQuery`, a copy per read, no response header; anything else
  `MISSING_FROZEN_RESPONSE`); `fetch` stays untouched by default, as before. **New:** `options.interceptFetch` (default
  false) answers `fetch` the same way; only a static bundle image of the page's origin and a request of the Google Maps
  SDK (`maps.googleapis.com` `/maps/`, `/$rpc/`, `maps.gstatic.com` `/mapfiles/`, https) still go to the network
  unchanged, any other origin fails with `MISSING_FROZEN_RESPONSE`.
- `installLiveWidgetRequests`: unchanged signature. The `fetch` stand-in keeps its rules; `XMLHttpRequest` follows the
  former axios adapter's rules (the scoped capture read only, answered with its JSON and no header; anything else
  `WIDGET_HYDRATION_FAILED`, failing without URL, headers, token or remote body). In a map capture a Google Maps SDK
  request sent through `XMLHttpRequest` goes through the `fetch` stand-in and its map rules (no credentials, no
  referrer, the allowed headers, `MAP_ASSET_FAILED` on failure), so a map is drawn whichever transport the SDK uses.
- `installWidgetPreviewRequests`: unchanged signature. `XMLHttpRequest` as the former axios adapter (a missing response
  fails without response and is reported, a `__status` entry answers with its status and `__body`, `__pending` never
  answers); the `fetch` stand-in keeps its answers.
- Fixed: a `Request` of another realm passed to the live or the preview `fetch` stand-in is read by its URL, method,
  headers and body (before, `instanceof Request` failed and the stand-in read `[object Request]` as the URL).
- Specs: `_browser-request-interception.spec.ts` (the stand-ins), `_live-widget-requests.spec.ts`,
  `_widget-image-render.spec.ts` (frozen, default and `interceptFetch`) and the new `_widget-preview-requests.spec.ts`,
  with plain `fetch` and `XMLHttpRequest`, the security cases (no token, credentials, URL or remote body leak; fail
  closed) included. README: the peer set without axios and the request stand-ins.
- `isGoogleMapsSdkRequestUrl(url)` (`widget-image-google-map.ts`): the one Maps SDK predicate of the live and the frozen
  capture. The upload side of the `XMLHttpRequest` stand-in is its own module
  (`src/request-interception/intercepted-xml-http-request-upload.ts`).

**Behaviour changes for a host on axios** (axios now builds its result from the `XMLHttpRequest` stand-in instead of
receiving the adapter's object; checked with axios 0.28 and 1.7):

- A failed request (a missing preview fixture, a refused frozen or live request) rejects with axios's own
  `Network Error` (`code` `ERR_NETWORK`, no `response`) instead of the adapter's error object. The failure and readiness
  codes are the same; no token, request header, URL or backend response reaches the error.
- Preview `__status`: axios's `validateStatus` decides. A 2xx `__status` now resolves with `__body` (before: every
  `__status` entry rejected); 3xx, 4xx and 5xx reject with `response.status` and `response.data` as before; only a
  status of 400 or more is reported as a failed request, as before.
- A 204, 205 or 304 `__status` has no body: axios gives `data` `""` instead of `null`.
- A `__status` outside 200–599 cannot be a response: it fails as a network error.
- Every `XMLHttpRequest` of the page is answered now, not only the requests of axios's default instance: a raw
  `XMLHttpRequest` and an axios instance created before the install get the same answers and failures (before, they
  went to the network). An axios instance with an adapter of its own (not `xhr`) is not answered.
- **Specs:** a spec setup that gives axios an adapter of its own (a fallback for unmocked requests) no longer reaches the
  stand-ins; such a spec gives axios its browser adapter (`xhr`) back. A stubbed `fetch` answer for the live capture
  carries `text()`: the scoped read parses the text.

## 0.5.16

**A blocked or not-applicable container prunes its whole subtree in every count, walk and marker of the task plan; one
leaf definition; the reasoning-effort floor reports what the call sends; a stalled model's turn says it got no answer
instead of naming the step limit.** Package tests of the turn head, of "already decided this turn", of the
reasoning-effort matrix, of the tool-result screen, of the release of a worker turn, of the loop's exits, of the origin
predicates, of the run-state sets and of the task-plan tables, contributed by an application team. npm
`@mgamadeus/ddd-agents` 0.5.16 in lockstep (no web change).

- **Task plan, pruned subtrees:** the rule of `hasOpenItems()` now holds everywhere. `countItemsByStatus()` (the "Plan
  now" counts) counts a not_applicable container done and its subtree nothing (before: "1 done, 2 open" on a plan with
  nothing open). The owed walks (`hasOwedOpenItems()`, `getOwedOpenLeafTextsByNumberPath()`, the skill lookahead, the
  tickable list after a call, `hasOwedOpenItemsForProgressStepKey()`) and both platform closings never reach a leaf
  under a blocked or not_applicable container: a completed call no longer closes such a leaf, as the turn end never
  did. The `← next` marker never lands inside such a container. The progress-step members, the purposed run's open
  items and the criteria review count such a node as the container's verdict; the outcome summary lists the container
  in place of its subtree. New `TaskPlanItemAddresses::getLeavesOutsideTerminalContainers()`,
  `TaskPlanItemAddress::getStatusOfCoveringTerminalAncestor()`.
- **One leaf definition:** `TaskPlanItem::isLeaf()` (no subtask level or an empty one) is read by the address walk, the
  leaf count, the tick gate and the two step-key checks, which tested `subtasks === null` and never judged an item
  with an empty level.
- **Reasoning effort:** `AgentReasoningEffortLimits::buildFloor()` reports the effort the call is sent with. Its source
  says `purpose` for the purpose's base effort, as the control panel's baseline does (before: `configured`). Under site
  `off` (and the kill switch) the floor is the catalog's effort, because the call sends nothing of this layer — the
  purpose's base and minimum, the plan item's minimum and the row pin belong to the site and apply under `shadow` and
  `on` only (unchanged; the decisions and purposes skills say so now). A fixed effort reports as `fixed`.
  `resolveFloorEffort()` takes an optional source of its base.
- **Stalled model:** `AgentLoopStopReason::EMPTY_FINAL_TEXT` has its own terminal texts: the ERROR row "Turn ended
  without an answer: the model returned no text, also after the nudges." and the run report's stop reason
  `AgentRunReport::STOP_REASON_MODEL_GAVE_NO_ANSWER` ("the AI model gave me no answer, even after I asked it again.").
  Before, both named the step budget after four of forty steps. The report text is a new English translation key
  (`AgentOwnerFacingTextTranslationService`); a host with its own translations adds it, else English is shown.
- **TurnHeadTest stays off the database:** each case starts with no entity manager in the process and asserts that
  none was created, so a run with a database writes nothing and a swallowed connection error cannot pass it.
- New package tests below `tests/Domain/AI/Services/Decisions/SiteOutcomes/`, `…/Conversations/RunRelease/`,
  `…/Conversations/LoopExits/`, `…/Conversations/Agent/TaskPlanRecord/`, `tests/Domain/AI/Entities/Conversations/`
  and `…/TaskPlan/Characterization/`; the rows of the findings flipped to the fixed behaviour.
- Loop, decisions and purposes skills.

## 0.5.15

**A completed `wait_for_sub_agents` is no longer an action of the turn: the final answer is not asked to report it,
the judge's write ledger leaves it out and it starts no plan re-sweep; the dispatch's always-false `awaitingDelegates`
is deprecated.** Package tests of the dispatch table of a tool call and of the loop's tool-name lists, contributed by an
application team. npm `@mgamadeus/ddd-agents` 0.5.15 in lockstep (no web change).

- **Control tools that change nothing:** new `AgentMetaTools::CONTROL_TOOLS_CHANGING_NOTHING_NAMES`
  (`wait_for_sub_agents`) and `isControlToolChangingNothing()`, read by
  `AgentModelInput::completedWritesAnswerContractHint()` (no "Completed so far in this turn: wait_for_sub_agents"),
  `AIConversationAuditRenderService::renderWriteLedger()` and `ToolCall::canHaveChangedTheRecordWhenCompleted()` (false:
  no re-sweep of the seeded plan's leaves after a wait). A wait still ends the reuse of the turn's reads: the
  sub-agents may change the data while the turn waits. `message_sub_agent` stays an action, like `delegate`: the
  answer may report the instruction, and on a sync drive the sub-agent's run it starts writes within the call.
- **`AgentToolCallAnswer::$awaitingDelegates` deprecated:** `dispatchToolCall()` never raised it; a turn parks for
  awaited delegations through `AgentToolCallFollowUpsOutcome::$awaitingDelegates`
  (`AgentCompletedToolCallFollowUpsService`). The field and `withFlags()` stay; the loop still ORs the field in, so a
  host's dispatch override that raises it keeps working. The comment over the dispatch's flags is corrected. No loop
  change.
- New package tests below `tests/Domain/AI/Services/Conversations/Agent/DispatchTable/` (repeat guard, meta-tool
  branches, earlier calls, execution and catch arms) and `AgentMetaToolNameListsMembershipTest`, with the wait cases
  adjusted to the fix.
- Tools skill: the meta-tool contract names the new list and where a turn parks.

## 0.5.14

**A failing decision site no longer takes the later sites and the request rows with it; the display summary names only
finished actions; the compaction breakdown knows a purpose's budget by its stamp.** Package tests of the decision sites'
outcomes, the compaction threshold, the consistency report and the compaction telemetry, contributed by an application
team. npm `@mgamadeus/ddd-agents` 0.5.14 in lockstep (no web change).

- **Turn start:** `applyTurnStartDecisions()` isolates every site's application. A throw is logged as a warning (trace
  `turn.decisions.applicationFailed`) and leaves that site as without the decision; before, one catch around all of them
  skipped the later sites and the request events of requests that were sent and billed. The order is the new
  `AgentTurnStartDecisionBatchService::SITES_IN_APPLICATION_ORDER`, the application `applySiteAnswer()`. A lost run claim
  leaves the method as before (no slice, no collected event).
- **Sub-skill passes:** a sub-skill child, the follow-up pass and the pass after the chat model loaded a skill keep their
  request event when the application of the answer throws; after a chat-model load the other skills' passes go on and
  a failed one is not announced as loaded.
- **Display summary:** an ACTION line is written only for a COMPLETED or FAILED call; PENDING, RUNNING, cancelled and
  status-less calls got one too, while the summary prompt reads an ACTION line as executed. An action still unfinished
  in the newest turn holds the summary's coverage marker before it (`AIConversation::getDisplaySummaryCoverageIndex()`), so the
  next incremental refresh carries its line.
- **Compaction breakdown:** the purposed start records its trigger on the settings
  (`CompactionSettings::$triggerTokensStampedByPurpose`, in the stored JSON, hidden on the API), so a budget stamped under
  another model's window is reported as `purposeContextBudget`, not as an override nobody set. Settings without the stamp
  are matched by value as before. The applied threshold and the wire fields are unchanged.
- Docblocks: `modelEffectiveContextTokens` is the model's own value, not the applied one; the fallback is 100000; the
  telemetry's list of meta tools.
- New package tests below `tests/Domain/AI/Services/Decisions/SiteOutcomes/` and three in `CompactionCharacterization/`,
  with cases for each fix.
- Decision and observability skills.

## 0.5.13

**Agent.UI requests reach a real client again; the state sweep lets the application's answer decide; the resume text
"closed by the platform" only for a schedule the platform cancelled; the eval fire checks the status before it writes;
the narrow split view keeps a way back to a closed canvas; package tests of the service methods that move a schedule
outside its tools.** The schedule service tests were contributed by an application team. npm `@mgamadeus/ddd-agents`
0.5.13 in lockstep.

- **Agent.UI (critical):** Core's `Cache::get()` answers a miss with `false`, not null.
  `AgentClientToolRequestsService::hasAnswer()` compared with null, so every unanswered request counted as answered and
  `findDeliveryForClient()` delivered none to a real client (the scripted evaluation client does not go through it).
  `hasAnswer()` and the fallback of `claimAnswer()` (a cache without an atomic counter) treat `false` and null as a miss
  (new `AgentClientToolRequestsService::isCacheMiss()`); `AgentClientPresenceService::readSharedCacheValue()` answers a
  miss as null. Every other cache read of the package already checks the type of the value. The package's cache double
  (`InMemoryCacheGroupDouble`) now answers a miss with `false` like Core; new cases: an unanswered request is delivered
  through `findDeliveryForClient()` and an answered one is not, and the first answer wins on a cache without a counter.
- **State sweep:** `applyAutomationStateSweepToSchedule()` asked `refusesAutomation()` under
  `scheduleStateSweep:<status>` and dropped the answer: a scope the application lets run (its guard off, a support
  override) had its schedules paused or cancelled all the same, while the fire and the resume ran. A pause or a close
  is now applied only when the application refuses (`AIConversationSchedulesService::STATE_SWEEP_REFUSAL_OPERATION_PREFIX`
  followed by the target status); without a refusal the row stays as it is and no run is cancelled. A re-arming asks
  nothing. **Hosts** that override only the find methods of `AgentAutomationEligibilityService` and rely on the sweep to
  pause: override `refusesAutomation()` as well (the package's default refuses nothing).
- **Dry run of the sweep:** it asks the application nothing any more (a host that logs refusals logged one for a row the
  dry run left alone). It answers the transition the state leads to; a pause or a close carries the new
  `AIConversationScheduleTransition::$appliesOnlyWhenTheApplicationRefuses`, and the command's dry-run line adds
  "— would ask the application (scheduleStateSweep:PAUSED), applied only if it refuses". The port is unchanged.
- **Resume text:** the RESUME rule names the platform state (`REFUSAL_CLOSED_BY_PLATFORM_STATE`) only for a PAUSED or
  CANCELLED row with a `stateReason`; an ARCHIVED, ACTIVE, EXPIRED or DONE row with a reason left in it gets "Schedule 42
  cannot be resumed in status ARCHIVED." (before: "was closed by the platform (account suspended) and is archived — …").
  `transitionStatus()` (the owner's pause, resume, cancel, archive and the platform's expire, done) clears a reason left
  in the row in the same write (`status,stateReason`, `status,nextRunAt,stateReason`); a row without a reason is
  written as before.
- **Eval fire:** `fireEvalScheduleNow()` moved `nextRunAt` to now before the claim, also for a PAUSED, CANCELLED or
  expired eval schedule whose claim is then lost. It now checks the run-now rule first (the manual fire interval is the
  only rule it skips) and throws the run-now refusal (`assertScheduleMayRunNow()`) without a write.
- **Split view (web):** in a narrow split with the canvas closed, the split view's "Re-open canvas" toggle now floats over
  the chat whenever a canvas exists to reopen; before, only the chat's own button could reopen it and a host did not
  show that button in that state. New optional `sideToggle.mainPaneShowsReopen` (`AgentsSplitViewProps`): the chat sets
  it while its own reopen button is shown, so there is no second toggle. Cases in `_default-split-view.spec.tsx`.
- New package tests: `AIConversationSchedulesServiceManualFireTest` (run-now guard, fire now, eval fire, start now),
  `AIConversationSchedulesServiceRunTerminalTest` (failure streak, close of a one-time task, done criteria, the turn
  path), `AIConversationSchedulesServiceStateSweepTest` (pause, close, re-arming, rows left alone, scope, dry run) with
  their doubles; cases for the four fixes added. `AIConversationScheduleTransitionTest`: RESUME rows of a row with a
  platform reason per status.
- Autonomy skill (eligibility, schedules), entry skill trap on cache misses.

## 0.5.12

**The schedule tools tell the model what happens: a cancelled schedule is not "resumed by itself", a paused schedule
has no next firing, a resume of a schedule whose lifetime is over closes it; a package test of the four tools that
change a schedule.** The tools test was contributed by an application team. npm `@mgamadeus/ddd-agents` 0.5.12 in
lockstep (no web change).

- `resumeTaskSchedule` on a schedule the state sweep CANCELLED for an ended or erased scope: before, "Schedule 42 was
  paused by the platform (agent space ended) and resumes by itself once the <billing entity> or account is active
  again" — the sweep never re-arms a final status. Now (`assertScheduleMayBeResumed()`): "Schedule 42 was closed by the
  platform (agent space ended) and is cancelled — it does not resume and never fires again. Once the <billing entity>
  or account is active again, a new schedule is needed." A PAUSED schedule the platform paused keeps its text.
- `updateTaskSchedule` on a PAUSED schedule: before, "Next firing: …" from the anchor the pause leaves in the row (for
  a paused one-time task whose moment moved: the old moment). Now `nextRunAt` is null and the note says "It is PAUSED
  and does not fire until resumed (resumeTaskSchedule); once resumed, it fires at <the one-time moment> (<weekday>)" or
  "… at the next slot of its cron after the resume". The stored state is unchanged.
- RESUME of an owner-PAUSED schedule past its `ttlAt` or its `maxIterations`: `AIConversationScheduleTransition` now
  answers "allowed → EXPIRED" (decided after the passed one-time moment, before the scope's eligibility; no re-arm).
  Before, the resume re-armed it, answered "Schedule resumed. Next firing: …" and the first fire scan expired it
  without a run. New `AIConversationSchedulesService::resumeSchedule()` runs the guard and writes the status the
  transition leads to (ACTIVE with the anchor from now, or EXPIRED, one `status` write), and returns the transition.
  `resumeTaskSchedule` answers the EXPIRED outcome with `nextRunAt` null and "Schedule 42 was not resumed: it has
  ended — its lifetime (ttl) has passed | its runs are used up (3 of 3) — and is now EXPIRED; it never fires again. …
  a new schedule is needed (scheduleOneTimeTask or createRecurringTaskSchedule)." **Hosts** whose own resume
  endpoints call `assertScheduleMayBeResumed()` and then `transitionStatus(…, ACTIVE)` keep the old behaviour; switch
  them to `resumeSchedule()`. `canBeResumed()` is true for such a row (the resume closes it).
- The tool description of `resumeTaskSchedule` names the EXPIRED outcome; `agent-package-surface.json` regenerated
  (host surface snapshots change once for that description).
- New test `AgentTaskSchedulesMCPPackageTransitionToolsTest` (131 cases) with `TaskScheduleRecordingWritesDouble`
  and `ResumeRecordingEligibilityServiceDouble`: pause, resume, delete and update called as the loop calls them on a
  schedule in each status — refusal texts, written columns, answers; cases added on ingest for the three answers above.
  `AIConversationScheduleTransitionTest`: RESUME rows for a schedule past its ttl and one whose runs are used up, and
  the end of the lifetime told before the scope.

## 0.5.11

**Core floor `mgamadeus/ddd ^3.47.1`; a schedule expires only while ACTIVE; a package test of the transition table of
a schedule.** The transition table test was contributed by an application team. npm `@mgamadeus/ddd-agents` 0.5.11 in
lockstep (no web change).

- Core 3.47.1: the attribute-instance cache of a property keyed on the lookup flags, and `FiltersDefinitions` reads
  the framework's `Choice` with `IS_INSTANCEOF`. Before, the filter description of a property with a `Choice` listed
  its allowed values only when no exact-name read of the attribute had run first in the process, so the shipped
  surface (`src/Testing/Agents/Surface/agent-package-surface.json`) depended on the order of the tests. The filter
  descriptions now always list the allowed values ("one of [...]") and the value check always applies.
  A second order dependence of the surface test was in the package's own suite: `AgentInstallationCheckServiceTest`
  resolved `AISkill`'s process-wide skill binding from a test binding and left it behind, so the surface test after
  it saw no skills; the test now restores the binding. The surface test passes with and without the installation
  check tests before it, in either order, and two regenerations are byte-identical.
  **Host surface snapshots change once:** the filter descriptions of e.g. `getTaskSchedules` gain the allowed values
  of `status` and `reportChannel`; accept the difference once (`REGENERATE_AGENT_SURFACE_SNAPSHOT=1`).
- `AIConversationScheduleTransition::OPERATION_EXPIRE` has a status rule: only an ACTIVE schedule expires. A PAUSED
  or final (EXPIRED, DONE, CANCELLED, ARCHIVED) schedule past its ttl or its runs is refused with `REFUSAL_STATUS`
  (before: "allowed → EXPIRED" for every status). The fire command asks only for ACTIVE rows, so nothing it writes
  changes; a second caller can no longer move a final status. A paused row past its ttl expires at the first fire
  scan after a resume; a platform-paused one-time row whose moment passed is still closed by the state sweep.
- `REFUSAL_ALREADY_FIRED` (start-now) stays: `createSchedule()` caps every one-time schedule at one run, and such a
  schedule that fired is told `EXPIRED` first; the reason is reached by a one-time schedule without a cap (a host's
  writer that bypasses `createSchedule()`, rows written without one). The precedence is unchanged; the package's only
  start-now caller starts a schedule it has just created.
- New test `AIConversationScheduleTransitionTest`: every operation on every status per kind of schedule, the resume
  with the eligibility of the scope, the manual fire interval, expiry, the state sweep table, an unknown operation,
  and a guard that every `OPERATION_*` and `REFUSAL_*` constant has a case (311 cases; the EXPIRE row added on
  ingest).

## 0.5.10

**Schedules: the frequency floor counts per wall-clock day, a skip for a running previous run gives its iteration
back, a dry run says "would expire"; the installation check probes the database with a short connect timeout; package
tests for the creation rules of the task tools and the occurrence of a schedule.** The creation-rules and occurrence
tests were contributed by an application team. npm `@mgamadeus/ddd-agents` 0.5.10 in lockstep (no web change).

- Frequency floor (DST): `AIConversationScheduleCreationLimits::countCronFiringsPerDay()` counted the firings within 24
  ELAPSED hours. On the eve of the change to summer time those span 25 wall-clock hours, so a cron with four fixed times
  of day counted five in the hour before its evening firing and `createRecurringTaskSchedule` / `updateTaskSchedule`
  refused it (`0 6,10,14,18 * * *`, Europe/Berlin, 2027-03-27 17:30: 5; 16:30 and 18:30: 4); on the eve of the change
  back an hour counted three. The window is now 24 wall-clock hours in the schedule's zone (from now to the same local
  time the next day, `AIConversationSchedule::computeWallClockWindowEnd()`), so N fixed times of day count N at every
  hour of the year. The rule stays "at most 4 firings per day". `countCronFiringsWithinDays()` and
  `computeCronOccurrencesWithinDays()` use the same wall-clock window (monthly cost estimate, agenda: N calendar days
  instead of N × 24 h).
- Occurrence skip for a previous run in progress: the claim had already counted the iteration and moved the anchor; the
  not-eligible and pot skips gave the iteration back, this one did not, so a recurring schedule with `maxRuns` lost
  one real run per such skip. It now refunds through `refundClaimedOccurrence($schedule, reArmNow: false)` like the
  other skips: the anchor stays on the next slot (no immediate retry); a one-time schedule is left as it is.
- `app:agent:fire-due-schedules --dry-run`: an expired schedule printed "transitioned to EXPIRED" and was counted as
  expired although nothing was written. It now prints `Expired (dry-run): schedule N passed its ttl/maxIterations —
  would expire` and counts nothing, as a due schedule's "would fire".
- `app:agent:check-installation`, check `missingTables`: the tables are read over a probe connection of its own — the
  default connection's parameters (read without opening it) plus a connect timeout as a driver option of the probe
  only (`PDO::ATTR_TIMEOUT` for `pdo_mysql` / `pdo_pgsql`, `MYSQLI_OPT_CONNECT_TIMEOUT` for `mysqli`), connected once
  (no second attempt of the platform detection), closed afterwards. New key `databaseConnectTimeoutSeconds` (default
  5, at least 1) in `config/app/AI/installationCheck.php`. A host that does not answer is the existing WARNING, now
  naming the timeout. The command probes before it reads its default account and skips that read when the probe gets
  no answer (the second wait). With an unreachable host the command took about 181 s; now about the timeout.
  New value object `AgentInstallationDatabaseProbe`; `AgentInstallationCheckService::findDatabaseProbe()`,
  `getDatabaseConnectTimeoutSeconds()`, override points `buildProbeConnectionParams()`, `findDefaultConnectionParams()`.
- Tests: `AgentTaskSchedulesMCPPackageCreationRulesTest` (52 cases: active cap, frequency floor incl. a zone with
  summer time, daily creation cap, `maxRuns`, title, report channel, zone, one-time moment, scheduled-run guard,
  background start), `AIConversationSchedulesServiceOccurrenceSpawnTest` (the run a claimed occurrence starts and the
  order and writes of the guards), `AgentFireDueSchedulesCommandPathsTest` (rows gone or inactive, expiry, dry run,
  lost claim, throwing spawn, late one-time), `AIConversationScheduleExpiryBoundariesTest`,
  `AIConversationScheduleCronFiringsPerDayTest` (both summer-time changes in Europe/Berlin and America/New_York, a
  normal day), `AgentInstallationDatabaseProbeTimeoutTest` (an unreachable TEST-NET host with a timeout of 1 s on
  MariaDB and PostgreSQL drivers, the probe's parameters).

## 0.5.9

**Host guards: the installation check sees which class runs under a command's names, two rights checks, a test case
that makes the check a CI gate, and the tools' declarations in the surface document.** Contributed by an application
team. npm `@mgamadeus/ddd-agents` 0.5.9 in lockstep (no web change).

- `app:agent:check-installation`, check `consoleCommands` (only with a console application): it now builds every
  name and alias of a package command once and reports, beside a name the console does not resolve (WARNING, as
  before):
  - a name the console lists and cannot build — **BLOCKING** (every run of it throws; the finding carries the first
    line of the reason);
  - a name that runs a class which is no subclass of the package command — WARNING (the framework lets a command
    replace another by name, so it may be meant);
  - an application subclass without `#[AsCommand]` (it takes the package command's names) while a name runs another
    class — WARNING (its overrides never run; usually it is not registered);
  - two such application subclasses of one package command — WARNING;
  - the names of one package command running different classes — WARNING.
- New check `skillOnlyRights` (WARNING): a skill names a right in `requiredAccountRights` that no tool of its bound
  packages requires (tool attribute, class attribute or the tool's overlay) — the right hides the offer while the
  tools still execute over the HTTP MCP route and the console; a misspelled right name shows here too. Accepted per
  skill in the new key `acceptedSkillOnlyRights` of `config/app/AI/installationCheck.php`.
- New check `rightsDeclaredOutsidePackages` (WARNING): `#[MCPRequiresAccountRights]` on a class below
  `Presentation/Mcp` that is no MCP package (a bundle, a controller), where it gates nothing.
- `DDD\Testing\Agents\Installation\AgentInstallationCheckTestCase` (abstract, extends PHPUnit's `TestCase`): runs the
  installation check in the application's suite. Hooks `createConsoleApplication()` (null skips `consoleCommands`),
  `getFailingSeverities()` (default BLOCKING), `getCheckNamesNotFailingTheTest()`. Accepted findings are read from
  the same config as the command. Below `DDD\Testing\Agents\`, excluded from the container like the surface hooks.
- Surface document (`AgentSurfaceDocumentBuilder`): per tool two new parts — `declarations` (every attribute of the
  tool method but `#[MCPTool]`, short class name => its declared arguments, nothing instantiated) and `overlay`
  (`sets`: the keys of the tool's `AI.toolOverlays` entry the overlay reads, plus `ownerFacingSubject`). The shipped
  `agent-package-surface.json` is regenerated (about 68 KB → 85 KB).
- **Host surface snapshots:** after the update, `testThePackagesSurfaceDiffersOnlyWhereTheApplicationAcceptedIt()`
  shows new differences wherever the application adds an overlay to a package tool or redeclares a package tool's
  attributes in a same-path subclass, and `testTheOfferedSurfaceIsThePinnedOne()` shows the new parts of the
  application's own tools. Regenerate both files (`REGENERATE_AGENT_SURFACE_SNAPSHOT=1`), review, commit.
- Tests: command-name owners, the unbuildable-name BLOCKING finding, skill-only rights (11); declarations and overlay
  of the surface (5). Entry skill reference "installation-check", observability reference "characterization-tests",
  README, source ownership.

## 0.5.8

**A characterization test hook for applications: the agent surface snapshot.** Contributed by an application team.
npm `@mgamadeus/ddd-agents` 0.5.8 in lockstep (no web change).

- `DDD\Testing\Agents\Surface\AgentSurfaceSnapshotTestCase` (abstract, extends PHPUnit's `TestCase`): an application
  pins what its agent is offered — skills, tool packages, tools, parameters (documented and wire schema), purposes,
  HTTP MCP bundles — under its own container, config and bindings. A failure names each changed value by its path
  (`~ packages → X → tools → y → description: … => …`), not a file hash. Hooks `getPinnedSurfaceFile()` (abstract),
  `getAcceptedDifferencesFile()`, `getPackageSurfaceFile()`, `findPurposeNames()` (`findPurposeNamesInDirectory()`),
  `findBundleClasses()`, `createSurfaceDocumentBuilder()`, `buildSurfaceDocumentToPin()`. `REGENERATE_AGENT_SURFACE_SNAPSHOT=1`
  writes the files instead of comparing.
- The package ships its own surface, `src/Testing/Agents/Surface/agent-package-surface.json` (9 skills, 9 packages,
  3 purposes, about 67 KB), pinned by the package's `AgentPackageSurfaceTest`. With an accepted-differences file an
  application keeps only where it differs from the package's entries (path => its value, `(absent)` for a package
  skill it does not offer), and its pinned file holds its own entries only.
- `AgentSurfaceDocumentBuilder` (values up to `MAXIMUM_PLAIN_TEXT_CHARACTERS` = 120 / `MAXIMUM_PLAIN_MAP_CHARACTERS` =
  240 characters stay readable, longer ones become `#<12 hex> (<n> characters)`), `AgentSurfaceDocument` (the five
  parts), `AgentSurfaceDocumentComparer` (value-by-value tree comparison, entry-wise helpers, no container),
  `AgentSurfaceDifference` / `AgentSurfaceDifferences`.
- Module: `DDD\Testing\Agents\` is an owned namespace and excluded from the container (`getExcludePatterns()` adds
  `Testing`, as the framework excludes its own `src/Testing`): a production install without PHPUnit never loads the
  hooks.
- Tests: the comparer (12), the builder's value rules (6), the package surface. Observability skill reference
  "characterization-tests", package-suite reference, README.

## 0.5.7

**Two generic enablers for packages beside ddd-agents: a voice channel provider port and the account-level realtime
read.** npm `@mgamadeus/ddd-agents` 0.5.7 in lockstep.

- `AgentVoiceChannelProvider` (`DDD\Domain\AI\Services\Conversations\Channels`, `#[AutoconfigureTag]`, tag
  `ai.agent_voice_channel_provider`): `isVoiceChannelAvailable()`, `findLiveAnsweredCallChannelType()`,
  `putCallReachVariantsInto()`, `findCallTaskTextsOfConversation()`. `AgentVoiceChannelService` keeps its methods and
  asks exactly one provider — the first available in container order; without one it gives today's answers ("no voice
  channel"); a provider that throws is logged and the question gets the default. A same-path subclass of the service
  keeps working.
- `app:agent:check-installation`: new check `voiceChannel` — a WARNING when more than one voice channel provider is
  registered (only the first available one is asked).
- Chat controller traits: `GET …/realtimeMessages` (`getAccountRealtimeMessages()`, body
  `buildAccountRealtimeMessagesResponse()`; `AgentAccountRealtimeMessagesGetRequestDto` with `clientId`,
  `AgentAccountRealtimeMessagesGetResponseDto` with `realtimeMessages` and `realtimeListenerCredential`): the open
  messages addressed to the signed-in account (`AgentRealtimeAudience::forAccount()`), optionally to one client,
  expired ones left out, and the listener credential without a conversation. Same gate and delivery rules as the
  run-state read; 403 without a signed-in account. Hooks `findRealtimeMessageDeliveriesOfAccountClient()`,
  `findRealtimeListenerCredentialOfAccount()`, `getAgentRealtimeChannelService()`. A host that lists its routes gets
  one more GET route.
- Web: `AgentsConversationClient.getAccountRealtimeMessages?()` (HTTP binding and polling wrapper);
  `@mgamadeus/ddd-agents/ui`: `createAccountRealtimePoll()` (a read at once, a poll every
  `ACCOUNT_REALTIME_POLL_INTERVAL_MS` while no transport is bound, the arrival hook `onMessage` once per message,
  `acceptArrivedMessage()` for a transport that carries the message, `readAfterPing()` with one re-read after
  `ACCOUNT_REALTIME_PING_REREAD_DELAY_MS`), `AccountRealtimeRead`; wire type `WireAccountRealtimeMessagesResponse`.
- Tests: provider absent, registered, unavailable, failing, several (one asked, installation warning); the account read
  (the account's messages only, not a conversation's or another account's, expiry, target client, credential without
  a conversation, 403 without an account); web: the account poll, the arrival hook, the ping re-read, the HTTP read.

## 0.5.6

**Recurring vs one-time in `getTaskSchedules`.** Requires `mgamadeus/ddd` ^3.46.3; npm `@mgamadeus/ddd-agents` 0.5.6
in lockstep, no web change.

- `getTaskSchedules` (`agent.tasks`) tells the model the filter idiom in its description, its summary, an example and
  the `Agent.Tasks` skill file: `oneTimeAt eq null` = recurring tasks, `oneTimeAt ne null` = one-time tasks (there is
  no kind or type property).
- Floor `mgamadeus/ddd` ^3.46.3: `oneTimeAt eq 'NULL'` was refused as not a date-time under the model-facing time zone
  (the zone conversion of MOMENT literals); 3.46.3 leaves an `eq` / `ne` null comparison alone.
- Test: the null comparisons on `oneTimeAt` (quoted and unquoted, `eq` and `ne`, in a group) pass validation and the
  zone conversion against the filter definitions of `TaskSchedulesGetRequestDto`.

## 0.5.5

**The model's reasoning carry-over per wire API.** Requires `mgamadeus/ddd-ai` ^3.8.0; npm `@mgamadeus/ddd-agents`
0.5.5 in lockstep, no web change.

- `AgentWireConfiguration::resolveFor()` reads the catalog's carry-over (`AIModel::getAgenticPreviousReasoningCarryOver()`)
  for the wire the run resolved — the run settings' row, else the catalog's wire after any fallback — so a value under
  `agenticUseCase.previousReasoningByWireApi.<wire API>` applies to the mode and to the numbers of the cut on that wire,
  and a run that falls back to another wire gets that wire's value (before: always the model-wide value).

## 0.5.4

**Fix: a client tool request no longer times out behind a transport that only pings.** npm `@mgamadeus/ddd-agents`
0.5.4 in lockstep.

- `AgentRealtimeChannelService::sendMessage()` writes the shared-cache mailbox first and rings the transport
  (`publishMessageToTransport()`) after it; `clearMessage()` clears the mailbox before it tells the transport
  (`clearMessageOnTransport()`). Before, a transport that only pings woke the client before the write: its run-state
  read found an empty mailbox and the request waited for the next regular read, past its time limit. A host override
  that reordered the two calls can be removed.
- npm `@mgamadeus/ddd-agents/ui`: `readRunStateAfterClientToolRequestPing(conversationId, readRunState, uiClient,
  rereadDelayMs?)` for a transport that only pings — reads the run state and, when that read delivered no client tool
  request, reads once more after `CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS` (400 ms). `AgentUIClient` counts the
  requests delivered to it (`countAcceptedClientToolRequests()`).

## 0.5.3

**The payer of the schedule budget pot at run time** (`AIConversationSchedulesService`), the counterpart of 0.5.2's
tool hook. npm `@mgamadeus/ddd-agents` 0.5.3 in lockstep; no web change.

- New protected `findBudgetPayerOfSchedule(AIConversationSchedule $schedule): ?Account` — the account whose pot a
  stored schedule spends. Default: the schedule's account (unchanged behaviour); null = no account, no pot read.
- Read at the pot check of a firing run (`spawnRunConversationForClaimedSchedule()`) and for the budget and display
  policy of the monthly cost estimate (new protected `applyBudgetOfPayerToMonthlyCostEstimate()`). The run still runs
  as the schedule's account; only the pot moves.
- A host that overrides `AgentTaskSchedulesMCPPackage::findBudgetPayerOfCall()` overrides this one with the same rule
  (the schedule's space through `findAgentSpaceOfSchedule()`, read only when the rule needs it), so the tools and the
  fire step read the same pot. The spend still counts by the run conversations' account; pool it into the payer's pot
  with `ScheduleBudgetPotsService::findAccountIdsPoolingBudgetWithAccount()`.

## 0.5.2

**A host hook for the payer of the schedule budget pot in the task tools** (`agent.tasks`,
`AgentTaskSchedulesMCPPackage`). npm `@mgamadeus/ddd-agents` 0.5.2 in lockstep; no web change.

- New protected `findBudgetPayerOfCall(?AgentSpace $agentSpace, Account $actingAccount, AgentToolCallProvenance
  $callProvenance): Account` — the account whose schedule budget pot the tools read. Default: the acting account
  (unchanged behaviour). A host whose payer is another account (e.g. the owner of the space when an account outside the
  owner's account family works in it) overrides this one method.
- Every pot read of the tools goes through it: the start-time check of `startBackgroundTask` (refused on a used-up pot)
  and the budget line of `startBackgroundTask`, `scheduleOneTimeTask`, `createRecurringTaskSchedule` and
  `getTaskSchedules`.
- New protected `composeScheduleBudgetPotNoteOfCall($aIConversationSchedulesService, ?AgentSpace, Account
  $actingAccount, AgentToolCallProvenance)`: the budget line with the space and the call; the tools call it. It hands
  the payer to `composeScheduleBudgetPotNote()`, whose signature is unchanged, so an override of it keeps working; its
  account argument (renamed `$budgetPayerAccount`) is now the payer.
- Not changed: the run-time pot check of a scheduled run reads the pot of the account the schedule is stored for (the
  signed-in creator), and the spend counts by the run conversations' account. A host that moves the payer in the tools
  moves it at run time through `ScheduleBudgetPotsService` (e.g. `findAccountIdsPoolingBudgetWithAccount()`).

## 0.5.1

**The default split view works while the canvas covers the chat** (a split narrower than `coversBelowWidth`), so a host
no longer needs its own `SplitView` for it. npm `@mgamadeus/ddd-agents` 0.5.1 in lockstep; no PHP change.

- **Named toggle:** the divider toggle carries a translated name (`Close canvas` / `Re-open canvas`, keys already in
  `i18n-keys.json`) and an icon (`closeCanvas` / `maximize`). While the canvas covers the chat it floats over the
  canvas's top end (`agents-split-view__toggle--floating`) instead of disappearing. Side by side it stays the strip on
  the divider.
- **Covered chat is inert:** the covered main pane gets `inert` and `aria-hidden`, so Tab no longer reaches it. The
  focus moves into the canvas when it covers the chat, and back to the element last focused in the chat when the
  canvas closes or the split widens.
- **Full width:** the covering pane takes the full width of the split (`inset: 0`, `box-sizing: border-box`, no
  divider border, no horizontal overflow), sits in its own stacking context (`isolation: isolate`, z-index 20) with an
  opaque background, and the split clips while covered.
- The side-by-side layout is unchanged.

## 0.5.0

Agent.UI without tables, screen pictures, the realtime port, and host adaptability. A minor with removals. Requires
`mgamadeus/ddd` ^3.46.1 and `mgamadeus/ddd-ai` ^3.7.0; npm `@mgamadeus/ddd-agents` 0.5.0 in lockstep.

**Removed (read before updating):**

- **The Agent.UI tables.** Client tool requests, their answers and the client presence live in the shared cache for
  the seconds they matter; nothing is persisted. Removed: the entities `AgentClientToolRequests`,
  `AgentClientAttachment(s)`, `AgentClientToolsPurgeResult`, the repositories `DBAgentClientToolRequest(s)`,
  `DBAgentClientAttachment(s)` with their models, `AgentClientToolRowRights`, `AgentClientAttachmentsService`, the
  namespace `DDD\Domain\AI\Repo\DB\ClientTools\` from the owned namespaces, and the command
  `app:agent:purge-client-tool-requests` (alias `app:ai-agent:client-tools:purge`) with its cron line.
  `AgentClientToolRequest` is a ValueObject now (no repository).
- `AgentClientToolRequestsService`: `blankAnswerText()` and `purge()` are gone; `findDeliveryForClient()` takes the
  account id (`$conversationId, $accountId, $clientId`); `findRequest()` reads the shared cache.

**Host steps:**

1. Give the agent a shared cache (`config/app/AI/agentRealtimeChannel.php` `sharedCacheGroup`; null tries `redis`,
   then Core's default group). APCu alone is no shared cache: the UI tools then answer "no client" and
   `app:agent:check-installation` warns (`realtimeChannel`).
2. Remove the cron line of `app:agent:purge-client-tool-requests` and any screen or fixture that names it.
3. Drop the tables `AgentClientToolRequests` and `AgentClientAttachments` (with the host's table prefix or
   `tableNames`) when convenient; the package no longer reads them. Regenerate the Doctrine models.
4. Optional push transport: a same-path subclass of `AgentRealtimeChannelService` overriding
   `publishMessageToTransport()`, `clearMessageOnTransport()`, `issueListenerCredential()`,
   `askTransportWhetherClientIsListening()`. An existing override of
   `AIConversationRealtimeSignalService::publishClientToolRequestSignal()` keeps working (deprecated, still rung).
5. npm: update `@mgamadeus/ddd-agents` to 0.5.0; the answer now carries `uiStateJson` (and `pictureBase64`).

**Changed defaults:**

- `openScreen` opens at most one screen per user message (`AI.agentUI.maxOpenScreenPerUserTurn`, default 1; counted
  over the stored calls and a shared-cache counter); a further call answers `OPEN_SCREEN_CAP`.
- The window tool `setAgentWindowMode` (package `agent.ui.window`, request type `SET_WINDOW_MODE`) is off unless
  `AI.agentUI.windowModeRequests` is true; a switched-off or unknown request type answers `NOT_ENABLED`.

**New:**

- **The realtime port** `AgentRealtimeChannelService`: typed messages (tagged `AgentRealtimeMessageTypeProvider`) to an
  account's or a conversation's clients — `sendMessage()`, `clearMessage()` (same type and key replaces),
  `findListenerCredential()`, `isClientListening()` — with a mailbox in the shared cache that the run-state read
  delivers (`realtimeMessages`, `realtimeListenerCredential`), so a host without a transport works as is. Client
  presence from what the clients already send (`AgentClientPresenceService`). Request types are open (tagged
  `AgentClientToolRequestTypeProvider`).
- **Screen pictures:** `getCurrentScreen(format: picture, pictureSize: small|medium|large, renderMode:
  colour|greyscale|machine)`; the client's picture is checked as untrusted bytes (`RasterImageDecoder`, size and type
  caps) and reaches the model as an image part (`MCPToolResponseImageProvider`), with the fixed list of what a picture
  cannot show (`AgentUIMCPPackage::PICTURE_LIMITATIONS`). The answer also carries the UI state after the request.
- **npm `/ui`:** `createDomScreenTargetSource()` (`data-agent-anchor` marks, optional `groupKey`),
  `createDomScreenPictureCapturer()` (three presets with pixel and byte budgets, three render modes, retries from the
  source size, passwords as dots, `data-agent-secret` / `data-agent-skip`, scroll containers expanded),
  `createDomScreenPathGuide()` (Esc or a click cancels, skipped under reduced motion, on the target or without a path
  element, never delays the answer; `createGuideCounter()` guides a target twice, localStorage in try/catch),
  `createAgentWindowAdapter()` (steps aside before opening, marking and a picture), `acceptArrivedRealtimeMessage()`
  for a host transport, `runClientToolRequestForDevelopment()`; the runner takes `SET_WINDOW_MODE`, pictures and
  `requestHandlers` for further request types; the HTTP client hands other realtime message types to
  `onRealtimeMessage` and the credential to `onRealtimeListenerCredential`.
- **Tool overlays** (`config/app/AI/toolOverlays.php`): a parameter set to `false` is hidden — gone from the schema,
  a sent value ignored; for the space argument the conversation's space applies (also for the rights gates).
  `examples` replaces the `@example` lines the model sees.
- **Installation check:** a feature's table is required only while its feature is on or its skill is bound (tagged
  `AgentFeatureTableConditionProvider`; organ packages declare theirs); the database error message is redacted (the
  driver's text is not shown); the renames state file is found by `AI.installationCheck.renamesStateFile` or in the
  parent folders up to the repository root.
- **Tool picture store:** the object store holds the raw bytes with their content type (older base64 objects are
  still read), any Core object store driver serves (`BasicObjectStorage`), and the eval purge erases the pictures of
  the trees it deletes (`toolPicturesErased`).
- **Schedules:** the hard delete of the archive policy runs without entity rights once the scope rule allowed it
  (`hardDeleteScheduleWithoutEntityRights()`, protected), like the status changes; the note of a one-time task whose
  result already exists is a protected hook, `composeScheduleAlreadyProducedNote()`.

## 0.4.2

**Bug fix: a schedule stored without a zone no longer changes when it fires on an unrelated edit.** In 0.4.0 and 0.4.1
`updateTaskSchedule` gave a schedule stored without a zone (an older row, on UTC) the zone of its space on EVERY edit —
a title, a task text, a report channel — and the edit then re-armed `nextRunAt`: the old UTC cron digits were read as
local digits and the schedule moved by the zone's offset.

- **Fixed:** the zone is stored only together with a new cron (the model writes the new cron in local digits). A new
  one-time moment (`runAtLocalTime`) of such a schedule is read in the zone of its space without storing it. Any other
  edit leaves the zone and the next firing untouched. The timing edits are one protected method,
  `applyTimingEditsToSchedule()`; the hooks see the update request: `applyTimezoneOnCronEditToScheduleWithoutZone()`
  (by default `applyTimezoneForNewScheduleToScheduleWithoutZone()`, which a host override keeps serving, now for cron
  edits only), `findTimezoneIdentifierForMomentEdit()` and `findTimezoneOfScopeForScheduleWithoutZone()`.
- **Host check:** a zoneless schedule edited with 0.4.0 or 0.4.1 (title, task, channel, done criteria) may carry the
  zone of its space and fire at the local reading of its old UTC digits; compare `timezone` and `schedule` of schedules
  updated since the 0.4.0 deployment.

**Consistency: a schedule the lists show is found by its id.** The task lists read the scope without entity rights (a
space the acting account may use, or the account's own rows), but pausing, resuming, updating, deleting a schedule or
reading its run report looked it up under the entity read rights: a sub-account saw a schedule in `getTaskSchedules`
and got "No such task schedule" for it. The lookup now reads by id without the entity rights and the scope rule of the
lists decides (`findScheduleByIdForScopeCheck()`, `isScheduleInScopeOfAccount()`, both protected). A hard delete still
runs under the delete rights of the repository.

## 0.4.1

**Security fix for 0.4.0 adopters: composed rights no longer open a fail-closed host.** In 0.4.0 a host that answers
rights with its own rights object (it overrides `findAccountRightsOfConversation()` / `findAccountRightsOfCurrentAccount()`
of `AIConversationEntitlementsService`) and ships no `config/app/AI/accountRights.php` of its own got, for every package
right its object did not hold, the grant of the PACKAGE's default mapping: `scheduleAgentTasks`, `useAgentMemory`,
`useWeb`, `useUI` and `useDrive` for every account, the operator rights (`operatePlatform`, `manageTexts`, `useSandbox`,
`useBrowser`) for superadmins — without any change on the host's side.

- **Fixed:** the package composes a host's rights object only with a mapping the host ships (its own
  `config/app/AI/accountRights.php`, in an application config root;
  `AIConversationEntitlementsService::isAccountRightsMappingShippedByTheApplication()`). Without one the host object is
  checked as it is: a package right it does not hold is not held, as before 0.4.0. Hosts on the package's role-mapped
  rights (no own rights object) keep the default mapping unchanged.
- **New check** `accountRightsMapping` of `app:agent:check-installation` (WARNING): the host answers with its own
  rights object and ships no mapping — it names the package rights held by nobody therefore. `rightsHeldByNobody` stays
  silent in that case (the default mapping it would read grants nothing then).
- **Host steps:** a host with its own rights object that relied on 0.4.0 granting package rights through the package's
  default mapping copies `config/app/AI/accountRights.php` into its own config and keeps the rights it means to grant,
  or holds them in its rights object. Check the access 0.4.0 gave meanwhile (schedules created, memories written, UI
  and drive tool calls of accounts that should not have had them).
- The comment of the package's `config/app/AI/accountRights.php` said a host with its own rights model does not read
  the file; it now states the rule above.

## 0.4.0

Host adaptability: a host adapts the package through config and small overrides, never by copying package code or
changing tables. Requires `mgamadeus/ddd` ^3.44.3.

**Changed defaults (read before updating):**

- **Rights are composed.** A host that answers rights with its own `AgentAccountRightsInterface` object no longer
  loses every package right it does not know: per right, the host object's "held" counts, else the mapping
  `AI.accountRights` decides (roles, `'*'`, or the new delegation `['right' => '<host right>']`). Before, every right
  the host object did not hold was missing. A host object that implements the new
  `AgentAccountRightsAnsweringInterface` decides the names it answers alone (also "not held").
- **Task tools gated per tool.** `AgentTaskSchedulesMCPPackage` carries no class-wide right any more. The tools that
  create or change a schedule — `startBackgroundTask` (spends budget at once), `scheduleOneTimeTask`,
  `createRecurringTaskSchedule`, `updateTaskSchedule`, `pauseTaskSchedule`, `resumeTaskSchedule`,
  `deleteTaskSchedule` — require `scheduleAgentTasks`; `getTaskSchedules`, `getTaskRuns`, `getTaskRunReport` require
  none. The skill file `Agent/Tasks.md` names no right: an account without `scheduleAgentTasks` now gets the skill and
  its reads.
- **Task reads of a space.** An account that may use a space reads that space's schedules and runs, also those that
  run as another account (the reads run without entity read rights, bounded by the scope filter).
- **Memory.** `AI.agentMemory.enabled` means memory on; every memory read and write goes through
  `AgentMemoryStoreService`.

**New:**

- **Composed rights** (`AgentComposedAccountRights`, `AIConversationEntitlementsService::findComposedAccountRightsOfConversation()`,
  `findComposedAccountRightsOfCurrentAccount()`) at every check (tool offer and executor refusal, conversation rights,
  client tool rows). **Declared rights:** `AgentDeclaredAccountRightsService::getDeclaredAccountRightNames()` lists
  every right the installed packages declare (their `*AccountRights::RIGHTS` lists and the rights on their packages
  and tools) — for a host guard test "every package right is answered or mapped".
- **Tool overlays by name** (`config/app/AI/toolOverlays.php`, `AI.toolOverlays`; `MCPToolOverlays`): per tool name
  `accountRights` (replaces the attributes, `[]` = none), `description`, `summary`, `parameters` (parameter
  descriptions), `ownerFacingSubject`. Read where the schema, the entitlement, the mandate summary and the run report
  are built; the documentation caches follow the overlay's fingerprint. Unknown keys and unknown tool names are reported
  by `app:agent:check-installation`.
- **The space argument by the host's name** (`config/app/AI/agentSpaceToolArgument.php`, `AgentSpaceToolArgument`):
  `name` and `description` of `agentSpaceId` on the wire. Schemas carry the name, the resolver reads it first,
  `findAgentSpaceOfToolCall()` receives the whole tool arguments (`AgentToolCallProvenance::$toolArguments`).
- **Task tools:** the task list widget from config (`config/app/AI/agentTasks.php`, `taskListWidgetType`; else
  `TASK_LIST_WIDGET_TYPE`), its field the host's self-hydration key field (`WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName()`,
  `AgentSpace::getWidgetSelfHydrationKey()`) instead of a fixed `agentSpaceId`; on a phone line a note to read at most
  three tasks aloud (`composeTaskListPhoneNote()`); `updateTaskSchedule` of a schedule stored without a zone stores the
  zone `AgentSpacesService::findTimezoneForNewSchedule()` answers (UTC only when null); `findScheduleAlreadyProducedByCall()`
  (protected, default null) lets a host answer a retried `scheduleOneTimeTask` with the schedule it already produced;
  protected reads of a scope (`isScopeReadWithoutEntityRights()`, `findSchedulesOfScope()`, `findRunConversationsOfScope()`,
  their counts). The payer of scheduled runs is decided only in `ScheduleBudgetPotsService` (documented; the class
  docblock said otherwise). Texts: `updateTaskSchedule` never rewrites a task the owner did not mention; a moved
  moment asks the model again to verify it against the request.
- **Memory is a host port:** `AgentMemoryStoreService` answers every memory read and write, plus
  `getEmbeddingRepairTargets()` and `getColumnsNotPurgedByEvalPurge()` (new); the evaluation purge and the embedding
  repair ask it. A host with memories in its own rows needs no package table (README, "Memory: the store is the host port").
- **Console commands inherit their names.** `AgentConsoleCommand` gives a subclass without its own `#[AsCommand]` the
  name, aliases and description of its nearest attributed ancestor (with Core 3.44.3 a host subclass replaces the
  package command under all its names).
- **`subSkillOf`** in skill frontmatter: a skill names the root skill(s) it is a sub-skill of; the root lists it
  without editing the root file. Linted (`AISkillFilesLintService::findSubSkillOfProblems()`).
- **Evaluation:** a case whose skill the host does not register is skipped (`SKIP <case> skill not registered (<skill>)`)
  instead of failing.
- **`app:agent:check-installation`** — see below.
- **Renames as data:** `renames.json` at the package root lists the renames of 0.3.0 (skill names, `AISkill`
  constants, the two MCP namespaces, package ids, the `app:ai-*` console names; the recorded chat harness name stays)
  for Core's `vendor/bin/ddd-renames`. 0.4.0 renames nothing.

**`app:agent:check-installation`** (read-only; `--format=json` prints `{ok, blocking, warnings, findings: [{checkName,
severity, subject, message, hint}]}`; exit 1 on a BLOCKING finding, 2 on an unknown format, else 0). Checks:
`skillBindings` (a binding names a missing class: BLOCKING), `unboundPackageSkills`, `rightsHeldByNobody` (a declared
package right the mapping gives no account), `skillRights` (a bound package's class-wide right its effective skill file
does not name), `toolOverlays` (unknown keys and tool names), `missingTables` (BLOCKING; the memory table only while
the package store is on; one WARNING when the database does not answer), `renamedConfigKeys` (old keys of
`renames.json` still set), `consoleCommands` (package command names the host does not resolve), `skillLint` (the
naming and file lint; BLOCKING for an application skill file that fails it), `pendingRenames` (renames newer than
`.ddd-renames.json`; points at `vendor/bin/ddd-renames`), and the feature checks `toolImageStore` (picture store
without its object store) and `documentTools` (document tools without their binaries on PATH). A package adds a check
by implementing `AgentInstallationCheckInterface` (service tag `ai.agent_installation_check`). Accepted findings and
skipped checks: `config/app/AI/installationCheck.php`. A finding of an unreachable database carries the driver's
message (it may name the database user and host).

**What a host does:**

1. Composer: `mgamadeus/ddd-agents` ^0.4.0 (`mgamadeus/ddd` ^3.44.3; ^3.45.0 for `vendor/bin/ddd-renames`).
2. Run `vendor/bin/ddd-renames` (dry run), then `--apply`, and commit `.ddd-renames.json` — a host still on 0.2.x names
   gets the 0.3.0 renames applied.
3. Run `php bin/console app:agent:check-installation` and fix every blocking finding.
4. Rights: delete wrappers that re-answered package rights in your own rights object — the mapping answers them now.
   To withhold a package right, leave it out of your `config/app/AI/accountRights.php`, map it to a role, delegate it
   (`['right' => '<your right>']`) or implement `AgentAccountRightsAnsweringInterface`.
5. Task tools: an account without `scheduleAgentTasks` now reads tasks. To gate the reads too, set
   `'getTaskSchedules' => ['accountRights' => ['scheduleAgentTasks']]` (and the two other reads) in
   `config/app/AI/toolOverlays.php`. A host subclass that redeclared `MCPRequiresAccountRights` on the class may drop
   it; one that chooses the task widget by subclass constant may move it to `agentTasks.php`.
6. A host that renamed or redescribed package tools by subclass may move that to `toolOverlays.php`; a host whose space
   is called otherwise on the wire sets `agentSpaceToolArgument.php`.
7. Memory: hosts on the package table need nothing; a host with its own memory rows replaces the store
   (`AgentMemoryStoreService`, same path) and switches `AI.agentMemory.enabled` on.

## 0.3.14

Drive files open in the canvas. Web package only; no PHP change.

**Added (web):**

- **`registerCanvasKind({ canvasId, behaviour, body, openedByWidgets?, featureFlag? })`** (root entry, with
  `unregisterCanvasKind` and the type `CanvasKind`): a further package brings a whole canvas — behaviour, body, opener
  widgets, gate — in one call; the app declares nothing for it. `canvasBehaviourOf`, `isCanvasId`,
  `getDeclaredCanvasIds`, the stored pointer and the restore after a reload read registered kinds like the app's
  canvases. An id of the package's own canvases is refused; the app's `furtherCanvasBehaviours` entry for an id wins
  over a kind's behaviour. `AgentsEnvironment.furtherCanvasBehaviours` + `registerCanvasBodies` work unchanged.
- **`registerDriveFileCanvasOpener(store, opener)`** (root entry; `/widgets`: `readDriveFileCanvasOpener`,
  `unregisterDriveFileCanvasOpener`, `driveFileCanvasTargetOf`): the opener of a drive store says which canvas shows
  one of its files or folders. A row of the `DriveFiles` widget with a canvas opens it (anchored on the block's
  message, origin `widgetClick`) and marks its button while that canvas is open; a row without one, a host without a
  canvas surface and a store without an opener keep open-in-a-tab and download.
- **Reference instance ids** (`/renderer`): `canvasInstanceIdForReference(canvasId, reference)`,
  `referenceOfCanvasInstanceId(instanceId)`, `isCanvasReference(value)` — a viewer per object of a store
  (`driveFile:reference:drive/812`); only a store reference passes (no path with `..`, no URL, no markup).
  `isPackageCanvasId`, `canvasKindOpenerWidgetNamesOf`.
- **Default icons:** `visibility` (the open-in-the-canvas button of a `DriveFiles` row) and `details` draw a glyph
  (`◎`, `⋯`) instead of the fallback dot.
- **`CanvasLauncherWidget`: `leading`** (optional node): the caller's own drawing in the leading badge in place of the
  `icon` glyph, for a subject without a package icon name (the folder of ddd-clouddrive's `DriveFolder` block).

## 0.3.13

The files release: documents of a drive reach the model as text by default, page images only when asked for.

**Added:**

- **`readDriveDocument`** (package `agent.drive`): the text of a drive document page by page — PDF (poppler
  `pdftotext`; a PDF without a text layer by `tesseract`, at most 20 pages), Word, Excel (one page per sheet, rows with
  their row number, cells tab-separated in their columns), PowerPoint (one page per slide, in the presentation's
  order), OpenDocument text, spreadsheet and presentation, plain text. One read returns as many pages as fit into
  `maxCharactersPerRead` and names the next page (`fromPage`); the text is one untrusted passage
  (`MCPToolReturnsUntrustedContent`); parallel-safe.
- **`viewDriveDocumentPages`**: PDF pages as PNG (`pdftoppm`), at most `pageImages.maxPerCall` per call (default 4,
  cap 12); the images go through the tool picture store of 0.3.12 like every tool picture.
- **`AgentDriveFileContentReader`** (`DDD\Domain\AI\Services\Drives`): implemented by a drive store whose files the
  agent may read. The store reads the released version's bytes through its own backend or service layer under the
  call's rights — never a presigned or client URL — and refuses what the caller may not read. A store without it keeps
  its files unread; the tools say so. `AgentDriveFileContent` carries the bytes (never serialized).
- **`AgentDocumentTextExtractionService`** (`DDD\Domain\AI\Services\Documents`, `AgentDocumentText`,
  `AgentDocumentPagesRead`): the kind comes from the bytes, never from the name or a claimed type; poppler and
  tesseract run as Core's `IsolatedProcess`; Office and OpenDocument files are checked by `ArchiveInspector` and their
  XML parsed by `SafeXml` (macros are never read); legacy `.doc`/`.xls`/`.ppt`, other ZIPs and binary files answer
  with a reason. The text is cached by the document's sha256 (`cacheGroup`, `cacheTtlSeconds`).
- **Config `AI.documents`** (`config/app/AI/documents.php`, `AgentDocumentSettings`): `enabled`, `maxDocumentBytes`
  (25 MB), `maxExtractedPages` (500), `maxCharactersPerRead` (40000), `ocr`, `cacheGroup` (null = Core's default),
  `cacheTtlSeconds` (86400), `pageImages` (`enabled`, `maxPerCall`, `resolutionDpi` 110).

**Host steps:**

1. `composer update mgamadeus/ddd-agents mgamadeus/ddd-clouddrive -W` (clouddrive 0.3.3 implements the reader; it
   conflicts with older agents).
2. Install `poppler-utils` (pdftotext, pdftoppm, pdfinfo) and, for scanned PDFs, `tesseract-ocr` with the needed
   languages in the image that runs the agent workers. Without poppler a PDF answers "the document reader is not
   available on this host"; Office files need no binary.
3. Optional: `config/app/AI/documents.php` with `'cacheGroup' => 'redis'` so every worker reuses an extraction.
4. Restart the agent workers.

## 0.3.12

The image release. It was planned as 0.3.10; 0.3.10 went to the evaluation scope fix and 0.3.11 to the `DriveFiles`
schema fix. Files (PDF, Office: text extraction, an artifact, page images on demand) follow in a later version.

**Added:**

- **The tool picture store** (`AgentToolCallImageStoreService`, settings `AI.toolCallImageStore`,
  `config/app/AI/toolCallImageStore.php`, `AgentToolCallImageStoreSettings`) replaces the spool of 0.3.7. Conversation
  rows hold references only. Three optional tiers, all configured in one place:
  - L1 `processCache`: APCu by default, 64 MB written per 600 s window.
  - L2 `sharedCache`: a shared group of Core's Cache API (Redis, Valkey). One batch read per model call, writes in one
    pipeline, TTL 1800 s, extended on a read.
  - L3 `objectStore`: a Core object store `agentToolImages`, key `agent-tool-images/<agent space id>/<sha256>`. Read in
    parallel; the lifecycle is the host's.
  - A read goes L1 → one L2 batch → L3 in parallel, then fills the faster tiers again; a resume reads only the
    pictures still in context.
  - A picture is durable (`ToolCallImageReference::$isDurable`) once L3 acknowledged it. A failed L3 write is logged
    and the picture stays in the caches.
  - L1 + L2 without an object store is the 0.3.7 spool; with nothing reachable the picture stays inline.
  - A process-local L2 and a missing L3 are reported once per process. Nothing throws.
- **Wire profile**: a captured picture is normalized once: long edge 2048, at most 3.75 MP, WebP at quality 85 (or
  JPEG), through Core's pinned `RasterImageDecoder`. The sha256 is taken over the wire form. The full check runs at
  capture; a read checks the sha256 and the header only.
- **Retention**: at a turn change the picture results of earlier turns beyond the newest four leave the context. Each
  picture becomes a placeholder line with its image id; the current turn keeps all its pictures (at most 20 per call).
  The call that moves the cut marks the prompt cache right before the first retired result, on the Messages and the
  Responses API.
- **Re-view tool** `viewEarlierToolImage` (package `agent.images`, `AgentImagesMCPPackage`, skill `Agent.Images`,
  `AISkill::AGENT_IMAGES`): attaches a retired picture again from the store, with the same reference and the trust of
  the call that produced it.
- **Measurement**: request snapshots record the pictures sent and the store's L1/L2/L3 hits, misses and read time
  (`RequestSnapshot::recordToolCallImages()`). `app:agent:cache-diff` prints the wire build p50/p95 with and without
  pictures.
- `MCPToolResponse::addImageContent()` takes `$isDurable`; `ToolResultImage::decodeMcpImageContentPart()` reads it.

**Changed:**

- Requires `mgamadeus/ddd` `^3.44.0`, for `Cache::getMultiple()` / `setMultiple()` / `extendTtlOfKeys()` and
  `ParallelReadableObjectStorage`.
- Removed `AgentToolCallImageSpoolService` and `config/app/AI/toolCallImageSpool.php`. A host's own
  `AI.toolCallImageSpool` is still read (`cacheGroup` → `sharedCache.cacheGroup`, `cacheTtlSeconds` →
  `sharedCache.ttlSeconds`, `objectStorage`, `objectKeyPrefix`) and reported once per process.

**Host steps:**

- Shared cache: set `sharedCache.cacheGroup` to a shared group (`'redis'`). `maxmemory-policy`: a shared instance that also
  holds keys without a TTL → `volatile-lru`/`volatile-ttl`; a dedicated cache instance → `allkeys-lru`; never
  `noeviction` for L2.
- Durable pictures:
  - configure the object store `agentToolImages` (`OBJECT_STORAGE_AGENTTOOLIMAGES_*`, `async-aws/s3`);
  - give its key prefix an expiry rule in the bucket;
  - or set `objectStore.enabled` false.
- A host with its own `config/app/AI/skillPackages.php` adds `AISkill::AGENT_IMAGES => [AgentImagesMCPPackage::class]`.
- A host with its own `toolCallImageSpool.php` moves it to `toolCallImageStore.php`.
- Setup and two examples: `ddd-module-agents-context-specialist` › references/tool-picture-store.md.

## 0.3.11

**Fixed:**

- **The shipped ComposedView node schema lacked the widget `DriveFiles`.** `web/src/renderer/widget-tree/composed-node-schema.json`
  (Composer package and npm `dist/files/renderer/widget-tree/`) is regenerated from `ComposedViewNodeSchemaParts::buildModuleSchemaJson()`,
  which has listed `DriveFiles` since 0.1.26; the parity fixture `composed-view-parity.json` (`stubSchema`, read by
  hosts' parity tests) carries it too. `ComposedViewNodeSchemaExportCommandTest` is green again.
- New checks: `ComposedViewParityFixtureSchemaTest` (PHP: the shipped schema equals the export; the fixture carries
  every widget, embeddable widget and data source of the module) and `_composed-view-parity-fixture.spec.ts` (web CI:
  the fixture carries every widget, embeddable widget and data source of the shipped schema).

**Host steps:** none (a host's own parity expectations regenerated against the package fixture now include `DriveFiles`).

## 0.3.10

**Fixed:**

- **Evaluation runs with `--accountId` skipped the host's scope hook.** `AgentEvalRunHooksService::resolveEvalScopeWithFallbackAccount()`
  now asks `resolveEvalScope()` first, as every run without the option does, so a host's own scope keys in a case (a
  record that carries the agent space) decide also under `--accountId`. Before, such a case ran at account level and
  missed the space's drive ("no drive mounted"). The named account steps in only where the hook finds no space or a
  space without an owner (`AgentEvalScopeWithoutOwnedAgentSpaceException`, thrown by the module's default); any other
  failure of the hook fails the case.

**Host steps:**

- A host override of `resolveEvalScope()` that throws for "no space" itself throws
  `DDD\Domain\AI\Exceptions\Evals\AgentEvalScopeWithoutOwnedAgentSpaceException` there (or calls the parent), so
  `--accountId` runs such cases at account level.

## 0.3.9

**Changed:**

- **The write gate after an untrusted read is configurable and off by default.** `AI.untrustedContent.writeAfterUntrustedRead`
  takes `off` (the new package default), `ownerConsent` or `refuse`. Under `off` a WRITE or DELETE after an untrusted
  read of the turn runs as before 0.1.30: no consent is reserved, and a scheduled run or a delegated sub-task is not
  refused. `ownerConsent` and `refuse` behave as before. An unknown value reads as `off` (before: `ownerConsent`) and
  is reported once per process in the issues log (`MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode()`).
- The unseen-URL refusal (`MCPToolFetchesModelChosenUrl`) has its own key `AI.untrustedContent.unseenUrlAfterUntrustedRead`:
  `refuse` (default, unchanged behaviour) or `off`.
- Unchanged: a READ or WORKSPACE tool is untrusted unless it declares trust.

**Host steps:**

- A host that wants the write gate sets `'writeAfterUntrustedRead' => 'ownerConsent'` (and the decision site
  `ownerConsent` `on`) or `'refuse'` in its own `config/app/AI/untrustedContent.php`. A host with its own copy of that
  file adds `'unseenUrlAfterUntrustedRead' => 'refuse'` only to be explicit (a missing key is `refuse`).

## 0.3.8

**Fixed:**

- **A picture left the wire at the turn change.** Root cause of the thinking drop in the first call of a new turn: the
  changed message was the tool result of the picture (a user message on the Messages API), not an owner message. The
  first call of the new turn runs in a new process; the picture had no transient carry there, and the spool wrote it
  into Core's default cache group, APCu, which no other process reads. The result then carried the "no longer on the
  wire" marker, and the provider dropped the thinking of the steps after it (`prefix_binding_mismatch`). With a shared
  spool cache the result is byte-identical across the turn change on both wire APIs (new request-prefix test).
- `app:agent:cache-diff` names that change `pictureLeftWire` (a tool result that sent pictures on the previous call
  sends fewer) and any other changed tool result `toolResultRewrite`. Before, a changed tool result on the Messages API
  was counted as `ownerMessageRewrite`.

**Added:**

- `AI.toolCallImageSpool` `cacheGroup`: the cache group of the spool (`Cache::instance($cacheGroup)`), null = Core's
  default group. When the spool writes into a process-local cache (APCu), it reports this once per process in the
  issues log (warning).

**Host steps:**

- Without the object store, set `'cacheGroup' => 'redis'` (or another group all workers share) in an own
  `config/app/AI/toolCallImageSpool.php`, or configure `OBJECT_STORAGE_AGENTTOOLIMAGES_*`. No table, no DDL.

## 0.3.7

**Added:**

- **Durable tool pictures.** A picture a tool returns without a store of its own (a browser viewport, a workspace
  image) is written to the package's spool when the call completes (`AgentToolCallImageSpoolService`), and its
  reference names it (`ToolCallImageReference::SOURCE_STORE_SPOOL`, id `<conversation id>/<sha256>`). Every later model
  call re-reads the same base64 text (`MCPToolExecutorService::resolveToolCallImage()`), so the frozen tool result is
  byte-identical across worker invocations and turns. Before, such a picture left at the end of its worker invocation:
  the next invocation sent the "no longer on the wire" marker in its place, the prompt cache was written again from that
  result on, and on the Messages API the thinking after it was dropped (one drop per picture that left). A picture now
  leaves only on purpose: its result is stubbed or cleared, the picture cap pushes it off, or a compaction removes it.
- Where the bytes go: `config/app/AI/toolCallImageSpool.php` (`AI.toolCallImageSpool`). The Core object store
  `agentToolImages` when it is configured (`OBJECT_STORAGE_AGENTTOOLIMAGES_*`, key prefix `agent-tool-images/`), else
  the application cache for 24 h (at most 2 MB per picture). Every read checks the bytes again: the hash against the
  reference, the type from the magic bytes against the allow-list.
- It degrades without errors: a failed or impossible write leaves the picture inline (the behaviour before), a failed
  read is a dropped picture, `enabled => false` switches the spool off. A per-process cache (APCu under the CLI) is not
  shared by the workers, so there the picture leaves as before.
- Cost: pictures ride on more calls (while their result is in full and the cap allows them); on a cached prefix they
  are read from the cache.

**Host steps:**

- None required. For the effect, the workers need a shared cache (Redis) or the object store: configure
  `OBJECT_STORAGE_AGENTTOOLIMAGES_*` (or name another store in an own `toolCallImageSpool.php`) and give the key prefix a
  lifecycle rule (e.g. 30 days). No table, no DDL.

## 0.3.6

**Fixed:**

- **Responses API: one fixed system marker, on the base prompt.** The markers inside the multi-part system item are part
  of the provider's cache key there; markers on separate items are not. 0.3.5 marked the system end instead of the base
  prompt on every call; the system item now carries one marker on the base prompt on every call and never one on its
  end (`ArgusAIAgentConversation::buildSystemContentPartsWithPromptCacheBreakpoints(…, $marksEnd)`). A normal call marks
  the base prompt, the newest stable message and the previous call's message; a call that moves the reasoning cut marks
  the base prompt, the entries before the earlier and the new cut and the newest stable message. Under the
  system-prompt swap the cut markers now fit as well. Cost: a change right behind the system content (a compaction
  summary, a changed variant) reads only the base prompt (about 12k tokens) instead of the whole system content. The
  first call after the update writes the system entry again. The Messages API is unchanged (`cache_control` is not part
  of the cache key there).

**Host steps:** none.

## 0.3.5

**Fixed:**

- **Responses API: the call after a cut reads the cache again.** On the explicit-breakpoint wire a breakpoint is part
  of the prefix, so the entries a cut call wrote without the base-prompt marker were never found by the next call (it
  read only the system prefix and wrote the history again). The system item now carries the same markers on every
  call: one at its end under append-only loading, the base prompt and the end under the system-prompt swap. The other
  slots of the four go to the stable message and either the previous call's message or the two cut markers; a cut call
  with one free slot marks no cut (`ArgusAIAgentConversation::addSystemMessageAndPromptCacheMarkers()`). On the
  Messages API nothing changes. Under append-only loading a Responses call no longer marks the base prompt on its own:
  after a compaction the base prompt is not read separately. The first call after the update writes the system entry
  once again.

**Host steps:** none.

## 0.3.4

**Fixed:**

- **A cut call keeps the split of the system parts.** A call that moved the reasoning cut (0.3.3) sent the system content
  as one text part instead of the parts every other call sends. The call after a cut then read only the system prefix
  from the cache and rewrote the history, and on the Messages API every kept thinking block was dropped
  (`prefix_binding_mismatch`), the cut call's own one on the next call as well. The parts now split at the same
  boundaries on every call; a cut call leaves out only the markers of the inner parts
  (`ArgusAIAgentConversation::buildSystemContentPartsWithPromptCacheBreakpoints(…, $marksInnerParts)`).
- The markers of a model call are set in one public method, `ArgusAIAgentConversation::addSystemMessageAndPromptCacheMarkers()`
  (`PROMPT_CACHE_MARKERS_EXPLICIT_BREAKPOINTS`, `PROMPT_CACHE_MARKERS_ANTHROPIC_CACHE_CONTROL`), which
  `buildModelContext()` calls. A test composes three calls around a cut on both wires and checks that the request
  bodies, without markers, repeat byte for byte up to the earlier cut and up to the cut call's last marker.

**Host steps:** none.

## 0.3.3

**New:**

- **The reasoning carry-over per model.** The model's catalog block `agenticUseCase.previousReasoning`
  (mgamadeus/ddd-ai 3.6.0, `AIModelPreviousReasoningCarryOver`) decides the carry-over and the numbers of its cut. Each
  value is resolved on its own: the conversation's run settings row, else the model's catalog value, else
  `AI.contextRetention.<key>ByAgentWireApi[<wire API>]`, else `AI.contextRetention.<key>`, else the built-in default
  (`AgentWireConfiguration::resolveFor()`). The catalog sets GPT-6 Luna and GPT-6.2 Sol to `capped` at 1,000 and
  Claude Opus 5.5 to `all`; Kimi K3.1 Pro keeps `currentTurn`.
- **Min, steps and headroom of the cut** (`AgentPreviousReasoningCut::decideFor()`): the cut fires above
  `max(cap, kept at the last cut + headroom)`; at a cut, whole steps are kept walking back from the newest one until at
  least `keptTokensAtCut` reasoning tokens and `keptStepsAtCut` steps. New run settings rows (with `SetBy` / `SetAt`),
  console options of `app:agent:chat`, eval arms and purpose `runSettings:` keys: `previousReasoningKeptTokensAtCut`,
  `previousReasoningKeptStepsAtCut`, `previousReasoningHeadroomTokensAfterCut` (`--previous-reasoning-kept-tokens-at-cut`,
  `--previous-reasoning-kept-steps-at-cut`, `--previous-reasoning-headroom-tokens-after-cut`); config keys of the same
  names in `contextRetention.php`, each with a `…ByAgentWireApi` map. Defaults keep the rule before: min 0, 2 steps,
  headroom 0. Delegation children inherit the rows.

**Fixed:**

- **A cut call reads the cache up to the earlier cut.** A call that moves the cut marks the entry before the earlier
  cut (written by the cut call before) and the entry before the new cut (read by the next cut call), on the Responses
  and the Messages API; the previous-call marker and the system markers after the base prompt and before the loaded
  skills give up their slots (`ArgusAIAgentConversation::addPromptCacheBreakpointsAtPreviousReasoningCut()`). Before,
  every cut call read only the system prefix from the cache.

**Host steps:** update `mgamadeus/ddd-ai` to 3.6.0 together with this release (required). A host catalog that sets
`previousReasoningCarryOver` on a model moves it to `'agenticUseCase' => ['previousReasoning' => ['carryOver' => …]]`
— the old key is no longer read. A host that replaces `contextRetention.php` needs nothing (missing keys take the
defaults). Optional: the new cut settings per model, per wire API or per conversation.

## 0.3.2

**New:**

- **A step floor per skill.** A skill file may declare `turnMinimumSteps: <n>`: while the skill is loaded, every owner
  turn gets at least that many steps (the highest floor of the loaded skills counts, beside a tool-declared floor;
  `AISkill::$turnMinimumSteps`, `AISkills::findHighestTurnMinimumSteps()`,
  `AIConversationTurnBudget::buildForConversation(…, $loadedSkillsTurnMinimumSteps)`). Capped by
  `PLANNED_TURN_MAX_STEPS_CEILING` (60); a raised turn carries the planned turn's default cost cap
  (`PLANNED_TURN_DEFAULT_COST_CAP_USD`) unless the run settings name one; explicit `runSettings.maxStepsPerTurn`
  wins. For skills whose work is long (build a document, render, check, hand over), so a turn no longer stops before
  the hand-over. The skill-file lint rejects a value that is no positive whole number. No skill of this package
  declares one; the default budget is unchanged.
- **`./ui`: marks beside the agent window.** `DomScreenHighlighter` takes `getOccludingRect` (the region the
  application's agent window covers) and scrolls a marked element into the band that region leaves free (the
  region's height as scroll margin, `planHighlightScroll()`); a side panel over the element, or an element taller than
  the free band, is answered as out of view. The note stays out of a window below the element. `onHighlight`
  receives every mark (`ScreenHighlightEvent`: references, elements, region, `coveredAfterScroll`) so the application
  can make its window smaller or move it. Without the new options nothing changes. `@mgamadeus/ddd-admin-ui` 0.7.1
  fills both from its shell (`highlighterOptions`).

**Host steps:** none. Optional: `turnMinimumSteps` in an own long-running skill; pass the highlighter options.

## 0.3.1

**Fixed:**

- `Agent.UI`: a run-state read that carries `uiStateJson` and the UI state note of a client whose state is stored no
  longer fail with "could not be passed by reference" (`AIConversationRunStateGetRequestDto::findUIState()`,
  `AgentClientAttachment::getUIState()`). With 0.3.0 every client that reported its UI state got an error on the read.

**New:**

- **A carry-over cap per wire API.** `AI.contextRetention.previousReasoningCapTokensByAgentWireApi` (keyed by the wire
  API: `responses`, `messages`) gives one wire API its own cap of the carry-over `capped`; a wire API without an entry
  takes `previousReasoningCapTokens`, a conversation's run settings win over both
  (`AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens($wireApi)`). The package ships no entry: every
  wire API stays at 4,000. A host that replaces `contextRetention.php` needs nothing; to give the Messages API its own
  cap it adds the key.
- **`WebPageTextSource`**, the port for a page reader of another package or the host
  (`DDD\Domain\AI\Services\WebSearch\WebPageTextSource`, a tagged service). While one is available,
  `getWebPageAsMarkdown()` / `getWebPagesAsMarkdown()` read through it after the refusals (results page, unresolvable
  host, private address) and `urlToMarkdown` counts as available without an endpoint; without one, or when it throws,
  the endpoint reads as before. `WebPageMarkdown::$fetchedVia` names how a reader read a page (null = the endpoint).
  The tool `getWebPageAsMarkdown` stays in this package.
- `RunSettings::$agentWireApi` documents `messages` among its values (the value has been accepted since 0.2.0): a host
  whose generated client types lack it regenerates them from the OpenAPI document.

**Docs:** the naming and rights rules in the agent skills (skill file names, package ids, rights constants and their
mapping, platform skills, what a host does not build), linked to the framework's rulebook
(`ddd-module-orchestrator` › package-cuts-and-names). Database tests for the client tool tables on MariaDB and
PostgreSQL.

## 0.3.0

One release for the module cut: what moved to its own package leaves, the agent's skills get their final names, and
three framework features arrive (`Agent.UI`, the `System.*` tools, rights by package). Requires `mgamadeus/ddd` ^3.41.0.

**Removed (no alias):**

- The sandbox administration (`Sandboxes/*`: entities, `AgentSandboxAdministration`, `AgentSandboxesAdminService`,
  `AgentSandboxesControllerTrait`, the audit table `AgentSandboxActionLogEntries`). Its home is `mgamadeus/ddd-agent-sandbox`.
  Kept: `MCPToolEffect::WORKSPACE`, `AgentEvalWorkspaceParticipant`, `AgentDriveStore`, `showDriveFiles`, the
  `DriveFiles` widget, and the conversation queries of an admin screen: `AIConversationsService::countRootConversationsPerAgentSpaceId()`,
  `findAgentSpaceIdsWithALiveTurn()` and `findNewestRootConversationsOfAgentSpaceId()` (returns
  `AgentSpaceRootConversations`; the former `findNewestRootConversationsOfAgentSpaceIdForSandbox()` delegates to it,
  deprecated).
- The page tools: skill `Web.PageView`, `WebPageScreenshotMCPPackage` (`getWebPageAsScreenshot`), `PageScreenshots/*`,
  `ArgusPageScreenshot`, `AIWebSearchService::capturePagePartForModel()` / `capturePageScreenshot()`, the `screenshot`
  capability of `AI.webSearch`. Their home is `mgamadeus/ddd-agent-browser` (`Agent.Web.Pages`, `agent.web.pages`).
  `getWebPageAsMarkdown` stays here.

**Renamed:**

| Before | Now |
|---|---|
| `Assistant.Mandates`, `.Tasks`, `.TaskRunReports` | `Agent.Mandates`, `Agent.Tasks`, `Agent.TaskRunReports` (files `Skills/Agent/*.md`) |
| `Memory.Management` | `Agent.Memory` (`Skills/Agent/Memory.md`) |
| `Web.Search` | `Agent.Web.Search` (`Skills/Agent/Web/Search.md`, names no sub-skill) |
| ids `agent.taskSchedules`, `agent.memories`, `agent.webSearch` | `agent.tasks`, `agent.memory`, `agent.web.search` |
| constants `AISkill::ASSISTANT_*`, `MEMORY_MANAGEMENT`, `WEB_SEARCH`, `WEB_IMAGES`, `COMMUNICATION_*`, `WEB_PAGE_VIEW` | `AISkill::AGENT_MANDATES`, `AGENT_TASKS`, `AGENT_TASK_RUN_REPORTS`, `AGENT_MEMORY`, `AGENT_WEB_SEARCH`, `AGENT_UI`, `AGENT_SETTINGS`, `AGENT_BUDGET`, `AGENT_WEB_IMAGES`, `AGENT_COMMUNICATION_MESSAGES`, `AGENT_COMMUNICATION_VOICE_CALLS`; `WEB_PAGE_VIEW` gone |
| `DDD\Presentation\Mcp\Client\AI\…`, `DDD\Presentation\Mcp\Agent\…` | `DDD\Presentation\Mcp\Client\Agents\…`, `DDD\Presentation\Mcp\Agents\…` (class names unchanged) |
| console `app:ai-agent:*`, `app:ai-conversation:*`, `app:ai-schedule:*`, `app:ai:*` | `app:agent:*` (`mark-finalized-conversations`, `compact-finalized-conversations`, `backfill-conversation-environment`, `conversation-scorecards`, `conversation-search-inspect`, `close-schedules-for-inactive-agent-spaces`, `export-composed-view-node-schema`, the rest keep their last part); every old name stays a Symfony alias. The harness stored in a chat recording stays `app:ai-agent:chat` |

The default `skillPackages.php` binds by class. A stored conversation that loaded an old skill name skips it; the model
loads the skill under its new name.

**New:**

- **Rights by package.** `AgentAccountRights` (`operatePlatform`, `scheduleAgentTasks`, `useAgentMemory`, `useWeb`,
  `useUI`; names the other packages' `manageTexts`, `useSandbox`, `useBrowser`). The packages carry them
  (`#[MCPRequiresAccountRights]`), the skills name them. The default rights of `AIConversationEntitlementsService` are
  now `AgentRoleMappedAccountRights` over the config `AI.accountRights` (shipped: the operator rights to
  `Role::SUPERADMIN`, the everyday rights to every account); an unmapped right is not held. Before, the default
  answered null (ungated).
- **`Agent.UI`** (`agent.ui`, `AgentUIMCPPackage`): `getCurrentScreen`, `listScreens`, `openScreen`,
  `highlightOnScreen` over a client tool request channel (`AgentClientToolRequest`, `AgentClientAttachment`, tables
  `AgentClientToolRequests`, `AgentClientAttachments`; answer route `POST /{aiConversationId}/clientToolRequests/{requestId}/answer`
  in `AgentConversationWritesTrait`; the request is delivered as `clientToolRequest` of the run-state read); the
  `[SIGNAL:ui]` state note (state delivery kind `ui`, a tail entry without state delivery) from the UI state the client
  sends (`clientId`, `uiState` on start, message and signal; `clientId`, `uiStateJson` on the run-state read); the screen
  catalog port `AgentUIScreenCatalogService`; `app:agent:purge-client-tool-requests`; the scripted client of the
  evaluations `AgentScriptedUIClientService`. Without the tables the chat keeps working (stamping skipped and logged).
- **`System.*`** for the platform operators (right `operatePlatform` and `#[MCPRequiresAdminWithAccessToAllResources]`):
  `System.Health` (`ddd.system.health`, sections port `SystemHealthSectionProvider`), `System.Issues`
  (`ddd.system.issues`), `System.Workers` (`ddd.system.workers`), `System.Crons` (`ddd.system.crons`), over Core's
  `WorkersOverviewService`, `CronsManagementService`, `IssueGroupsService`, `IssuesOverviewService`. With them
  `LlmTextRendering`, `LlmSecretRedaction`, `LlmLineRenderable` (`Entities/MCPPackages/LlmRendering/`) and the admin
  tool result DTOs (`DDD\Presentation\Mcp\Admin\Common\…`).
- **Naming lint:** `AISkillFilesLintService::findSkillNamingProblems()` and `findBindingNamingProblems()`: skill names
  (parts, case, repeated topic, `And`), no skill file beside a folder of the same name, package id form and provider
  (`AI.packageIdProvider`), a package one skill binds carries its words, the binding names classes, a skill's right is
  on each of its packages; older names go into `AI.skillLintAllowList`, a list that only shrinks.
- `AgentsModule::getOwnedNamespaces()` for Core's `ModuleNamespacesTestCase` (also run by the package suite).
- Web: the entry `@mgamadeus/ddd-agents/ui` (`ClientToolRequestRunner`, the adapter contracts `ScreenTargetSource`,
  `ScreenNavigator`, `ScreenReader`, `ScreenHighlighter`, the Markdown writer, `ScreenReferenceRegistry`,
  `DomScreenReader`, `DomScreenHighlighter`, `AgentUIState`, `AgentUIClient`, `useAgentUIClient`); the HTTP binding
  takes `uiClient` and offers `answerClientToolRequest`.

**What a host must do:**

1. Composer: `mgamadeus/ddd-agents` ^0.3.0 (with `mgamadeus/ddd` ^3.41.0); for screenshots
   `mgamadeus/ddd-agent-browser` ^0.2.0, for the sandbox `mgamadeus/ddd-agent-sandbox` ^0.1.0.
2. `skillPackages.php`: the new constants and classes; drop `Web.PageView` (bind `Agent.Web.Pages` of the browser
   package and name it in `subSkillNames` of your copy of `Agent/Web/Search.md`); move same-path skill overrides to
   `Skills/Agent/…`; rename eval fixtures with their skill.
3. Imports `DDD\Presentation\Mcp\Client\AI\` → `…\Client\Agents\`, `DDD\Presentation\Mcp\Agent\` → `…\Mcp\Agents\`;
   same-path overrides move with them (`App\Presentation\Mcp\Client\Agents\…`).
4. Rights: map yours in `config/app/AI/accountRights.php` (replaces the package's whole) or keep answering them with
   your own `AgentAccountRightsInterface`; a host subclass of a package class repeats its `#[MCPRequiresAccountRights]`
   (class attributes are not inherited). Run the naming lint in your suite.
5. `Agent.UI`: create the two tables, implement `AgentUIScreenCatalogService`, mount the client adapters
   (`@mgamadeus/ddd-agents/ui`, `@mgamadeus/ddd-admin-ui`), add the hourly purge cron. Remove an own copy of the client
   tool classes (an application class at the same path that does not extend the package's is not used).
6. `System.*`: delete own tools over Core's issues, workers and crons; add your health sections as
   `SystemHealthSectionProvider` services; map `operatePlatform`.
7. Console names: crons and scripts may keep the old names (aliases); an application subclass of a package command
   repeats the new name.
8. Regenerate the Doctrine models (`AgentClientToolRequests`, `AgentClientAttachments`; the sandbox audit model is gone).

**Next (not in this release):** durable pictures for the workspace image tool: a picture the agent viewed is kept as a
drive file reference with its rendition instead of base64 on the call row, so it survives the turn end and the picture
cap (the frozen history then keeps its bytes). Belongs here by the rulebook (the tool layer and the picture budget are
the agent's); the sandbox and the drive provide the bytes.

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
