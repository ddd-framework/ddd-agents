# AgentConnections

**Reader:** host developer

External accounts the agent works with: mailboxes (Google, IMAP/SMTP), calendars, contacts, drives (Google Drive,
native Docs/Sheets/Slides) and a team chat (Slack). Off by default (`AI.agentConnections.enabled`, every adapter off).

## Files

| Area | Where |
|---|---|
| Entities, sets, value objects (connection, permission, release, credentials, address, account link, thread, trigger, action audit and its targets, approval request, inbound event; boundaries, ceilings, step arguments, level requests; adapter VOs) | `src/Domain/AI/Entities/Connections/**` |
| Repositories and generated models | `src/Domain/AI/Repo/DB/Connections/` |
| Services: the connections, the gate (`AgentConnectionOperationsService`), approvals, connect flows, inbound, thread replies, triggers, read cache, Drive and contacts | `src/Domain/AI/Services/Connections/` |
| Adapters (FAKE, Google, IMAP/SMTP, Slack) and their clients | `src/Domain/AI/Services/Connections/Adapters/**` |
| Inbound worker message and handler | `src/Domain/AI/MessageHandlers/Connections/` |
| MCP packages (`agent.connections.overview`, `.mail`, `.calendar`, `.contacts`, `.files`, `.documents`, `.chat`) | `src/Presentation/Mcp/Client/Connections/` |
| Admin endpoints trait, public Slack events trait | `src/Presentation/Api/Admin/Connections/`, `src/Presentation/Api/Public/Connections/` |
| Commands: sweep, Slack Socket Mode, FAKE seed | `src/Symfony/Commands/Agents/AgentConnections*` |
| Config, skills | `config/app/AI/agentConnections.php`, `config/app/AI/Skills/Agent/Connections/` |

## Rules the code keeps

- **One gate.** Every provider call goes through `runRead()` / `runOutwardStep()`: usable connection, audience rule
  (a connection private to its owner never in a conversation others read), level, stage guard (outside production
  only allow-listed recipients and internal channels; eval runs only through FAKE), limits, then the owner's consent
  (attended) or an approval request (unattended). Provider calls are invokable objects
  (`AgentConnectionProviderCall`), never closures; reads may be served from the connection-keyed read cache.
- **Levels** (one ordered enum): OFF, READ, DRAFT, WRITE_WITH_APPROVAL, DELETE_WITH_APPROVAL, SEND_WITH_APPROVAL,
  SEND_BY_RULE; `AgentConnectionPermission::levelNeededFor()` maps an operation. A rule never covers a removal
  (`REMOVING_OPERATIONS`: a delete, and a merge, which deletes its donor).
- **Reach.** The owner of a mode-1 connection; anyone else only through an active release to an agent space the
  application says the account belongs to (`isAccountMemberOfAgentSpace()`, fail closed). Every reach names its
  `AgentConnectionReach`: a tool `inSpace(callProvenance->agentSpaceId)` (only a release to the conversation's own
  space counts), the admin screens and approval answers `acrossMemberSpaces()`, a trigger `inSpace(null)`. A release
  caps each capability (`AgentConnectionCapabilityCeilings`; none named = READ) and narrows boundaries
  (`AgentConnectionScopeBoundaries`, kinds FOLDER, LABEL, CALENDAR, CONTACT_LABEL, CONTACT_SOURCE; disjoint = nothing).
  Both are typed sets in JSON columns, also on the wire.
- **Scopes (Google).** The connect asks for the read scopes of the chosen capabilities plus identity. A level whose
  scopes are missing is refused with `AgentConnectionConsentRequiredException` (409 `consent_required`) until a
  reconsent (`POST …/oauth/start` with `connectionId` and `requestedLevels`) granted them; the gate refuses a tool the
  same way and shows the owner `ExtendConnectionPermission`. Map: `GoogleAgentConnectionAdapter::SCOPES_BY_CAPABILITY_AND_LEVEL`
  (cumulative), broader grants cover narrower ones (`SCOPE_IMPLICATIONS`).
- **Outward steps** carry `AgentConnectionStepArguments` (one typed payload per kind); an approval stores
  `toStoredJson()` and runs exactly that. The per-recipient limit counts `AgentConnectionActionTargets` by exact
  target. Tools that may write a widget (the gated ones) are not `MCPToolParallelSafe`; a widget is written once per
  owner message (`appendOwnerWidgetOnce`, from the stored rows).
- **Approvals** run at most once: a conditional `UPDATE … WHERE status = OPEN`; expired requests are refused and
  expired by the sweep; an approved step runs the stored arguments after a content-hash check.
- **OAuth** binds the consent to the starting browser (HttpOnly nonce cookie, hash in the signed state) and uses PKCE.
- **Rows that change concurrently** (error counters, status, thread answers, trigger stamps, settings) are written with
  conditional DQL updates or `updatePartialIgnoringRights()`, never a full-row update.

## Host hooks

`AgentConnectionsService`: `isAccountMemberOfAgentSpace`, `findShareableAgentSpaceIdsOfAccount`,
`mayShareToAgentSpace`, `isConversationReadByOthers`, `getOwnerFacingText` (the widget reasons in the host's
language). `AgentConnectionInboundService`: `findAccountIdByVerifiedEmail`,
`accountHoldsRight`, `findAgentSpaceForInboundConversation`. `AdminAgentConnectionsTrait`:
`throwForbiddenUnlessConnectionsAccessAllowed`, `mayManageSystemConnections`, `findSystemConnectionsAgentSpaceId`,
`mayLinkChatUserToAccount`. Rights: `AgentConnectionsAccountRights` (use, manage own, manage system).

## Firewall

The OAuth callback `GET …/oauth/{provider}/callback` and the Slack events route are public in the application's
firewall: the signed state plus the browser cookie, and the Slack signature, are their authentication.

## Tests

`tests/Domain/AI/Connections/`: the gate and levels, reach, scopes and consent, limits, approval answers, OAuth state and
nonce, trigger runs, typed settings, mail sender checks, drive documents, contacts. Database-free doubles in
`tests/Support/Connections/` (`InMemoryAgentConnectionsService`, `LimitTestAgentConnectionOperationsService`,
`ClaimTestApprovalRequestsService`, `RecordingTriggersRunService`).
