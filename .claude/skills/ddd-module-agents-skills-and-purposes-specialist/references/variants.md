# Skill variants — dimensions, marked sections, resolution

**Reader:** host developer and package maintainer

Contents: what a variant is · the marked-section syntax · how a section is chosen · declaring dimensions on a purpose ·
when the platform resolves · state and traces · the surface dimension · adding a dimension or a section · traps

## What a variant is

A skill (or a purpose directive) keeps one unconditional CORE and carries its conditional instructions as MARKED
SECTIONS. The purpose declares a small set of VARIANT DIMENSIONS (a name and a closed value set). The platform
resolves their values from the record and the owner's statements, never the model, and the model sees the core plus
the sections whose selectors match. Every model call records which values were active and which sections were
rendered, so a wrong instruction is attributable to one section or to the resolver.

The package's own example: `Schedule.Run` declares one dimension, `reportChannel`, and its directive carries one
section per channel.

## The marked-section syntax

```markdown
## [reportChannel=EMAIL] Delivery by email          one value
## [reportChannel=EMAIL|CHAT] Either channel         one of several values
## [reportChannel=CHAT&surface=phone] Both apply      several dimensions, all must match
```

Parsed by `AISkillSection::parseMarkedHeading()` and `AISkillSections::fromMarkdown()`:

- A marked heading of any level opens a section; it ends at the next heading of the same or a higher level, so deeper
  headings belong to it.
- A marked heading nested in an open section opens a child whose selectors are the parent's AND its own; when the
  child closes, the parent continues.
- Headings inside fenced code blocks are text.
- A dimension name starts with a letter and holds letters and digits; a value holds letters, digits and `_`. A marker
  that does not parse is kept as plain text (the line stays verbatim) — a typo silently turns the section into core.
- The marker is stripped from the rendered heading; a heading without a title renders its text only.
- A section id is its selectors (`reportChannel=EMAIL`); traces cite it prefixed by the skill name.

## How a section is chosen

`AISkillSection::appliesTo()`: core always; a conditional section when every selector matches the resolved value
(`AIConversationVariants::matchesSelectors()`). A dimension the purpose does not declare never matches, so its sections
stay hidden. Rendering (`AISkill::renderInstructionFor()`):

| Conversation | What the model gets |
|---|---|
| a purpose with dimensions | core + matching sections (`AISkillSections::renderFor()`) |
| no variants: a plain chat, a delegation child, an admin render | every section, each heading naming its condition, e.g. "Delivery by email (only when reportChannel=EMAIL)" (`AISkillSections::renderAll()`) |
| a body without marked headings | the body as written |

A PROPOSED value (`AIConversationVariant::$decided` false) renders its sections already; guards that need a decision
read `AIConversationVariants::isDecided()`. Under the loaded skills the model reads one situation line with the
selectors (`AIConversationVariants::toSelectorLine()`); the provenance of values the owner has not confirmed is
delivered separately so a confirmation does not rewrite the system content. Where those lines sit on the wire belongs
to `ddd-module-agents-context-specialist`.

## Declaring dimensions on a purpose

Three hooks of the purpose class, from `ScheduleRunPurpose`:

```php
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimension;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimensions;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;

public function getVariantDimensions(): AIConversationVariantDimensions
{
    $dimensions = new AIConversationVariantDimensions();
    $reportChannelDimension = AIConversationVariantDimension::of(
        self::DIMENSION_REPORT_CHANNEL,
        [AIConversationSchedule::REPORT_CHANNEL_EMAIL, AIConversationSchedule::REPORT_CHANNEL_CHAT]
    );
    $dimensions->add($reportChannelDimension);
    return $dimensions;
}

public function resolveVariants(AIConversation $conversation, ?AIConversationVariants $previous = null, ?array $onlyDimensions = null): AIConversationVariants
{
    $variants = new AIConversationVariants();
    // read the record; put one AIConversationVariant per dimension
    return $variants;
}
```

- `AIConversationVariantDimension::of()` takes the name, the values, a default (`AIConversationVariantDimension::VALUE_UNKNOWN`
  unless given; always part of the values) and `decidedByOwnerOnly` (a record value then stays a proposal).
- A value is built with `AIConversationVariant::decided()`, `AIConversationVariant::proposed()` or
  `AIConversationVariant::unknown()`, with a source (`SOURCE_PURPOSE`, `SOURCE_RECORD`, `SOURCE_OWNER`) and the index of
  the message that is its evidence.
- `resolveVariants()` gets the previous values: keep an owner-decided value when the record only proposes; let a newer
  owner statement replace it. `$onlyDimensions` narrows a re-resolve after a tool; copy the other dimensions.
- `variantDimensionsChangedByTool()` returns the dimensions a completed tool can change; `[]` (the default) = no
  re-resolve after that tool.
- Resolution must be deterministic: completed tool calls with their arguments and results, the owner's rows, widget
  signals, stored columns. No model call, no reading of prose.

## When the platform resolves

`AIConversationPurposeRunsService::resolveAndPersistVariants()` runs at every turn start (every dimension) and after
every completed tool the purpose lists for a dimension. It bypasses the entity registries while the purpose reads, so
the turn's own writes are seen. It never runs in a delegation child and never throws into the loop: a failed resolve
keeps the previous values and is traced (`variants.resolveFailed`).

## State and traces

The resolved set is the `variants` slice of the conversation's `AgentWorkingState`, written only when a value changed
(`AIConversationVariants::transitionsSince()`) and only that slice. Each model call's request snapshot carries the
values with provenance (`AIConversationVariants::toSnapshotArray()`) and the rendered section ids
(`AISkill::getRenderedSectionIds()`); a resolve that changed something is traced as `variants.resolved` with one
`AIConversationVariantTransition` per dimension. The trace token reads `reportChannel=EMAIL(record)`,
or `(proposed,record@8)` for a proposal with its evidence row.

## The surface dimension

The dimension `surface` (`OwnerDevice::SURFACE_DIMENSION`) is set by the platform from the owner's device, not by a
purpose. A section whose only selector is `surface=screen` or `surface=phone` follows the screen default: it is
decided by the surface alone, also without variants. The same markers work in a tool description and the base
prompt (`AISkillSections::renderSurfaceSectionsOf()`). The owner's device belongs to the context skill.

## Adding a dimension or a section

1. A dimension: declare it in `getVariantDimensions()`, resolve it in `resolveVariants()`, list the tools that change
   it in `variantDimensionsChangedByTool()`, test the resolver with fixed messages.
2. A section: the marked heading in the skill or purpose file. Nothing else changes: the loop, the rendering and the
   traces need no code.
3. Test the files: every selector of the purpose's skills names a declared dimension and value, every section parses,
   the core is not empty. The package does not ship this check; a host writes it over `AISkillSections::fromMarkdown()`
   (`AISkillSections::getDimensionNames()`, `AISkillSections::allSectionIds()`, `AISkillSections::getCoreText()`).

## Traps

- A fix goes into one section or into the resolver, never into the core: the core is what every case reads.
- A skill loaded in a delegation child renders all sections with their conditions; write conditions a reader without
  a situation can still follow.
- A section keyed on a dimension that another purpose declares is invisible under every purpose that does not.
