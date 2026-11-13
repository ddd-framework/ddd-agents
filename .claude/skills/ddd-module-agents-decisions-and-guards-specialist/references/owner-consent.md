# Owner consent

**Reader:** host developer

Contents: what it protects · the attribute is a marker · who is gated · what the tool must call · building the request · what the call returns and stores · the decision · the use record · what the model reads · consent and standing permissions · checklist

## What it protects

An irreversible or destructive step — deleting what the owner owns, replacing something published, changing what the
owner's agent space is known by outside — should run in the agent loop only after the owner explicitly agreed to
**exactly this step**, in the owner's own words, after it was explained. The agent can neither write nor fake that
agreement: the check reads the conversation's own rows, and the request describes the step from what the tool loaded.

## The attribute is a marker

`DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation` (no arguments; declaring it is `ddd-module-agents-tools-specialist`'s topic) is
read by **no package code**. It documents the tool and lets a host test freeze the set of gated tools. The gate exists
only when the tool body calls `DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService`: a marked tool that never calls it is not gated at all, and
removing the attribute from a tool that calls it changes nothing. There is no consent widget and the executor does not
check consent; it only turns an `OwnerConsentMissingException` thrown by the tool into a gate refusal.

## Who is gated

| Call | Result |
|---|---|
| no conversation in the provenance (an MCP client of the authenticated owner over HTTP gets an empty `AgentToolCallProvenance`) | ungated: the owner is the consent-giver |
| an evaluation harness's direct executor call (`AgentToolCallProvenance::$isEvalHarnessCall`) | ungated |
| a delegation child | refused: only the root conversation can ask the owner |
| site `ownerConsent` not `on` (global `off`, site `off` or `shadow`, the kill switch) | refused |
| a run without the owner present, or a purpose that writes its own owner text | refused |
| no eligible owner row after the tool's window and refusal floor | refused |
| breaker open, transport failure, refusal by the budget or envelope gate, unparsable answer, our own error | refused |
| the model answered, a probability below the request's threshold | refused — the model's own no; the refusal floor rises |
| both probabilities at or above the threshold | consent |

A refusal is either the model's own no (decided, `failure` null, `consented` false) or one of the failures on
`OwnerConsentVerdict` (`FAILURE_SITE_OFF`, `FAILURE_NO_OWNER_ROW`, `FAILURE_NO_LIVE_OWNER`, `FAILURE_DELEGATION_CHILD`,
`FAILURE_BREAKER`, `FAILURE_TRANSPORT`, `FAILURE_REFUSED`, `FAILURE_UNPARSABLE`, `FAILURE_ERROR`).

## What the tool must call

The tool method declares `OwnerConsentsService` and `AgentToolCallProvenance` as parameters; the executor fills the
provenance from the agent call (an empty instance on the HTTP path) and the service from the container.

1. Load the target the arguments name.
2. Run the tool's own refusals that cost nothing (unknown ids, a target that does not exist, a check that fails
   anyway). A consent recorded for a call that is refused afterwards would use up the owner's answer.
3. Build the `DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest` from what you loaded.
4. Take the consent step — one of two forms:
   - `OwnerConsentsService::requireOwnerConsentForStep()` throws `OwnerConsentMissingException` with the model-facing
     text; the executor answers it as a gate refusal with `MCPToolResponse::REFUSAL_OWNER_CONFIRMATION_REQUIRED`.
   - `OwnerConsentsService::reserveOwnerConsentForStep()` returns an `OwnerConsentReservation`; a tool with its own
     blocked outcome checks `isWriteAllowed()` and puts `buildModelFacingText()` into its answer.
5. Write.
6. Conclude: `OwnerConsentsService::markConsentUsed()` when the write went through **or its outcome is unknown**;
   `OwnerConsentsService::releaseConsent()` when the write reported that it changed nothing (the same owner answer then
   authorises the retry).

The reference kernel has no gated tool. In the example below every name that is not imported from the package — the
package, the DTOs, the notes service and its methods — is the **host's own** and must exist in the host; the consent
calls are the package's real signatures.

```php
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;

class HostNotesMCPPackage extends MCPPackage // the host's tool package
{
    #[MCPTool, MCPToolRequiresOwnerConfirmation]
    public function deleteHostNote(
        HostNoteDeleteRequestDto $requestDto,           // the host's DTO
        HostNotesService $hostNotesService,             // the host's service
        OwnerConsentsService $ownerConsentsService,
        AgentToolCallProvenance $callProvenance
    ): HostNotesResponseDto {                           // the host's DTO
        $note = $hostNotesService->findNoteOfCurrentAccount($requestDto->noteId);
        if ($note === null) {
            return HostNotesResponseDto::forMissingNote($requestDto->noteId); // a free refusal, before the consent step
        }
        $ownerConsentRequest = OwnerConsentRequest::forAction(
            'deleteHostNote',
            'NOTE_DELETE',
            'delete the note "' . mb_substr($note->text, 0, 80) . '"',
            'the note is gone for good and cannot be restored',
            'note:' . $note->id
        );
        // throws OwnerConsentMissingException (→ gate refusal, nothing written) unless the write is allowed
        $ownerConsentReservation = $ownerConsentsService->requireOwnerConsentForStep($callProvenance, $ownerConsentRequest);
        if ($hostNotesService->deleteNote($note)) {
            $ownerConsentsService->markConsentUsed($callProvenance, $ownerConsentReservation);
        } else {
            $ownerConsentsService->releaseConsent($callProvenance, $ownerConsentReservation);
        }
        return HostNotesResponseDto::forNotes($hostNotesService->findNotesOfCurrentAccount());
    }
}
```

