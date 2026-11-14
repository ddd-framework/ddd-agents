# Installation: adding the agent to a DDD application

**Reader:** host developer

Contents: what the package is · the checklist · the first request · what runs without extra work · checking the
installation.

## What the package is

`mgamadeus/ddd-agents` is a DDD module (`AgentsModule`, discovered through `extra.ddd-module` in its `composer.json`).
It requires `mgamadeus/ddd`, `mgamadeus/ddd-ai`, `mgamadeus/ddd-argus`, `mgamadeus/ddd-common-money` and
`erusev/parsedown`; `composer.json` is the source for the version floors. Its classes live in the namespace roots
`DDD\Domain\AI\` (shared with ddd-ai) and `DDD\Presentation\` (shared with the framework), never in a directory another
package uses: `AgentsModule::OWNED_NAMESPACES` lists the namespaces the package owns. Every `Services` namespace of the
module is public (`AgentsModule::getPublicServiceNamespaces()`); all but the MCP presentation services are built lazily
(`AgentsModule::getLazyServiceNamespaces()`). The module's own services need no block in the host's `services.yaml`,
the module compiler pass registers them.

**The host's root namespace.** Under `App\` a subclass at the mirrored path overrides a package class with no
configuration; under another root namespace the host maps every class it overrides in its `services.yaml`. The lookup
order and the folder scans it does not cover: [override-points.md](override-points.md).

The package ships one console command so far (the finalize mark sweep `AgentMarkFinalizedConversationsCommand`), no
route of its own for starting a conversation, no transport configuration and no envelope middleware. The host
provides the rest: [host-duties.md](host-duties.md).

## The checklist

1. **Modules discovered.** A Composer install lists `ddd-ai`, `ddd-argus`, `ddd-common-money` and `ddd-agents` in
   "vendor/composer/installed.json". A kernel without that file (the reference kernel) lists them in
   `DDDKernel::getAdditionalModuleClasses()`, in dependency order: `ArgusModule`, `MoneyModule`, `AIModule`,
   `AgentsModule`.
2. **Bundles:** `FrameworkBundle`, `DDDBundle`, `MonologBundle`.
3. **The environment reaches `$_ENV`.** The framework's config reads only `$_ENV`: load the env files with Dotenv, or
   run PHP with a `variables_order` that contains `E`.
4. **Environment variables.** The names, read by the framework, ddd-ai and the package (`Config::getEnv()`):

   | Variable | What it is for |
   |---|---|
   | `APP_ENV`, `APP_DEBUG`, `APP_SECRET` | Symfony; `APP_DEBUG` never `false`, use `0` |
   | "DB_DEFAULT_CONNECTION_DRIVER", "DB_DEFAULT_CONNECTION_HOST", "DB_DEFAULT_CONNECTION_HOST_PORT", "DB_DEFAULT_CONNECTION_DB_NAME", "DB_DEFAULT_CONNECTION_USER", "DB_DEFAULT_CONNECTION_PASSWORD", "DB_DEFAULT_CONNECTION_DB_SERVER_VERSION", "DB_DEFAULT_CONNECTION_DB_SERVER_CHARSET" | the entity manager (`EntityManagerFactory`); MariaDB 11.8 or newer (VECTOR columns) or PostgreSQL |
   | `LAZYLOAD_DEFAULT_REPO_TYPE` | the repository type of a lazy load without one (`DB`) |
   | `CACHE_APC_TTL` | the APCu cache group, the fallback of every group without configuration |
   | "CACHE_REDIS_CACHE_GROUP" (`redis`), "CACHE_REDIS_HOST", "CACHE_REDIS_PORT", "CACHE_REDIS_PASSWORD", "CACHE_REDIS_DATABASE", "CACHE_REDIS_NAMESPACE", "CACHE_REDIS_TTL" | the Redis cache group (`Cache::CACHE_GROUP_REDIS`); the group's type comes from "CACHE_REDIS_CACHE_GROUP", without it the group is APCu. The base system prompt cache reads and writes only this group. Without Redis it falls back to the APCu of each process: the web process and every worker keep their own copy, a warm-up or an inputs-version bump in one process never reaches the turn workers, and a worker reads a stale prompt for up to an hour |
   | `JWT_HASH_KEY`, `JWT_HASH_METHOD` | every outgoing Argus call is signed, the agent's model call included |
   | the provider key the model catalog names (`OPENROUTER_KEY`, `OPEN_AI_KEY`) | the egress refuses to build a request while it is empty, also under a recorded transport |
   | `DATABASE_TABLE_PREFIX` (optional) | [tables-and-persisted-names.md](tables-and-persisted-names.md) |
   | `CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS` (optional) | the account the framework's own console commands act as (`app:crons:upsert`) |
   | `APP_CACHE_DIR`, `APP_LOG_DIR` (optional) | the kernel's cache and log directories (`DDDKernel::CACHE_DIR_ENV`, `DDDKernel::LOG_DIR_ENV`) |
   | `AGENT_TRACE`, `AGENT_EGRESS`, `AGENT_UNLOAD_GRANULARITY`, `AGENT_SUB_SKILL_TOOL_DISCOVERY` (optional) | the agent's own switches: [config-map.md](config-map.md) |

   The Messenger DSNs are the host's own names ([transports-and-workers.md](transports-and-workers.md)).

5. **Services.** The framework's resource blocks; `logger` as a public alias of `monolog.logger`;
   `CustomAnnotationClassLoader`, `CustomAnnotationFileLoader` and `CustomAnnotationDirectoryLoader` tagged
   `routing.loader`; `DtoValueResolver` tagged `controller.argument_value_resolver` with priority 150; the host's
   controllers public with `controller.service_arguments`; the host's `Services` namespaces public. An MCP package is
   no service: exclude the MCP folder from autowiring. The reference kernel's `services.yaml` is a complete example.
6. **Routes:** a `type: annotation` resource on the host's controller folder.
7. **Authentication per request.** Before a controller runs, a `kernel.request` subscriber sets the account:
   `AuthService::setAccount()`. Without an account the read rights return nothing and the loop refuses. The reference
   kernel's `ReferenceDemoOwnerAuthenticationSubscriber` shows the place (it acts as one fixed account; a real host
   authenticates).
8. **An account row exists.** Conversations carry a foreign key to the account table of the framework.
9. **The chat controller.** A host controller extends `DDD\Presentation\Base\Controller\HttpController` and uses `DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait`,
   `AgentConversationWritesTrait` and `AgentConversationStartTrait` (optionally `AgentSelectableModelsTrait`). It
   declares the start route itself, opens a usage envelope around it and passes the agent space, or null for a start
   at account level. Template: the minimal template of the skill and `ReferenceConversationsController`. Hooks and
   their defaults: [override-points.md](override-points.md).
10. **Messenger.** Six transports, the routing and two middlewares: [transports-and-workers.md](transports-and-workers.md).
11. **The host commands and the cron list.** Without them nothing fires schedules, recovers a lost turn, reaps a dead
    worker, finalizes a conversation or purges the event tables: [host-duties.md](host-duties.md).
12. **Skills and tools.** `config/app/AI/skillPackages.php` of the host replaces the package's file whole and lists
    every skill the host offers; one skill file per skill under `config/app/AI/Skills/`; one MCP package per tool
    group. [first-capability-end-to-end.md](first-capability-end-to-end.md).
13. **Regenerating the models (a host with a table prefix or own table names).** No package declares a Composer
    script. A host with `DATABASE_TABLE_PREFIX` or `tableNames` entries adds `app:generate-doctrine-models-for-entities`
    to `post-install-cmd` and `post-update-cmd` of its own `composer.json`, or runs it after every install and update.
    Without it the vendor models keep the shipped names, and every raw statement of the module that reads a table name
    from a model hits a missing table ([tables-and-persisted-names.md](tables-and-persisted-names.md)).
14. **The schema.** `app:entity:show-sql` prints the DDL (the reference kernel's `bin/print-schema-sql.php` prints it
    with the committed table names). A foreign key may name a table created further down: apply with the foreign key
    checks off. There is no command that creates the schema.
15. **An HTTP MCP route (optional).** A controller that extends `DDD\Presentation\Mcp\MCPController` exposes tool packages to an outside
    MCP client. `tools/call` refuses a call without an authenticated account, but `initialize` and `tools/list` have no
    check of their own: the host's firewall must cover the route, otherwise the tool catalogue is public. The tools
    skill (`ddd-module-agents-tools-specialist`) explains the controller.

## The first request

```bash
php -S 127.0.0.1:8088 examples/reference-kernel/public/index.php
curl -X POST 127.0.0.1:8088/api/conversations/start -H 'Content-Type: application/json' \
  -d '{"aiConversationMessage": {"message": "Note: order coffee beans on Friday"}}'
