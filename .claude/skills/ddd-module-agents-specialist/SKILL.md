---
name: ddd-module-agents-specialist
description: "Installs, wires and runs the agent of mgamadeus/ddd-agents (the agentic loop on ddd-ai) in a DDD host and routes to the eight agent topic skills. Covers the installation checklist, the chat controller traits and start route, the agent space (agentSpaceId, account level, anchoring it on a host entity), override points by same-path subclass, entitlement interfaces, listeners, the 14 config files, host wording, persona and egress, the six Messenger transports, the usage envelope middleware, workers and stop grace, the host duties with command templates (fire due schedules, schedule state sweep, owed reports and owed turns, reaper, finalize, retention purge), the cron list, table names, persisted class names, the package suite and the source-ownership manifest. Use when adding the agent to an application, wiring agent transports or workers, schedules never fire, a crashed turn never answers, agent event tables grow, overriding an agent service, naming agent tables, or asking which agent skill explains a file."
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

1. **The package ships no console command.** Firing schedules, recovering owed turns and reports, reaping dead
   workers and stale sub-agent groups, finalizing conversations and purging the event tables are host commands.
   Without them unattended work never runs, a crashed turn never answers and the event tables grow without bound. Templates: `templates/`; contracts: [references/host-duties.md](references/host-duties.md).
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
the two middlewares, the host commands and the cron list. The reference kernel (`examples/reference-kernel`) is a
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
the host duty only); parallel tool calls in child processes in the tools skill (here: the optional host command); a
turn that never answers in the observability skill (here: the recovery commands).

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
| Write the host commands and the cron list | [host-duties.md](references/host-duties.md), `templates/` |
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

## Templates

The folder [templates/](templates/) holds the files a host copies:

| File | For |
|---|---|
| `templates/AgentSchedulesFireCommand.php` | fire due schedules |
| `templates/AgentSchedulesStateSweepCommand.php` | sweep schedule state |
| `templates/AgentOwedWorkRecoveryCommand.php` | deliver owed reports, resume owed turns and lost resumes |
| `templates/AgentStuckRunsReapCommand.php` | reap runs whose worker died, time out delegation batches |
| `templates/AgentFinalizedConversationsReduceCommand.php` | mark and reduce finalized conversations |
| `templates/AgentEventsRetentionPurgeCommand.php` | purge old trace and turn events and their unreferenced snapshots |
| `templates/AgentUsageEnvelopeMiddleware.php`, `templates/AgentConversationOverheadCostsMiddleware.php` | the worker middlewares |
| `templates/messenger.yaml` | the transport block |
| `templates/crons.sh` | the cron list |

Each PHP template names its placeholders in its first lines (`// placeholder {{NAME}} sample: Value`); with the
samples filled in it passes `php -l` and calls only services of the package and its dependencies.

## Cross-references

- `ddd-module-ai-specialist` (ddd-ai): models, prompts, usage envelopes, budget grants.
- `ddd-message-handler-specialist`, `ddd-cli-command-specialist`, `ddd-endpoint-specialist`,
  `ddd-entity-specialist`, `ddd-rights-specialist` (ddd): the framework patterns the host code follows.
- `ddd-module-argus-specialist` (ddd-argus): the Argus load behind the agent's model call.
