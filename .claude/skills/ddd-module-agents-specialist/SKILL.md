---
name: ddd-module-agents-specialist
description: "Installs, wires and runs the agent of mgamadeus/ddd-agents in a DDD host and routes to the eight agent topic skills. Covers the installation checklist, the chat controller traits and start route, the agent space (agentSpaceId, account level, its host entity), override points by same-path subclass, entitlement interfaces, listeners, the 14 config files, host wording, persona and egress, the six Messenger transports, the usage envelope middleware, workers and stop grace, the package's console commands (schedule fire, finalize, event purges, maintenance, diagnosis), the host duties with command templates (schedule state sweep, owed reports and turns, reaper), the cron list, table names, persisted class names, the package suite and the source-ownership manifest. Use when adding the agent to an application, wiring agent transports or workers, schedules never fire, a crashed turn never answers, agent event tables grow, overriding an agent service, naming agent tables, or asking which agent skill explains a file."
metadata:
  author: mgamadeus
  version: "0.2.0"
  module: mgamadeus/ddd-agents
---

# Agents module: install, wire and run

The agent of `mgamadeus/ddd-agents`: conversations, turns, tools, skills, purposes, widgets, delegation and schedules
on top of `mgamadeus/ddd-ai`. This skill answers **how a host installs, wires and runs it, and what the host must
provide**, and routes every other question to one of eight topic skills.

> Base patterns: the core skills of `mgamadeus/ddd` (services, entities, endpoints, message handlers, console
> commands). Models, prompts, usage envelopes and the language-model trait: `mgamadeus/ddd-ai`. Argus repositories:
> `mgamadeus/ddd-argus`.

## Scope

This skill owns the module class, agent spaces, entitlements and their interfaces, the scope filter, the read rights
of the agent tables, environments, the voice-channel override point, source-root discovery, the customer's model choice,
transports and handler routing, the worker state resetter, listeners, table names, the chat controller traits and
their DTOs, the naming, wording, persona and egress configs, and `examples/`. The full file list is the manifest
[references/source-ownership.tsv](references/source-ownership.tsv).

## Rules that always hold

1. **The package ships commands; the host runs them.** The commands that fire schedules, finalize conversations,
   purge the event tables, maintain old rows and diagnose a conversation are the package's (section "Console commands
   the package ships"); a scheduled one runs only from the cron line the host registers (`templates/crons.sh`).
   Sweeping schedule state, delivering owed reports and resuming owed turns, and reaping dead workers and stale
   sub-agent groups are host commands. Without the cron lines and these commands unattended work never runs, a
   crashed turn never answers and the event tables grow without bound. Templates: `templates/`; contracts:
   [references/host-duties.md](references/host-duties.md).
2. **The host provides the envelope middleware.** Every agent message that makes model calls declares its usage scope
   with `WithAIEnvelope` (all but `AIConversationReportEmailSendMessage`);
   no package ships the Messenger middleware that opens it. Without it the worker's model calls run outside any
   envelope: none is attributed to one, and the handlers' budget lifts (`grantBudgetBypassIfAnEnvelopeIsOpen()`) do
   nothing, so a host budget gate refuses compaction, finalize, summary and screening for an account over budget. The
   calls throw only when the host's own `AIModelCallGateService` requires an envelope.
3. **The framework registers no agent transport.** The host copies `AgentMessengerTransports::ALL` and
   `AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS` into its `messenger.yaml`; an unrouted message runs
   synchronously in the request that dispatched it.
4. **One turn per message.** The turn handler's PHP time limit is 1800 s of execution time, but model and tool waits
   do not count toward it, so a turn can run longer by the clock. The stop grace of the turn workers and
   `busySilentSeconds` must exceed 1800 s at least, or a deploy kills running turns.
5. **Overrides by same-path subclass, reached through the container.** `App\Domain\AI\…\X` replaces
   `DDD\Domain\AI\…\X`; build entities with `X::newInstance()`, services with `DDDService::getService()`, never `new`.
