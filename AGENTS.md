# mgamadeus/ddd-agents -- Agents Module

The agentic loop for the `mgamadeus/ddd` framework, on top of `mgamadeus/ddd-ai`: conversations, messages,
compaction, the task plan, skills and MCP tool metadata, purposes and done criteria, widgets and signal sources,
delegation, the supervisor, run claims, the decision layer, the loop's message handlers and the MCP tool executor.

**Package:** `mgamadeus/ddd-agents` (v0.1.x, not released)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^3.24.2), `mgamadeus/ddd-ai` (^3.2.1), `mgamadeus/ddd-argus` (^1.3.2),
`mgamadeus/ddd-common-money` (^1.1.1)

> **This module follows all DDD Core conventions.** For base patterns, see `vendor/mgamadeus/ddd/AGENTS.md` and skills in `vendor/mgamadeus/ddd`. For models, prompts and usage envelopes, see `vendor/mgamadeus/ddd-ai`. For Argus patterns, see `vendor/mgamadeus/ddd-argus`.

Detailed knowledge (installation checklist, override points, transports, controller traits, table prefix):
`.claude/skills/ddd-module-agents-specialist/SKILL.md`.

## Architecture

```
src/
+-- Domain/AI/
|   +-- Entities/Conversations/        [AIConversation, messages, task plan, widgets, delegation, supervisor, …]
|   +-- Entities/Skills/, MCPPackages/ [AISkill catalog, MCP tool metadata and attributes, tool results]
|   +-- Entities/Mandates/, Evals/, Discovery/, Environment/, RecordedRuns/, Texts/Embeddings/
|   +-- Entities/Models/CustomerSelection/ [the models a customer may pick]
|   +-- Exceptions/Conversations/, Evals/, Texts/Embeddings/
|   +-- Interfaces/                     [AIConversationOverheadCostsMessageInterface, AIConversationProgressReporterInterface]
|   +-- Libs/RecordedRuns/              [RecordedRunClockAndIdSources: static clock and id sources]
|   +-- Listeners/Conversations/        [turn-start and settled-owner-turn listener interfaces, transaction listener]
|   +-- MessageHandlers/                [the loop's messages and handlers, AgentMessengerTransports]
|   +-- Messenger/Conversations/        [AgentWorkerStateResetter]
|   +-- Repo/DB/Conversations/, Mandates/ ; Repo/Argus/Conversations/, Guard/, Mandates/
|   +-- Services/Conversations/, Decisions/, Skills/, Mandates/, Eval/, SystemPrompts/, Texts/Embeddings/
|   +-- Traits/Conversations/           [AITraceLogTrait]
+-- Presentation/Api/Agents/            [chat controller traits and DTOs]
+-- Presentation/Mcp/                   [MCP / JSON-RPC DTOs, Services/MCPToolExecutorService, console printer, MCPController]
+-- Modules/Agents/AgentsModule.php
config/app/                             [the module's defaults: prompts and agent settings]
examples/reference-kernel/              [a minimal host without an application]
examples/workers.yaml                   [consumer list for the six transports]
```

## Rules of this module

- **Owned namespaces only.** The module shares `DDD\Domain\AI\` with ddd-ai and `DDD\Presentation\` with the
  framework, never a directory. A class goes into one of `AgentsModule::OWNED_NAMESPACES`;
  `tests/Contract/OwnedNamespacesTest.php` fails otherwise.
- **Services namespaces hold services.** Every `Services` namespace of the module is public; a class there ends with
  `Service` and is fetched with `getService()`. Listeners, resetters, value holders, static libraries and traits live
  outside them.
- **Interfaces carry the `Interface` suffix.**
- **Overrides by same-path subclass.** A host replaces a base service, an entity set or a repository by a subclass at
  the same path under its own root namespace (`App\Domain\AI\…\X`).
- **Initiator types of an application's own runs are the application's.** The module knows `SCHEDULE`, `ADMIN_TASK`,
  `PHONE_CALL` and `EXTERNAL_AGENT`. A run an application starts itself gets its `INITIATOR_TYPE_*` constant on the
  application's conversation class, which redeclares `OWNER_ABSENT_INITIATOR_TYPES` and
  `PLATFORM_OWN_RUN_INITIATOR_TYPES`; module code reads both through `AIConversation::getOwnerAbsentInitiatorTypes()`
  and `AIConversation::getPlatformOwnRunInitiatorTypes()`, never as constants.
- **Query options live on the host's class.** A host's subclass of a filterable entity or set repeats
  `use QueryOptionsTrait;` (the framework checks the class itself, not its parents; without it the host's repository
  applies no filter, order or `top`). Default query options are kept per class and the repository reads those of the
  class it loads, so module code never writes `X::getDefaultQueryOptions()` on a class a host may derive: it asks
  `DDDService::instance()->getContainerServiceClassNameForClass(X::class)` for the class first
  (`AgentMandates::applyQueryOptionsFromRequestDto()`, `AgentConversationReadsTrait`). A request class whose
  `#[DtoQueryOptions]` names such a class uses `DtoQueryOptionsOfApplicationClassTrait`. Still naming the module's
  class: the message set in `AIConversationMessagesService` and `DBAIConversationMessages`, the node set in
  `DBConversationCompactionNodes` — a host that derives one of these two sets has to move them first.
- **Class-wide property hides reach the host's class.** `X::addStaticPropertiesToHide(true, …)` registers the hide
  for `X` only, and the serializer checks the class of the object it writes, never its parents: on a class a host
  derives, the hide leaves the host's objects visible. Module code hides through
  `AgentConversationControllerHooksTrait::hidePropertiesOnEveryObjectOfClass(X::class, …)`, which registers on the
  module's class and on the host's (`tests/Presentation/Api/Agents/Conversations/ClassWideHidesReachTheApplicationsClassTest.php`).
  A host's own code that hides class-wide names ITS class.
