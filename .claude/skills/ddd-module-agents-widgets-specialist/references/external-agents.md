# External agents

**Reader:** host developer

Contents: what the package ships · the conversation an external agent creates · what changes for the agent · a task is a slice of the conversation · push notifications · what the host builds

## What the package ships

Another software agent can call the agent over a protocol such as A2A. The package ships the parts that live inside a
conversation; the protocol endpoint, the agent card, the wire serialization and the push delivery are the host's:

| Package part | Class |
|---|---|
| the initiator type | `AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT` |
| who the agent talks to | `OwnerPresence::WORKING_FOR_AN_EXTERNAL_AGENT`, derived by `AIConversationOrigin` |
| the device bracket of each request row | `OwnerDevice::externalAgent()` (`KIND_EXTERNAL_AGENT`, rendered "via external agent") |
| the rows of one task | `AIConversation::findTaskSliceMessages()` |
| push registrations | `AIConversationTaskPushNotificationConfig` (entity, own table), `AIConversationTaskPushNotificationConfigsService` |
| the push entry | `AIConversationRealtimeSignalService::publishChangeSignal()` → `dispatchTaskPushNotification()` |

## The conversation an external agent creates

The host's protocol controller starts or continues a conversation with the initiator type `EXTERNAL_AGENT` and the
device `OwnerDevice::externalAgent()` on every request row. A follow-up into an existing context keeps that
conversation's initiator. How a conversation is started and driven is `ddd-module-agents-loop-specialist`.

## What changes for the agent

`AIConversationOrigin` derives the presence `WORKING_FOR_AN_EXTERNAL_AGENT`, and `AIConversation::isUserPresentRun()`
is false for this initiator type (only a dialog without an initiator and a phone call count as a live owner):

| Effect | Where it is decided |
|---|---|
| the system segment says an external software agent sends the requests, nobody reads the chat live, answer in plain text, ask nothing that waits for a click | `OwnerPresence::toSystemSegmentText()` |
| **no owner consent:** every consent decision fails with `OwnerConsentVerdict::FAILURE_NO_LIVE_OWNER`, so a tool that needs the owner's consent can never run here | `OwnerConsentsService` |
| never supervised | `AIConversation::canBeSupervised()` |
| the reply language is not measured from the request text | `AgentReplyLanguageDecisionsService` |
| no fast service tier unless the purpose asks for it | `AIConversation::runsWithCustomerPresent()`, read by `AIConversationModelCallsService` |
| the composed-view kit is not taught, and tool results carry no `$from` and entity-reference lines | `AgentModelInput::teachesComposedViewKit()` |
| `findWidgets` is not offered (screen presences only) | `AIConversation::buildOfferedTools()` |

The docblock of `AIConversation::isUserPresentRun()` also names memory curation as a reader; the package itself has no
curation that reads it, so that effect depends on the host's memory code.

A tool that writes a widget still writes its row in such a conversation (the delivery checks only an eval call, a
sub-agent, a missing conversation, the phone line and a blank row): a tool that knows its caller may be an external
agent returns the facts in its result too, so the answer the external agent receives does not depend on a widget it
cannot render. Consent and how a consent decision runs are `ddd-module-agents-decisions-and-guards-specialist`.

## A task is a slice of the conversation

There is no task table: the conversation id is the context, the conversation id plus the task's owner message id are
the task, and `AIConversation::findTaskSliceMessages()` reads only that task's rows. How the host projects a task state
from the run state, and how a cancel stops the run and its sub-agents, is `ddd-module-agents-loop-specialist`.

## Push notifications

An external agent registers a webhook for one task. `AIConversationTaskPushNotificationConfig` holds it in its own
table — never a column on the conversation or message rows, because its bookkeeping changes on every delivery:

| Field | Meaning |
|---|---|
| `aiConversationId`, `taskUserMessageId` | the task; the conversation is the read-rights anchor |
| `url`, `token` | where to POST, and the client's own secret echoed on every POST |
| `lastDeliveredState` | the dedup gate: a state already delivered is not sent again (`wasStateAlreadyDelivered()`) |
| `deliveryAttempts`, `disabledAt` | the failure streak; `MAX_CONSECUTIVE_DELIVERY_FAILURES` disables the config; polling stays the fallback |

`recordDeliverySuccess()` and `recordDeliveryFailure()` keep that bookkeeping on the entity. The service answers the
rights-scoped reads (`findConfigsForTask()`), the worker read (`findEnabledConfigsForConversation()`) and the hot-path
probe (`hasEnabledConfigsForConversation()`). Every `publishChangeSignal()` asks the probe — memoised per conversation
and turn — and calls `dispatchTaskPushNotification()` only when a config is enabled.

## What the host builds

- The protocol controller: card, send, get, cancel, the config methods, its error codes and the task id form.
- `dispatchTaskPushNotification()` in its subclass of `AIConversationRealtimeSignalService`: queue an ids-only message
  on a transport of its own.
- The delivery handler: per enabled config, project the task fresh, skip a state already delivered, POST, record success
  or failure (a failure throws after the bookkeeping, so the transport retries).
- One worker for that transport — few processes: a dead webhook must not hold the others, and two concurrent deliveries
  of one conversation can pass the dedup.
