# Catalog, binding, package ids, source roots, overlays and lint

**Reader:** host developer and package maintainer

Contents: the binding is the registry · package ids · config roots of skill and purpose files and same-path overrides · config files a host
replaces whole · overriding or replacing a package skill · skill name constants · the lint in a host's suite ·
the admin catalog and its namespace groups · what is read once per process

## The binding is the registry

`AISkill::getPackageClassesBySkillName()` reads the config `AI.skillPackages` (`AISkillsService::CONFIG_SKILL_PACKAGES`,
file `config/app/AI/skillPackages.php`): skill name → list of MCP packages. Its KEYS are the registry of skills
(`AISkill::getAllSkillNames()`), in catalog order. A skill file without a key is never offered; a key without a file
is skipped by `AISkillsService::getCatalog()` and reported by the lint. A skill without tools is bound to an empty
list.

A value names a package by class (it holds a backslash and is kept as it stands) or by package id (anything else,
resolved through `MCPPackageIdsService::findPackageClassForId()`). A missing or empty binding and an id no package
names both throw `LogicException` on first use: the agent does not run on a broken binding.

The reference kernel's binding is the smallest complete one:

```php
use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;

return [
    'Demo.Notes' => [DemoNotesMCPPackage::class],
];
```

## Package ids

Every MCP package names itself with its static `MCPPackage::$name` (the package's own: `agent.mandates`,
`agent.taskRunReports`; the kernel's: `demo.notes`). `MCPPackageIdsService` finds the packages by scanning the
source roots of `AgentSourceRootsService` (the application's `src/` under the root namespace `App\` first, then every
DDD module's `src/`), below `Presentation/Mcp`, file names ending in `MCPPackage.php`, abstract classes skipped. The
first class holding an id wins; a host class that EXTENDS the package's class of the same id is the intended
replacement, any other second holder is listed by `MCPPackageIdsService::getPackageClassesByDuplicateId()`.

Trap: a package id resolves only for a package the source-root scan finds, and that scan reads the application's
`src/` under the root namespace `App\` only. A host with another root namespace (the reference kernel's is
`AgentsReference\`) binds its own packages by class; the scan and the way to widen it belong to
`ddd-module-agents-specialist` (discovery, override points).

Use ids in a module's default binding (its classes may be replaced by a host) and in a host binding that names
module packages; classes are fine for the host's own packages.

## Config roots of skill and purpose files and same-path overrides

`AISkillSourcesService` lists where skill and purpose files come from: every config root that holds an
`AI/Skills` (`AISkillSourcesService::SKILLS_FOLDER`) or `AI/Purposes` (`AISkillSourcesService::PURPOSES_FOLDER`)
folder, in the order the config reader searches them: the application's roots first, then the modules'. These
config roots are independent of the host's root namespace: skill and purpose files are found under any namespace. Reading
stays `AISkillsService::getAISkillByName()`: the first root holding the file wins. A host file at a module's path
therefore REPLACES the module's file. It is never merged: the host's file must carry every key it wants.

`AISkillSourcesService::findSkillNamesByRoot()`, `findPurposeNamesByRoot()`, `findOverriddenNames()` and
`findCollidingNames()` answer which names each root holds, which a host overrides and which collide case-insensitively.

## Config files a host replaces whole

The config reader takes the application's file instead of the module's, key by key never merged:

| Config | Package default | What a host's file must do |
|---|---|---|
| `AI.skillPackages` | binds `Assistant.Mandates` → `agent.mandates`, `Assistant.TaskRunReports` → `agent.taskRunReports` | list EVERY skill the host offers, the package's two included. Without `Assistant.TaskRunReports` the purpose `Schedule.Run` preloads a skill that has no tools |
| `AI.skillRequirements` | empty | the gates the host puts on any skill, its own or a module's (reference [gating-by-entitlements-and-rights.md](gating-by-entitlements-and-rights.md)) |
| `AI.purposeRequirements` | empty | the flags a purpose needs before a customer may start it |
| `AI.skillNamespaceGroups` | one group, `Agent` | the groups of the admin catalog view (below) |
| `AI.agentPersona` | `Assistant`, `this product` | the names the placeholders stand for |

A template of the binding is [templates/skillPackages.php](../templates/skillPackages.php).

## Overriding or replacing a package skill

| Goal | Do |
|---|---|
| Change the text of a package skill | Copy the file to the same path below the host's `config/app/AI/Skills/` and edit it. The whole file replaces the package's; keep its keys |
| Gate a package skill | An entry in the host's `AI.skillRequirements`; never edit the file for a gate |
| Change what a package skill's tools do | A host package class at the same id: a subclass of the package's MCP package in the host's `src/Presentation/Mcp`, with the same `MCPPackage::$name` (found by id only under the scanned roots, see the trap above) |
| Drop a package skill | Leave it out of the host's `AI.skillPackages`. Check that no purpose preloads it and no root lists it |
| Rename | Not supported: the name is the file path and is stored on every message that loaded the skill |

After the change, run the lint (below). `AISkillSourcesService::findOverriddenNames()` lists every override, so a
test can pin the host's intended overrides.

## Skill name constants

`AISkill` carries constants for the names the package knows (for example `AISkill::ASSISTANT_MANDATES`,
`AISkill::WEB_SEARCH`). Only two of them ship a file and a package: `Assistant.Mandates` and
`Assistant.TaskRunReports`. The others (`Assistant.Settings`, `Assistant.Tasks`, `Assistant.Budget`,
`Memory.Management`, `Money.CurrencyConversion`, `Web.Search`, `Web.Images`, `Web.PageView`,
`Communication.VoiceCalls`, `Communication.Messages`) are names a host may implement itself; package code reads only
`AISkill::ASSISTANT_BUDGET` (the offer drops it under a hide-usage budget policy). A host names its own skills in a
same-path subclass of `AISkill` if it wants constants; the binding keys are the registry either way.

## The lint in a host's suite

`AISkillFilesLintService` reports what breaks a skill or purpose file at runtime without a warning:

| Method | Checks |
|---|---|
| `findSkillFileProblems()` | frontmatter present and valid YAML (after the persona step), a description, only known keys, lists of strings, sub-skills that resolve, no case-insensitive collision |
| `findPurposeFileProblems()` | frontmatter valid, a `purposeClass` that names an existing subclass of `AIConversationPurpose`, no collision |
| `findBindingProblems()` | the binding resolves, every key has a file, every bound class is an MCP package, no tool name is provided by two packages |

The package runs it over its own root. A host runs it over every root, a module's included (a module's file still
ships and loads in another application):

```php
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

class AgentSkillFilesTest extends TestCase
{
    public function testEverySkillPurposeAndBindingIsClean(): void
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillFilesLintService $aISkillFilesLintService */
        $aISkillFilesLintService = DDDService::instance()->getService(AISkillFilesLintService::class);
        $problems = array_merge(
            $aISkillFilesLintService->findSkillFileProblems($aISkillSourcesService->getSkillRootDirectories()),
            $aISkillFilesLintService->findPurposeFileProblems($aISkillSourcesService->getPurposeRootDirectories()),
            $aISkillFilesLintService->findBindingProblems()
        );
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
```

The test needs a booted kernel (the binding resolves ids through the container). It does not check purpose frontmatter
keys (a misspelt purpose key is silently ignored), sub-skill cycles, or that every right a skill declares is also
declared on its packages: a host that gates by rights adds that check itself.

## The admin catalog and its namespace groups

`AISkillCatalogDocService::buildSkillCatalog()` measures the catalog as a typed `AISkillCatalog`: per skill the
instruction tokens, per package and tool the tokens of the exact wire schema, totals, the purpose-bound skills split
into their purpose groups, and the general skills grouped by `AI.skillNamespaceGroups` (a skill belongs to the group
whose `skillNamePrefixes` entry is its longest prefix; no group = `Ungrouped`). `buildToolDocumentation()` documents
one tool, `buildSharedBlocks()` the two once-per-turn shared blocks. The figures come from a GPT-family tokenizer and
are indicative for other model families. Nothing here changes what the agent is offered; a host exposes it on an
admin endpoint or a console command of its own.

## What is read once per process

The binding (`AISkill::getPackageClassesBySkillName()`), the package ids, the skill and purpose requirement overlays,
the agent-relevant flag set (`AISkillsService::getAgentRelevantFeatureFlagNames()`) and each skill's resident token
estimate are memoized per process. After changing a binding, an overlay or a package class, restart the workers.
The framework also keeps read config files in a per-process tree: restart the workers after a skill or purpose file
edit as well.