```

The reference kernel defines the six transports with `sync://` and routes no message, so the turn runs inside the
request, and its model answers come from a recording (`ReferenceScriptedModelTransport`), so no provider is called. It
needs a database with the schema and the demo account (`bin/seed-demo-owner.sql`).

**The reference kernel runs only inside the package.** Its `bootstrap.php` takes the package at two folders above
itself, loads the package's test-only autoloader (`PackageTestAutoloader`) with third-party code from
`DDD_AGENTS_VENDOR_DIR`, and puts its cache and log directories below the system temp directory
(`ReferenceKernelDirectories`). A copy outside the package fails at once. An application keeps the kernel's `src/`
and `config/` as a model and writes its own bootstrap on Composer's autoloader ("vendor/autoload.php" and the
framework's kernel).

## What runs without extra work

- The loop makes model calls of its own besides the agent's: a display summary of the conversation
  (`AIConversationSummarizeMessage`) and compaction when a conversation grows. The decision layer is off in the
  package default (`AI.decisionModel` mode `off`) and the supervisor too (`AI.supervisor` `enabled` false); switching
  them on: `ddd-module-agents-decisions-and-guards-specialist` and `ddd-module-agents-autonomy-specialist`.
- Without an embedding provider every embedding job logs `TextEmbeddingNotGeneratedException` and the conversation
  search stays keyword-only. A host connects one by a same-path subclass of `DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService`.
- Every entitlement default is "ungated": no feature flags, no rights, every skill and tool offered. See
  [override-points.md](override-points.md).

## Checking the installation

- `php bin/console lint:container` (the reference kernel: `bin/console.php lint:container`). Building the container
  needs more memory than PHP's default 128M: the reference console and front controller set `memory_limit` to 1G
  before they boot the kernel; a host script that boots it does the same.
- `php bin/console debug:router` lists the chat routes.
- `app:workers:check` compares the host's worker list with the transports and routing of the compiled container.
- The package's transport contract (`MessengerTransportsHaveWorkerBlocksContract`, a trait for one test class of the
  host) checks the host's `messenger.yaml` and its supervisor file against `AgentMessengerTransports`. It is test code
  of the package and not autoloaded: `require_once` it from the package's install directory.
