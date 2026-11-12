<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\Agent\SubSkillToolDiscovery;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEntitlement;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Services\Skills\MCPPackageIdsService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;

/**
 * Agent runtime skill — file-based config, stringently mirrored from AIPrompt.
 *
 * One skill = one `.md` (prose: frontmatter selection signal + body instruction) under
 * `config/app/AI/Skills/<Domain>/<Feature>.md` of any config root (the application's or a module's,
 * {@see \DDD\Domain\AI\Services\Skills\AISkillSourcesService}) + one entry in `config/app/AI/skillPackages.php` (the
 * package wiring, {@see getPackageClassesBySkillName()}). Loaded via {@see \DDD\Domain\AI\Services\Skills\AISkillsService}.
 *
 * A skill is identified by its dotted **name** (e.g. `Assistant.Mandates`) — there is no separate id. It is a domain
 * concept (the abstract capability the agent loads), so it lives in the AI domain next to AIPrompt/AIModel.
 * The concrete MCP packages are named in that config file — by class or by package id
 * ({@see \DDD\Domain\AI\Services\Skills\MCPPackageIdsService}) — so the AI core holds no reference to Presentation.
 * The tool *execution* goes through the MCP tool executor.
 *
 * The constants name the skills the package knows; an application names its own skills in a same-path subclass.
 * Constant NAME = UPPER_SNAKE of the dot-path; VALUE = 'Domain.Feature'.
 *
 * @method static AISkillsService getService()
 */
class AISkill extends Entity
{
    // The skill names the package itself knows: the agent's own governance skills, whose skill files (where the package
    // ships them) and MCP packages live in this package, and the optional cross-cutting components an application may
    // bind. An application's own skill names live in the application's same-path subclass of this class.

    // ===== Assistant =====
    // How the AI assistant works OUTWARD, on the owner's behalf — the STANDING / automatic way it writes the
    // content it PUBLISHES to third parties (replies and public texts): automatic-reply behaviour,
    // standing rules for what it publishes, writing tone + creativity, target audience. Deliberately its OWN skill,
    // NOT merged into a domain skill: "set my writing style / always reply informally / my audience is 30-55 / always
    // add #X to what you publish" is a settings intent the model never reliably maps to a domain-titled skill (the
    // constant load_skills=[] miss). Sharply distinct from Memory.Management — that is how the AI treats the USER in
    // chat (inward, the chat relationship); THIS changes how the AI acts toward CUSTOMERS / the public (outward).
    public const string ASSISTANT_SETTINGS = 'Assistant.Settings';

    // ===== Memory =====
    // The assistant's two long-term-memory subsystems, folded into ONE loadable skill (fold related packages so the
    // agent disambiguates at the reliable tool layer, not at lossy routing):
    //   • SEMANTIC: durable facts/directives/solutions — search/remember/update/forget.
    //   • EPISODIC: recall what was SAID — this chat or any past one.
    // Loadable so the tools surface only when memory work is intended; the always-on DIRECTIVE injection
    // is separate and not gated on this.
    public const string MEMORY_MANAGEMENT = 'Memory.Management';

    // ===== Money =====
    // Currency conversion at the DATED rate of a day (cached up to a month) — never a live quote.
    public const string MONEY_CURRENCY_CONVERSION = 'Money.CurrencyConversion';

    // ===== Web =====
    // The agent's window onto the LIVE web — current facts/news and anything not in the space's own data. Its own
    // skill (not folded into a domain) because "look it up online" is a cross-cutting intent with no domain home; it
    // will grow (fetch a page, analyse a URL) so the skill is the right container.
    public const string WEB_SEARCH = 'Web.Search';

    // Real, freely licensed photos of public places and things (Wikimedia Commons) — attribution per photo.
    public const string WEB_IMAGES = 'Web.Images';

    // Web.PageView: a vision-gated SUB-SKILL of Web.Search — `getWebPageAsScreenshot` renders a
    // URL and returns the pixels so the model can SEE the page (layout/colours/design), the visual sibling of the
    // markdown read. Sub-skill so Web.Search core stays usable on blind models; `requiresVisionCapabilities` so it only
    // loads on a model that can process images.
    public const string WEB_PAGE_VIEW = 'Web.PageView';

    // ===== Communication =====
    // VOICE CALLS between the agent and the owner (one call tool for every channel): the
    // agent calling the owner now (`startVoiceCall` — the app ring on web tab and phones, or a verified number), the
    // phone call log, and the caller numbers that may reach the agent by phone (add/verify/manage). PIN + channel
    // activation stay settings-UI-only (secrets never travel through the model pipeline); the body routes those
    // questions. The voice channel as a whole is gated by a feature flag; the phone package and each call channel
    // carry their own flags.
    public const string COMMUNICATION_VOICE_CALLS = 'Communication.VoiceCalls';

    // MESSAGES to the owner: discretionary sends to the owner's OWN registered
    // address on the user's wish or a standing directive (email for now; SMS/push follow with their transports).
    // Deliberately NOT the run-report obligation — that is the loop meta-tool `send_owner_report`.
    // Ungated like the phone channel — a billing flag is a later decision.
    public const string COMMUNICATION_MESSAGES = 'Communication.Messages';

