---
name: ddd-module-agents-specialist
description: "Install, configure, extend and test the ddd-agents module, the agentic loop of mgamadeus/ddd on top of ddd-ai. Covers the owned namespaces, the host installation checklist (module discovery, env keys, routes, auth, firewall of the MCP route, schema), override points (same-path subclasses of base services, SQL filters for host tables, the tool failure text), the six Messenger transports and workers.yaml consumers, the chat controller traits with the scope check of a start, the table-prefix and table-name contract (a host with a prefix or with tableNames entries regenerates module models in vendor), persisted class names, and the package suite. Use when adding the agent to a DDD application, wiring agent transports or workers, overriding an agent service, exposing the chat endpoints or an MCP endpoint, adding a skill or MCP tool package, keeping a host's own table names, fixing missing agent tables or wrong table names, or running its tests."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Module Specialist

The agentic loop for `mgamadeus/ddd`, on top of `mgamadeus/ddd-ai`.

> **Base patterns:** See core skills in `vendor/mgamadeus/ddd` (services, message handlers, endpoints, entities). For
> models, prompts, usage envelopes and the language-model trait, see `vendor/mgamadeus/ddd-ai`. For Argus repos, see
> `vendor/mgamadeus/ddd-argus`.

## When to use

- Adding the agent to a DDD application (a host), or checking a host's installation
- Configuring the agent's Messenger transports, routing and worker list
- Replacing a part of the agent's behaviour through a same-path subclass
- Exposing the chat endpoints, adding a skill, a skill package or an MCP tool package
- Missing agent tables, wrong table names after an update, generated models with the wrong prefix
- Running or extending the package's own test suite

## What the module is

