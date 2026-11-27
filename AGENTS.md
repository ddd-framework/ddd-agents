# mgamadeus/ddd-agents -- Agents Module

The agentic loop for the `mgamadeus/ddd` framework, on top of `mgamadeus/ddd-ai`: conversations, messages,
compaction, the task plan, skills and MCP tool metadata, purposes and done criteria, widgets and signal sources,
delegation, the supervisor, run claims, the decision layer, the loop's message handlers and the MCP tool executor; and,
optional and switched off by default, the voice channel.

**Package:** `mgamadeus/ddd-agents` (v0.1.x, not released)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^3.27.0), `mgamadeus/ddd-ai` (^3.5.0), `mgamadeus/ddd-argus` (^1.3.2),
`mgamadeus/ddd-common-money` (^1.1.1)

> **This module follows all DDD Core conventions.** For base patterns, see `vendor/mgamadeus/ddd/AGENTS.md` and skills in `vendor/mgamadeus/ddd`. For models, prompts and usage envelopes, see `vendor/mgamadeus/ddd-ai`. For Argus patterns, see `vendor/mgamadeus/ddd-argus`.

Detailed knowledge: the entry skill `.claude/skills/ddd-module-agents-specialist/SKILL.md` (installation, host
duties, configuration, the folder → skill table) and nine topic skills `ddd-module-agents-<topic>-specialist` (loop,
context, skills-and-purposes, tools, widgets, autonomy, decisions-and-guards, observability, voice).

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
|   +-- {Entities,Services,Repo/DB,MessageHandlers,Exceptions}/Voice/ [the optional voice channel: call, store, admission, ring, provider seam, session worker]
+-- Presentation/Api/Agents/            [chat controller traits and DTOs; Voice/: the routes of a call]
+-- Presentation/Api/Admin/Voice/       [the admin list of voice calls as a controller trait]
+-- Presentation/Mcp/                   [MCP / JSON-RPC DTOs, Services/MCPToolExecutorService, console printer, MCPController; Client/Voice/: the voice tools]
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
- **Persisted class names.** A class whose name reaches stored JSON as an `objectType`, and every Messenger message
  class (`AgentMessengerTransports`), is a stored name. Moving one needs a migration entry (`Common.objectTypeMigrations`) or, for a
  message class, a drained queue or an alias in the host.

## Untrusted Input (mandatory)

No byte or string from outside — web pages, mail, files of a drive or a workspace, shell output, third-party API
bodies, model output, a client's claims about type, name or size — is ever executed or interpreted as code. Use the
Core helpers of `DDD\Infrastructure\Untrusted` (fixed-format image decode, guarded URL fetch, safe XML, contained
paths, isolated processes, archive caps, untrusted-content responses); any other raw sink call needs an entry with its
reason in `config/untrusted-sinks-allowlist.php`, enforced by `tests/Untrusted/UntrustedSinkScanTest.php`. Rule,
helpers and traps: Core skill **`ddd-untrusted-input-specialist`** — load it before touching such a call site.

In the agent loop: a READ or WORKSPACE tool returns untrusted content unless it declares
`MCPToolReturnsTrustedContent` (`MCPToolContentTrust`) — or its response DTO decides per call
(`MCPToolResultDeclaresContentTrust::isTrustedContent()`, a throw = untrusted); after such a call a WRITE or DELETE of the same owner turn
waits for the owner's consent (or is refused, `config/app/AI/untrustedContent.php`), and a fetch of an unseen
model-built URL is refused (skill `ddd-module-agents-decisions-and-guards-specialist`, injection defence).

## Names, rights and what belongs where

- **Skills** are `<Topic>.<Feature>` (three parts at most, upper camel case). `Agent.*` is the agent's own tools and
  organs (`Agent.Memory`, `Agent.Tasks`, `Agent.Mandates`, `Agent.TaskRunReports`, `Agent.Web.Search`, `Agent.UI`,
  `Agent.Voice`);
  Core's features are `System.*`; every other topic is the world the agent works on. No skill file beside a folder of
  the same name. A rename is code and config, without an alias.
- **Package ids** are `<provider>.<topic>.<feature>` in the skill's words (`agent.web.search`, `ddd.system.health`,
  a host's `rb.challenges.campaigns`); the provider is the host's prefix (`AI.packageIdProvider`), `ddd` or a
  module's word, never an audience. `skillPackages.php` binds classes.