    // Report the account's exact AI budget usage (monthly total / used / remaining). Its OWN skill so it is offered
    // ONLY when the account's policy allows the exact figures — dropped from the catalog under a fair-use or
    // hide-usage policy ({@see \DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement::hidesExactAIBudget()}).
    // An application gates it on its budget feature flag (its requirement overlay, `AI.skillRequirements`). Renamed
    // AI.Budget → Assistant.Budget: the lone AI.* skill joins the "the assistant itself"
    // family (Settings / Tasks / Budget).
    public const string ASSISTANT_BUDGET = 'Assistant.Budget';

    // SCHEDULED & BACKGROUND TASKS (an own skill — execution PLANNING is
    // not a communication channel): one-off now, one-time at a moment on the space's clock, recurring on a cron, list +
    // archive-policy delete. Delivery (EMAIL/CHAT) is a property OF a task; the report obligation itself is the
    // loop meta-tool `send_owner_report`, never a skill tool. A TIMED phone call is a task here whose text says to
    // call (Communication.VoiceCalls owns only the immediate call).
    public const string ASSISTANT_TASKS = 'Assistant.Tasks';

    // The reports of the earlier runs of a scheduled run's OWN task: purpose-bound
    // (`Schedule.Run` preloads it, never in a chat's catalog) — the run keeps its report's structure and states what
    // changed since. Its package and skill file ship with this package.
    public const string ASSISTANT_TASK_RUN_REPORTS = 'Assistant.TaskRunReports';

    // MANDATES: the customer's standing authorizations for consequential outward
    // actions — grant from the customer's own server-verified statement, list, revoke. Its own skill in the
    // "the assistant itself" family (Settings / Tasks / Budget / Mandates): "what may you do on your own?" and
    // "stop doing X automatically" are assistant-governance intents, not domain intents; and the executor's
    // mandate-gate refusal points the model here by name. Its package and skill file ship with this package.
    public const string ASSISTANT_MANDATES = 'Assistant.Mandates';

    /** @var array<string, string[]>|null the binding read once per process ({@see getPackageClassesBySkillName()}) */
    protected static ?array $packageClassesBySkillName = null;

    /** @var string The skill name / dot-path, e.g. 'Assistant.Mandates' — the skill's identifier. */
    public string $name;

    /** The decision model's criteria line: the frontmatter `decisionCriteria`, else the first sentence of `description`, cut to 200 chars. */
    public function getDecisionCriteria(): string
    {
        if (trim($this->decisionCriteria) !== '') {
            return trim($this->decisionCriteria);
        }
        $description = trim($this->description ?? '');
        $firstSentence = preg_split('/(?<=[.!?])\s/', $description, 2)[0] ?? $description;
        return mb_substr(trim($firstSentence), 0, 200);
    }

    /** @var string Selection description (the "use when" signal) from frontmatter — drives catalog selection */
    public string $description;

    /**
     * The decision model's catalog entry is capped here — a root description's disambiguating "NOT for …" tail sits at
     * the END, and a shorter cap cut exactly that tail. All offered roots at this cap stay well inside the decision
     * window.
     */
    public const int MAX_DECISION_CATALOG_ENTRY_CHARS = 2600;

    /** A tool's summary line in the decision model's state is capped here. */
    public const int MAX_DECISION_TOOL_SUMMARY_CHARS = 160;

    /**
     * The cap of a tool's summary where the decision model judges what a LOADED skill does by itself (the sub-skill
     * pass): the summary names the fields a read returns, and cut at the shorter cap a summary loses the last fields
     * it names — the sub-skill for them is then loaded for a question the loaded skill answers.
     */
    public const int MAX_DECISION_OWN_TOOL_SUMMARY_CHARS = 400;

    /**
     * The decision model's CATALOG ENTRY for this skill:
     * the same `description` the chat model reads in the `load_skills` catalog, plus —
     * for a root with sub-skills — the sub-skills' names and their one-line criteria, so the decision model knows what
     * a root unlocks. Capped to {@see MAX_DECISION_CATALOG_ENTRY_CHARS}. The one-line `decisionCriteria` stays the
     * fallback for a skill without a description.
     *
     * @param AISkills $offeredSkills the skills the sub-skills are named from — the (flag- and rights-gated) offered set,
     *                              so the decision model never reads a capability the chat model cannot load; the full
     *                              catalog where nothing is gated
     */
    public function getDecisionCatalogEntry(AISkills $offeredSkills): string
    {
        $entry = trim($this->description ?? '');
        if ($entry === '') {
            $entry = $this->getDecisionCriteria();
        }
        $entry = preg_replace('/\s+/', ' ', $entry) ?? $entry;
        $subSkillLines = [];
        foreach ($this->subSkillNames as $subSkillName) {
            $subSkill = $offeredSkills->findByName($subSkillName);
            if ($subSkill instanceof self) {
                $subSkillLines[] = $subSkillName . ' (' . $subSkill->getDecisionCriteria() . ')';
            }
        }
        if ($subSkillLines !== []) {
            $entry .= ' Loading it unlocks the sub-skills: ' . implode('; ', $subSkillLines) . '.';
        }
        return mb_substr($entry, 0, self::MAX_DECISION_CATALOG_ENTRY_CHARS);
    }