One entry point, `DDD\Modules\Agents\AgentsModule` (`src/Modules/Agents/`). The module shares the roots
`DDD\Domain\AI\` (with ddd-ai) and `DDD\Presentation\` (with the framework), never a directory. It owns:

| Area | Owned namespaces (below `DDD\Domain\AI\` unless noted) |
|---|---|
| The loop and its data | `Entities\Conversations\`, `Services\Conversations\`, `Repo\DB\Conversations\`, `Repo\Argus\Conversations\`, `MessageHandlers\` |
| Skills, tools, decisions | `Entities\Skills\`, `Services\Skills\`, `Entities\MCPPackages\`, `Services\Decisions\` |
| Mandates, eval guard, guard probe | `Entities\Mandates\`, `Services\Mandates\`, `Repo\DB\Mandates\`, `Repo\Argus\Mandates\`, `Entities\Evals\`, `Services\Eval\`, `Repo\Argus\Guard\` |
| Small areas | `Entities\Discovery\`, `Entities\Environment\`, `Entities\Models\CustomerSelection\`, `Entities\RecordedRuns\`, `Entities\Texts\Embeddings\`, `Services\SystemPrompts\`, `Services\Texts\Embeddings\`, `Interfaces\` |
| Exceptions | `Exceptions\Conversations\`, `Exceptions\Evals\`, `Exceptions\Texts\Embeddings\` |
| Non-service plumbing | `Libs\RecordedRuns\` (static clock and id sources), `Listeners\Conversations\`, `Messenger\Conversations\` (worker state resetter), `Traits\Conversations\` |
| Presentation | `DDD\Presentation\Mcp\` (MCP / JSON-RPC DTOs, `Services\MCPToolExecutorService`, console printer, `MCPController`), `DDD\Presentation\Api\Agents\` (chat controller traits and DTOs) |

`AgentsModule::OWNED_NAMESPACES` holds this list; `tests/Contract/OwnedNamespacesTest.php` fails when a class sits
outside it, an entry is empty, or another package has a file in it. A new class goes into one of these namespaces —
never directly into a directory ddd-ai or the framework also uses (`Entities\Models\`, `Entities\Prompts\`,
`Entities\UsageLogs\`, `Exceptions\`, `Services\`, `Presentation\Base\Dtos\`, `Presentation\Services\`): a later class
of the same name there would be a silent autoload race.

Every `Services` namespace of the module is public (`getService()` works); everything that is no service lives
outside them and stays private.

## Host installation checklist

1. **Modules discovered.** A Composer install lists `ddd-ai`, `ddd-argus`, `ddd-common-money` and `ddd-agents` through
   `extra.ddd-module` in `vendor/composer/installed.json`. A kernel without that file lists them in
   `getAdditionalModuleClasses()`.
2. **Bundles:** `FrameworkBundle`, `DDDBundle`, `MonologBundle`.
3. **Env reaches `$_ENV`** (`Config::getEnv()` reads only `$_ENV`): load the env files with Dotenv, or run PHP with
   `variables_order` containing `E`.
4. **Env keys:**

   | Key | Why |
   |---|---|
   | `APP_SECRET`, `APP_ENV`, `APP_DEBUG` | Symfony |
   | `DB_DEFAULT_CONNECTION_*` | the entity manager; MariaDB 11.8+ (VECTOR columns) or PostgreSQL |
   | `LAZYLOAD_DEFAULT_REPO_TYPE=DB` | repo type of a `LazyLoad` without `repoType` |
   | `CACHE_APC_TTL` or a full `CACHE_<GROUP>_*` set | the cache groups |
   | `JWT_HASH_KEY`, `JWT_HASH_METHOD` | every outgoing Argus call is signed, the agent's model call included |
   | the provider key the model catalog names (e.g. `OPENROUTER_KEY`) | the egress refuses to build a request while it is empty, also under a test transport |
   | `DATABASE_TABLE_PREFIX` | see "Table prefix" below |

5. **Services** (`config/symfony/default/services.yaml`): the framework's resource blocks; `logger` as a public alias
   of `monolog.logger`; `DDD\Symfony\Loaders\CustomAnnotation{Class,File,Directory}Loader` tagged `routing.loader`;
   `DDD\Symfony\Http\ValueResolvers\DtoValueResolver` tagged `controller.argument_value_resolver` (priority 150); the
   host's controllers public with `controller.service_arguments`; the host's `…\Services\` namespaces public. The
   module's own services need no block: the module pass registers them. MCP packages are no services.
6. **Routes:** a `type: annotation` resource on the host's controller folder.
7. **Chat controller:** a host controller extends `HttpController` and uses the traits (below). It declares the start
   route itself and passes the agent space the conversation works for, or null for one at account level.
8. **Auth per request:** `AuthService::instance()->setAccount($account)` before the controller runs (a `kernel.request`
   subscriber). Without an account the loop refuses; the read rights confine conversations and messages to it.
9. **An `Account` row exists:** conversations carry a foreign key to the accounts table.
10. **Messenger:** the transports and routing below.
11. **Skills and tools:** `config/app/AI/skillPackages.php` replaces the module's file whole and lists every skill the
    host offers; one skill file per skill under `config/app/AI/Skills/` (frontmatter `description`,
    `shortDescription`, `decisionCriteria`); one MCP package per tool group with its DTOs.
12. **Schema:** `app:entity:show-sql` prints the DDL; foreign keys may name a later table (apply with foreign key checks
    off). There is no schema-create command.

Per turn the module also makes a summary call besides the agent's own model calls. Without an embedding provider
every message logs `TextEmbeddingNotGeneratedException` and search stays keyword-only.

## Override points

The framework resolves a service, an entity set or a repository through the application's same-path subclass first
(`App\Domain\AI\…\X` before `DDD\Domain\AI\…\X`). The base services meant to be replaced, with what the default does:

| Base service | Default | A host overrides it to … |
|---|---|---|
| `Services\Conversations\Entitlements\AIConversationEntitlementsService` | ungated (no flags, no rights); `findAccountBillingAccessOfConversation()` null | gate skills, tools and purposes by feature flags, rights or billing access |
| `Services\Conversations\Eligibility\AgentAutomationEligibilityService` | every account eligible, no override | refuse automation for an account state |
| `Services\Conversations\Rights\AgentAccountRightsService` | a row is readable by its own account; an admin with access to all resources reads all | add account hierarchies (`applyNonAdminAccountCondition()`) |
| `Services\AgentSpaces\AgentSpacesService` (entity `Entities\AgentSpaces\AgentSpace`) | a space belongs to one account; time zone and owner from its own columns; a start without a space is open | anchor the space on the host's own entity (same-path subclasses of entity, repo and service): rights, time zone, owner, language, prompt context, the tool argument that names it |
| `Services\Conversations\Schedules\ScheduleBudgetPotsService` | no budget (a zero pot that never caps) | cap scheduled spend |
| `Services\Conversations\Situations\AgentSituationalContextService`, `Memories\AgentMemoriesService` | no context, no memories | add the host's facts to the prompt |
| `Services\Conversations\Agent\AgentToolResultFactsService`, `AgentDecisionFactsService` | no facts | add host facts to tool results and decisions |
| `Services\Conversations\Agent\AgentOwnerReportEmailsService`, `AgentBackgroundOperationsService`, `AgentEvalAccountsService` | no mail, no background operations, no eval accounts | send report mail, run host jobs, mark eval accounts |
| `Services\Conversations\Channels\AgentVoiceChannelService`, `AIConversationRealtimeSignalService` | no voice, no realtime push | connect a phone or push channel |
| `Services\Conversations\Environments\AgentEnvironmentNamesService`, `Quality\AgentConversationScorecardsService` | neutral names, no scorecard | name environments, score conversations |
| `Services\Mandates\AgentMandateRevocationEffectsService`, `Services\Eval\AgentEvalOutboundGuardHooksService` | no effects, no hooks | act on a revoked mandate, hold eval mail |
| `Services\Texts\Embeddings\AITextEmbeddingsService` | no provider | generate embeddings |
| `DDD\Presentation\Mcp\Services\MCPToolExecutorService` | no audit (`recordAdminAudienceToolCall()` a no-op); a failed tool answers a client error of the framework with its message and anything else with an issue id only (`buildToolFailureText()`) | record admin tool calls, change the failure text per caller |
| `Repo\DB\Conversations\DBAIConversation` (repository), `Services\Conversations\AIConversationSchedulesService` | raw statements over the module's tables only: `buildTestAccountsExclusionSqlFilter()` and `buildAutomationStateSweepSqlFilter()` return the empty `AgentApplicationSqlFilter` | join the host's account and scope tables (test accounts in the statistics, ended scopes in the schedule sweep) |
| `Libs\RecordedRuns\RecordedRunClockAndIdSources` (static) | wall clock, random ids | document which sites read through it |

Two listener interfaces collect host services by tag (autoconfigured): `Listeners\Conversations\AIConversationTurnStartListener`
(write a row before a turn's first model call) and `AIConversationSettledOwnerTurnListener` (react after a settled
owner turn). The console printer is fetched by its interface `AgentMessagePrinterInterface`, a public alias of
`AgentConsolePrinter`; a host aliases the id to its own printer.

## Transports and workers

`DDD\Domain\AI\MessageHandlers\AgentMessengerTransports` lists the six transports (`ALL`) and the routing
(`ROUTING_BY_MESSAGE_CLASS`). The framework registers neither: the host copies both into its `messenger.yaml`. A
message the host does not route runs synchronously in the dispatching process (`sync://` for all six is enough for a
demo).