## Building the request

`OwnerConsentRequest::forAction()` takes the tool name, a kind of step (telemetry only), the **target line** (the step
with its target), the **consequence line** (what it means for the owner) and a **target key** (a stable key of the
bound fields: the ids, the new value). Both lines are English and built from the loaded entities, never from the
model's arguments as prose — a yes to another target, another value or two records instead of one is then no yes to
this step. The threshold is `OwnerConsentRequest::MINIMUM_PROBABILITY` for every kind of step.

A tool package that must rebuild requests for historical calls (for a replay command of the host) implements
`DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequestProvider::buildOwnerConsentRequest()`.

## What the call returns and stores

`reserveOwnerConsentForStep()` returns an `OwnerConsentReservation` with one of three outcomes:

| Outcome | Meaning | `isWriteAllowed()` |
|---|---|---|
| `OUTCOME_UNGATED` | no consent applies (no conversation, harness call) | true |
| `OUTCOME_RESERVED` | this call holds the owner's consent; `reservedUse` is set | true |
| `OUTCOME_NOT_RESERVED` | no consent this call may use; write nothing | false |

Any exception inside is `NOT_RESERVED` (fail closed); only `AgentRunClaimLostException` crosses it.
`requireOwnerConsentForStep()` returns the same reservation when the write is allowed and throws otherwise.

What it stores, on a checked call (not ungated): one turn event of site `ownerConsent` with the verdict, both
probabilities, the threshold, the consenting owner row and the failure; on a decided no, a raised refusal floor and a
`REFUSED` use; on a consent, a `RESERVED` use and the moved window ([the use record](#the-use-record)). It writes no
entry into the decision log.

## The decision

`OwnerConsentsService::buildOwnerConsentDialogue()` reads the owner rows above the tool's window and refusal floor —
the newest `OwnerConsentDialogue::MAX_OWNER_LINES` of them — each with the assistant text right before it and, when the
row is the first owner text after an owner-question widget, that widget's question and options. Eligible are the
owner's own text rows only: typed, a picked answer, a phone transcript. Never a signal, a row the platform wrote in the
owner's name, or a row the injection screen filtered.

`ArgusAgentOwnerConsentDecision::forRequest()` asks two `noul` questions over the step, the consequence, the dialogue
and the channel (`CHANNEL_CHAT` or `CHANNEL_PHONE`): did the assistant name exactly this step and its consequence
before the owner's answer, and did the owner explicitly agree afterwards. Both must reach the threshold. The call runs
through `AgentDecisionCallsService::runDecisionBatch()` (the breaker applies) inside the turn's usage envelope.

`decideDialogue()` is the decision alone over a built dialogue, without the site gate or the event: what a host's
replay command uses to test the question on recorded conversations (it must open a usage envelope itself).

## The use record

One owner row authorises **one** successful execution of one tool. The state lives in the conversation's working
state and is written only by guarded single statements (both database engines), fenced on the run claim:

- **Window** per tool — the newest owner row a reservation or use was recorded at. A reservation succeeds only while
  the stored window is still the one the decision read; a second call on the same row, a forked call, or a stale read
  cannot reserve.
- **Refusal floor** per tool — the newest owner row the decision model refused. It only rises; no reservation records
  at or below it, and a release never lowers it.
- **Uses** keyed by tool and owner row (`OwnerConsentUse`), in the states `RESERVED`, `USED`, `REFUSED`. Marking used
  and releasing are guarded on the record still being reserved by the same tool call.

## What the model reads

`OwnerConsentReservation::buildModelFacingText()` (empty when the write is allowed) starts with NOT DONE and tells the
agent to ask the owner explicitly about exactly this step, naming the target and the consequence, and to call the tool
again with the same arguments only after the owner agreed. When the owner did agree but another call already used that
agreement, the text says this step needs an agreement of its own. The tool's description should say the same — that it
runs only after the owner agreed in their own words — so the agent asks before the first call.

## Consent and standing permissions

A standing permission (a mandate, `MCPToolRequiresMandate`) lets the executor run a class of recurring outward actions
without asking each time; it does not replace the consent step a tool body calls for one irreversible instance — a tool
may carry both. Mandates are `ddd-module-agents-autonomy-specialist`'s topic.

## Checklist

- `ownerConsent` is `on` in the host's `decisionModel.php`, and the host's model catalog reaches the decision model.
- Every gated tool calls the consent step after its free refusals and before its write, and concludes the reservation.
- A host test freezes the set of tools that carry the marker AND call the consent step, so a new destructive tool
  cannot ship without the call.
- The tool's description tells the agent to ask first.
