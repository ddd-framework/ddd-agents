# The agent space: what the agent works for

**Reader:** host developer

Contents: the idea · without spaces · partition rules · the facts the module asks · anchoring the space on the host's
entity · starting a conversation for a space · tools and the space · read rights · traps.

## The idea

Every conversation belongs to an account: who talks to the agent. Most applications know a second anchor: what the
agent works for (a workshop, a team, a work order). `DDD\Domain\AI\Entities\AgentSpaces\AgentSpace` is that anchor in the package's general form, one row
per thing the agent works for. Conversations, schedules and mandates reference it through `agentSpaceId`. The package
knows no kind of space and carries no column of an application; the host decides what a space stands for, adds its
own reference in a subclass and creates the rows. **The package never creates, copies or deletes a space.**

The space holds the link and the answers the agent needs, none of the host's own data. Its own columns: `accountId`
(set when a space belongs to one account for its whole life) and `timezone` (an IANA zone; empty means "not known
here").

## Without spaces

An empty `agentSpaceId` means **account level**: the row belongs to the account alone. A host without spaces never
sets one and runs the module unchanged; the reference kernel starts every conversation that way. Every method of
`DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService` has an answer for a conversation without a space, and none throws because the space is missing.
Whether such work may start is the host's decision in `requireConversationStartWithoutAgentSpaceAllowed()` (default:
allowed) and, for schedules, `isScheduleWithoutAgentSpaceAllowed()` (default: allowed).

## Partition rules

Every "of the same space" read of the module filters on `agentSpaceId` through `DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition`, which has
named modes so that no statement writes the condition by hand:

| Mode | Used for | With a space | Without a space |
|---|---|---|---|
| `forAgentSpace()` | conversations, schedules: the rows of this space | `agentSpaceId = :id` | `agentSpaceId IS NULL` (the statement carries its own account condition) |
| `forAgentSpaceOrAccountWide()` | mandates: the rows that apply to this space | bound to it, or account-wide | account-wide only |
| `forAccountWithoutAgentSpace()` | the rows one account holds without a space | — | `agentSpaceId IS NULL AND accountId = :accountId` |

"No space" never selects the rows of all spaces of an account; a read across spaces carries no space condition and
says so in its name. A space object without an id (not stored) matches no row
(`AgentSpaceSqlCondition::AGENT_SPACE_ID_THAT_MATCHES_NO_ROW`), and a write refuses it
(`AgentSpace::getIdOfStoredAgentSpace()` throws), so a failed load never lands a row at account level.

## The facts the module asks

The module asks `AgentSpacesService`, never the host's entity and never the space directly. Each default hands the
question to the space or answers "not known".

| Method | Default | Override on |
|---|---|---|
| `requireAgentSpaceUsableByAccount()` | the space's `accountId` is the account, or the account is an admin with access to all resources; else `ForbiddenException` | the service (decide by the host's rights on its entity) |
| `findTimezoneOfConversation()`, `findTimezoneForNewSchedule()` | `AgentSpace::findTimezone()`: the stored zone, confirmed | the space (`findTimezone()`) |
| `findOwnerAccountOfAgentSpace()` | `AgentSpace::findOwnerAccount()`: the space's account | the space |
| `findBillingEntityOfConversation()`, `findLoadedBillingEntityOfConversation()` | none: the account is billed | the space (`findBillingEntity()`, `findLoadedBillingEntity()`; the second never loads) |
| `resolveUserLanguageCode()`, `resolveLocaleString()` | the confirmed language of the conversation, else the space's (`findLanguageCode()`, `findLocaleString()`), else the default language | the space |
| `applyAgentSpacePromptParameters()` | sets `AGENT_SPACE_ID`, `USER_LANGUAGE`, `USER_LANGUAGE_CODE`, and `AGENT_SPACE_CONTEXT` from `buildAgentSpaceContextPromptSegment()` (empty by default) | the service |
| `buildConversationSummaryAgentSpaceContextPromptSegment()`, `buildStoredFactLines()`, `buildOwnerAddressingInstruction()`, `resolveOwnerAddressingArguments()` | none | the service |
| `findPromptCacheRoutingKeyOfConversation()` | `agent-space-<id>`, else `agent-account-<id>` | the service; a host that sent other keys before keeps sending them, a changed key starts every prompt cache cold |
| `findUnversionedPromptInputsOfConversation()` | none | the service: facts of the space another system writes, so the prompt cache key changes with them (asked on every turn, keep it cheap) |
| `attachAgentSpaceRelations()` | nothing | the service: load the relations the space's facts read before a run |
| `findDefaultModelNameOfConversation()`, `findProductNameOfConversation()`, `findProductOperatorNameOfConversation()`, `findPromptExtensionAliasOfConversation()` | the base model; none | the service |
| `isCrossConversationRecallEnabled()` | true | the service |
| `findChannelConnectionState()` | null (unknown, never "connected") | the service |
| `findWidgetSelfHydrationKeyOfConversation()` | `AgentSpace::getWidgetSelfHydrationKey()`: the space id | the space |
| `applyTranslationSettingsOfAgentSpace()`, `getActiveInterfaceLanguageCodes()` | nothing; none | the service |

