# The first capability, end to end

**Reader:** host developer

Contents: what the reference kernel runs · step 1 the tool · step 2 the agent skill bound to it · step 3 the mount ·
step 4 a purpose with a done criterion · starting a purposed conversation · step 5 a widget · the discovery rule · checking.

A capability is what the agent can do for the owner: one or more tools, an agent skill that tells the model when and
how to use them, optionally a purpose that starts a conversation for a goal, optionally a widget that shows something
in the conversation. This walk-through builds the smallest one. Each step names the topic skill with the depth.

## What the reference kernel runs

| Part | In the reference kernel | Status |
|---|---|---|
| Tool package with a read and a write tool | `DemoNotesMCPPackage` (`getDemoNotes`, `addDemoNote`) with three DTOs, over `DemoNotesService` | runs |
| Agent skill bound to it | `config/app/AI/Skills/Demo/Notes.md` (`Demo.Notes`), bound in `config/app/AI/skillPackages.php` | runs |
| Mount (the agent loop offers the skill) | through the skill binding; no HTTP MCP route | runs |
| Purpose with a done criterion | none | template only (this file) |
| Widget | none | template only (this file) |
| Schedules | none | template only ([host-duties.md](host-duties.md)) |

## Step 1: the tool

An MCP package is a class extending `DDD\Domain\AI\Entities\MCPPackages\MCPPackage` with a static `$name` (its package id, `demo.notes`) and
`$description`. Each tool is a public method with the `MCPTool` attribute, an effect (`MCPToolEffect::READ` or
`MCPToolEffect::WRITE`) and a short summary (`MCPToolSummary`); its docblock is the tool description, an `@example`
line the example call. The method takes request DTOs (`RequestDto`, one public property per argument with a
`Parameter` attribute) and public container services; it returns a response DTO. A response that implements
`LlmMarkdownRenderable` renders itself for the model with `toLlmMarkdown()`.

```php
namespace AgentsReference\Presentation\Mcp\Demo;

use AgentsReference\Domain\Demo\Services\DemoNotesService;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNoteAddRequestDto;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNotesMcpResponseDto;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;

class DemoNotesMCPPackage extends MCPPackage
{
    public static ?string $name = 'demo.notes';
    public static ?string $description = 'Read and add the owner\'s notes.';

    /**
     * Keeps one note for the owner; returns all notes afterwards.
     *
     * @example {"text": "Order coffee beans on Friday"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Keep one note for the owner (the owner\'s words); returns all notes.')]
    public function addDemoNote(DemoNoteAddRequestDto $requestDto, DemoNotesService $demoNotesService): DemoNotesMcpResponseDto
    {
        $responseDto = new DemoNotesMcpResponseDto();
        $responseDto->notes = $demoNotesService->addNoteForCurrentAccount($requestDto->text);
        return $responseDto;
    }
}
```

An MCP package is no service: exclude its folder from autowiring. The data service behind it is a normal public
service of the host. Every tool attribute, schemas, result size, time limits and owner confirmation:
`ddd-module-agents-tools-specialist`.

## Step 2: the agent skill bound to it

One Markdown file per skill under the host's `config/app/AI/Skills/<Group>/<Name>.md`; the dotted name is
`<Group>.<Name>`. Frontmatter `description`, `shortDescription` and `decisionCriteria`; the body is what the model
reads once the skill is loaded.

```markdown
---
decisionCriteria: "The owner asks to keep a note, or asks what the notes say."
shortDescription: "keeps the owner's notes and reads them back"
description: >-
  Keep a note for the owner with addDemoNote, read the notes back with getDemoNotes.
---
# The owner's notes

- When the owner asks you to note something, call `addDemoNote` with the owner's words.
```

The binding: the host's `config/app/AI/skillPackages.php` maps the skill name to its packages, by class or by package
id, and **replaces the package's file whole**: a host that wants the package's own skills offered lists them too.

```php
use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;

return [
    'Demo.Notes' => [DemoNotesMCPPackage::class],
];
```

Skill file format, gates, loading, overriding a package skill: `ddd-module-agents-skills-and-purposes-specialist`.

## Step 3: the mount

The agent loop offers every bound skill in its catalog; the model loads a skill and its tools become callable. No
further registration is needed for the agent. To expose the same packages to an outside MCP client, a host controller
extends `DDD\Presentation\Mcp\MCPController`, sets its `BUNDLE` to an `DDD\Domain\AI\Entities\MCPPackages\MCPBundle` whose `INCLUDED_PACKAGES` lists the packages, declares the
route and calls `handleMcpRequest()`; the host's firewall covers that route. Details: `ddd-module-agents-tools-specialist`.