    /**
     * The tools this skill exposes as `name => summary` — what the chat model sees as the tool's description, capped to
     * {@see MAX_DECISION_TOOL_SUMMARY_CHARS} per tool (the decision model's view of a LOADED skill: the chat model
     * knows the loaded tools, the decision model does not).
     *
     * @return array<string, string>
     */
    public function getToolSummaries(
        ?AIConversationEntitlement $entitlement = null,
        int $maximumSummaryChars = self::MAX_DECISION_TOOL_SUMMARY_CHARS
    ): array {
        $toolDefinitions = ToolDefinitions::forPackages($this->getPackages());
        if ($entitlement !== null) {
            $toolDefinitions = $entitlement->filterToolDefinitions($toolDefinitions);
        }
        $summaries = [];
        foreach ($toolDefinitions->getElements() as $toolDefinition) {
            $summary = trim((string)($toolDefinition->summary ?? ''));
            if ($summary === '') {
                $summary = trim($toolDefinition->description);
            }
            $summaries[$toolDefinition->name] = mb_substr(preg_replace('/\s+/', ' ', $summary) ?? $summary, 0, $maximumSummaryChars);
        }
        return $summaries;
    }

    /**
     * @var string The DECISION criteria (frontmatter `decisionCriteria`): ≤ 200 chars of SITUATIONS in which
     * this skill is the one to load, written for the decision model's `choice` — not the model-facing `description`
     * (which stays the routing signal for the chat model). Empty = fall back to the first sentence of `description`.
     */
    public string $decisionCriteria = '';

    /**
     * @var string One-line, customer-facing capability summary (frontmatter `shortDescription`) — feeds the
     * product-awareness entries; NOT the routing signal ({@see $description} stays that).
     */
    public string $shortDescription = '';

    /**
     * @var string The observable SALES moments for this capability (frontmatter `salesTriggers`) — rendered as
     * "offer when: …" on the LOCKED awareness entry, so a small model pattern-matches the upsell moment instead of
     * having to infer it.
     */
    public string $salesTriggers = '';

    /**
     * @var bool SUB-SKILL marker (frontmatter `isSubSkill`, default false): a
     * sub-skill is NOT part of the general catalog and is offered only while a ROOT skill that lists it in its
     * {@see $subSkillNames} is loaded. Visibility is owned by the skill ITSELF (no registry scan, no silent
     * hiding); the edges are owned by the roots — a sub-skill may be listed by several roots (shared expertise
     * under multiple expert tracks). One nesting level: a sub-skill declares no sub-skills of its own.
     */
    public bool $isSubSkill = false;

    /**
     * @var string[] The ROOT's sub-skill edges (frontmatter `subSkillNames`): while THIS skill is loaded, the
     * listed sub-skills enter the load_skills catalog. Listing a general (non-sub) skill is harmless — it is in
     * the catalog anyway. See {@see $isSubSkill} for the visibility half.
     */
    public array $subSkillNames = [];

    /**
     * @var string[] PURPOSE-BOUND marker (frontmatter `onlyUnderPurposeNames`, default empty = general): when set, the
     * skill is offered and loadable ONLY inside a conversation whose {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$purposeName}
     * is one of these names. The working skill of a purposed run (e.g. the skill that carries out a `Nightly.Digest`
     * run) belongs to that run — offered in an open customer chat it would start the whole run inline. Enforced like
     * {@see $requiresVisionCapabilities}: dropped from the catalog AND refused on the load path (a hallucinated name
     * must not bypass the enum). The sub-skills of such a root need no marker — sub-skill visibility already
     * requires the root to be loaded.
     */
    public array $onlyUnderPurposeNames = [];

    /**
     * @var string[] PURPOSE EXCLUSION (frontmatter `notUnderPurposeNames`, default empty): the skill is never offered,
     * loadable or preloaded inside a conversation whose purpose is one of these names. E.g. a skill that edits a
     * finished result, inside the purposed run that builds it: the decision model would preload it on every owner wish
     * that reads like an edit, and there its tools refuse the target — context and a refused call for nothing.
     * Enforced at the same points as {@see $onlyUnderPurposeNames}
     * ({@see isOfferedUnderPurposeName()}).
     */
    public array $notUnderPurposeNames = [];

    /**
     * The rights this skill declares that the account does NOT have (pure): an unknown right name counts as missing —
     * the same fail-closed rule the offer filters and the execution guard apply. Empty = permitted.
     *
     * @return string[]
     */
    public function getMissingAccountRightNames(AgentAccountRightsInterface $accountRightsSetting): array
    {
        return $accountRightsSetting->getMissingRightNames($this->requiredAccountRights);
    }

    /** Whether this skill is gated on the feature-flag axis at all ({@see $requiredFeatureFlags} or {@see $anyOfRequiredFeatureFlags}). */
    public function isGatedByFeatureFlags(): bool
    {
        return $this->requiredFeatureFlags !== [] || $this->anyOfRequiredFeatureFlags !== [];
    }

    /**
     * Whether the agent space's plan includes this skill: ALL of {@see $requiredFeatureFlags} AND — when declared — AT LEAST
     * ONE of {@see $anyOfRequiredFeatureFlags}. The one predicate of the catalog filter, the `load_skills` refusal, the
     * awareness entry and the eval's preload filter.
     */
    public function isIncludedInFeatureFlags(AgentFeatureFlagsInterface $featureFlags): bool
    {
        if (!$featureFlags->hasAllAvailableFeatureFlags($this->requiredFeatureFlags)) {
            return false;
        }
        return $this->anyOfRequiredFeatureFlags === []
            || $featureFlags->hasAnyAvailableFeatureFlag($this->anyOfRequiredFeatureFlags);
    }