Rule of thumb: a fact the space reads off its own relations is overridden on the `AgentSpace` subclass; a fact that
needs a query or another service, a prompt segment, or an answer for a conversation without a space is overridden on
the `AgentSpacesService` subclass. The module resolves the service only through the container.

The feature flags of a space are asked from the entitlement service (`findFeatureFlagsOfAgentSpace()`), the
eligibility of a space for automated work from `DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService`: [override-points.md](override-points.md).

## Anchoring the space on the host's entity

1. Subclass `AgentSpace` at the same path under the host's root namespace (`App\Domain\AI\Entities\AgentSpaces\AgentSpace`),
   with its own set, repositories and generated model (`AgentSpaces`, `DBAgentSpace`, `DBAgentSpaces`); the subclass
   and its set repeat `use QueryOptionsTrait;`.
2. Add the reference to the host's entity there: its column, its relation, its unique key.
3. **Ownership.** A space that belongs to one account for its whole life sets `accountId`. A space anchored on an
   entity whose owner may change leaves `accountId` empty, declares its own parent relation, answers
   `findOwnerAccount()` from that entity and overrides `applyReadRightsQuery()` of its repository: a copy of the owner
   that can go stale would delete the space, and its conversations, together with a former owner's account. A space
   without `accountId` is visible to nobody under the default read rights.
4. Override the facts it can answer (table above).
5. Create, update and delete the space rows where the host's entity changes. The package's integration tests use a
   fixture application that subclasses the space with a required column of its own
   (`DatabaseTestCase::insertAgentSpaceRow()` adds what the application requires).
6. The host may also derive conversation, schedule or mandate at the same path to keep its own id of the thing next to
   `agentSpaceId` for its own reads and clients; the module never reads such a column, and the host keeps it equal to
   the space. A request DTO that should filter on such a column uses `DtoQueryOptionsOfApplicationClassTrait`.

## Starting a conversation for a space

The host's start route (the controller template of this skill's SKILL.md, with its `use` lines) hands the space to
`DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationStartTrait::buildConversationStartedResponse()`:

```php
return $this->buildConversationStartedResponse($requestDto, $this->findAgentSpaceOfStartRequest($requestDto), $aiConversationAgentService);
```

`findAgentSpaceOfStartRequest()` reads the request's `agentSpaceId` under the read rights (unreadable is 403, never
an account-level start). A host that names the space by its own id in the path, or has one fixed space per account,
overrides it. Before anything is stored the module checks `requireAgentSpaceUsableByAccount()`, and for a start
without a space `requireConversationStartWithoutAgentSpaceAllowed()`. With an `entryPointKey` a recent conversation
of the same entry point and the same space (or account level) is reopened instead of a new one started.

## Tools and the space

The package's tools name their target space with the argument `agentSpaceId`
(`AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID`); a host whose tools name it by its own id overrides
`applyAgentSpaceDefaultsToToolArguments()`, `getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()` and
`findAgentSpaceOfToolCall()` in its `AgentSpacesService`. How the loop fills and checks it:
`ddd-module-agents-tools-specialist`.

## Read rights

A direct read of a space, a list and an expand join follow the read rights of its repository
(`DBAgentSpace::applyReadRightsQuery()`: the space's own account, or an admin with access to all resources). The
relation of a conversation, a schedule or a mandate to its space does not: `DBAgentSpace::lazyload()` loads the
space with the rights switched off, because a row stored for a space must not read "no space" (account level) when
the rights on the host's entity changed later.

## Traps

- **While the space lazy-loads, the rights are off for every repository.** The mapping of the host's space repository
  (`mapToEntity()`, `postProcessAfterMapping()`) must read no other entity; read the host's entity through its lazy
  relation instead.
- **Whoever may read a row sees its space with every column**, the host's own reference included. A response that
  serializes a conversation with its loaded space shows them; hide the relation or the column on output when that is
  too much.
- `isset($conversation->agentSpace)` never triggers the lazy load; a plain read does. The module relies on that in
  `findLoadedBillingEntityOfConversation()`.
- A space object without an id is never "no space": it matches no row and cannot be written.