## Step 4: a purpose with a done criterion (template only)

A purpose starts a conversation for a declared goal: a directive, preloaded skills, run settings, a seeded task plan
and done criteria, stamped once at creation. It is not a skill and cannot be loaded mid-conversation.

1. One file `config/app/AI/Purposes/<Domain>/<Feature>.md` with the required frontmatter line `purposeClass:` (the
   class it loads as) and the directive as body. The package's own purposes (`Schedule.Run`,
   `Supervisor.Checkpoint`, `Background.MechanicalOperation`) are examples of the format.
2. The purpose name as a constant of the host's purpose class at the same path as `DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose`, which
   redeclares `REGISTERED_PURPOSE_NAMES` with the module's names plus its own (read through
   `AIConversationPurpose::getPurposeClassOfTheApplication()`).
3. A purpose class (a subclass of `AIConversationPurpose`, in the purpose's own domain) lists its done criteria in
   `DONE_CRITERION_CLASSES`: each a `DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion` subclass with its `KEY` (the seeded plan item's
   `doneCriterion`) and the tools that can change its verdict (`CHANGED_BY_TOOLS`).
4. Start a conversation for the purpose: see "Starting a purposed conversation" below.

Purpose file format, seeded plans, criteria, purpose runs: `ddd-module-agents-skills-and-purposes-specialist`.

### Starting a purposed conversation

The package has no route for it; the host's own route or service calls
`DDD\Domain\AI\Services\Conversations\AIConversationAgentService`:

- **With an agent space:** `startOrResumePurposedConversation($scope, $agentSpace, $purposeName, $async,
  $aiModelName, $kickoffSignalAppendix, $runSettings)`. `$scope` is `AIConversationDriveScope::CUSTOMER` (the owner)
  or `AIConversationDriveScope::ADMIN`. It checks that the name is registered and `startableViaApi`, and for a
  customer start that the space has every flag the purpose requires (frontmatter `requiredFeatureFlags`, united with
  `config/app/AI/purposeRequirements.php`); an unknown name is a `BadRequestException`. It keeps one active
  conversation per space and purpose: an active one is resumed (`AgentPurposedConversationStart::$resumedExisting`, the
  appendix is then not delivered), otherwise a new one starts with the purpose's kickoff signal (its
  `kickoffSignalText` plus `$kickoffSignalAppendix`, for example the owner's request in the owner's words). It returns
  an `AgentPurposedConversationStart` (`conversation`, `resumedExisting`).
- **At account level, without a space:** there is no singleton. The caller checks the name itself
  (`AIConversationPurpose::isRegisteredPurposeName()`, and its own flags) and calls `driveConversation($scope, null,
  null, $message, …, purposeName: $purposeName)`; this checks neither the registry nor the purpose's flags.

The purpose's preloaded skills (frontmatter `preloadSkillNames`) ride on the start signal: they are loaded, and the
agent speaks first, only when a start signal text is passed. `startOrResumePurposedConversation()` always passes one;
a `driveConversation()` call passes `startSignalText` (the purpose's kickoff text) and `startSignalSource:
AIConversationMessage::SIGNAL_SOURCE_PURPOSE_KICKOFF` itself, with an empty `$message`. Without a start signal the
conversation waits for the owner's first message and starts without its preloaded skills.

## Step 5: a widget (template only)

A widget is a block the agent writes into the conversation and the client renders; an answerable widget carries the
owner's answer back. A host widget is a class extending `DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget` (or `AnswerableConversationWidget`) whose wire
name is its `WIDGET_TYPE` constant and whose short name is that wire name followed by `ConversationWidget`, in a folder
`Domain/<Domain>/Entities/AI/Conversations/Widgets/` of the host. `ConversationWidgetClasses` takes every file of that
folder whose name ends in `ConversationWidget.php`; a class elsewhere is not found, and a class whose short name does
not match its wire name is a build error (logged and skipped in production). A tool returns a
widget; reading the answer and the block syntax: `ddd-module-agents-widgets-specialist`.

## The discovery rule

Widgets, signal sources, composed-view classes and tool package ids are found by folder scans that read the source
roots of the host under the root namespace `App\`; a host under another root namespace binds its tool packages by
class, as the reference kernel does: [override-points.md](override-points.md).

## Checking

- Run a conversation that needs the skill: the reference kernel's recorded model answers
  (`recordings/reference-conversation.json`) load `Demo.Notes` and call `addDemoNote`.
- The package's smoke test `ReferenceKernelSmokeTest` lints the reference kernel's container and checks that its chat
  routes are served; it runs no conversation.
- A turn that does not load the skill or does not call the tool: `ddd-module-agents-observability-specialist`.