6. **A host config file replaces the package's whole.** Keys are never merged; copy the file, then change it.
7. **An empty `agentSpaceId` means account level**, never "every space". The package never creates a space.
8. **Envelopes are opened by the caller.** The chat traits open one when none is open, except the start route, which
   the host declares and wraps itself; the turn handler opens a fallback one. Whether a call without an envelope is
   refused is the host's policy (its `AIModelCallGateService`), not the package's.
9. **No application words in the package.** The host declares its words in `agentApplicationWording.php`, its names in
   `agentApplicationNames.php`, its persona in `agentPersona.php`.
10. **Code rules of the module:** no closures in regular methods, no `private`, no `final`, `declare(strict_types=1)`,
    one class per file, classes only in `AgentsModule::OWNED_NAMESPACES`.

## Traps

- **Model regeneration is the host's script.** A host with `DATABASE_TABLE_PREFIX` or `tableNames` entries adds
  `app:generate-doctrine-models-for-entities` to its own Composer `post-install-cmd` and `post-update-cmd` (no package
  declares a script) and never runs Composer with `--no-scripts`: the vendor models keep the shipped names, and every
  raw statement of the module hits a missing table.
- **The space lazy-load lifts the rights for every repository.** A host's space repository reads no other entity
  while it maps a row.
- **An MCP route without a firewall** publishes the tool catalogue: `initialize` and `tools/list` check nothing.
- **A host root namespace other than `App\`** gets no automatic same-path override and no folder discovery: it maps
  every class it replaces in its `services.yaml` and binds tool packages by class
  ([references/override-points.md](references/override-points.md)).
- **`QueryOptionsTrait` is not inherited.** A host subclass of a filterable entity or set repeats it.
- **The finalize and schedule scans are not environment-scoped.** Several environments on one database run those
  commands in one environment only.
- **`AuthService::setAccount()` takes its argument by reference:** pass a variable, never a literal `null`.

## Minimal template: the host's chat controller

```php
namespace App\Presentation\Api\Agents\Controller;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationStartTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostResponseDto;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/conversations')]
class AgentConversationsController extends HttpController
{
    use AgentConversationReadsTrait, AgentConversationWritesTrait, AgentConversationStartTrait;

