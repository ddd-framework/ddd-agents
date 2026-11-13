# Eval safety

**Reader:** host developer and package maintainer

Contents: the layers · the eval marker · the eval tool block · the outbound recipient guard · the host's part of the guard · what else the marker switches · what no layer covers · for the package maintainer · full class names

An evaluation drives the real agent with real tools against a test agent space. What keeps it from writing to a
customer's profile, mailing a real person or calling a real phone is a set of layers, each switched by the eval
marker on the conversation. The runner that sets them is the host's ([building-evaluation-in-the-host](building-evaluation-in-the-host.md)).

## The layers

| Layer | Stops | Lives in |
|---|---|---|
| the eval marker | nothing by itself; it switches every layer below | `RunSettings::$evalRun` |
| the eval tool block | a named tool, or every tool that is not a declared read | `RunSettings::setEvalToolBlock()`, read by `MCPToolExecutorService` |
| the outbound recipient guard | a mail, SMS, call, push, in-app ring or webhook to anyone but a test sink | `EvalOutboundRecipientsService` at the host's send points |
| the report-mail skip | the owner report mail of an eval run | `AIConversationOwnerReportDeliveryService` |

## The eval marker

`RunSettings::$evalRun = true` on every conversation an eval runner creates, obtains or forks, stamped and persisted
**before the first drive** (pass the run settings to `driveConversation()`, or set them on the conversation before
`generateAgentReply()`). A sub-agent belongs to an eval run when its root carries the marker
(`AIConversationOwnerReportDeliveryService::belongsToAnEvalRunTree()`); `AIConversation::isEvalRun()` reads the
conversation's own row only. `RunSettings::$evalSeededHistory` marks a conversation the eval seeds as the owner's past
history: an eval run is kept out of the cross-conversation search, a seeded history stays findable.

## The eval tool block

`RunSettings::setEvalToolBlock($blockedToolNames, $blockedToolSimulatedErrors, $everyWriteToolBlocked)` stores the
case's block on the run settings:

- a blocked tool answers its simulated error text, else "Tool '…' is blocked in this evaluation run …", and does not
  run;
- with `$everyWriteToolBlocked`, every tool that `MCPToolEffectCatalog::isReadOnlyToolName()` does not declare a read
  answers that it changed nothing and was not executed;
- the executor reads the block from the **root** conversation's run settings (`RunSettings::describeEvalToolBlock()`):
  the live root object when the call is the root's own, else the root loaded once per process for a sub-agent and
  kept until the next worker job (`MCPToolExecutorService::resetToolBlocksForNewJob()`). A worker that resumes the
  conversation later and every sub-agent refuse the same tools; a block changed after a sub-agent's first call is not
  seen by that process;
- a console process can also set the process-static block `MCPToolExecutorService::$blockedToolNames`,
  `$blockedToolSimulatedErrors` and `$everyWriteToolBlocked` (same wording through
  `RunSettings::describeEvalToolRefusal()`); a worker job clears it;
- a conversation without the eval marker blocks nothing, whatever the stored names say.

Block every tool that writes to a third party (a public profile, a listing, a social post, a payment) in every case
by default; a case whose purpose is that write opts in per tool and restores what it changed. Which tools those are
is the host's list.

## The outbound recipient guard

`EvalOutboundRecipientsService` is the single check a host's outbound channels call before they send. A send is in
**eval context** when any of these holds (`EvalOutboundRecipientCheck::EVAL_CONTEXT_REASON_*`):

| Reason | When |
|---|---|
| `evalRun` | a tool call of an eval run is executing in this process (`EvalRunOutboundScope`, opened by `AgentToolCallExecutionService` around each tool call), or the caller passes such a conversation |
| `evalAccount` | the account of the send, the account owning the scope passed, or the authenticated account is an eval account |
| `throwawayScope` | the scope passed is a throwaway set-up |

In eval context every recipient must be a test sink, else the send is **blocked**: nothing leaves, the check is
logged as a warning on the issues channel with masked recipients, and `buildBlockedNotice()` gives the text to report.