    /**
     * The flags whose upgrade unlocks this skill — what its awareness entry names (the required flag names of the
     * host's capability entry):
     * every required flag plus the FIRST any-of flag, the entry a declaration lists first (the cheapest way in). An
     * upgrade offer that named every alternative would offer the dearer plan beside the cheaper one.
     *
     * @return string[]
     */
    public function getFeatureFlagNamesAnUpgradeUnlocks(): array
    {
        $upgradeFeatureFlagNames = $this->requiredFeatureFlags;
        if ($this->anyOfRequiredFeatureFlags !== []) {
            $upgradeFeatureFlagNames[] = $this->anyOfRequiredFeatureFlags[0];
        }
        return array_values(array_unique($upgradeFeatureFlagNames));
    }

    /**
     * The whole flag gate in words — "AI.AGENT and one of AI.AGENT.CHANNEL.APPVOICE, AI.AGENT.CHANNEL.PHONE" — the wording the tool
     * refusal uses too ({@see MCPToolEntitlement::describeFeatureFlagRequirementOf()}); '' for an ungated skill.
     */
    public function describeFeatureFlagRequirement(): string
    {
        return MCPToolEntitlement::describeFeatureFlagRequirementOf($this->requiredFeatureFlags, $this->anyOfRequiredFeatureFlags);
    }

    /**
     * The gate's flags the agent space lacks, in words for a reason line: each missing required flag, plus "one of A, B"
     * when the any-of gate is not met. Empty when the plan includes the skill.
     *
     * @return string[]
     */
    public function describeMissingFeatureFlags(AgentFeatureFlagsInterface $featureFlags): array
    {
        $missingFeatureFlagDescriptions = [];
        foreach ($this->requiredFeatureFlags as $requiredFeatureFlag) {
            if (!$featureFlags->hasAllAvailableFeatureFlags([$requiredFeatureFlag])) {
                $missingFeatureFlagDescriptions[] = $requiredFeatureFlag;
            }
        }
        if ($this->anyOfRequiredFeatureFlags !== [] && !$featureFlags->hasAnyAvailableFeatureFlag($this->anyOfRequiredFeatureFlags)) {
            $missingFeatureFlagDescriptions[] = 'one of ' . implode(', ', $this->anyOfRequiredFeatureFlags);
        }
        return $missingFeatureFlagDescriptions;
    }

    /**
     * Whether this skill may be offered, loaded or preloaded under the given conversation purpose (pure): a purpose-bound
     * skill only under its purposes ({@see $onlyUnderPurposeNames}; null purpose = open chat = general skills only),
     * never under an excluded purpose ({@see $notUnderPurposeNames}).
     */
    public function isOfferedUnderPurposeName(?string $purposeName, ?string $rootPurposeName = null): bool
    {
        if ($this->onlyUnderPurposeNames !== [] && !($purposeName !== null && in_array($purposeName, $this->onlyUnderPurposeNames, true))) {
            return false;
        }
        if ($purposeName !== null && in_array($purposeName, $this->notUnderPurposeNames, true)) {
            return false;
        }
        // A delegation child carries no purpose of its own, so the
        // EXCLUSION is judged on its tree's ROOT as well — a child of a purposed run must not be
        // offered a skill that run's purpose excludes (its tools refuse a target of that run; the context is wasted
        // and the child drifts). The INCLUSION rule stays on the child's own purpose: a child never loads a purpose-bound
        // root by itself — it is seeded with it.
        return !($rootPurposeName !== null && in_array($rootPurposeName, $this->notUnderPurposeNames, true));
    }

    /**
     * @var string[] WIDGET-TRIGGERED preload (frontmatter `preloadOnWidgetTypes`, default empty): the ```widget fence
     * `type`s a CUSTOMER message can carry — a widget the customer composed in the chat input (a marked element of a
     * page, an attachment-like structure) — that make this skill's tools + instruction live for that turn WITHOUT a
     * load_skills hop. The message's own structure is the routing signal; a weak model must not have to recognise a
     * JSON block as a request (it skipped the load or answered the block literally). The AI core knows only the fence
     * `type` string, never what the widget means — that is the declaring skill's business. Entitlement- and
     * purpose-filtered like every other load; nothing is pinned, the eviction rules stay in charge.
     */
    public array $preloadOnWidgetTypes = [];

    /**
     * @var string[] The widget types this skill teaches the model to write (frontmatter `providesWidgetTypes`):
     * the widget catalog's map from a widget to the skill that brings its data — `findWidgets` loads it, and the
     * widget contract test fails an `emitter: agent` widget that no skill or tool declares. Metadata only: it loads and
     * offers nothing by itself.
     */
    public array $providesWidgetTypes = [];

    /**
     * @var string[] INITIATOR-BOUND marker (frontmatter `onlyOnInitiatorTypes`, default empty = general): when set,
     * the skill is offered, loadable and PRELOADED only in a conversation whose
     * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$initiatorType} is one of these — a call
     * conversation (`PHONE_CALL`) gets its call-only skill from its first turn, a chat structurally never sees
     * it. Enforced like {@see $onlyUnderPurposeNames}: dropped from the catalog AND refused on the load path.
     */
    public array $onlyOnInitiatorTypes = [];

