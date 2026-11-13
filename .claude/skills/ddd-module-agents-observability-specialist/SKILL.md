---
name: ddd-module-agents-observability-specialist
description: "Watches, debugs, records, replays and evaluates runs of the mgamadeus/ddd-agents loop. Covers the symptom table of a turn that never answers (run states, the read-only resume diagnosis, the recovery pass per case), request snapshots, the reconstructed sent payload, context composition, prompt-cache diff, integrity and delegation tree reports, turn and trace events, AGENT_TRACE lines, the console printer for a host chat or inspect command, HTML transcript, TUI view model, record and replay with the recorded-run comparison, eval safety (evalRun marker, eval tool blocks, outbound recipient guard), conversation metrics, costs, scorecards, building an eval runner, the control panel statistics and the admin drive with truncate. Use when a turn never answers, a run stays RUNNING, IDLE, FAILED or AWAITING_DELEGATES, what the model saw is needed, a console chat is built, a run is recorded and replayed to prove a refactor, a test run must not send mail or SMS, agent time or cost is measured, or an eval runner is built."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Observability Specialist

How to see what a run of the agent did and what the model saw, how to reproduce it, and how to measure and evaluate
the agent — on top of the services and value objects the package ships. The package ships **no console command**:
every inspect, diagnose, chat, replay or evaluation command is a console command of the host, built on the contracts
below.

> Installation, transports, workers and the full host-duty table: `ddd-module-agents-specialist`. How a turn runs,
> is claimed, released and recovered: `ddd-module-agents-loop-specialist`.

## Scope

| This skill | Another skill |
|---|---|
| the symptom table "a turn that never answers" and the read-only diagnosis | run claims, release, recovery mechanics (`ddd-module-agents-loop-specialist`) |
| request snapshots, the reconstructed payload, cache diff, integrity report | what goes into the model input, context reduction (`ddd-module-agents-context-specialist`) |
| turn events, trace events, `AITraceLogTrait` | the task plan and its trace while working on a plan (`ddd-module-agents-loop-specialist`) |
| the console printer, console stream, HTML transcript, TUI view model | the chat HTTP routes and controller traits (`ddd-module-agents-specialist`) |
| record and replay, the recorded-run comparison | delegation and sub-agent runs themselves (`ddd-module-agents-autonomy-specialist`) |
| eval safety: `evalRun`, eval tool blocks, the outbound recipient guard | owner consent and mandates for real runs (`ddd-module-agents-decisions-and-guards-specialist`) |
| conversation metrics, cost reads, scorecard worker | usage envelopes and cost summaries of a turn (`ddd-module-agents-loop-specialist`) |
| the control panel statistics, the admin drive and truncate | widgets and the realtime push (`ddd-module-agents-widgets-specialist`) |

## Rules that always hold

1. **Diagnose read-only first.** `AIConversationResumeDiagnosis::buildFor()` replays the resume's two loads and says
   whether a resume would drive a turn — without driving one. Run it (and read the stored state columns) before you
   dispatch, retry or edit anything.
2. **A repair is a recovery pass, never a hand-edited row.** The package repairs every stuck state through a service
   method (`findStuckConversationIds()`, `findConversationIdsWithInboundLeftIdle()`, `retryUnansweredTurn()`, …);
   the host's console commands call them. Writing `runState` or `resumeAttempts` by SQL skips the claim fence.
3. **The slim request snapshot is not the payload.** A request snapshot stores model, effort, times, hashes and
   timings. The full sent payload and raw response exist only when `storeRequestSnapshot` was on for that
   conversation before the call; otherwise `reconstructSentPayloadForDisplay()` rebuilds it, and the rebuild shows
   today's system prompt, skills and space state, not necessarily what was sent.
4. **Every console turn opens a usage envelope.** `generateAgentReply()` makes model calls; outside a worker nobody
   opens the envelope for you (`AIUsageEnvelopesService::openEnvelope()` with
   `AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN`, closed in a `finally`). Without one the calls are not grouped
   under the turn and a budget lift granted on the envelope does nothing; the envelope middleware of the workers is
   a host duty of `ddd-module-agents-specialist`.