| Method | Channel | Test sink |
|---|---|---|
| `checkEmailRecipients($addresses, $accountId, $scope)` | `CHANNEL_EMAIL` | an address in `allowedEmailAddresses`, or a domain in `allowedEmailDomains`; a recipient string with several addresses is checked address by address |
| `checkPhoneRecipient($channel, $number, …)` | `CHANNEL_SMS`, `CHANNEL_PHONE_CALL` | a number in `allowedPhoneNumbersE164` or the host's additional numbers, compared normalized |
| `checkDeviceRecipient($channel, …)` | `CHANNEL_PUSH_NOTIFICATION`, `CHANNEL_APP_CALL_RING` | none: always blocked in eval context |
| `checkWebhookRecipient($url, …)` | `CHANNEL_A2A_WEBHOOK` | none: always blocked in eval context |

Each returns an `EvalOutboundRecipientCheck` (`isBlocked()`); `throwIfBlocked()` turns a blocked check into an
`EvalOutboundRecipientBlockedException` for a channel whose caller reports the outcome to the agent. Outside eval
context every check passes. A send a worker makes later is checked where it is **queued**, while the eval context is
still known.

The test sinks are the config file `evalOutboundRecipients.php` (`AI.evalOutboundRecipients`): the package's file is
empty, so with no host file nothing may be sent in eval context. A host replaces the file whole.

## The host's part of the guard

`AgentEvalOutboundGuardHooksService` (same-path subclass, override point of `ddd-module-agents-specialist`):

| Method | Default | The host returns |
|---|---|---|
| `findConfiguredEvalAccountIds()` | none | the accounts its evaluations run on |
| `findScopeAccountId($scope)` | unknown | the account owning its scope entity |
| `isThrowawayScope($scope)` | never | true for a scope made for a test and marked for deletion |
| `normalizePhoneNumber($number)` | digits and `+` | its own normal form |
| `findAdditionalAllowedPhoneNumbers()` | none | a shared test line |

Without the subclass the guard knows only eval run trees. The host also calls the guard: every mail, SMS, call and
push sender of the host passes its recipients through it before the provider — the package cannot see a send it
does not make.

## What else the marker switches

- The owner report mail of an eval run is never sent (`composeAndSendReportEmail()` skips the tree); the owed-report
  recovery never selects an eval run's tree (`AIConversationsService::buildNotAnEvalRunTreeSqlPredicate()`), and the
  two owed-turn passes never resume an eval conversation (`DBAIConversations::buildNotAnEvalRunPredicate()`): a
  runner drives its own turns.
- The decision statistics (`AgentDecisionStatisticsService`) leave eval rows out; `AgentEvalAccountsService::getEvalAccountIds()`
  (same-path subclass) adds the host's eval accounts.
- Turn events carry `evalRun`, so an analysis can filter them.
- `RunSettings::$evalStopAfterToolName` ends the owner turn after that tool completed — only on an eval conversation
  — so a runner can inject the next owner message between two stages.
- An eval schedule (`runSettings.evalRun` on the schedule) may be fired without the manual fire interval.

## What no layer covers

- A tool that is not blocked writes as it always does: an eval space connected to a real third-party profile is
  changed by an unblocked write tool.
- A host sender that does not call the guard sends.
- Rows the run created stay; deleting a throwaway space and its rows is the host's job.

## For the package maintainer

- A new outbound channel in the package gets a `CHANNEL_*` constant and a check method, and its send point calls it
  where the send is queued.
- A new eval-only switch on `RunSettings` is read only while `$evalRun` is true, like `describeEvalToolBlock()` and
  `evalStopAfterToolApplies()`: a production row with a stray value must change nothing.
- The worker state resetter forgets open eval holds (`EvalRunOutboundScope::forgetScopesOpenInThisProcess()`); a new
  process-wide eval state is reset there too.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\Conversations\AIConversation` ·
`DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationsService` ·
`DDD\Domain\AI\Services\Decisions\AgentDecisionStatisticsService` ·
`DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService` ·
`DDD\Domain\AI\Services\Eval\AgentEvalOutboundGuardHooksService` ·
`DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallExecutionService` ·
`DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations` ·
`DDD\Domain\AI\Exceptions\Evals\EvalOutboundRecipientBlockedException` ·
`DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalOutboundRecipientCheck` ·
`DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService` ·
`DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalRunOutboundScope` ·
`DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog` · `DDD\Presentation\Mcp\Services\MCPToolExecutorService` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RunSettings`