    /** @var string Load-time instruction (the `.md` body) — injected with the tools when the skill is loaded */
    public string $instruction;

    /**
     * The PIN (from the skill's `nonEvictable: true` frontmatter, default false): once this skill body is loaded the
     * per-turn sweep never cascade-degrades it, even when childless. The skill-level twin of the tool/package
     * #[MCPToolNoEviction] — eviction exemption is uniform across skills, tools and packages. Keep pins few + small.
     */
    public bool $nonEvictable = false;

    /**
     * @var string[] Product FeatureFlags this skill requires (frontmatter `requiredFeatureFlags`). The skill is
     * offered to a conversation ONLY if the agent space has ALL of them available; empty = ungated. Composes hierarchically
     * with each tool's #[MCPRequiresFeatureFlags] (a tool may narrow further, but a locked skill hides all its tools).
     */
    public array $requiredFeatureFlags = [];

    /**
     * @var string[] Alternative Product FeatureFlags (frontmatter `anyOfRequiredFeatureFlags`): the agent space needs AT LEAST
     * ONE of them available, on top of ALL of {@see $requiredFeatureFlags} — the skill twin of a tool's
     * #[MCPRequiresAnyFeatureFlag], the same `ALL(required) AND ANY(anyOf)` predicate
     * ({@see isIncludedInFeatureFlags()}). For a capability two plan options each unlock (voice calls: the app
     * ring OR the phone). The FIRST entry is the one an upgrade offer names ({@see getFeatureFlagNamesAnUpgradeUnlocks()}),
     * so list the cheapest entry first. Empty = no any-of gate.
     */
    public array $anyOfRequiredFeatureFlags = [];

    /**
     * @var bool The skill needs a VISION-capable model (frontmatter `requiresVisionCapabilities`,
     * default false): its work depends on the model seeing images (e.g. a browser-navigation skill whose whole point
     * is reading screenshots). `load_skills` REFUSES it on a model without
     * {@see \DDD\Domain\AI\Entities\Models\AIModel::$hasVisionCapabilities}, naming the reason; the refusal is
     * re-evaluated on a mid-conversation model switch and at a delegation spawn, so a skill loaded under a vision
     * model cannot silently ride forward onto a blind one.
     */
    public bool $requiresVisionCapabilities = false;

    /**
     * @var string[] AccountRights this skill requires (frontmatter `requiredAccountRights`) — the SECOND
     * entitlement axis: `requiredFeatureFlags` asks whether the plan of the space includes the capability, this asks
     * whether the acting USER may use it. Values are
     * the application's account right names; empty = ungated.
     *
     * This level is an OFFER filter only — it removes the skill from the catalog and refuses `load_skills`. It CANNOT
     * be the hard gate: the executor receives package classes and no skill, and a tool is multi-anchored across
     * skills, so "the skill that exposed this call" has no single answer at execution. Every right
     * named here MUST therefore ALSO be declared with `#[MCPRequiresAccountRights]` on the packages this skill
     * exposes, or the offer disappears while the HTTP/CLI execution path stays open —
     * a test of the application fails the build if it is not.
     */
    public array $requiredAccountRights = [];

    /**
     * @var bool The skill only makes sense where the application bills the customer directly and the acting user may see
     * billing (frontmatter `requiresAccountBillingAccess`, default false) — invoices, payment method, invoice
     * address. For an account a third party provisions and bills there is no such surface at all, and for a user
     * without the application's billing right it is not theirs to touch.
     *
     * Enforced like {@see $requiresVisionCapabilities}: the skill is dropped from the catalog and `load_skills`
     * refuses it. The predicate is the application's (whether the account has billing access).
     * Unlike a missing flag this is NOT an upgrade offer and unlike a missing right it is not even grantable — for
     * such an account the capability simply does not exist, so the agent says nothing about it at all.
     */
    public bool $requiresAccountBillingAccess = false;