| Transport | Messages | Handler time limit |
|---|---|---|
| `ai_conversation_turn` | `AIConversationResumeMessage` (one agent turn) | 1,800 s |
| `ai_conversation_compaction` | compaction, finalize, summary, report mail | up to 1,800 s |
| `ai_conversation_scorecard` | the quality assessment | 900 s |
| `ai_conversation_message_embedding` | one message embedding | 600 s |
| `ai_tool_result_screening` | the background injection screen of a tool result | 120 s |
| `ai_activity_lines` | the narration of a running turn | 60 s |

`examples/workers.yaml` is a consumer list for the framework's worker list (`config/system/workers.yaml`,
`app:workers:check` compares it with the routing). The turn transport needs `busySilentSeconds` above the turn's time
limit, and its process manager's stop grace must cover a whole turn: a restart waits for the turn, it never kills it.

`tests/Contract/MessengerTransportsHaveWorkerBlocksContract.php` checks a host's configuration against this class.

## Chat controller traits

`DDD\Presentation\Api\Agents\Conversations\Controller\`:

| Trait | Routes |
|---|---|
| `AgentConversationReadsTrait` | list, get, messages, activity, message search, message context, conversation search, run state |
| `AgentConversationWritesTrait` | post a message, inject a signal, retry a failed run, patch the title, delete |
| `AgentConversationStartTrait` | the start logic (the host declares the route and passes the agent space or null) |
| `AgentSelectableModelsTrait` | the models a customer may pick |
| `AgentConversationControllerHooksTrait` | the hooks (gate, admin session, device) with neutral defaults |

A start writes a conversation for the `AgentSpace` the host's route passes to `buildConversationStartedResponse()`,
or at account level when it passes null. The hook `findAgentSpaceOfStartRequest()` reads the space the request names
(`agentSpaceId`; unreadable = 403); a host that names it by an id of its own entity overrides the hook. Before
anything is stored `AgentSpacesService::requireAgentSpaceUsableByAccount()` decides whether the account may use the
space (default: the space belongs to the account), and `requireConversationStartWithoutAgentSpaceAllowed()` whether a
start without one is open (default: yes).

The relation of a conversation, a schedule or a mandate to its space (`$conversation->agentSpace`) loads past the read
rights of the space (`DBAgentSpace::lazyload()`): whoever may read the row may read the space it names, with every
column, the host's own reference included. A direct `find()`, a list and an expand join of spaces keep their rights.
Two rules for the host follow. The mapping of the host's space repository (`mapToEntity()`,
`postProcessAfterMapping()`) reads no other entity, because the rights are off for every repository while the space
loads. And a response that serializes a row together with its loaded space shows the space's columns to whoever may
read the row; a host for which that is too much hides the relation or the column on output.

## MCP endpoint

A host's controller extends `DDD\Presentation\Mcp\MCPController` and lists its tool packages. `tools/call` refuses a
request without an authenticated account, and each tool checks its own rights; `initialize` and `tools/list` have no
check of their own. The host's firewall must therefore cover the MCP route (authenticated callers only): without it
the tool catalogue of the mount is public. `?errors_as_json` answers a raw tool exception only for an admin with
access to all resources (`mayCallerReadRawToolErrors()`).

A tool method takes request DTOs, objects of the framework, the server-provided call context and public container
services; a parameter of any other class is refused, it is never built from the model's arguments.

## Table prefix

The module ships its models without a table prefix, and no entity of the module carries a fixed table name: a table
is `DATABASE_TABLE_PREFIX` plus the entity set's name. A host that keeps another name for a module table lists it in
its `config/app/Base/Repo/database.php` under `tableNames`
(`[\DDD\Domain\AI\Entities\Conversations\AIConversation::class => '<table>']`): the name is taken verbatim, and the
entry of the module class also covers the host's same-path subclass. The module ships no `Base/Repo/database.php`.

A host with a `DATABASE_TABLE_PREFIX` or with `tableNames` entries regenerates the module models in its vendor copy
with `app:generate-doctrine-models-for-entities`; Composer runs it in `post-install-cmd` / `post-update-cmd`. **Never
run `composer install` or `update` with `--no-scripts` in such an application**: the vendor models then keep the
shipped names, and every module query that reads a table name from a model class hits a missing table. Module code
reads table names from the module's model classes, so a same-path model in the host cannot change them; only the
regeneration does.

## Class names that leave the process

| Group | Count | Rule |
|---|---|---|
| `objectType` values in stored JSON (stored columns at any depth, elements of polymorphic properties) | 117 classes | never move or rename one without a `Common.objectTypeMigrations` entry (old → new) |
| Subclass indicator / discriminator values | 0 | — |
| Messenger message classes (in a broker queue during a deploy) | 9 classes in `MessageHandlers\` | a rename needs the host to drain the queues first or to alias the old name (`class_alias`) |

## Running the package suite

The package runs on its own kernel with no application class on the class path; third-party code comes from a host's
vendor directory:

```bash
DDD_AGENTS_VENDOR_DIR=<host>/vendor php tests/Support/bin/phpunit.php -c phpunit.xml.dist --testsuite package
DDD_AGENTS_VENDOR_DIR=<host>/vendor php tests/Support/bin/console.php lint:container
DDD_AGENTS_VENDOR_DIR=<host>/vendor <host>/vendor/bin/phpstan analyse -c phpstan.neon.dist
```

Contract tests guard the package: `OwnedNamespacesTest`, `NoClosuresInRegularMethodsTest` (ratchet
`closure-ratchet.txt`, counts only go down), `EnvironmentDistFilesTest`, and
`tests/Kernel/DoctrineModelsRegenerationTest` (the committed models equal a prefix-less regeneration).
`tests/Kernel/ReferenceKernelSmokeTest` boots the example host in `examples/reference-kernel/`.

## Traps

- `getService()` only fetches classes from a public `Services` namespace. A listener, a resetter, a value holder or
  a static library in such a namespace becomes a public lazy service by prefix — keep them out.
- An interface carries the `Interface` suffix; a class in a `Services` namespace ends with `Service`.
- No closures in regular methods; no `private`, no `final`.
