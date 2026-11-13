# Control panel and admin drive

**Reader:** host developer and package maintainer

Contents: what ships · the control panel statistics · the admin drive · edit and branch: truncate · the admin's run settings · for the package maintainer · full class names

An admin surface over the agent has two halves: a read-only control panel (usage, volume, active accounts, heavy
tools, top conversations) and a drive that lets an admin start, continue, edit and branch any account's conversation.
The package ships the reads, the value objects and the drive; the admin controller, its routes and its screens are
the host's.

## What ships

| Ships | The host writes |
|---|---|
| the finders of the panel on `AIConversationsService` and `AIConversationMessagesService` | the admin controller, its routes and the admin gate |
| `AgentControlPanelStatistics` and its parts in the folder `Entities/Conversations/ControlPanel` | the AI usage per bucket (from the usage logs of `ddd-ai`), the assembly of the payload |
| `AIConversationAgentService::driveAdminConversation()` / `driveConversation()` with `AIConversationDriveScope::ADMIN` | the admin screens (list, inspector, composer) |
| `AIConversationMessagesService::truncateFrom()` | the edit and delete-from routes |
| the request and response DTOs of the sent-payload view | the route that returns it ([debugging-a-turn](debugging-a-turn.md)) |

## The control panel statistics

`AgentControlPanelStatistics` is the payload of one panel for a range (`$rangeFrom`, `$rangeTo`) and a
`$granularity` (`GRANULARITY_DAY`, `GRANULARITY_WEEK`, a week keyed by its Monday):

| Part | Read with | Notes |
|---|---|---|
| `$conversationsPerBucket` | `AIConversationsService::findConversationCountsPerBucket($from, $to, $byWeek)` | conversations started; sub-agents left out |
| `$activeAccountsPerBucket` | `findActiveAccountCountsPerBucket()` | an account is active in a bucket when it sent at least one owner message; a week is a real distinct count |
| `$weeklyActiveAccounts`, `$monthlyActiveAccounts` | `countDistinctActiveAccounts($from, $to)` over 7 and 30 days up to `rangeTo` | |
| `$toolResponseStats` | `AIConversationMessagesService::findToolResponseStats($from, $to)` | per tool: calls and the sum, average and maximum of the result size — the tools whose results to shrink first |
| a drill-down of one tool | `findLargestToolResponseSamples($toolName, $from, $to, $limit)` | the largest results with message and conversation id |
| `$topConversations` | `AIConversationsService::findTopConversationStats($from, $to, $orderBy, $limit)` | per root conversation the whole tree's messages, tokens and cost; `$orderBy` is `totalCostInUSD`, `totalTokens` or `messageCount` |
| `$aiUsagePerBucket` | the host, from the usage logs | tokens and cost per bucket (`AgentAIUsagePerBucket`) |
| `$toolResponseDataAvailable` | the host sets it false when the tool finder throws | the tool-size reads need generated columns of the message table; a database without them throws |
| `$satisfactionCaptureAvailable` | stays false | no feedback capture exists yet |

The per-bucket finders take `$excludedAccountId` and `$excludeTestUsers` to turn the fleet figures into customer
figures; test accounts are a join to the host's tables, returned by the host's subclass of the repository
(`buildTestAccountsExclusionSqlFilter()`, override point of `ddd-module-agents-specialist`).
`findEarliestConversationDate()` tells "no conversation in this period" from "the records do not reach back so far".
The reads are fleet-wide: the controller is admin-only.

## The admin drive

`AIConversationAgentService::driveAdminConversation($aiConversationId, $agentSpace, $message, …)` is
`driveConversation()` with `AIConversationDriveScope::ADMIN`. Compared with a customer drive (`CUSTOMER`):

| | ADMIN | CUSTOMER |
|---|---|---|
| runs as | the conversation's account, else `$runAsAccount` (a parameter of `driveConversation()` only; the `driveAdminConversation()` wrapper does not pass it), else the owner of the space (impersonated, restored in `finally`) | the authenticated account |
| reads | any account's conversation (rights lifted for the lookup) | its own, by the read rights |
| budget | may lift the check (`$budgetBypass`), stored on the conversation so the worker honours it | always enforced |
| model | any agent-eligible model | only the customer-selectable list |
| truncate / branch | `$truncateFromIndex` | ignored |
| sync or async | synchronous on an `IDLE` root; forced async when the conversation is not idle or is a sub-agent | always async |
| owner device | `OwnerDevice::adminConsole()`, the message marked as written by the platform | the owner's device |

The synchronous admin turn opens its own `ENVELOPE_AI_CONVERSATION_TURN` envelope and dispatches compaction instead
of running it inline. `$compactionSettings` and `$contextRetentionSettings` let an admin start force low thresholds or
full request snapshots on a fresh conversation; on a continue the stored skill-loading strategy stays.
`$supportOverride` lets an admin's conversation run for a space whose automation is not eligible — audited, never
inherited by a customer's sub-agents. A host's start route passes the space and the first message; its continue route
passes the id.

## Edit and branch: truncate

`AIConversationMessagesService::truncateFrom($conversation, $fromIndex)` marks every row from `$fromIndex` on as
truncated (`truncatedAt`) under the message-index lock, and every sub-agent conversation those rows spawned, through
every level. Nothing is deleted; every read leaves truncated rows out. The admin drive with `$truncateFromIndex`
truncates, reloads the compaction cover and the tail without the registry cache, clears a task plan the cut reaches,
then appends the new owner message and drives — "edit this message" is a truncate from its index plus a new message;
"delete from here" is the truncate alone. A host's inspector can still show truncated rows by reading them on
purpose; the metrics and the integrity report treat them as gone or mark them.

## The admin's run settings

The switches an admin sets on one conversation (decision sites off or on, reasoning effort, wire API, …) are a
`RunSettingsOverride` applied by `AIConversationsService::applyAdminRunSettingsOverride()`, each stamped with who set
it and when; `driveConversation()` accepts one with the drive. What each switch does: the decision sites of
`ddd-module-agents-decisions-and-guards-specialist`, the wire settings of `ddd-module-agents-context-specialist`.

## For the package maintainer

- The panel finders are raw SQL over the package's tables; a figure that needs a host table goes through an
  `AgentApplicationSqlFilter` the host's repository returns, never a host table name in the package.
- A new admin-only seam of the drive is gated on the ADMIN scope or an admin session
  (`AuthService::isCurrentSessionAdmin()`) and is stored on the conversation when the worker must honour it.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Services\Conversations\AIConversationAgentService` ·
`DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope` ·
`DDD\Domain\AI\Services\Conversations\AIConversationMessagesService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationsService` ·
`DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentAIUsagePerBucket` ·
`DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter` ·
`DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentControlPanelStatistics` ·
`DDD\Infrastructure\Services\AuthService` · `DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride`