    /** The skill's dotted name is its identity (there is no numeric id). */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }

    /**
     * This skill's load-time block as the model sees it once loaded: `## <name>`, then the explicit list of the
     * tool/function names THIS skill brought in (so the model knows which tools came from which skill, and that they
     * are already callable), then the `.md` instruction. Empty string only when the skill has neither tools nor an
     * instruction.
     *
     * `$featureFlags`: tools gated TIGHTER than the skill (#[MCPRequiresFeatureFlags]) that the
     * agent space lacks are dropped from the tools line, and ONE short sentence marks the rest as plan-locked — without it
     * this line contradicted the product-capabilities block by presenting locked tools (a write on a plan that locks
     * it) as "already available", and the static `.md` body still mentions them. Deliberately ONE line, no locked
     * tool names — the upgrade details already live in the plan & capabilities section (do not re-spam them here).
     *
     */
    public function getInstructionBlock(
        ?AgentFeatureFlagsInterface $featureFlags = null,
        ?AgentAccountRightsInterface $accountRightsSetting = null,
        ?AISkills $offeredSkillsForSubSkillLines = null,
        ?AIConversationVariants $variants = null
    ): string {
        $toolsLine = $this->buildInstructionBlockToolsLine($featureFlags, $accountRightsSetting, $offeredSkillsForSubSkillLines);
        $instruction = $this->renderInstructionFor($variants);
        if ($instruction === '' && $toolsLine === '') {
            return '';
        }
        return "## {$this->name}\n{$toolsLine}{$instruction}";
    }

    /**
     * {@see getInstructionBlock()} without the conditional sections of the body: the append-only epoch's
     * system content and an appended skill load carry the core only; the sections for the situation follow in the
     * appended `# Situation` entry ({@see renderConditionalSectionsFor()}). A body without conditional sections renders
     * as in {@see getInstructionBlock()}.
     */
    public function getInstructionBlockWithoutConditionalSections(
        ?AgentFeatureFlagsInterface $featureFlags = null,
        ?AgentAccountRightsInterface $accountRightsSetting = null,
        ?AISkills $offeredSkillsForSubSkillLines = null
    ): string {
        $toolsLine = $this->buildInstructionBlockToolsLine($featureFlags, $accountRightsSetting, $offeredSkillsForSubSkillLines);
        $sections = $this->getSections();
        $instruction = $sections->hasConditionalSections()
            ? $sections->renderWithoutConditionalSections()
            : (isset($this->instruction) ? $this->instruction : '');
        if ($instruction === '' && $toolsLine === '') {
            return '';
        }
        return "## {$this->name}\n{$toolsLine}{$instruction}";
    }

    /** The conditional sections of the body for `$variants` ({@see AISkillSections::renderConditionalSectionsFor()}); '' when the body has none. */
    public function renderConditionalSectionsFor(?AIConversationVariants $variants): string
    {
        $sections = $this->getSections();
        return $sections->hasConditionalSections() ? $sections->renderConditionalSectionsFor($variants) : '';
    }

    /**
     * The conditional sections the body renders for `$variants`, as skill-prefixed `id => label`
     * (`Intake.Chat[workshopKind=mobile]` => `Intake.Chat — Mobile workshop (when workshopKind=mobile)`).
     *
     * @return array<string, string>
     */
    public function buildConditionalSectionLabelsFor(?AIConversationVariants $variants): array
    {
        $labelsById = [];
        foreach ($this->getSections()->buildConditionalSectionLabelsFor($variants) as $sectionId => $sectionLabel) {
            $labelsById[$this->name . '[' . $sectionId . ']'] = $this->name . ' — ' . $sectionLabel;
        }
        return $labelsById;
    }

    /**
     * The head of {@see getInstructionBlock()} after the name line: the tools line with its entitlement sentences and
     * the sub-skill lines.
     */
    protected function buildInstructionBlockToolsLine(
        ?AgentFeatureFlagsInterface $featureFlags,
        ?AgentAccountRightsInterface $accountRightsSetting,
        ?AISkills $offeredSkillsForSubSkillLines
    ): string {
        $entitlement = AIConversationEntitlement::forAxes($featureFlags, $accountRightsSetting);
        $toolNames = $this->getToolNames($entitlement);
        $toolsLine = $toolNames === [] ? '' : 'Tools from this skill (already available): ' . implode(', ', $toolNames) . "\n";
        $allToolNamesCount = count($this->getToolNames());
        // The two exclusions get DIFFERENT sentences, because the customer's next step differs: a plan gap is an
        // upgrade, a missing right is a request to their own administrator. Computed separately so a skill that is
        // narrowed on both axes says both.
        $flagFilteredToolNamesCount = count($this->getToolNames($entitlement->withFeatureFlagsOnly()));
        if ($featureFlags !== null && $flagFilteredToolNamesCount < $allToolNamesCount) {
            $toolsLine .= "Any other tool these instructions mention is NOT included in this customer's plan — never call or announce it; offer the unlocking upgrade instead (see the plan & capabilities section).\n";
        }
        if ($accountRightsSetting !== null && count($toolNames) < $flagFilteredToolNamesCount) {
            $toolsLine .= "Some tools these instructions mention are NOT permitted for THIS user account — never call or announce them, and never present them as a plan upgrade; say the account administrator has to grant the permission (see the restricted capabilities section).\n";
        }
        // the hierarchy: a loaded skill names its direct sub-skills, each with its purpose
        if ($offeredSkillsForSubSkillLines !== null && $this->subSkillNames !== []) {
            $toolsLine .= $this->getSubSkillLines($offeredSkillsForSubSkillLines);
        }
        return $toolsLine;
    }

    /** @var AISkillSections|null The parsed body, memoized per instruction text (never persisted) */
    protected ?AISkillSections $parsedSections = null;

    /** @var string|null The instruction text {@see $parsedSections} was parsed from */
    protected ?string $parsedSectionsSource = null;

    /**
     * The body cut into core and conditional sections (the skill variants) — parsed once
     * per instruction text. A body without marker headings is one core section.
     */
    public function getSections(): AISkillSections
    {
        $instruction = isset($this->instruction) ? $this->instruction : '';
        if ($this->parsedSections === null || $this->parsedSectionsSource !== $instruction) {
            $this->parsedSections = AISkillSections::fromMarkdown($instruction);
            $this->parsedSectionsSource = $instruction;
        }
        return $this->parsedSections;
    }

    /**
     * The instruction text for the conversation's variants: core + the matching sections. WITHOUT variants (a
     * conversation whose purpose declares no dimensions, a delegation, a plain chat) the whole body renders with the
     * markers stripped — a skill reads as before the cut.
     */
    public function renderInstructionFor(?AIConversationVariants $variants): string
    {
        $sections = $this->getSections();
        if (!$sections->hasConditionalSections()) {
            return isset($this->instruction) ? $this->instruction : '';
        }
        return $variants === null ? $sections->renderAll() : $sections->renderFor($variants);
    }

    /**
     * The ids of the conditional sections the render for `$variants` contains, prefixed by the skill name
     * (`Intake.Chat[workshopKind=mobile]`) — what the request snapshot, the inspector and the judge cite.
     *
     * @return string[]
     */
    public function getRenderedSectionIds(?AIConversationVariants $variants): array
    {
        $sections = $this->getSections();
        if (!$sections->hasConditionalSections()) {
            return [];
        }
        $ids = $variants === null ? $sections->variantLessRenderedSectionIds() : $sections->renderedSectionIds($variants);
        return array_map(fn (string $id): string => $this->name . '[' . $id . ']', $ids);
    }

    /** Leads the sub-skill lines in the block of a loaded skill. */
    public const string SUB_SKILLS_HEADER = 'Sub-skills of this skill, each for the work its line names. Load one ONLY when the request needs that work and nothing you have loaded covers it; "Currently loaded" names the ones you already have:';

    /**
     * The lines this skill shows of its direct sub-skills once it is loaded: name and purpose of each one the offer
     * holds (plan, rights, purpose and bindings are already applied to `$offeredSkills`). The lines do not change
     * when a sub-skill is loaded — which skills are loaded is stated by the loaded-skills block. Empty string without
     * an offered sub-skill.
     */
    public function getSubSkillLines(AISkills $offeredSkills): string
    {
        $lines = [];
        foreach ($offeredSkills->getElements() as $offeredSkill) {
            if ($offeredSkill->isSubSkill && in_array($offeredSkill->name, $this->subSkillNames, true)) {
                $lines[] = "- {$offeredSkill->name} — {$offeredSkill->description}";
            }
        }
        return $lines === [] ? '' : self::SUB_SKILLS_HEADER . "\n" . implode("\n", $lines) . "\n";
    }

    /** @var array<string, int> Per-skill-name memo of {@see getResidentBodyTokens()} — the `.md` body is deploy-invariant. */
    protected static array $residentBodyTokensByName = [];

    /**
     * The cached STATIC resident cost of this skill's BODY — the per-turn context cost while the skill is loaded, the
     * input to the eviction cost-benefit ({@see \DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability::$residentTokens}).
     * Estimated as `chars/4` of {@see getInstructionBlock()} —
     * NEVER the tokenizer (consistent with the tool-result budget's `toolResultChars`/4); the eviction is COMPARATIVE so
     * a consistent estimate suffices (NOT a BPE-exact count — `AISkillCatalogDocService` has the exact figure). The
     * `.md` body is deploy-invariant → computed ONCE per skill name per worker ({@see self::$residentBodyTokensByName}).
     */
    public function getResidentBodyTokens(): int
    {
        if (!array_key_exists($this->name, self::$residentBodyTokensByName)) {
            // under the hierarchy the block carries the lines of the sub-skills: every sub-skill counts, the upper bound
            $offeredSkillsForSubSkillLines = $this->subSkillNames !== [] && SubSkillToolDiscovery::listsSubSkillsUnderLoadedParent()
                ? self::getService()->getCatalog()
                : null;
            self::$residentBodyTokensByName[$this->name] = (int)(mb_strlen($this->getInstructionBlock(null, null, $offeredSkillsForSubSkillLines)) / 4);
        }
        return self::$residentBodyTokensByName[$this->name];
    }

    /** @return array<int, class-string> the MCPPackage classes that provide this skill's tools. */
    public function getPackages(): array
    {
        return self::getPackagesForSkill($this->name);
    }

    /**
     * The tool/function names this skill exposes (reflected off its packages' `#[MCPTool]` methods). Lets the loaded
     * context state the skill→tools relationship explicitly, so the model knows which tools it already has.
     * With `$entitlement` only the tools the plan includes (#[MCPRequiresFeatureFlags]) and the user may call
     * (the rights axis reaches the INSTRUCTION body too — a view right can keep a skill loaded while a
     * manage right removes its write tools).
     *
     * @return string[]
     */
    public function getToolNames(?AIConversationEntitlement $entitlement = null): array
    {
        $toolDefinitions = ToolDefinitions::forPackages($this->getPackages());
        if ($entitlement !== null) {
            $toolDefinitions = $entitlement->filterToolDefinitions($toolDefinitions);
        }
        $toolNames = [];
        foreach ($toolDefinitions->getElements() as $toolDefinition) {
            $toolNames[] = $toolDefinition->name;
        }
        return $toolNames;
    }

    /**
     * skill name (dot-path) => list of MCPPackage classes providing the skill's tools, from config
     * ({@see AISkillsService::CONFIG_SKILL_PACKAGES}). The keys are the authoritative registry of skills (one entry per
     * skill), in catalog order. Read once per process: the binding is code metadata, the same in every process. The
     * binding may name a package by class or by package id; the ids are resolved here, once, so every reader sees
     * classes ({@see resolvePackageIdsOfBinding()}).
     *
     * @return array<string, string[]>
     */
    public static function getPackageClassesBySkillName(): array
    {
        if (self::$packageClassesBySkillName === null) {
            $configuredPackageClassesBySkillName = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
            if (!is_array($configuredPackageClassesBySkillName) || $configuredPackageClassesBySkillName === []) {
                throw new \LogicException('The skill → MCP package binding (config ' . AISkillsService::CONFIG_SKILL_PACKAGES . ') is missing or empty.');
            }
            self::$packageClassesBySkillName = self::resolvePackageIdsOfBinding($configuredPackageClassesBySkillName);
        }
        return self::$packageClassesBySkillName;
    }

    /**
     * A skill binding with every package id replaced by its package class. A value naming a namespace
     * (it holds a backslash) is a class name and kept as it stands — nothing is autoloaded for it; any other value is a
     * package id ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPPackage::$name}) resolved by
     * {@see MCPPackageIdsService::findPackageClassForId()}. An id no package names is a configuration error.
     *
     * @param array<string, string[]> $configuredPackagesBySkillName skill name => package classes or ids
     * @return array<string, string[]> skill name => package classes, keys and order unchanged
     * @throws \LogicException for an id no package names
     */
    public static function resolvePackageIdsOfBinding(array $configuredPackagesBySkillName): array
    {
        $packageClassesBySkillName = [];
        $mCPPackageIdsService = null;
        foreach ($configuredPackagesBySkillName as $skillName => $configuredPackageReferences) {
            $packageClassesBySkillName[$skillName] = [];
            foreach ((array)$configuredPackageReferences as $configuredPackageReference) {
                $configuredPackageReference = (string)$configuredPackageReference;
                if (str_contains($configuredPackageReference, '\\')) {
                    $packageClassesBySkillName[$skillName][] = $configuredPackageReference;
                    continue;
                }
                if ($mCPPackageIdsService === null) {
                    /** @var MCPPackageIdsService $mCPPackageIdsService */
                    $mCPPackageIdsService = DDDService::instance()->getService(MCPPackageIdsService::class);
                }
                $packageClass = $mCPPackageIdsService->findPackageClassForId($configuredPackageReference);
                if ($packageClass === null) {
                    throw new \LogicException("The skill '$skillName' names the MCP package '$configuredPackageReference' (config " . AISkillsService::CONFIG_SKILL_PACKAGES . '), which is neither a class nor the id of an MCP package.');
                }
                $packageClassesBySkillName[$skillName][] = $packageClass;
            }
        }
        return $packageClassesBySkillName;
    }

    /**
     * @return string[] dotted names of all registered skills (the catalog/enum source)
     */
    public static function getAllSkillNames(): array
    {
        return array_keys(self::getPackageClassesBySkillName());
    }

    /**
     * @param string $skillName
     * @return array<int, class-string> MCPPackage classes wired to a skill name
     */
    public static function getPackagesForSkill(string $skillName): array
    {
        return self::getPackageClassesBySkillName()[$skillName] ?? [];
    }

    /**
     * The TOOL method names a skill's packages expose, or null when the given name is not a skill at all.
     * Backs the skill-called-as-a-tool correction in {@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::buildRefusalBeforeExecution()}:
     * a model that calls the SKILL name as if it were a tool (`Web.Search` instead of `searchTheWeb`) is told
     * the skill's real tool names instead of looping through a dead-end "tool not found". Pure
     * reflection over the statically wired packages — no container, no cache.
     *
     * @return string[]|null
     */
    public static function getToolNamesForSkillName(string $skillName): ?array
    {
        if (!isset(self::getPackageClassesBySkillName()[$skillName])) {
            return null;
        }
        $toolNames = [];
        foreach (self::getPackageClassesBySkillName()[$skillName] as $mcpPackageClass) {
            foreach ((new \ReflectionClass($mcpPackageClass))->getMethods(\ReflectionMethod::IS_PUBLIC) as $packageMethod) {
                if ($packageMethod->getAttributes(\DDD\Domain\AI\Entities\MCPPackages\MCPTool::class) !== []) {
                    $toolNames[] = $packageMethod->getName();
                }
            }
        }

        return $toolNames;
    }

    /**
     * The catalog skills whose packages provide a given tool name — the REVERSE of {@see getToolNamesForSkillName()}.
     * Lets the loop turn the executor's dead-end "Tool 'X' not found in this domain" into a self-correct that names
     * the skill to load first: a model that calls a tool it knows by name (from a task-plan gate like
     * `doneRequiresToolCall: confirmBooking`, or from prior context) but whose SKILL is not loaded would otherwise loop
     * on the bare not-found error and stall the turn. Pure reflection over the static wiring —
     * no container, no cache; called only on the rare unloaded-tool path.
     *
     * @return string[] the providing skill names (empty = a genuinely unknown tool)
     */
    public static function getSkillNamesProvidingToolName(string $toolName): array
    {
        $skillNames = [];
        foreach (self::getAllSkillNames() as $skillName) {
            if (in_array($toolName, self::getToolNamesForSkillName($skillName) ?? [], true)) {
                $skillNames[] = $skillName;
            }
        }
        return $skillNames;
    }
}
