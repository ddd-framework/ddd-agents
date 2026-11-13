# The package suite

**Reader:** package maintainer

Contents: how the suite runs · the suites · the contract tests · the kernel tests · database tests · the rules the
suite enforces · when a test fails.

## How the suite runs

The package runs on its own kernel (`AgentsTestKernel`): the framework, `ddd-argus`, `ddd-common-money`, `ddd-ai` and
this module, no application class. Third-party code and the framework packages come from a host's vendor directory
(`DDD_AGENTS_VENDOR_DIR`); `PackageTestAutoloader` loads them and refuses every other application class.

```bash
DDD_AGENTS_VENDOR_DIR=<host>/vendor php tests/Support/bin/phpunit.php -c phpunit.xml.dist --testsuite package
DDD_AGENTS_VENDOR_DIR=<host>/vendor php tests/Support/bin/console.php lint:container
DDD_AGENTS_VENDOR_DIR=<host>/vendor <host>/vendor/bin/phpstan analyse -c phpstan.neon.dist
```

No env file is committed. `PackageTestEnvironment` sets every variable where the kernel is built (key-shaped values
are the fake `test-not-a-secret`); a git-ignored "tests/Support/kernel/.env.test" (copied from `.env.dist`) overrides
them, the process environment wins over both. Nothing connects to a database unless a test reads or writes a DB
entity. The package `README.md` lists every variable.

## The suites

| Suite | Directory | Needs |
|---|---|---|
| `package` | `tests/` without `Integration`, `Application` and `Fixtures` | no database |
| `integration` | `tests/Integration` | a throwaway database (`tests/Integration/docker-compose.yml`, MariaDB and PostgreSQL) |
| `application` | `tests/Application` | the fixture application |

```bash
DDD_AGENTS_TEST_DRIVER=pdo_mysql … phpunit.php -c phpunit.xml.dist --testsuite integration
DDD_AGENTS_TEST_WITH_APPLICATION=1 DDD_AGENTS_TEST_DRIVER=pdo_pgsql … phpunit.php -c phpunit.xml.dist --testsuite application,integration
```

Database tests run on both engines, once for the package alone and once with the fixture application
(`tests/Fixtures/Application`: an application that subclasses the space with a required column of its own, with its
own set, repositories, generated model and service). A run with the fixture application is a run of its own: the
framework resolves a package class to the application's same-path class for the whole process. A database test stores
a space row through `DatabaseTestCase::insertAgentSpaceRow()`, which adds what the application requires in that run.

## The contract tests (`tests/Contract`)

| Test | Guards |
|---|---|
| `OwnedNamespacesTest` | every class sits in one of `AgentsModule::OWNED_NAMESPACES`; no entry is empty; no other package has a file there |
| `NoClosuresInRegularMethodsTest` | no new closure in a regular method; the counts in `closure-ratchet.txt` only go down |
| `DatabaseEntitiesAreNeverBuiltWithNewTest` | no database entity, set or repository of the package is built with `new` |
| `RelationsToPackageEntitiesResolveARepositoryTest` | a relation to a package entity finds the package's repository whatever the host's `LAZYLOAD_DEFAULT_REPO_TYPE` |
| `EnvironmentDistFilesTest` | the two kernels ship `.env.dist` files with every key-shaped value empty |
| `ExampleWorkerListTest` | `examples/workers.yaml`: one consumer per transport, no unknown key, the turn consumer's silent threshold above the turn's 1800 s |
| `MessengerTransportsContractFixtureTest` | the transport contract against a minimal configuration; every handler consumes the transport its message is routed to |
| "DeveloperSkillFormatTest", "DeveloperSkillNamesExistTest", "DeveloperSkillSourceOwnershipTest", "DeveloperSkillTemplatesTest" | the developer skills: strict frontmatter and links, every backticked name exists, every source file has one owner in the manifest, every PHP template passes the syntax check with its samples ([source-ownership.md](source-ownership.md)) |
| `MessengerTransportsHaveWorkerBlocksContract` | the trait a host uses against its own configuration ([transports-and-workers.md](transports-and-workers.md)) |

## The kernel tests (`tests/Kernel`)

| Test | Guards |
|---|---|
| `PackageKernelStandaloneTest` | the package boots alone; the central services resolve; no application class is loadable |
| `DoctrineModelsRegenerationTest` | the committed models equal a prefix-less regeneration; every committed model has an entity |
| `GeneratedDdlAgentSpaceTest` | the space reference is optional, and deleting a space deletes its rows, never empties the reference |
| `GeneratedDdlRequiredColumnsTest` | the tables that hang on a conversation have NOT NULL required columns and delete with the conversation |
| `ReferenceKernelSmokeTest` | the reference kernel's container compiles and its router serves the chat routes |

## The rules the suite enforces

- A class goes into an owned namespace, never into a directory ddd-ai or the framework also uses (a later class of
  the same name there would be a silent autoload race).
- A class in a `Services` namespace ends with `Service` and is fetched with `getService()`; a listener, a resetter, a
  value holder, a static library or a trait stays outside those namespaces (a class there becomes a public lazy
  service by its prefix).
- An interface carries the `Interface` suffix.
- No closures in regular methods (a short callback to a sort, filter or map primitive is allowed), no `private`, no
  `final`, `declare(strict_types=1)` everywhere, one class per file.
- No application, company, product or agent name in code, config or docs; what one application needs is declared by
  that application (see [override-points.md](override-points.md)).
- A model change regenerates the committed Doctrine models without a prefix; a persisted class name changes only with
  its migration ([tables-and-persisted-names.md](tables-and-persisted-names.md)).
- A change that changes a statement of a developer skill updates the owning skill in the same release
  ([source-ownership.md](source-ownership.md)).

## When a test fails

| Failure | Usual cause |
|---|---|
| `OwnedNamespacesTest` | a new folder outside `OWNED_NAMESPACES`; add the namespace to the constant and the module docblock, or move the class |
| `NoClosuresInRegularMethodsTest` | a new local lambda or closure parameter; write a named method or pass a value object |
| `DoctrineModelsRegenerationTest` | an entity attribute changed without regenerating the committed model |
| `PackageKernelStandaloneTest` | a new reference to a class outside the package and its dependencies |
| `ReferenceKernelSmokeTest` | a service the reference kernel cannot autowire, or a route trait that changed its path |