5. **The synchronous console path never drives a conversation a worker may hold.** `generateAgentReply()` takes no
   run claim. Drive only an IDLE conversation from the console; otherwise dispatch with
   `resumeConversationIfReady()` (`async: true`) and inspect afterwards.
6. **An eval run is marked before its first drive.** `RunSettings::$evalRun` decides the tool block, the outbound
   guard's eval context, the report-mail skip and the statistics exclusion. A conversation stamped later has already
   run unguarded.
7. **The outbound guard guards only the send points that call it.** `EvalOutboundRecipientsService` checks a
   recipient when a host's mail, SMS, call, push or webhook sender asks; a sender that does not ask is unguarded.
8. **Record and replay in one console process.** The recorders are process statics
   (`ArgusApiOperations::$transportRecording`, `MCPToolExecutorService::$toolResultRecording`,
   `RecordedRunClockAndIdSources`). A job consumed by a worker resets the Argus and tool recorders, so a recording
   made in a worker loses them when the next job starts; the pinned clock and id sources are not reset there. Run
   the turn transport on `sync://` in the recording process.
9. **No closures in the host's commands** (the package's own rule): pass a value object or call a named method; a
   short callback for `array_map` / `usort` is fine.

## Traps

- `AIConversationResumeDiagnosis::buildFor()` replaces `$conversation->messages` and `compactionNodes` on the object
  you pass. Reload the messages (`findMessagesInOrder()`) before you render a transcript from the same object.
- The recovery selectors read only the rows of the process's own environment
  (`AIConversationsService::currentEnvironment()`); a conversation written under another environment name is never
  recovered by this process.
- `AGENT_TRACE` is read from the raw process environment first; an inline `AGENT_TRACE=1` before the command works,
  and the lines are logged at INFO — a logger configured for errors only drops them.
- A recording made per owner turn needs a turn set on replay (the instance property `turnOrdinal` of the installed `ArgusTransportRecording`: `$argusTransportRecording->turnOrdinal`) and the other
  way round; the player refuses to match across turns.
- The comparison covers the conversation tree only: a supervisor conversation is outside it, so record without the
  supervisor.
- `AgentConversationScorecardsService` writes nothing by default; the scorecard worker runs and logs `scorecard.done`
  with no row behind it until the host overrides it.

## Minimal template: the host's inspect command

The duty "inspect or diagnose one conversation" (host-duty table of `ddd-module-agents-specialist`): read-only,
rights lifted for the read, exits non-zero on an integrity violation.

```php
<?php

declare(strict_types=1);

namespace App\Symfony\Commands\AI;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationDelegationTreeReport;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityReport;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosis;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:agent:inspect-conversation', description: 'Diagnoses one agent conversation, read-only')]
class AgentConversationInspectCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('conversationId', InputArgument::REQUIRED, 'The conversation to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $conversation = $aIConversationsService->find((int)$input->getArgument('conversationId'), false);
            if (!$conversation instanceof AIConversation) {
                $output->writeln('<error>no such conversation</error>');
                return Command::FAILURE;
            }
            /** @var AIConversationDelegationBatchesService $batchesService */
            $batchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
            $output->writeln(AIConversationResumeDiagnosis::buildFor($conversation, $batchesService)->toLlmMarkdown());

            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $integrityReport = AIConversationIntegrityReport::buildFromRecords(
                $aIConversationMessagesService->findIntegrityProjectionRows($conversation),
                $aIConversationTurnEventsService->findIntegrityRecordsOfConversation($conversation),
                $conversation->runState
            );
            foreach ($integrityReport->violations as $integrityViolation) {
                $output->writeln("<error>{$integrityViolation->check}</error> {$integrityViolation->detail}");
            }

            // the diagnosis left the bounded tail on the object: reload every row before rendering
            $conversation->messages = $conversation->findMessagesInOrder(null, null, false);
            $output->writeln(AIConversationDelegationTreeReport::buildFor($conversation, $batchesService)->toLlmMarkdown());
            /** @var AgentMessagePrinterInterface $agentMessagePrinter */
            $agentMessagePrinter = DDDService::instance()->getService(AgentMessagePrinterInterface::class);
            $output->write($agentMessagePrinter->renderTranscript($conversation, true, false, false));
            return $integrityReport->hasViolations() ? Command::FAILURE : Command::SUCCESS;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
```