    #[Post('/start')]
    #[Summary('AIConversation Start')]
    public function startConversation(
        AIConversationStartWithMessagePostRequestDto $requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartWithMessagePostResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            // null = account level; a host with spaces passes the space the request names
            return $this->buildConversationStartedResponse($requestDto, $this->findAgentSpaceOfStartRequest($requestDto), $aiConversationAgentService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }
}
```

Besides this controller: an authentication subscriber that calls `AuthService::setAccount()`, the transport block,
the two middlewares, the host commands the package does not ship and the cron list. The reference kernel (`examples/reference-kernel`) is a
complete host without spaces that runs only inside the package (its bootstrap loads the package's test autoloader);
an application copies its `src/` and `config/` as a model and writes its own bootstrap.
[references/installation.md](references/installation.md) is the checklist.

## Which topic skill answers which question

| Question | Skill |
|---|---|
| How does a turn run from the owner's message to the answer? Run claims, release, failed turns, message writes, the task plan, cost summaries | `ddd-module-agents-loop-specialist` |
| What does the model see on each call? System prompt, prompt cache, context reduction, skill eviction, recall and search, memories, owner presence and time | `ddd-module-agents-context-specialist` |
| How do I write an agent skill or a purpose, bind it to tools, gate it, override a package skill? Done criteria, variants | `ddd-module-agents-skills-and-purposes-specialist` |
| How do I write an MCP tool the agent and an outside client can call? Attributes, DTOs, results, time limits, parallel calls, closed owner questions | `ddd-module-agents-tools-specialist` |
| How does the agent show something in the conversation and read the owner's answer? Widgets, the composed view, signals, realtime push, external agents | `ddd-module-agents-widgets-specialist` |
| How does the agent work without the owner? Delegation, schedules and their fire contract, eligibility, mandates, the supervisor, owner reports | `ddd-module-agents-autonomy-specialist` |
| How do the decision layer and the guards work? Decision sites, injection screening, owner consent, circuit breaker | `ddd-module-agents-decisions-and-guards-specialist` |
| How do I watch, debug, record and replay, and evaluate a run? A turn that never answers, diagnostics, trace and turn events | `ddd-module-agents-observability-specialist` |

A topic two skills touch has one owner: firing schedules and eligibility are explained in the autonomy skill (here:
the command and its cron line only); parallel tool calls in child processes in the tools skill (here: the package's
child-process command and the host's console script); a turn that never answers in the observability skill (here:
the recovery commands).

## Task or symptom → reference

### Host developer

| Task or symptom | Reference |
|---|---|
| Add the agent to an application; the first request | [installation.md](references/installation.md) |
| Replace an agent service, gate by flags or rights, add a listener, join a host table into a statement | [override-points.md](references/override-points.md) |
| Which config file does what, which skill explains it; the host's words, persona, egress; environment variables | [config-map.md](references/config-map.md) |
| Configure transports, routing, middlewares, worker pools, stop grace, retries | [transports-and-workers.md](references/transports-and-workers.md) |
| A worker's model calls are attributed to no envelope, or refused over budget although the handler lifts the budget | [transports-and-workers.md](references/transports-and-workers.md) |
| Schedules never fire; owed reports never arrive; a turn whose worker died never answers; conversations never finalize; event tables grow without bound | [host-duties.md](references/host-duties.md) |
| Which console commands the package ships and which need a cron line | section "Console commands the package ships" below |
| Write the host commands the package does not ship; register the cron list | [host-duties.md](references/host-duties.md), `templates/` |
| Model "what the agent works for": spaces, account level, starting a conversation for a space, tools that name a space | [agent-space.md](references/agent-space.md) |
| Missing agent tables, wrong table names, a table prefix, a renamed persisted class | [tables-and-persisted-names.md](references/tables-and-persisted-names.md) |
| Build the first tool, skill, purpose and widget | [first-capability-end-to-end.md](references/first-capability-end-to-end.md) |
| Start a purposed conversation, with or without an agent space | [first-capability-end-to-end.md](references/first-capability-end-to-end.md) |

### Package maintainer

| Task or symptom | Reference |
|---|---|
| Run the package suite; a contract or kernel test fails | [package-suite.md](references/package-suite.md) |
| Which skill must change when a file changes; a file without an owner; a dead rule | [source-ownership.md](references/source-ownership.md) |
| Rename a persisted class or a message class | [tables-and-persisted-names.md](references/tables-and-persisted-names.md) |

## Console commands the package ships

All on the base `AgentConsoleCommand`, in `src/Symfony/Commands/Agents/`. Shipping a command does not run it: a
command with an interval below runs only from the cron line the host registers (`templates/crons.sh`). An application
replaces a command by a subclass outside the namespace `DDD\Symfony\Commands` that repeats the `AsCommand` attribute
with the same name.

| Command | What it does | On a schedule | Writes |
|---|---|---|---|
| `app:ai-conversation:fire-due-schedules` | expires, claims and spawns the due schedules; counts each skip reason | every minute to every five minutes | yes |
| `app:ai-conversation:mark-finalized` | sets `finalizedAt` on conversations idle past the window (`--idle-minutes`, default 60), one UPDATE | every five minutes | yes |
| `app:ai-conversation:compact-finalized` | dispatches one finalize job per finalized conversation that owes its completion compaction (`--batch`, default 50); `--inline` runs the chain of one conversation in the console | every five minutes | yes (`--inline` makes model calls) |
| `app:ai-agent:trace-events-purge` | deletes trace events older than `--days` (default 180) in batches | daily | yes |
| `app:ai-agent:turn-events-purge` | deletes turn events older than `--days` (default 180), then the snapshots no event references | daily | yes |
| `app:ai-agent:request-snapshot-debug-payloads-strip` | removes the full request and raw response from request snapshots older than `--olderThanDays` (default 2); resumes from a cursor | optional, nightly, `--maxSeconds` below 360 | yes |
| `app:ai-agent:loaded-capabilities-compact` | rewrites loaded-capability rows that carry their complete set into checkpoints and change records; resumes from a cursor | optional, nightly, `--maxSeconds` below 360; only once every reader reads the chain format | yes |
| `app:ai-conversation:repair-zero-embeddings` | re-embeds (or deletes) embeddings that hold the zero vector: messages, compaction nodes, the host's memory tables | optional; no interval in the code | dry run unless `--apply` |
| `app:ai-conversation:backfill-message-embeddings` | embeds the messages that have no embedding row; `--async` dispatches them to the embedding worker | no (on demand) | yes |
| `app:ai-conversation:backfill-environment` | stamps conversations without an environment with one (`--environment`) | no (once) | yes (`--dryRun` counts) |
| `app:ai-agent:execute-tool-call` | hidden: executes one persisted tool call in a child process and writes its outcome into the call row | no; the turn worker starts it when the host declares `consoleScript` | yes |
| `app:ai-agent:diagnose` | reconstructs the resume decision of one conversation; `--drive` drives one turn in the console, `--retry` retries a FAILED run | no | no (`--drive`, `--retry` write) |
| `app:ai-agent:validate-conversation` | checks the integrity of stored conversations; exit 1 on a violation | no | no |
| `app:ai-agent:cache-diff` | per model call: where the request first differs from the previous one, the cache credit, the cause | no | no |
| `app:ai-agent:activity-lines` | replays the tool calls of a conversation through the activity narrator and prints the lines per model | no | no (makes model calls) |
| `app:ai-agent:tool-response-stats` | ranks tools by stored result size; the largest results of one tool; one call's payload | no | no |
| `app:ai-agent:skill-token-map` | the per-turn token size of every agent skill, by package and tool, on the wire schema | no | no (no database) |
| `app:ai-agent:context-eviction-sim` | simulates the per-tool eviction pass (`unloadGranularity = tool`) over scripted scenarios; not the skill-granular default | no | no (no database, no model call) |
| `app:ai-agent:compact` | runs one compaction round of a conversation in the console and prints the tree and the cover; `--show-only` renders only | no | yes, unless `--show-only` (makes model calls) |
| `app:ai-conversation:search-inspect` | runs the conversation search stage by stage, rights off and on, inside the search envelope | no | no (makes model calls; `--embed` writes embeddings) |

The host still writes the commands that sweep schedule state, deliver owed reports and resume owed turns, and reap
runs whose worker died ([references/host-duties.md](references/host-duties.md), `templates/`), and its own chat,
replay and evaluation commands (`ddd-module-agents-observability-specialist`).

## Templates

The folder [templates/](templates/) holds the files a host copies:

| File | For |
|---|---|
| `templates/AgentSchedulesStateSweepCommand.php` | sweep schedule state |
| `templates/AgentOwedWorkRecoveryCommand.php` | deliver owed reports, resume owed turns and lost resumes |
| `templates/AgentStuckRunsReapCommand.php` | reap runs whose worker died, time out delegation batches |
| `templates/AgentUsageEnvelopeMiddleware.php`, `templates/AgentConversationOverheadCostsMiddleware.php` | the worker middlewares |
| `templates/messenger.yaml` | the transport block |
| `templates/crons.sh` | the cron list: the package's scheduled commands and the three host commands above |

Each PHP template names its placeholders in its first lines (`// placeholder {{NAME}} sample: Value`); with the
samples filled in it passes `php -l` and calls only services of the package and its dependencies.

## Cross-references

- `ddd-module-ai-specialist` (ddd-ai): models, prompts, usage envelopes, budget grants.
- `ddd-message-handler-specialist`, `ddd-cli-command-specialist`, `ddd-endpoint-specialist`,
  `ddd-entity-specialist`, `ddd-rights-specialist` (ddd): the framework patterns the host code follows.
- `ddd-module-argus-specialist` (ddd-argus): the Argus load behind the agent's model call.