- **Rights** are declared by the package that needs them (`AgentAccountRights`: `operatePlatform`,
  `scheduleAgentTasks`, `useAgentMemory`, `useWeb`, `useUI`; `AgentVoiceAccountRights`: `useVoice`), carried by its
  package class
  (`#[MCPRequiresAccountRights]`) and named in its skill. A host maps them to its roles (`AI.accountRights`, the
  default `AgentRoleMappedAccountRights`) or answers them itself; an unmapped right is not held. The platform skills
  are for the operators: right plus `#[MCPRequiresAdminWithAccessToAllResources]`.
- **Package or host:** tools and skills for a feature of the framework ship with the package that owns the feature
  (Core's issues, workers, crons and health ship here as `System.*`); the host builds its domain topics, adapters and
  ports (the screen catalog of `Agent.UI`, health sections, record descriptions), never a second tool over a package's
  table or service.
- The lint (`AISkillFilesLintService`: `findSkillNamingProblems()`, `findBindingNamingProblems()`) checks the names,
  the binding and the rights; Core's `ModuleNamespacesTestCase` checks that no two packages ship one class and that
  this package's classes stay in `AgentsModule::OWNED_NAMESPACES` (`tests/Contract/ModuleNamespacesTest.php`).

## Agent.UI and System

`Agent.UI` reads the user's screen, opens screens and points at elements: a `[SIGNAL:ui]` state note from the UI state
the client reports, the client tool requests over the realtime port `AgentRealtimeChannelService` (typed messages,
a mailbox and the presences in the shared cache, no table; a host binds its transport in a same-path subclass), the
tools of `AgentUIMCPPackage` (screen text or picture, a navigation cap per user message), the screen catalog port
`AgentUIScreenCatalogService`, and the client side in the npm entry `@mgamadeus/ddd-agents/ui`. `System.*`
reads Core's workers, crons and issues registry and changes an issue group's status; the host adds health sections
through `SystemHealthSectionProvider`. Details: skill `ddd-module-agents-tools-specialist`.

## Workspaces and sandboxes

The sandbox runner, its workspaces and their admin screen are the package `mgamadeus/ddd-agent-sandbox`. This package
keeps what an agent workspace needs from the agent: the tool effect `MCPToolEffect::WORKSPACE`, the drive store port
(`AgentDriveStore`), the drive tools and the root conversations of a space for an admin screen
(`AIConversationsService::findNewestRootConversationsOfAgentSpaceId()`, `countRootConversationsPerAgentSpaceId()`,
`findAgentSpaceIdsWithALiveTurn()`).

## Voice channel

Optional and off (`AI.agentVoice.enabled`, `config/app/AI/agentVoice.php`): the owner talks to the agent in the
application's window, the agent rings the owner's open clients, a session worker stays with each call. The channel's
classes are in a folder `Voice/` of every layer; outside it are only the places where the package asks voice (the
installation check, the sweep of `app:agent:reap`, the seam `AgentVoiceChannelService`) and the call's signal source
enum. Skill: `ddd-module-agents-voice-specialist` — load it before changing voice code.

- **The WebSocket library is suggested, not required** (`ratchet/pawl`, `react/event-loop`; in `require-dev` so the
  suite covers the worker). Only a class below `AgentsModule::VOICE_SESSION_WORKER_NAMESPACES` (the two
  `SessionWorker` folders) imports it; `AgentsModule::getNamespacesRequiringClasses()` keeps those classes out of the
  container of an installation without the library. Code outside the two folders never imports the library and
  reaches a gated class only behind `canVoiceRunHere()` (`tests/Kernel/VoiceWithoutSessionWorkerLibraryTest.php`).
- **One place answers whether voice can run here:** `AgentVoiceCallAdmissionService::canVoiceRunHere()` (switched on
  AND the library installed; the override point is the `protected` `isSessionWorkerLibraryInstalled()`). The admission,
  the channel provider, the tools and skill, the routes and the installation check ask it. Switched on without the
  library is a named WARNING of `app:agent:check-installation`, never a fatal.
- **Switched off costs nothing:** no tool, no skill, no required table, nothing reads the call table.
- **Configuration only through `AgentVoiceConfiguration::current()`;** secrets never in a config file (the config
  names the environment variable that holds the provider key).
- **Calls only through `AgentVoiceCallStoreService`.** No repository call and no `update()` on a call outside a
  store. Every write names its columns: a ring, an answer, the worker and an end can write the same call within one
  second. Raw SQL quotes identifiers through the dialect (MariaDB and PostgreSQL).
- **A call ends once.** Every exit goes through the one end path of `AgentVoiceCallsService`; an unanswered end is
  claimed atomically before anything is written to the conversation.
- **Stored values keep their spelling** (end reasons, directions, trace event kinds, usage scope, envelope and prompt
  names): applications bind tables that already hold them. A new value is additive.
- **Every sentence the voice model is told is a prompt file,** read through `AgentVoicePromptText::forName()`. No
  sentence in code. A missing file costs its block, never the call.
- **The provider's wire:** a field is sent only when the provider's own reference documents it; the docblock names
  the page. A failed session action is a `BadGatewayException` with the code `provider_unavailable` and the
  provider's words in the previous exception; those words go to the log and the trace, never to a client.
- **An application's own medium, columns or rules** are same-path subclasses; every class docblock lists its override
  points (`protected` methods). No dead branch "for later".
- **Untrusted text:** a call's task and its transcript are written by the agent or the owner; a screen shows them as
  text only.
- **What voice hands on is a value object, never a keyed array:** seed rows (`AgentVoiceSeedInputMessage`), completed
  sentences (`LiveTranscriptSentence`), the ring (`AgentVoiceRingPayload` with `AgentVoiceRingCallerContext`, rendered
  only in `toPayloadJson()`), task answers (`AgentCallTaskStart`, `AgentCallTaskOutcome`).
- **A list of objects on a public or protected signature or on a DTO is an object set, never `X[]`**
  (`AgentVoiceSeedInputMessages`, `LiveTranscriptSentences`, `AgentVoiceCalls`, `AdminAgentVoiceCallsDerivedValues`,
  the trace summary's sets), and its element class has a deliberate `uniqueKey()`: the object itself
  (`spl_object_id`) where two equal elements are two elements and the order counts (sentences, seed rows, pushes),
  a content key where the content is the identity (the derived values of a call: the call's id). An array stays only
  as state of a service that is never serialised and never returned, and its comment says exactly that. Scalar lists,
  wire and storage documents and config documents stay arrays.
- **The package's own voice channel provider is the fallback** (`AgentVoiceChannelService`): an application's
  provider is asked first and the package's last, recognised by its class; only an application's providers are
  counted for the "several providers" warning.
- **Who is rung:** a ring starts only while a client of the account listens (`isClientListening()` of the account
  audience). A bound transport answers; without its answer a fresh presence statement counts, and the account-level
  realtime read states it for a client that only polls (`stampUIClientOfAccount()`: a cache entry per account, written
  whether voice is on or off, because it is the realtime port's). The web client states a client id on every account
  read.
- **The call's signal sources** are the enum `PhoneConversationSignalSource` in
  `Entities/Conversations/SignalSources/`: found there by the folder convention, so it stays outside the `Voice/`
  folders. Its values are stored and keep their spelling. A case no class of the package writes stays for an
  application that stores it.
- **Web** (`web/src/voice/`, the entries `@mgamadeus/ddd-agents/voice` and `/voice/admin`): texts through
  `AgentsEnvironment.texts` (no `translate` prop), keys in `i18n-keys.json`; styles in the package's one stylesheet,
  custom properties `--agents-voice-*`; only `/voice/admin` imports the three optional peers (`boundary.json`,
  `optionalPeerImports`; check 10 of `web/scripts/check-package.mjs`).
- **Tests** live in `tests/Voice/` (base classes and helpers in `tests/Voice/Support/`) and run in the `package` suite
  with voice switched off by default.

## Messenger

`AgentMessengerTransports` lists six transports and the routing. The framework registers neither; the host copies them
into its messenger configuration and runs one consumer per transport (`examples/workers.yaml`). The voice channel has
two transports of its own (`AgentVoiceMessengerTransports`), copied only by an application that switches voice on:
the Messenger template of the installation skill and `examples/workers.yaml` carry them as a commented block headed
"only when voice is enabled".

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

## Contributing: versions and commit messages

- **A pull request never sets a version.** Leave `version` in `composer.json` and in `package.json` as it is, and add
  no version heading to `CHANGELOG.md`. Write the changelog entry under `## Unreleased` at the top of the changelog
  (add the heading when it is missing).
- **The maintainer assigns the version at release:** bumps `composer.json` / `package.json`, renames `## Unreleased` to
  the new version and tags it. Versions are regular only (no pre-release suffixes).
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):** `feat: …`, `fix: …`,
  `docs: …`, `refactor: …`, `test: …`, `chore: …`, optionally with a scope (`fix(chat): …`); a breaking change carries
  `!` after the type or a `BREAKING CHANGE:` footer.
- **Merging a pull request that still carries a version:** keep the version fields of `main`, move its changelog entry
  under `## Unreleased`, then release as usual.