The command name is the host's choice. Add a prompt-cache section with `PromptCacheDiffReport::fromMessages()` and a
stuck-run list with `AIConversationStuckRunsReadService::findStuckRuns()` the same way
([debugging-a-turn](references/debugging-a-turn.md)).

## Task or symptom → reference

**Host developer**

| Task or symptom | Reference |
|---|---|
| The owner wrote and the agent never answers; a run stays RUNNING, IDLE, FAILED, QUEUED or CANCELLED; a parent waits for its sub-agents forever | [a-turn-that-never-answers](references/a-turn-that-never-answers.md) |
| What did the model see on this call; which tools were offered; why did the cache miss | [debugging-a-turn](references/debugging-a-turn.md) |
| Build a console chat, an inspect command, an HTML transcript or a TUI | [console-chat-on-the-printer](references/console-chat-on-the-printer.md) |
| Record a run and replay it against the recording to prove a refactor | [record-and-replay](references/record-and-replay.md) |
| Keep a test run from writing to a real profile, mailbox or phone | [eval-safety](references/eval-safety.md) |
| Agent time, owner time, tool failures, cost per conversation, scorecards | [metrics-and-cost](references/metrics-and-cost.md) |
| Build an evaluation runner, judge and suite | [building-evaluation-in-the-host](references/building-evaluation-in-the-host.md) |
| Build an admin control panel; drive, edit or branch a conversation as an admin | [control-panel-and-admin-drive](references/control-panel-and-admin-drive.md) |
| Read the turn events and the trace events of a conversation | [trace-and-turn-events](references/trace-and-turn-events.md) |

**Package maintainer**

| Task | Reference |
|---|---|
| Add a symptom or a diagnosis field; keep the diagnosis in step with the resume | [a-turn-that-never-answers](references/a-turn-that-never-answers.md) |
| Add a turn-event kind or a trace-event type; keep the writers fail-open | [trace-and-turn-events](references/trace-and-turn-events.md) |
| Add a column the comparison must ignore, a timing key or an id family | [record-and-replay](references/record-and-replay.md) |
| A new outbound channel or a new eval marker on `RunSettings` | [eval-safety](references/eval-safety.md) |
| Change a metrics definition (bump `AIConversationMetrics::METRICS_VERSION`) | [metrics-and-cost](references/metrics-and-cost.md) |
| Change the printer, the transcript or the view model | [console-chat-on-the-printer](references/console-chat-on-the-printer.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, override points (`AgentConversationScorecardsService`,
  `AgentEvalOutboundGuardHooksService`, `AgentEvalAccountsService`, the printer alias), the host-duty table and the
  recovery command templates.
- `ddd-module-agents-loop-specialist` — run claims, release, failed turns that stay owed, the recovery passes, the
  task plan and its trace, usage envelopes and cost summaries.
- `ddd-module-agents-context-specialist` — what the model sees on each call, the prompt cache and the stable prefix,
  context reduction.
- `ddd-module-agents-autonomy-specialist` — delegation, sub-agent deadlines, owner reports, schedules.
- `ddd-module-agents-decisions-and-guards-specialist` — decision sites and their statistics, injection screening.
- `ddd-module-agents-tools-specialist` — tool attributes such as `MCPToolEffect`, which the eval read-only block reads.
- `ddd-module-agents-widgets-specialist` — the realtime signal a frontend waits for.
