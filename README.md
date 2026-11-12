# mgamadeus/ddd-agents

Status: not released. Nothing here is stable API yet.

The agentic loop for `mgamadeus/ddd`, on top of `mgamadeus/ddd-ai`: conversations, messages, compaction, the task
plan, skills and MCP metadata, purposes and done criteria, widgets and signal sources, delegation and sub-runs, the
supervisor, run claims, the decision layer, the message handlers of the loop and the MCP tool executor.

## Layout

| Path | Content |
|---|---|
| `src/Modules/Agents/AgentsModule.php` | the `DDDModule` (source and config path, public service namespaces, exclude patterns) |
| `src/Domain/AI/…` | the agent core, in the namespace `DDD\Domain\AI\…` shared with `ddd-ai` (no class name collides) |
| `src/Presentation/…` | the agent console printer, the JSON-RPC base controller `MCPController`, `MCPToolExecutorService` |
| `config/app/` | the module's config root (empty in this step; the agent's defaults move here in a later step) |
| `tests/` | the package's own tests (none yet; the tests of the moved classes still run in the application) |

## State of this step

The code was moved mechanically out of an application (the namespace root swapped from `App\` to `DDD\`, every class
suffix unchanged). It still references application classes (`App\…`); the measured list of those references is the
work list of the next steps. The package therefore does not run standalone yet.

## Application overrides

`DDD` resolves an overridable class through the application's same-path subclass first (`App\Domain\AI\…\X` before
`DDD\Domain\AI\…\X`), so an application customizes a service, an entity set or a repository by a subclass at the same
path under its own root namespace.

## Environment of the package test kernel (`tests/Support/kernel`)

No env file is committed. `tests/Support/PackageTestEnvironment.php` sets every variable where the kernel is built
(`tests/bootstrap.php`, `tests/Support/bin/console.php`); key-shaped values there are the fake `test-not-a-secret`.
Order: process environment, then a git-ignored `tests/Support/kernel/.env.test` (copy `.env.dist`), then those
defaults. Nothing connects to a database unless a test reads or writes a DB entity.

| Variable | Purpose | Example format |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | Symfony environment and debug flag | `test`, `0` |
| `APP_SECRET` | Symfony's secret | any random string |
| `DB_DEFAULT_CONNECTION_DRIVER` | Doctrine driver | `pdo_mysql` |
| `DB_DEFAULT_CONNECTION_HOST`, `…_HOST_PORT` | a local throwaway database (integration tests only) | `127.0.0.1`, `3307` |
| `DB_DEFAULT_CONNECTION_DB_NAME` | its schema | `ddd_agents_test` |
| `DB_DEFAULT_CONNECTION_USER`, `…_PASSWORD` | its credentials | `<your local user>`, `<your local password>` |
| `DB_DEFAULT_CONNECTION_DB_SERVER_VERSION`, `…_DB_SERVER_CHARSET` | server version and charset | `mariadb-11.8.5`, `utf8mb4` |
| `LAZYLOAD_DEFAULT_REPO_TYPE` | the repository type of a `LazyLoad` without `repoType` | `DB` |

## Environment of the reference host (`examples/reference-kernel`)

No env file is committed. Copy `examples/reference-kernel/.env.dist` to `.env` (git-ignored) and fill the empty values;
a git-ignored `.env.local` overrides `.env`, and the process environment wins over both. Without a `.env` the bootstrap
loads `.env.dist`. The smoke test (`tests/Kernel/ReferenceKernelSmokeTest.php`) passes `test-not-a-secret` for every
key-shaped variable. No model provider is called: the model answers come from a recording.

| Variable | Purpose | Example format |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | Symfony environment and debug flag (never `false`: use `0`) | `prod`, `0` |
| `APP_SECRET` | Symfony's secret | any random string |
| `DB_DEFAULT_CONNECTION_DRIVER` | Doctrine driver | `pdo_mysql` |
| `DB_DEFAULT_CONNECTION_HOST`, `…_HOST_PORT` | the host's database (MariaDB 11.8+ for VECTOR columns) | `127.0.0.1`, `3307` |
| `DB_DEFAULT_CONNECTION_DB_NAME` | its schema | `ddd_agents_reference` |
| `DB_DEFAULT_CONNECTION_USER`, `…_PASSWORD` | its credentials | `<your local user>`, `<your local password>` |
| `DB_DEFAULT_CONNECTION_DB_SERVER_VERSION`, `…_DB_SERVER_CHARSET` | server version and charset | `mariadb-11.8.5`, `utf8mb4` |
| `LAZYLOAD_DEFAULT_REPO_TYPE` | the repository type of a `LazyLoad` without `repoType` | `DB` |
| `REFERENCE_DEMO_OWNER_ACCOUNT_ID` | the fixed demo account every request acts as | `1` |
| `CACHE_APC_TTL` | cache TTL in seconds (every cache group falls back to APCu) | `3600` |
| `OPENROUTER_KEY` | the provider key the model catalog names; the egress refuses to build a request while it is empty, even when the recorded transport answers | `<your provider key>` (any non-empty string for the recording) |
| `JWT_HASH_KEY` | key that signs every outgoing Argus call and login token | a random string of 32+ characters |
| `JWT_HASH_METHOD` | its algorithm | `HS256` |