- **A sentence of the module never carries a host's word.** What a host calls the entity that is billed, a space or
  the target of a confirmed step is the host's: a sentence for the model or the owner reads the word from
  `AgentApplicationWording::current()` (`config/app/AI/agentApplicationWording.php`, replaced whole by the host; a
  missing key keeps the default), and a section of the supervisor briefing takes its headline from the purpose's
  `SupervisorPolicy`. A translated sentence builds its key with the word, so a host's key stays the sentence it has
  always had (`tests/Domain/AI/Entities/Conversations/Agent/AgentApplicationWordingTest.php`). The context text
  of a space is `{%AGENT_SPACE_CONTEXT%}` / `agentSpaceContext` in the module; the label of its line in the
  instructions, the kind of its state note (`[SIGNAL:<kind>]`, also the stored kind) and the word for what it
  describes are declared the same way, and a host's prompt file writes the declared label in front of the
  placeholder.
- **What one application does is the application's.** A field, a method, a constant, a tool name, a record kind, a
  provider name or a widget that only one application's runs need never goes into the module. The application
  declares it where the module reads an application's own: a class of the same path that the module builds with
  `X::newInstance()` (`RunSettings`, `TaskPlanItem`, `TaskPlanItems`, `AgentModelInput`, `AIConversationMetrics`), a
  service of the same path (`AgentBackgroundOperationsService`, `AIConversationAuditRenderService`), the purpose's
  `SupervisorPolicy`, a key of `agentApplicationWording.php`, a config or prompt file it replaces whole
  (`agentToolCallChildProcess.php`, `AI.Agent.OwnerMessageInjectionGateRules`), or a widget or node class in its own
  `Domain/<Domain>/Entities/AI/Conversations/Widgets/` or `…/Nodes/` folder. A name of an application in a comment
  or a description is allowed only as a marked example ("e.g."), never as a statement of what the module does.
  The same holds for words: a sentence of the module names the owner and the agent space with the words of
  `agentApplicationWording.php`, never with the words of one application ("business", "location"), and a comment
  never names a console command, a test, a plan or a date the package does not contain.
- **A chat element is a widget.** No text a model reads and no name in the module says "card".
- **A database entity and its set are built with `X::newInstance()`, never with `new X()`.** `newInstance()` gives the
  host's subclass when one exists; an object of the module's own class is written through the module's model, so the
  host's column is not written or the insert fails without an error. A repository is never built with `new DBX()`
  either: a service asks `getEntityRepoClassInstance()` / `getEntitySetRepoClassInstance()`, other code
  `X::getRepoClassInstance()`, so the host's repository with its read rights is the one that runs
  (`tests/Contract/DatabaseEntitiesAreNeverBuiltWithNewTest.php`).
- **No closures in regular methods** (ratchet `tests/Contract/closure-ratchet.txt`, counts only go down), no `private`,
  no `final`, `declare(strict_types=1)` everywhere, one class per file.
- **No application, company, product or agent names** in code, config or docs. The package lists no such name, not
  even in a test: an application that builds on the module checks its own names against the installed package in its
  own test suite. What an application has always written (log prefix, console label, environment variable names) it
  declares in `config/app/AI/agentApplicationNames.php`.
- **Persisted class names.** Classes whose name reaches stored JSON as an `objectType`, and the Messenger message
  classes, are listed in `CHANGELOG.md`. Moving one needs a migration entry (`Common.objectTypeMigrations`) or, for a
  message class, a drained queue or an alias in the host.

## Messenger

`AgentMessengerTransports` lists six transports and the routing. The framework registers neither; the host copies them
into its messenger configuration and runs one consumer per transport (`examples/workers.yaml`).

## Table prefix

The models ship without a table prefix, and no entity carries a fixed table name. A host that keeps other table names
lists them under `tableNames` in its `config/app/Base/Repo/database.php`; the module ships no such file. A host with
`DATABASE_TABLE_PREFIX` or with `tableNames` entries regenerates the models in vendor through
`app:generate-doctrine-models-for-entities` (Composer's `post-install-cmd` / `post-update-cmd`); never
`--no-scripts` in such an application.

## Tests

```bash
DDD_AGENTS_VENDOR_DIR=<host>/vendor php tests/Support/bin/phpunit.php -c phpunit.xml.dist --testsuite package
DDD_AGENTS_VENDOR_DIR=<host>/vendor php tests/Support/bin/console.php lint:container
DDD_AGENTS_VENDOR_DIR=<host>/vendor <host>/vendor/bin/phpstan analyse -c phpstan.neon.dist
```

The package kernel (`tests/Support/AgentsTestKernel.php`) loads the framework, ddd-argus, ddd-common-money, ddd-ai and
this module, and no application class.

Database tests run on both engines against the throwaway databases of `tests/Integration/docker-compose.yml`, once for
the package alone and once together with the fixture application (`tests/Fixtures/Application`: an application that
subclasses the space with a required column of its own, its own set, repositories, generated model and service):

```bash
DDD_AGENTS_TEST_DRIVER=pdo_mysql|pdo_pgsql … phpunit.php -c phpunit.xml.dist --testsuite integration
DDD_AGENTS_TEST_WITH_APPLICATION=1 DDD_AGENTS_TEST_DRIVER=pdo_mysql|pdo_pgsql … phpunit.php -c phpunit.xml.dist --testsuite application,integration
```

A run with the fixture application is a run of its own: the framework resolves a package class to the application's
same-path class for the whole process. A database test stores a space row through
`DatabaseTestCase::insertAgentSpaceRow()`, which adds what the application requires in that run; code that creates a
space with `new` of the package class stores nothing there.
